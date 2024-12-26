<?php
require_once dirname(__FILE__) . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Enable error reporting
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Read and execute the migration SQL
    $sql = file_get_contents(__DIR__ . '/add_default_rule_type.sql');
    $conn->exec($sql);
    
    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
