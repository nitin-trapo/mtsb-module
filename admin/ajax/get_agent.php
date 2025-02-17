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

if (!isset($_GET['agent_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Agent ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT * FROM customers WHERE id = ?
    ");
    
    $stmt->execute([$_GET['agent_id']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($agent) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'agent' => $agent]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Agent not found']);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
