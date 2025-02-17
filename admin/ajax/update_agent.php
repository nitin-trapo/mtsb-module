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
    
    // Update agent
    $stmt = $conn->prepare("
        UPDATE customers 
        SET first_name = ?,
            last_name = ?,
            phone = ?,
            is_agent = ?,
            status = ?,
            bank_name = ?,
            bank_account_number = ?,
            bank_account_header = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $_POST['first_name'],
        $_POST['last_name'],
        $_POST['phone'],
        isset($_POST['is_agent']) ? 1 : 0,
        $_POST['status'],
        $_POST['bank_name'],
        $_POST['bank_account_number'],
        $_POST['bank_account_header'],
        $_POST['agent_id']
    ]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Agent updated successfully']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
