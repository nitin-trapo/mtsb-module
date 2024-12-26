<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get product tags from product_tags table
    $stmt = $conn->query("
        SELECT tag_name 
        FROM product_tags
        ORDER BY id ASC
    ");
    
    if (!$stmt) {
        $error = $conn->errorInfo();
        throw new Exception('Database error: ' . $error[2]);
    }
    
    $product_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Log the results for debugging
    error_log('Product Tags from Database: ' . print_r($product_tags, true));

    header('Content-Type: application/json');
    echo json_encode(['product_tags' => $product_tags, 'count' => count($product_tags)]);
} catch (Exception $e) {
    error_log('Error fetching product tags: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'error' => 'Failed to fetch product tags: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
