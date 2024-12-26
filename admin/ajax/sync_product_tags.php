<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

function logSyncError($message, $context = []) {
    $logFile = __DIR__ . '/../../logs/sync_error.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context) : '';
    $logMessage = "[$timestamp] $message $contextStr\n";
    
    error_log($logMessage, 3, $logFile);
}

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    logSyncError("Unauthorized access attempt", ['user_id' => $_SESSION['user_id'] ?? null]);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

try {
    logSyncError("Starting product tags sync");
    
    $shopify = new ShopifyAPI();
    $db = new Database();
    $conn = $db->getConnection();

    // Ensure the product_tags table has the tag_slug column
    try {
        $conn->exec("ALTER TABLE product_tags ADD COLUMN IF NOT EXISTS tag_slug VARCHAR(255) NOT NULL AFTER tag_name");
        $conn->exec("ALTER TABLE product_tags ADD UNIQUE INDEX IF NOT EXISTS idx_tag_slug (tag_slug)");
    } catch (PDOException $e) {
        logSyncError("Error updating table structure", ['error' => $e->getMessage()]);
        // Continue even if the column already exists
    }
    
    // Start sync
    $stmt = $conn->prepare("
        INSERT INTO sync_logs 
        (sync_type, status, started_at, items_synced)
        VALUES ('product_tags', 'running', NOW(), 0)
    ");
    $stmt->execute();
    $sync_id = $conn->lastInsertId();
    
    logSyncError("Sync started", ['sync_id' => $sync_id]);
    
    // Send initial response with sync_id
    header('Content-Type: application/json');
    header('Connection: close');
    echo json_encode([
        'success' => true,
        'sync_id' => $sync_id,
        'message' => 'Product tags sync started'
    ]);
    
    // Calculate content length and flush
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Get all product tags using GraphQL
    $tags = $shopify->getProductTags();
    $total_tags = count($tags);
    logSyncError("Retrieved product tags", [
        'count' => $total_tags,
        'sample_tags' => array_slice($tags, 0, 5)
    ]);
    
    // First, get existing tags to track which ones to delete
    $existing_tags = [];
    $stmt = $conn->query("SELECT tag_name, tag_slug FROM product_tags");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_tags[$row['tag_slug']] = $row['tag_name'];
    }
    
    // Prepare statements for new tags only
    $insert_stmt = $conn->prepare("
        INSERT INTO product_tags (tag_name, tag_slug)
        VALUES (?, ?)
    ");
    
    $tags_synced = 0;
    $tags_skipped = 0;
    $batch_size = 50;
    $current_batch = [];
    
    foreach ($tags as $tag) {
        try {
            // Create slug version of the tag (for system use)
            $tag_slug = 'tag_' . strtolower(trim($tag));
            $tag_slug = str_replace(' ', '_', $tag_slug);
            $tag_slug = preg_replace('/[^a-z0-9_]/', '', $tag_slug);
            
            // Skip if tag already exists
            if (isset($existing_tags[$tag_slug])) {
                unset($existing_tags[$tag_slug]); // Remove from existing to track deletions
                $tags_skipped++;
                continue;
            }
            
            // Insert only new tags
            $insert_stmt->execute([$tag, $tag_slug]);
            $tags_synced++;
            $current_batch[] = ['name' => $tag, 'slug' => $tag_slug];
            
            // Update sync progress for each batch
            if (count($current_batch) >= $batch_size) {
                $update_stmt = $conn->prepare("
                    UPDATE sync_logs
                    SET items_synced = ?,
                        items_processed = ?
                    WHERE id = ?
                ");
                $update_stmt->execute([$tags_synced, $tags_synced + $tags_skipped, $sync_id]);
                
                logSyncError("Progress updated", [
                    'sync_id' => $sync_id,
                    'new_tags' => $tags_synced,
                    'skipped_tags' => $tags_skipped,
                    'total_processed' => $tags_synced + $tags_skipped,
                    'batch_sample' => array_slice($current_batch, 0, 5)
                ]);
                
                $current_batch = [];
            }
        } catch (Exception $e) {
            logSyncError("Error syncing tag", [
                'tag' => $tag,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Final progress update
    if ($tags_synced > 0 || $tags_skipped > 0) {
        $update_stmt = $conn->prepare("
            UPDATE sync_logs
            SET items_synced = ?,
                items_processed = ?
            WHERE id = ?
        ");
        $update_stmt->execute([$tags_synced, $tags_synced + $tags_skipped, $sync_id]);
    }
    
    // Delete tags that no longer exist in Shopify
    $deleted_count = 0;
    if (!empty($existing_tags)) {
        $delete_slugs = array_keys($existing_tags);
        $placeholders = str_repeat('?,', count($delete_slugs) - 1) . '?';
        $delete_stmt = $conn->prepare("
            DELETE FROM product_tags 
            WHERE tag_slug IN ($placeholders)
        ");
        $delete_stmt->execute($delete_slugs);
        $deleted_count = count($delete_slugs);
        
        logSyncError("Deleted obsolete tags", [
            'count' => $deleted_count,
            'sample_deleted' => array_slice($delete_slugs, 0, 5)
        ]);
    }
    
    // Mark sync as complete
    $stmt = $conn->prepare("
        UPDATE sync_logs
        SET status = 'success',
            completed_at = NOW(),
            items_synced = ?,
            items_processed = ?,
            error_message = ?
        WHERE id = ?
    ");
    $summary = "Added {$tags_synced} new tags, skipped {$tags_skipped} existing tags, deleted {$deleted_count} obsolete tags";
    $stmt->execute([$tags_synced, $tags_synced + $tags_skipped, $summary, $sync_id]);
    
    logSyncError("Sync completed successfully", [
        'sync_id' => $sync_id,
        'new_tags' => $tags_synced,
        'skipped_tags' => $tags_skipped,
        'deleted_tags' => $deleted_count,
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    logSyncError("Sync failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    if (isset($sync_id)) {
        $stmt = $conn->prepare("
            UPDATE sync_logs
            SET status = 'failed',
                error_message = ?,
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $sync_id]);
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
