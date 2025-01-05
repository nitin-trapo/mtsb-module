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
    echo json_encode(['success' => false, 'error' => 'Commission ID is required']);
    exit;
}

try {
    $commission_id = intval($_POST['commission_id']);
    $db = new Database();
    $conn = $db->getConnection();

    // Delete the commission
    $stmt = $conn->prepare("DELETE FROM commissions WHERE id = ?");
    $result = $stmt->execute([$commission_id]);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete commission']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
