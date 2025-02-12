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

if (!isset($_POST['commission_id']) || !isset($_POST['payment_note']) || !isset($_FILES['payment_receipt'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    $commission_id = intval($_POST['commission_id']);
    $payment_note = trim($_POST['payment_note']);
    $paid_by = $_SESSION['user_id'];
    
    // Handle file upload
    $file = $_FILES['payment_receipt'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Only PDF and images are allowed.']);
        exit;
    }
    
    // Create upload directory if it doesn't exist
    $upload_dir = '../../assets/uploads/receipts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'receipt_' . $commission_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    $db = new Database();
    $conn = $db->getConnection();

    // Check if commission amount is 0
    $check_stmt = $conn->prepare("SELECT amount FROM commissions WHERE id = ?");
    $check_stmt->execute([$commission_id]);
    $commission = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($commission['amount'] <= 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot mark commission as paid when amount is 0']);
        exit;
    }

    // Start transaction
    $conn->beginTransaction();

    // Update commission status and payment details
    $stmt = $conn->prepare("
        UPDATE commissions 
        SET 
            status = 'paid',
            payment_note = ?,
            payment_receipt = ?,
            paid_at = NOW(),
            paid_by = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $payment_note,
        $filename,
        $paid_by,
        $commission_id
    ]);

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Commission marked as paid successfully'
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
