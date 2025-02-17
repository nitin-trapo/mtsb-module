<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/tables.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get POST data
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $business_registration_number = $_POST['business_registration_number'] ?? '';
    $tax_identification_number = $_POST['tax_identification_number'] ?? '';
    $ic_number = $_POST['ic_number'] ?? '';
    $bank_name = $_POST['bank_name'] ?? '';
    $bank_account_holder = $_POST['bank_account_holder'] ?? '';
    $bank_account_number = $_POST['bank_account_number'] ?? '';
    $bank_swift_code = $_POST['bank_swift_code'] ?? '';

    // Update agent details
    $stmt = $conn->prepare("
        UPDATE " . TABLE_CUSTOMERS . "
        SET 
            first_name = ?,
            last_name = ?,
            phone = ?,
            business_registration_number = ?,
            tax_identification_number = ?,
            ic_number = ?,
            bank_name = ?,
            bank_account_holder = ?,
            bank_account_number = ?,
            bank_swift_code = ?
        WHERE email = ? AND is_agent = 1
    ");

    $stmt->execute([
        $first_name,
        $last_name,
        $phone,
        $business_registration_number,
        $tax_identification_number,
        $ic_number,
        $bank_name,
        $bank_account_holder,
        $bank_account_number,
        $bank_swift_code,
        $_SESSION['user_email']
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);

} catch (Exception $e) {
    error_log("Error in save_profile.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to update profile']);
}
