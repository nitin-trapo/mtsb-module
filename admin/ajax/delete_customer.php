<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['customer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // First check if customer has any orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
    $stmt->execute([$_POST['customer_id']]);
    $orderCount = $stmt->fetchColumn();
    
    if ($orderCount > 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete customer with existing orders. Please deactivate instead.'
        ]);
        exit;
    }
    
    // Delete customer if no orders exist
    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->execute([$_POST['customer_id']]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
