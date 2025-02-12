<?php
require_once '../../config/database.php';
require_once '../includes/check_auth.php';

header('Content-Type: application/json');

try {
    $query = isset($_GET['query']) ? $_GET['query'] : '';
    
    if (empty($query)) {
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT email 
        FROM customers 
        WHERE is_agent = 1 
        AND email LIKE :query 
        ORDER BY email 
        LIMIT 10
    ");
    
    $stmt->execute(['query' => "%$query%"]);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($results);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to search agents']);
}
