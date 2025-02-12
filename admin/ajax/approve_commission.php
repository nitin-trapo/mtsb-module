<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['commission_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing commission ID']);
    exit;
}

try {
    $commission_id = intval($_POST['commission_id']);
    
    $db = new Database();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();

    // Update commission status
    $stmt = $conn->prepare("
        UPDATE commissions 
        SET status = 'approved'
        WHERE id = ? AND status = 'pending'
    ");
    
    $stmt->execute([$commission_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Commission not found or already approved');
    }

    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
