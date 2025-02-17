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

if (!isset($_POST['agent_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Agent ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // First check if agent has any orders
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE agent_id = ?");
    $stmt->execute([$_POST['agent_id']]);
    $orderCount = $stmt->fetchColumn();
    
    if ($orderCount > 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete agent with existing orders. Please deactivate instead.'
        ]);
        exit;
    }
    
    // Delete agent if no orders exist
    $stmt = $conn->prepare("DELETE FROM agents WHERE id = ?");
    $stmt->execute([$_POST['agent_id']]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Agent deleted successfully']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
