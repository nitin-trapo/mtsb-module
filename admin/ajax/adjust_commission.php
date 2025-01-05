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

if (!isset($_POST['commission_id']) || !isset($_POST['amount']) || !isset($_POST['adjustment_reason'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    $commission_id = intval($_POST['commission_id']);
    $amount = floatval($_POST['amount']);
    $adjustment_reason = trim($_POST['adjustment_reason']);
    $adjusted_by = $_SESSION['user_id']; // Using user_id since admins are stored in users table
    
    $db = new Database();
    $conn = $db->getConnection();

    // Start transaction
    $conn->beginTransaction();

    // Update commission amount
    $stmt = $conn->prepare("
        UPDATE commissions 
        SET 
            amount = ?,
            adjustment_reason = ?,
            adjusted_at = NOW(),
            adjusted_by = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $amount,
        $adjustment_reason,
        $adjusted_by,
        $commission_id
    ]);

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Commission adjusted successfully',
        'new_amount' => $amount
    ]);

} catch (Exception $e) {
    if ($conn) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
