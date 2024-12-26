<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

try {
    $shopify = new ShopifyAPI();
    $db = new Database();
    $conn = $db->getConnection();

    // First ensure sync_logs table exists
    // require_once 'check_sync_table.php';
    
    // Get all existing customers first
    $stmt = $conn->query("SELECT shopify_customer_id FROM customers");
    $existing_customers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get the date range for sync (last 90 days)
    $date_90_days_ago = date('c', strtotime('-90 days')); // Using ISO 8601 format
    
    // Start sync
    $stmt = $conn->prepare("
        INSERT INTO sync_logs 
        (sync_type, status, started_at, items_synced)
        VALUES ('orders', 'running', NOW(), 0)
    ");
    $stmt->execute();
    $sync_id = $conn->lastInsertId();
    
    // Start output buffering
    ob_start();
    
    // Send headers and initial response
    header('Content-Type: application/json');
    header('Connection: close');
    echo json_encode([
        'success' => true,
        'sync_id' => $sync_id,
        'message' => 'Order sync started'
    ]);
    
    // Calculate content length
    $size = ob_get_length();
    header("Content-Length: $size");
    
    // Flush all output
    ob_end_flush();
    flush();
    
    // Close session and continue processing
    if (session_id()) session_write_close();
    ignore_user_abort(true);
    set_time_limit(0);
    
    // Continue with the sync process
    $total_synced = $shopify->syncOrders($date_90_days_ago);
    
    // Update sync log with success
    $stmt = $conn->prepare("
        UPDATE sync_logs 
        SET status = 'success',
            items_synced = ?,
            completed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$total_synced, $sync_id]);
    
} catch (Exception $e) {
    // Log error and update sync status
    if (isset($sync_id)) {
        try {
            $stmt = $conn->prepare("
                UPDATE sync_logs 
                SET status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $sync_id]);
        } catch (Exception $logError) {
            error_log("Failed to update sync log: " . $logError->getMessage());
        }
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
