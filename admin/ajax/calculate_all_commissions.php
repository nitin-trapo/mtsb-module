<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Set error logging
ini_set('display_errors', 0);
error_reporting(E_ALL);
$logFile = __DIR__ . '/../../logs/commission_calculation.log';

function logError($message, $context = []) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' Context: ' . json_encode($context) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
    error_log($logMessage, 3, $logFile);
}

// Set JSON header
header('Content-Type: application/json');

try {
    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        throw new Exception('Unauthorized access');
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if this is a refresh request
    $is_refresh = isset($_POST['refresh']) && $_POST['refresh'] === 'true';

    // Get orders based on whether this is a refresh or new calculation
    $query = "
        SELECT o.*, c.first_name, c.last_name, c.email
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN commissions cm ON o.id = cm.order_id
        WHERE " . ($is_refresh ? "1=1" : "cm.id IS NULL") . "
        AND o.customer_id IS NOT NULL
        ORDER BY o.created_at DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'message' => '',
        'processed_orders' => [],
        'total_commission' => 0,
        'skipped_orders' => 0,
        'errors' => []
    ];

    if (empty($orders)) {
        $response['message'] = $is_refresh ? 'No orders found to refresh' : 'No new orders found for commission calculation';
        echo json_encode($response);
        exit;
    }

    foreach ($orders as $order) {
        try {
            $line_items = json_decode($order['line_items'], true);
            $total_commission = 0;
            $processed_items = [];

            if (!empty($line_items) && is_array($line_items)) {
                foreach ($line_items as $item) {
                    // Get product type from the item name
                    $title_parts = explode(' - ', $item['name']);
                    $product_type = !empty($title_parts[0]) ? trim($title_parts[0]) : '';
                    
                    // Map product types
                    $product_type_map = [
                        'BYD' => 'TRAPO CLASSIC',
                        'Trapo Tint' => 'OFFLINE SERVICE',
                        'Trapo Coating' => 'OFFLINE SERVICE'
                    ];
                    
                    foreach ($product_type_map as $key => $mapped_type) {
                        if (stripos($product_type, $key) !== false) {
                            $product_type = $mapped_type;
                            break;
                        }
                    }

                    // Get commission rate from product type rules
                    $stmt = $conn->prepare("
                        SELECT * FROM commission_rules 
                        WHERE status = 'active' 
                        AND rule_type = 'product_type' 
                        AND LOWER(rule_value) = LOWER(?)
                        LIMIT 1
                    ");
                    $stmt->execute([$product_type]);
                    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

                    // If no product type rule found, get default rule
                    if (!$rule) {
                        $stmt = $conn->prepare("
                            SELECT * FROM commission_rules 
                            WHERE status = 'active' 
                            AND rule_type = 'default'
                            LIMIT 1
                        ");
                        $stmt->execute();
                        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($rule) {
                        $rate = floatval($rule['commission_percentage']);
                        
                        // Calculate commission
                        $item_price = isset($item['price_set']['shop_money']['amount']) 
                            ? floatval($item['price_set']['shop_money']['amount'])
                            : floatval($item['price']);
                            
                        $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                        $item_total = $item_price * $item_quantity;
                        
                        // Apply any discounts
                        if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                            $item_total -= floatval($item['total_discount']);
                        }
                        
                        $commission = $item_total * ($rate / 100);
                        $total_commission += $commission;

                        // Log the commission calculation
                        logError("Commission calculated", [
                            'order_id' => $order['id'],
                            'product' => $item['name'],
                            'product_type' => $product_type,
                            'rule_type' => $rule['rule_type'],
                            'rate' => $rate,
                            'item_total' => $item_total,
                            'commission' => $commission
                        ]);
                    }
                }
            }

            if ($total_commission > 0) {
                // Save commission
                $stmt = $conn->prepare("
                    INSERT INTO commissions (order_id, agent_id, amount, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'pending', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE amount = ?, updated_at = NOW()
                ");
                
                $stmt->execute([
                    $order['id'],
                    $order['customer_id'],
                    $total_commission,
                    $total_commission
                ]);

                $response['processed_orders'][] = [
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'customer_name' => trim($order['first_name'] . ' ' . $order['last_name']),
                    'commission_amount' => $total_commission
                ];
                $response['total_commission'] += $total_commission;
            }

        } catch (Exception $e) {
            $response['errors'][] = [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ];
            logError("Error processing order", [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    $total_orders = count($orders);
    $processed_count = count($response['processed_orders']);
    $skipped_count = count($response['errors']);
    $no_commission_count = $total_orders - $processed_count - $skipped_count;

    $response['message'] = sprintf(
        'Successfully processed %d out of %d orders. %d orders had no eligible commission items. %d orders had errors. Total commission: RM %.2f',
        $processed_count,
        $total_orders,
        $no_commission_count,
        $skipped_count,
        $response['total_commission']
    );

    echo json_encode($response);

} catch (Exception $e) {
    logError("Error in bulk commission calculation", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
