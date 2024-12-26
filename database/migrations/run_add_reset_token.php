<?php
require_once dirname(__FILE__) . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/add_reset_token_to_users.sql');
    $conn->exec($sql);
    
    echo "Successfully added reset_token columns to users table\n";
} catch(PDOException $e) {
    echo "Error executing migration: " . $e->getMessage() . "\n";
}
?>
