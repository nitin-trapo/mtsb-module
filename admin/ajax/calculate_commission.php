<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get order ID from request
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        throw new Exception('Order ID is required');
    }

    // Get order items
    $stmt = $conn->prepare("
        SELECT oi.*, o.order_number, o.created_at 
        FROM order_items oi 
        JOIN orders o ON o.id = oi.order_id 
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orderItems)) {
        throw new Exception('Order not found or has no items');
    }

    // Get all commission rules
    $stmt = $conn->query("
        SELECT * 
        FROM commission_rules 
        WHERE status = 'active'
        ORDER BY commission_percentage DESC
    ");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCommission = 0;
    $commissionDetails = [];

    // Process each order item
    foreach ($orderItems as $item) {
        $itemCommission = 0;
        $appliedRules = [];

        // Get product details (type and tags)
        $stmt = $conn->prepare("
            SELECT pt.type_name, GROUP_CONCAT(ptags.tag_name) as tags
            FROM products p
            LEFT JOIN product_types pt ON p.product_type_id = pt.id
            LEFT JOIN product_tag_relations ptr ON p.id = ptr.product_id
            LEFT JOIN product_tags ptags ON ptr.tag_id = ptags.id
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$item['product_id']]);
        $productDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($productDetails) {
            // Check rules for product type
            foreach ($rules as $rule) {
                if ($rule['rule_type'] === 'product_type' && $rule['rule_value'] === $productDetails['type_name']) {
                    $commission = ($item['price'] * $rule['commission_percentage']) / 100;
                    $itemCommission += $commission;
                    $appliedRules[] = [
                        'type' => 'product_type',
                        'value' => $productDetails['type_name'],
                        'percentage' => $rule['commission_percentage'],
                        'amount' => $commission
                    ];
                }
            }

            // Check rules for product tags
            if ($productDetails['tags']) {
                $tags = explode(',', $productDetails['tags']);
                foreach ($tags as $tag) {
                    foreach ($rules as $rule) {
                        if ($rule['rule_type'] === 'product_tag' && $rule['rule_value'] === trim($tag)) {
                            $commission = ($item['price'] * $rule['commission_percentage']) / 100;
                            $itemCommission += $commission;
                            $appliedRules[] = [
                                'type' => 'product_tag',
                                'value' => trim($tag),
                                'percentage' => $rule['commission_percentage'],
                                'amount' => $commission
                            ];
                        }
                    }
                }
            }
        }

        $totalCommission += $itemCommission;
        $commissionDetails[] = [
            'item_id' => $item['id'],
            'product_id' => $item['product_id'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'product_type' => $productDetails['type_name'] ?? null,
            'product_tags' => $productDetails['tags'] ?? null,
            'commission_amount' => $itemCommission,
            'applied_rules' => $appliedRules
        ];
    }

    // Save commission details to database
    $stmt = $conn->prepare("
        INSERT INTO order_commissions 
        (order_id, commission_amount, commission_details, created_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        commission_amount = VALUES(commission_amount),
        commission_details = VALUES(commission_details),
        updated_at = NOW()
    ");
    $stmt->execute([
        $order_id,
        $totalCommission,
        json_encode($commissionDetails)
    ]);

    // Return the results
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'order_number' => $orderItems[0]['order_number'],
        'total_commission' => $totalCommission,
        'details' => $commissionDetails
    ]);

} catch (Exception $e) {
    error_log('Error calculating commission: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'error' => 'Failed to calculate commission: ' . $e->getMessage()
    ]);
}
