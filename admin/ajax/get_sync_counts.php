<?php
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../includes/functions.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get counts from each table
    $counts = [
        'customers' => $conn->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
        'orders' => $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
        'product_types' => $conn->query("SELECT COUNT(*) FROM product_types")->fetchColumn(),
        'product_tags' => $conn->query("SELECT COUNT(*) FROM product_tags")->fetchColumn()
    ];

    echo json_encode([
        'success' => true,
        'counts' => $counts
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
