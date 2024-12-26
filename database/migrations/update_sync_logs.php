<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $sql = "ALTER TABLE sync_logs 
            MODIFY COLUMN sync_type ENUM('customers', 'orders', 'product_types', 'product_tags') NOT NULL;";
    
    $conn->exec($sql);
    echo "Successfully updated sync_logs table\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
