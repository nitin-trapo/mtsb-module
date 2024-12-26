<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if sync_logs table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'sync_logs'");
    if ($stmt->rowCount() == 0) {
        // Create sync_logs table
        $sql = "CREATE TABLE IF NOT EXISTS sync_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sync_type ENUM('customers', 'orders') NOT NULL,
            status ENUM('running', 'success', 'failed') NOT NULL,
            items_synced INT DEFAULT 0,
            error_message TEXT,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $conn->exec($sql);
    }
} catch (PDOException $e) {
    error_log("Error checking/creating sync_logs table: " . $e->getMessage());
}
?>
