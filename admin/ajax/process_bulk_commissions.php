<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get POST data
$action = $_POST['action'] ?? '';
$commission_ids = isset($_POST['commission_ids']) ? json_decode($_POST['commission_ids']) : [];

// Validate input
if (empty($action) || empty($commission_ids) || !is_array($commission_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input parameters']);
    exit;
}

try {
    $conn->beginTransaction();

    if ($action === 'approve') {
        // Approve selected commissions
        $stmt = $conn->prepare("
            UPDATE commissions 
            SET status = 'approved'
            WHERE id IN (" . str_repeat('?,', count($commission_ids) - 1) . "?)
        ");
        
        $stmt->execute($commission_ids);
        $message = count($commission_ids) . ' commission(s) approved successfully';
    } 
    elseif ($action === 'mark_paid') {
        // Handle file upload
        if (!isset($_FILES['payment_receipt'])) {
            throw new Exception('Payment receipt is required');
        }

        $file = $_FILES['payment_receipt'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type. Only PDF and images are allowed.');
        }

        // Create upload directory if it doesn't exist
        $upload_dir = '../../assets/uploads/receipts/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'receipt_bulk_' . time() . '.' . $extension;
        $filepath = $upload_dir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to upload file');
        }

        // Get payment notes and user ID
        $payment_note = $_POST['payment_notes'] ?? '';
        $paid_by = $_SESSION['user_id'];

        // Check if any commission has amount 0
        $check_stmt = $conn->prepare("
            SELECT COUNT(*) as zero_amount 
            FROM commissions 
            WHERE id IN (" . str_repeat('?,', count($commission_ids) - 1) . "?) 
            AND amount <= 0
        ");
        $check_stmt->execute($commission_ids);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['zero_amount'] > 0) {
            throw new Exception('Cannot mark commissions as paid when amount is 0');
        }

        // Update commissions
        $stmt = $conn->prepare("
            UPDATE commissions 
            SET 
                status = 'paid',
                payment_note = ?,
                payment_receipt = ?,
                paid_at = NOW(),
                paid_by = ?
            WHERE id IN (" . str_repeat('?,', count($commission_ids) - 1) . "?)
        ");

        $params = [$payment_note, $filename, $paid_by];
        $params = array_merge($params, $commission_ids);

        $stmt->execute($params);
        
        $message = count($commission_ids) . ' commission(s) marked as paid successfully';
    } 
    else {
        throw new Exception('Invalid action');
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);
} 
catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
