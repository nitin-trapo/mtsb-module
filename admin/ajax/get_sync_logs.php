<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Add items_processed column if it doesn't exist
    try {
        $conn->exec("ALTER TABLE sync_logs ADD COLUMN IF NOT EXISTS items_processed INT DEFAULT 0 AFTER items_synced");
    } catch (PDOException $e) {
        // Continue even if column already exists
    }
    
    $stmt = $conn->query("
        SELECT 
            sync_type,
            status,
            items_synced as new_items,
            items_processed as total_processed,
            started_at,
            completed_at,
            CASE 
                WHEN status = 'failed' THEN error_message
                ELSE CONCAT(
                    'Added ', items_synced, ' new items',
                    ', processed ', COALESCE(items_processed, 0), ' total'
                )
            END as summary
        FROM sync_logs 
        ORDER BY started_at DESC 
        LIMIT 100
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($logs);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
