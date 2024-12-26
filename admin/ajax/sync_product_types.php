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
    logSyncError("Starting product types sync");
    
    $shopify = new ShopifyAPI();
    $db = new Database();
    $conn = $db->getConnection();
    
    // Start sync
    $stmt = $conn->prepare("
        INSERT INTO sync_logs 
        (sync_type, status, started_at, items_synced)
        VALUES ('product_types', 'running', NOW(), 0)
    ");
    $stmt->execute();
    $sync_id = $conn->lastInsertId();
    
    logSyncError("Sync started", ['sync_id' => $sync_id]);
    
    // Send initial response with sync_id
    header('Content-Type: application/json');
    header('Connection: close');
    echo json_encode([
        'success' => true,
        'sync_id' => (int)$sync_id,
        'message' => 'Product types sync started'
    ]);
    
    // Calculate content length and flush
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Get product types directly using GraphQL
    logSyncError("Fetching product types from Shopify");
    $product_types = $shopify->getProductTypes();
    logSyncError("Product types fetched", ['count' => count($product_types)]);
    
    // Insert product types
    $stmt = $conn->prepare("INSERT IGNORE INTO product_types (type_name) VALUES (?)");
    $count = 0;
    foreach ($product_types as $type) {
        if (!empty($type)) {
            $stmt->execute([$type]);
            $count += $stmt->rowCount();
        }
    }
    
    logSyncError("Product types inserted", ['count' => $count]);
    
    // Update sync log
    $stmt = $conn->prepare("
        UPDATE sync_logs 
        SET status = 'success',
            completed_at = NOW(),
            items_synced = ?
        WHERE id = ?
    ");
    $stmt->execute([$count, $sync_id]);
    
    logSyncError("Sync completed successfully", [
        'sync_id' => $sync_id,
        'items_synced' => $count
    ]);
    
} catch (Exception $e) {
    logSyncError("Sync error occurred", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'sync_id' => $sync_id ?? null
    ]);
    
    // Log error
    if (isset($sync_id)) {
        $stmt = $conn->prepare("
            UPDATE sync_logs 
            SET status = 'failed',
                completed_at = NOW(),
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $sync_id]);
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'sync_id' => $sync_id ?? null
        ]);
    }
}
