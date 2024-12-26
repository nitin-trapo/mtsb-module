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
                    // Comprehensive product type identification
                    $product_type = '';
                    $shopify_api = new ShopifyAPI();
                    
                    // Debug logging for line item details
                    logError("Line Item Raw Details", [
                        'item_name' => $item['name'] ?? 'N/A',
                        'product_id' => $item['product_id'] ?? 'N/A',
                        'full_item_details' => json_encode($item)
                    ]);
                    
                    // Try to get product type from Shopify API
                    if (isset($item['product_id'])) {
                        try {
                            $product_details = $shopify_api->getProductById($item['product_id']);
                            
                            if ($product_details && !empty($product_details['product_type'])) {
                                $product_type = $product_details['product_type'];
                                
                                logError("Product Type from API", [
                                    'product_id' => $item['product_id'],
                                    'identified_type' => $product_type
                                ]);
                            }
                        } catch (Exception $e) {
                            logError("API Product Type Fetch Error", [
                                'product_id' => $item['product_id'],
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
                            if (stripos($item['name'], $key) !== false) {
                                $product_type = $mapped_type;
                                break;
                            }
                        }
                    }
                    
                    // Final fallback
                    if (empty($product_type)) {
                        $product_type = 'OTHERS';
                    }
                    
                    // Log commission rule matching
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
                        $rate = floatval($rule['commission_percentage']);
                        
                        // More robust price extraction
                        $item_price = 0;
                        if (isset($item['price_set']['shop_money']['amount'])) {
                            $item_price = floatval($item['price_set']['shop_money']['amount']);
                        } elseif (isset($item['price'])) {
                            $item_price = floatval($item['price']);
                        }
                        
                        $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                        $item_total = $item_price * $item_quantity;
                        
                        // More comprehensive discount handling
                        $total_discount = 0;
                        if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                            $total_discount = floatval($item['total_discount']);
                        }
                        $item_total -= $total_discount;
                        
                        // Ensure non-negative total
                        $item_total = max(0, $item_total);
                        
                        $commission = $item_total * ($rate / 100);
                        $total_commission += $commission;

                        // Enhanced logging
                        logError("Commission Calculation Details", [
                            'order_id' => $order['id'],
                            'product' => $item['name'],
                            'product_type' => $product_type,
                            'rule_type' => $rule['rule_type'],
                            'commission_rate' => $rate,
                            'item_price' => $item_price,
                            'item_quantity' => $item_quantity,
                            'total_discount' => $total_discount,
                            'item_total_after_discount' => $item_total,
                            'calculated_commission' => $commission
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
