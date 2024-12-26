<?php
require_once dirname(__FILE__) . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Read and execute the migration SQL
    $sql = file_get_contents(__DIR__ . '/add_agent_id_to_orders.sql');
    $conn->exec($sql);
    
    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
