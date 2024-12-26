<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $sql = "ALTER TABLE sync_logs 
            ADD COLUMN progress DECIMAL(5,2) DEFAULT 0.00 
            AFTER items_synced;";
    
    $conn->exec($sql);
    echo "Successfully added progress column to sync_logs table\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
