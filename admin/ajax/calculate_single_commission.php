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
            // Comprehensive product type identification
            $product_type = '';
            $shopify_api = new ShopifyAPI();
            
            // Debug logging for line item details
            logError("Line Item Raw Details", [
                'item_name' => $product_tags['item_name'] ?? 'N/A',
                'product_id' => $product_tags['product_id'] ?? 'N/A',
                'full_item_details' => json_encode($product_tags['full_item_details'])
            ]);
            
            // Try to get product type from Shopify API
            if (isset($product_tags['product_id'])) {
                try {
                    $product_details = $shopify_api->getProductById($product_tags['product_id']);
                    
                    if ($product_details && !empty($product_details['product_type'])) {
                        $product_type = $product_details['product_type'];
                        
                        logError("Product Type from API", [
                            'product_id' => $product_tags['product_id'],
                            'identified_type' => $product_type
                        ]);
                    }
                } catch (Exception $e) {
                    logError("API Product Type Fetch Error", [
                        'product_id' => $product_tags['product_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Fallback to manual mapping if no API result
            if (empty($product_type)) {
                $product_type_map = [
                    'BYD' => 'TRAPO CLASSIC',
                    'Trapo Tint' => 'OFFLINE SERVICE',
                    'Trapo Coating' => 'OFFLINE SERVICE',
                    'Ceramic Coating' => 'OFFLINE SERVICE',
                    'Paint Protection Film' => 'OFFLINE SERVICE',
                    'Windscreen Replacement' => 'OFFLINE SERVICE',
                    'Detailing' => 'OFFLINE SERVICE'
                ];
                
                foreach ($product_type_map as $key => $mapped_type) {
                    if (stripos($product_tags['item_name'], $key) !== false) {
                        $product_type = $mapped_type;
                        break;
                    }
                }
            }
            
            // Final fallback
            if (empty($product_type)) {
                $product_type = 'OTHERS';
            }
            
            // Modify commission rule query to match the all_commissions approach
            $stmt = $conn->prepare("
                SELECT * FROM commission_rules 
                WHERE status = 'active' 
                AND (
                    (rule_type = 'product_type' AND LOWER(rule_value) = LOWER(?)) 
                    OR 
                    (rule_type = 'default')
                )
                ORDER BY rule_type = 'product_type' DESC
                LIMIT 1
            ");
            $stmt->execute([$product_type]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Detailed logging for rule matching
            logError("Commission Rule Matching", [
                'product_type' => $product_type,
                'matched_rule' => $rule ? json_encode($rule) : 'No rule found',
                'rule_type' => $rule['rule_type'] ?? 'N/A',
                'rule_value' => $rule['rule_value'] ?? 'N/A'
            ]);
            
            if ($rule) {
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => $rule['rule_type'],
                    'rule_value' => $rule['rule_value'],
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
    $order_total = 0;
    $processed_items = [];

    if (!empty($line_items) && is_array($line_items)) {
        foreach ($line_items as $item) {
            // Get commission rate
            $rate = getCommissionRate($conn, '', [
                'item_name' => $item['name'],
                'product_id' => $item['product_id'],
                'full_item_details' => $item
            ]);
            
            if ($rate['rate'] > 0) {
                // Calculate commission
                $item_price = isset($item['price_set']['shop_money']['amount']) 
                    ? floatval($item['price_set']['shop_money']['amount'])
                    : floatval($item['price']);
                    
                $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                $item_total = $item_price * $item_quantity;
                $order_total += $item_total;
                
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

    // Process discount codes if present
    $final_commission_amount = $total_commission;
    $total_discount = 0;
    if (!empty($order['discount_codes'])) {
        $discount_codes = json_decode($order['discount_codes'], true);
        if (is_array($discount_codes)) {
            foreach ($discount_codes as $discount) {
                if ($discount['type'] === 'percentage') {
                    // For percentage discounts, apply directly to commission amount
                    $discount_amount = floatval($discount['amount']);
                    $total_discount += $discount_amount;
                }
            }
            
            // Calculate final commission after all percentage discounts
            $final_commission_amount = $total_commission - $total_discount;
        }
    }

    // Ensure commission amount is not negative
    $final_commission_amount = max(0, $final_commission_amount);

    // Save commission to database
    $stmt = $conn->prepare("
        INSERT INTO commissions (
            order_id, 
            agent_id, 
            amount,
            total_discount,
            actual_commission,
            status,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE 
            amount = VALUES(amount),
            total_discount = VALUES(total_discount),
            actual_commission = VALUES(actual_commission),
            status = VALUES(status),
            updated_at = NOW()
    ");

    $stmt->execute([
        $order_id,
        $order['customer_id'],
        $total_commission,
        $total_discount,
        $final_commission_amount,
        $final_commission_amount == 0 ? 'paid' : 'pending'
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Commission calculated successfully',
        'base_commission' => $total_commission,
        'total_discount' => $total_discount,
        'final_commission' => $final_commission_amount,
        'currency' => $order['currency'],
        'processed_items' => $processed_items,
        'discount_codes' => $order['discount_codes'],
        'status' => $final_commission_amount == 0 ? 'paid' : 'pending'
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
