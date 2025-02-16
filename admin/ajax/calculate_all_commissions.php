<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php-error.log');
// Increase execution time and memory limits
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '256M');
set_time_limit(300);

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

    // Get all orders that need commission calculation
    $is_refresh = isset($_GET['refresh']) && $_GET['refresh'] == 1;
    
    // Add pagination/batch processing
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $batch_size = 50; // Process 50 orders at a time
    $offset = ($page - 1) * $batch_size;
    
    $query = "
        SELECT o.*, c.id as agent_id 
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN commissions cm ON o.id = cm.order_id
        WHERE o.customer_id IS NOT NULL
        " . (!$is_refresh ? "AND cm.id IS NULL" : "") . "
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bindValue(1, $batch_size, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to fetch orders");
    }

    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*) as total 
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN commissions cm ON o.id = cm.order_id
        WHERE o.customer_id IS NOT NULL
        " . (!$is_refresh ? "AND cm.id IS NULL" : "");
    
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_count / $batch_size);
    
    if (empty($orders)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'No ' . ($is_refresh ? 'orders found to refresh' : 'new orders found for commission calculation'),
            'total_count' => $total_count,
            'current_page' => $page,
            'total_pages' => $total_pages
        ]);
        exit;
    }
    
    $errors = [];
    $processed_orders = 0;
    $total_commissions = 0;

    // If refreshing, first delete existing commissions for this batch
    if ($is_refresh) {
        $order_ids = array_map(function($order) { return $order['id']; }, $orders);
        $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';
        $delete_stmt = $conn->prepare("DELETE FROM commissions WHERE order_id IN ($placeholders)");
        if (!$delete_stmt->execute($order_ids)) {
            throw new Exception("Failed to delete existing commissions");
        }
        
        logError("Existing commissions deleted for batch", [
            'page' => $page,
            'orders_count' => count($orders)
        ]);
    }

    // Create a cache for product types to reduce API calls
    $product_type_cache = [];

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

            $order_total = 0;
            $commission_amount = 0;

            if (!empty($line_items) && is_array($line_items)) {
                foreach ($line_items as $item) {
                    // Initialize product type with default value
                    $product_type = 'TRAPO CLASSIC';

                    // Check cache first before making API call
                    if (isset($item['product_id'])) {
                        if (isset($product_type_cache[$item['product_id']])) {
                            $product_type = $product_type_cache[$item['product_id']];
                        } else {
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
                                curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds timeout for each API call
                                
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
                                        // Cache the product type
                                        $product_type_cache[$item['product_id']] = $product_type;
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
                        $commission_amount += $commission;
                        $order_total += $item_total;

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

            // Process discount codes if present
            $final_commission_amount = $commission_amount;
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
                    $final_commission_amount = $commission_amount - $total_discount;
                }
            }

            // Ensure commission amount is not negative
            $final_commission_amount = max(0, $final_commission_amount);

            // Update commission in database
            try {
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
                        :order_id,
                        :agent_id,
                        :actual_commission,
                        :total_discount,
                        :amount,
                        'pending',
                        NOW(),
                        NOW()
                    )
                ");
                
                if (!$stmt->execute([
                    ':order_id' => $order['id'],
                    ':agent_id' => $order['agent_id'],
                    ':amount' => $commission_amount,
                    ':total_discount' => $total_discount,
                    ':actual_commission' => $final_commission_amount
                ])) {
                    throw new Exception("Failed to update commission");
                }
                
                logError("Commission calculated successfully", [
                    'order_id' => $order['id'],
                    'base_commission' => $commission_amount,
                    'total_discount' => $total_discount,
                    'actual_commission' => $final_commission_amount,
                    'discount_codes' => $order['discount_codes']
                ]);
                
                $processed_orders++;
                $total_commissions += $final_commission_amount;
                
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
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Successfully processed $processed_orders orders with total commissions of $total_commissions",
        'processed_orders' => $processed_orders,
        'total_commissions' => $total_commissions,
        'errors' => $errors,
        'total_count' => $total_count,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
} catch (Exception $e) {
    logError("Fatal error in commission calculation", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => "Error: " . $e->getMessage(),
        'current_page' => $page ?? 1,
        'total_pages' => $total_pages ?? 0,
        'total_count' => $total_count ?? 0
    ]);
    exit;
}

// Add progress tracking in session
$_SESSION['commission_calculation'] = [
    'last_processed_page' => $page,
    'total_pages' => $total_pages,
    'processed_orders' => $processed_orders,
    'total_commissions' => $total_commissions,
    'timestamp' => time()
];
?>
