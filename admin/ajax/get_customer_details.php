<?php
session_start();
require_once '../../config/database.php';
require_once '../../classes/Database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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
    
    // Get customer details including bank information
    $stmt = $conn->prepare("
        SELECT c.*,
               COUNT(DISTINCT o.id) as total_orders,
               COALESCE(SUM(o.total_price), 0) as total_spent
        FROM customers c
        LEFT JOIN orders o ON c.id = o.customer_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    
    $stmt->execute([$_GET['customer_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        // Parse bank statement header if it exists
        $bankStatementInfo = null;
        if (!empty($customer['bank_account_header'])) {
            $bankStatementInfo = json_decode($customer['bank_account_header'], true);
        }
        
        // Get file extension and icon
        $fileInfo = [];
        if ($bankStatementInfo && isset($bankStatementInfo['url'])) {
            $extension = strtolower(pathinfo($bankStatementInfo['url'], PATHINFO_EXTENSION));
            $fileInfo = [
                'url' => $bankStatementInfo['url'],
                'extension' => $extension,
                'icon' => getFileIcon($extension)
            ];
        }
        
        $customer['bank_statement_info'] = $fileInfo;
        
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

function getFileIcon($extension) {
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            return 'fas fa-file-image';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        default:
            return 'fas fa-file';
    }
}
