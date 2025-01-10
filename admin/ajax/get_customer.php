<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT id, shopify_customer_id, email, first_name, last_name, 
               phone, is_agent, status, bank_name, bank_account_number, 
               bank_account_header
        FROM customers 
        WHERE id = ?
    ");
    
    $stmt->execute([$_GET['customer_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'customer' => $customer]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
