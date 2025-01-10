<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    $shopify = new ShopifyAPI();
    
    // Get the last synced customer ID
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get all existing customer IDs
    $stmt = $conn->query("SELECT shopify_customer_id FROM customers");
    $existing_customers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get all customer IDs from orders that don't exist in customers table
    try {
        $stmt = $conn->query("
            SELECT DISTINCT o.customer_id as shopify_customer_id
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.shopify_customer_id
            WHERE c.id IS NULL 
            AND o.customer_id IS NOT NULL
        ");
        $missing_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($missing_customers) . " missing customers to sync");
    } catch (Exception $e) {
        error_log("Error fetching missing customers: " . $e->getMessage());
        $missing_customers = [];
    }
    
    // Start sync log entry
    $stmt = $conn->prepare("
        INSERT INTO sync_logs 
        (sync_type, status, started_at) 
        VALUES 
        ('customers', 'running', NOW())
    ");
    $stmt->execute();
    $syncLogId = $conn->lastInsertId();
    
    $itemsSynced = 0;
    $hasError = false;
    $errorMessage = '';
    
    // Return sync_id immediately
    echo json_encode([
        'success' => true,
        'sync_id' => $syncLogId,
        'message' => 'Customer sync started'
    ]);
    
    // Ensure all output is sent
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    
    // Close the session to allow concurrent requests
    session_write_close();
    
    // Start sync process
    $total_synced = 0;
    
    // First sync missing customers from orders
    foreach ($missing_customers as $customer) {
        try {
            if (!empty($customer['shopify_customer_id'])) {
                error_log("Processing customer ID: " . $customer['shopify_customer_id']);
                
                $shopify_customer = $shopify->getCustomerById($customer['shopify_customer_id']);
                
                if ($shopify_customer) {
                    try {
                        $synced = $shopify->syncCustomer($shopify_customer);
                        if ($synced) {
                            $total_synced++;
                            $itemsSynced++;
                            error_log("Successfully synced customer: ID={$customer['shopify_customer_id']}");
                        } else {
                            error_log("Failed to sync customer: ID={$customer['shopify_customer_id']} (already exists or invalid data)");
                        }
                    } catch (Exception $e) {
                        error_log("Error during customer sync: ID={$customer['shopify_customer_id']}, Error: " . $e->getMessage());
                        $hasError = true;
                        $errorMessage = $e->getMessage();
                    }
                } else {
                    error_log("Customer not found in Shopify: ID={$customer['shopify_customer_id']}");
                }
            }
        } catch (Exception $e) {
            error_log("Error processing customer ID {$customer['shopify_customer_id']}: " . $e->getMessage());
            $hasError = true;
            $errorMessage = $e->getMessage();
            continue;
        }
    }
    
    // Now sync any remaining customers from Shopify
    try {
        $synced_count = $shopify->syncCustomers();
        $total_synced += $synced_count;
        $itemsSynced += $synced_count;
        
    } catch (Exception $e) {
        // Log error
        error_log("Error in customer sync: " . $e->getMessage());
        $hasError = true;
        $errorMessage = $e->getMessage();
    }
    
    // Update sync log
    if ($hasError) {
        $stmt = $conn->prepare("
            UPDATE sync_logs 
            SET status = 'failed',
                items_synced = ?,
                completed_at = NOW(),
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$itemsSynced, $errorMessage, $syncLogId]);
    } else {
        $stmt = $conn->prepare("
            UPDATE sync_logs 
            SET status = 'success',
                items_synced = ?,
                completed_at = NOW(),
                error_message = NULL
            WHERE id = ?
        ");
        $stmt->execute([$itemsSynced, $syncLogId]);
    }
    
} catch (Exception $e) {
    // Log error
    if (isset($syncLogId)) {
        $stmt = $conn->prepare("
            UPDATE sync_logs 
            SET status = 'failed',
                error_message = ?,
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $syncLogId]);
    }
    
    // Only send error response if we haven't sent the initial response
    if (!headers_sent()) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
