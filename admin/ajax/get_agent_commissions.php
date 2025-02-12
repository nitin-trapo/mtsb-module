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

header('Content-Type: application/json');

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $email = isset($_GET['email']) ? $_GET['email'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    // Build the WHERE clause
    $where = ['c.is_agent = 1'];
    $params = [];
    
    if (!empty($email)) {
        $where[] = 'c.email = :email';
        $params['email'] = $email;
    }
    
    if (!empty($status)) {
        $where[] = 'cm.status = :status';
        $params['status'] = $status;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get commission counts by status
    $countsQuery = $conn->prepare("
        SELECT 
            cm.status,
            COUNT(*) as count
        FROM commissions cm
        JOIN customers c ON c.id = cm.agent_id
        $whereClause
        GROUP BY cm.status
    ");
    
    $countsQuery->execute($params);
    $counts = [];
    
    while ($row = $countsQuery->fetch()) {
        $counts[$row['status']] = (int)$row['count'];
    }

    // Get commission details
    $commissionsQuery = $conn->prepare("
        SELECT 
            cm.*,
            o.order_number,
            o.total_price as order_amount,
            c.first_name as agent_first_name,
            c.last_name as agent_last_name,
            c.email as agent_email,
            CONCAT(c.first_name, ' ', c.last_name) as agent_name,
            cm.adjustment_reason
        FROM commissions cm
        LEFT JOIN orders o ON cm.order_id = o.id
        LEFT JOIN customers c ON cm.agent_id = c.id
        $whereClause
        ORDER BY cm.created_at DESC
    ");
    
    $commissionsQuery->execute($params);
    $commissions = [];
    
    while ($row = $commissionsQuery->fetch(PDO::FETCH_ASSOC)) {
        $commissions[] = [
            'id' => $row['id'],
            'order_number' => $row['order_number'],
            'created_at' => date('M d, Y', strtotime($row['created_at'])),
            'order_amount' => number_format($row['order_amount'], 2),
            'amount' => $row['amount'],
            'actual_commission' => $row['actual_commission'],
            'commission_amount' => $row['amount'], // For backward compatibility
            'status' => $row['status'],
            'agent_email' => $row['agent_email'],
            'agent_name' => $row['agent_name'],
            'adjustment_reason' => $row['adjustment_reason'],
            'agent_first_name' => $row['agent_first_name'],
            'agent_last_name' => $row['agent_last_name']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $commissions,
        'counts' => $counts
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
