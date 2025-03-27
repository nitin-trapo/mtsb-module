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
            $total_discount = 0;

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

                    // Get commission rule
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

                    if ($rule) {
                        // Calculate commission for this item
                        $item_price = isset($item['price_set']['shop_money']['amount']) 
                            ? number_format(floatval($item['price_set']['shop_money']['amount']), 2, '.', '')
                            : number_format(floatval($item['price']), 2, '.', '');
                            
                        $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                        $item_total = number_format($item_price * $item_quantity, 2, '.', '');
                        $order_total = number_format($order_total + floatval($item_total), 2, '.', '');
                        
                        $commission_rate = floatval($rule['commission_percentage']);
                        $item_commission = number_format($item_total * ($commission_rate / 100), 2, '.', '');
                        $commission_amount = number_format($commission_amount + floatval($item_commission), 2, '.', '');
                        
                        logError("Commission calculated for item", [
                            'order_id' => $order['id'],
                            'product_id' => $item['product_id'],
                            'rate' => $commission_rate,
                            'amount' => $item_commission
                        ]);
                    }
                }
            }

            // Process discount codes if present
            if (!empty($order['discount_codes'])) {
                $discount_codes = json_decode($order['discount_codes'], true);
                if (is_array($discount_codes)) {
                    foreach ($discount_codes as $discount) {
                        if ($discount['type'] === 'percentage') {
                            $discount_amount = number_format(floatval($discount['amount']), 2, '.', '');
                            $total_discount = number_format($total_discount + floatval($discount_amount), 2, '.', '');
                        }
                    }
                    
                    // Apply discounts to commission amount
                    $commission_amount = number_format($commission_amount - floatval($total_discount), 2, '.', '');
                }
            }

            // Ensure commission amount is not negative and round off very small amounts to zero
            if (floatval($commission_amount) <= 0.01) {
                $commission_amount = "0.00";
            } else {
                $commission_amount = number_format(max(0, floatval($commission_amount)), 2, '.', '');
            }

            // Insert or update commission record
            $stmt = $conn->prepare("
                INSERT INTO commissions (
                    order_id, 
                    agent_id, 
                    actual_commission,
                    total_discount,
                    amount,
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

            // Store original commission amount before discount in actual_commission
            $actual_commission = number_format($commission_amount + floatval($total_discount), 2, '.', '');
            if (!$stmt->execute([
                $order['id'],
                $order['agent_id'],
                $actual_commission, // actual_commission (before discount)
                $total_discount,
                $commission_amount, // amount (after discount)
                $commission_amount == "0.00" ? 'paid' : 'pending'
            ])) {
                throw new Exception("Failed to save commission for order {$order['id']}");
            }

            $processed_orders++;
            $total_commissions += floatval($commission_amount);

            logError("Commission saved", [
                'order_id' => $order['id'],
                'actual_commission' => $actual_commission,
                'total_discount' => $total_discount,
                'final_amount' => $commission_amount,
                'status' => $commission_amount == "0.00" ? 'paid' : 'pending'
            ]);

        } catch (Exception $e) {
            $errors[] = "Error processing order {$order['id']}: " . $e->getMessage();
            logError("Error processing order", [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);
            continue;
        }
    }

    // Return response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Processed $processed_orders orders. Total commissions: $total_commissions",
        'errors' => $errors,
        'total_count' => $total_count,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);

} catch (Exception $e) {
    logError("Fatal error in commission calculation", [
        'error' => $e->getMessage()
    ]);
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
