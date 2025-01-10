<?php
require_once '../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/create_orders_and_commissions_tables.sql');
    
    // Split into individual queries
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (!empty($query)) {
            $conn->exec($query);
            echo "Executed query successfully.\n";
        }
    }
    
    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    echo "SQL State: " . $e->getCode() . "\n";
}
