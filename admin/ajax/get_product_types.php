<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Check if the table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'product_types'");
    if ($stmt->rowCount() === 0) {
        throw new Exception('Table product_types does not exist');
    }

    // Get the table structure
    $stmt = $conn->query("DESCRIBE product_types");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log('Table structure: ' . print_r($columns, true));

    // Get product types from product_types table
    $stmt = $conn->query("
        SELECT type_name 
        FROM product_types
        ORDER BY id ASC
    ");
    
    if (!$stmt) {
        $error = $conn->errorInfo();
        throw new Exception('Database error: ' . $error[2]);
    }
    
    $product_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Log the results for debugging
    error_log('Product Types from Database: ' . print_r($product_types, true));

    header('Content-Type: application/json');
    echo json_encode(['product_types' => $product_types, 'count' => count($product_types)]);
} catch (Exception $e) {
    error_log('Error fetching product types: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'error' => 'Failed to fetch product types: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
