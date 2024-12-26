<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Enable error logging
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

    if (!isset($_POST['order_id'])) {
        throw new Exception('Order ID is required');
    }

    $order_id = intval($_POST['order_id']);
    $db = new Database();
    $conn = $db->getConnection();

    // Get order details
    $stmt = $conn->prepare("
        SELECT o.*, c.first_name, c.last_name, c.email
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found');
    }

    if (empty($order['customer_id'])) {
        throw new Exception('No customer found for this order');
    }

    // Function to get commission rate
    function getCommissionRate($conn, $product_type, $product_tags) {
        try {
            // First check for product type rules
            if (!empty($product_type)) {
                $query = "SELECT * FROM commission_rules 
                         WHERE status = 'active' 
                         AND rule_type = 'product_type' 
                         AND LOWER(rule_value) = LOWER(?)
                         LIMIT 1";
                
                $stmt = $conn->prepare($query);
                $stmt->execute([$product_type]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($rule) {
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Product Type',
                        'rule_value' => $product_type,
                        'rule_id' => $rule['id']
                    ];
                }
            }
            
            // Then check for tag-based rules
            if (!empty($product_tags)) {
                $tags_array = is_array($product_tags) ? $product_tags : explode(',', $product_tags);
                $tags_array = array_map('trim', $tags_array);
                
                $query = "SELECT * FROM commission_rules 
                         WHERE status = 'active' 
                         AND rule_type = 'product_tag' 
                         AND LOWER(rule_value) IN (" . implode(',', array_fill(0, count($tags_array), 'LOWER(?)')) . ")
                         ORDER BY commission_percentage DESC 
                         LIMIT 1";
                
                $stmt = $conn->prepare($query);
                $stmt->execute($tags_array);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($rule) {
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Product Tag',
                        'rule_value' => $rule['rule_value'],
                        'rule_id' => $rule['id']
                    ];
                }
            }
            
            // Get default rate
            $query = "SELECT * FROM commission_rules 
                     WHERE status = 'active' 
                     AND rule_type = 'default' 
                     LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rule) {
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Default',
                    'rule_value' => 'All Products',
                    'rule_id' => $rule['id']
                ];
            }
            
            return [
                'rate' => 0,
                'rule_type' => 'No Rule',
                'rule_value' => 'None',
                'rule_id' => null
            ];
            
        } catch (Exception $e) {
            logError("Error in getCommissionRate", [
                'error' => $e->getMessage(),
                'product_type' => $product_type,
                'product_tags' => $product_tags
            ]);
            return [
                'rate' => 0,
                'rule_type' => 'Error',
                'rule_value' => $e->getMessage(),
                'rule_id' => null
            ];
        }
    }

    // Process line items and calculate commission
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

            // Get commission rate
            $rate = getCommissionRate($conn, $product_type, []);
            
            if ($rate['rate'] > 0) {
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
                
                $commission = $item_total * ($rate['rate'] / 100);
                $total_commission += $commission;
                
                $processed_items[] = [
                    'title' => $item['title'],
                    'product_id' => $item['product_id'],
                    'price' => $item_price,
                    'quantity' => $item_quantity,
                    'total' => $item_total,
                    'rate' => $rate['rate'],
                    'rule_type' => $rate['rule_type'],
                    'rule_value' => $rate['rule_value'],
                    'rule_id' => $rate['rule_id'],
                    'commission' => $commission
                ];
            }
        }
    }

    // Save commission to database
    $stmt = $conn->prepare("
        INSERT INTO commissions (order_id, agent_id, amount, status, created_at, updated_at)
        VALUES (?, ?, ?, 'pending', NOW(), NOW())
        ON DUPLICATE KEY UPDATE amount = ?, updated_at = NOW()
    ");
    
    $stmt->execute([
        $order_id,
        $order['customer_id'], // Use customer_id as agent_id
        $total_commission,
        $total_commission
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Commission calculated successfully',
        'commission_amount' => $total_commission,
        'currency' => $order['currency'],
        'processed_items' => $processed_items
    ]);

} catch (Exception $e) {
    logError("Error calculating commission", [
        'error' => $e->getMessage(),
        'order_id' => $order_id ?? null
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
