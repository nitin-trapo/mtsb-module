<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php-error.log');

session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Set error logging
$logFile = __DIR__ . '/../../logs/commission_calculation.log';

function logError($message, $context = []) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if (!empty($context)) {
        $logMessage .= " Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $logMessage .= "\n";
    error_log($logMessage, 3, $logFile);
}

try {
    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        throw new Exception('Unauthorized access');
    }

    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failed");
    }

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
    if (!$stmt->execute()) {
        throw new Exception("Failed to fetch orders");
    }

    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    logError("Fetched orders", ['count' => count($orders)]);

    if (empty($orders)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $is_refresh ? 'No orders found to refresh' : 'No new orders found for commission calculation']);
        exit;
    }

    $errors = [];
    $processed_orders = 0;
    $total_commissions = 0;

    foreach ($orders as $order) {
        try {
            logError("Processing order", ['order_id' => $order['id']]);
            
            $line_items = isset($order['line_items']) ? json_decode($order['line_items'], true) : [];
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid line items JSON: " . json_last_error_msg());
            }
            
            logError("Line items decoded", [
                'order_id' => $order['id'],
                'items_count' => count($line_items)
            ]);

            $total_commission = 0;
            $total_amount = 0;

            if (!empty($line_items) && is_array($line_items)) {
                foreach ($line_items as $item) {
                    // Initialize product type with default value
                    $product_type = 'TRAPO CLASSIC';

                    // Get product type from Shopify API
                    if (isset($item['product_id'])) {
                        try {
                            $shop_domain = SHOPIFY_SHOP_DOMAIN;
                            $access_token = SHOPIFY_ACCESS_TOKEN;
                            $api_version = SHOPIFY_API_VERSION;
                            $url = "https://{$shop_domain}/admin/api/{$api_version}/products/{$item['product_id']}.json";

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                "X-Shopify-Access-Token: {$access_token}",
                                "Content-Type: application/json"
                            ]);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            
                            $response = curl_exec($ch);
                            if ($response === false) {
                                throw new Exception("Curl error: " . curl_error($ch));
                            }
                            
                            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($http_code === 200) {
                                $product_data = json_decode($response, true);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    throw new Exception("Invalid API response JSON: " . json_last_error_msg());
                                }
                                
                                if (isset($product_data['product']['product_type']) && !empty($product_data['product']['product_type'])) {
                                    $product_type = strtoupper($product_data['product']['product_type']);
                                    logError("Got product type from Shopify API", [
                                        'product_id' => $item['product_id'],
                                        'type' => $product_type
                                    ]);
                                }
                            }
                        } catch (Exception $e) {
                            logError("Error fetching from Shopify API", [
                                'error' => $e->getMessage(),
                                'product_id' => $item['product_id']
                            ]);
                        }
                    }

                    // Get product tags
                    $product_tags = isset($item['tags']) ? explode(',', $item['tags']) : [];
                    
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
                    
                    if (!$stmt->execute([$product_type])) {
                        throw new Exception("Failed to fetch commission rule");
                    }
                    
                    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    logError("Commission Rule Matching", [
                        'product_type' => $product_type,
                        'matched_rule' => $rule ? json_encode($rule) : 'No rule found',
                        'rule_type' => $rule['rule_type'] ?? 'N/A',
                        'rule_value' => $rule['rule_value'] ?? 'N/A'
                    ]);

                    if ($rule) {
                        $rate = floatval($rule['commission_percentage']);
                        
                        // Get item price
                        $item_price = 0;
                        if (isset($item['price_set']['shop_money']['amount'])) {
                            $item_price = floatval($item['price_set']['shop_money']['amount']);
                        } elseif (isset($item['price'])) {
                            $item_price = floatval($item['price']);
                        }
                        
                        $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                        $item_total = $item_price * $item_quantity;
                        
                        // Handle discounts
                        $total_discount = 0;
                        if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                            $total_discount = floatval($item['total_discount']);
                        }
                        $item_total -= $total_discount;
                        
                        // Ensure non-negative total
                        $item_total = max(0, $item_total);
                        
                        $commission = $item_total * ($rate / 100);
                        $total_commission += $commission;
                        $total_amount += $item_total;

                        logError("Commission calculated", [
                            'order_id' => $order['id'],
                            'product' => $item['name'] ?? 'Unknown',
                            'product_type' => $product_type,
                            'price' => $item_price,
                            'quantity' => $item_quantity,
                            'discount' => $total_discount,
                            'final_total' => $item_total,
                            'commission_rate' => $rate,
                            'commission_amount' => $commission
                        ]);
                    }
                }
            }

            // Update commission in database
            try {
                $stmt = $conn->prepare("
                    INSERT INTO commissions 
                    (order_id, agent_id, amount, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'pending', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    amount = VALUES(amount),
                    status = VALUES(status),
                    updated_at = NOW()
                ");
                
                if (!$stmt->execute([$order['id'], $order['customer_id'], $total_commission])) {
                    throw new Exception("Failed to update commission");
                }
                
                logError("Commission Updated", [
                    'order_id' => $order['id'],
                    'total_commission' => $total_commission
                ]);
                
                $processed_orders++;
                $total_commissions += $total_commission;
                
            } catch (Exception $e) {
                logError("Database Update Error", [
                    'order_id' => $order['id'],
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

        } catch (Exception $e) {
            logError("Order Processing Error", [
                'order_id' => $order['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $errors[] = "Error processing order " . ($order['id'] ?? 'unknown') . ": " . $e->getMessage();
        }
    }

    // After processing all orders
    echo json_encode([
        'success' => true,
        'message' => "Successfully processed $processed_orders orders with total commissions of $total_commissions",
        'processed_orders' => $processed_orders,
        'total_commissions' => $total_commissions,
        'errors' => [] // Add any errors if needed
    ]);
    exit;
} catch (Exception $e) {
    logError("Failed to calculate commissions", [
        'error' => $e->getMessage()
    ]);
    
    echo json_encode([
        'success' => false,
        'message' => "Failed to calculate commissions: " . $e->getMessage(),
        'errors' => [$e->getMessage()]
    ]);
    exit;
}
