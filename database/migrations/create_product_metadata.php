<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS product_metadata (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meta_key VARCHAR(255) NOT NULL,
        meta_value TEXT NOT NULL,
        last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_meta_key (meta_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $conn->exec($sql);
    echo "Successfully created product_metadata table\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
