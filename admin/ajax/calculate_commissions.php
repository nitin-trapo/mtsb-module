<?php
// Prevent PHP errors from being displayed in output
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Enable error reporting for debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Set up error logging
$logFile = __DIR__ . '/../../logs/commission_calculation.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

function logError($message, $context = []) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' Context: ' . json_encode($context, JSON_PRETTY_PRINT) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
    error_log($logMessage, 3, $logFile);
}

// Set JSON header before any output
header('Content-Type: application/json');

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $response = [
            'success' => false,
            'message' => 'Fatal Error: ' . $error['message'],
            'error_details' => [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]
        ];
        echo json_encode($response);
        exit;
    }
});

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'processed_orders' => [],
    'total_commission' => 0,
    'logs' => [],
    'errors' => []  // New array to track all errors
];

function addLog($message, $context = [], $type = 'info') {
    global $response;
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT) : '';
    $logMessage = "[{$timestamp}] [{$type}] {$message}";
    if ($contextStr) {
        $logMessage .= "\nContext: {$contextStr}";
    }
    
    $response['logs'][] = $logMessage;
    
    // Also add to errors array if it's an error
    if ($type === 'error') {
        $response['errors'][] = [
            'timestamp' => $timestamp,
            'message' => $message,
            'context' => $context
        ];
    }
    
    logError($logMessage, $context);
}

function getCommissionRate($conn, $product_type, $product_tags) {
    try {
        // First check for product type rules
        if (!empty($product_type)) {
            $query = "SELECT * FROM commission_rules 
                     WHERE status = 'active' 
                     AND rule_type = 'product_type' 
                     AND LOWER(rule_value) = LOWER(?)
                     LIMIT 1";
            addLog("Checking product type commission rules", [
                'product_type' => $product_type,
                'query' => $query
            ]);
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$product_type]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rule) {
                addLog("Found product type commission rate", [
                    'rate' => $rule['commission_percentage'],
                    'product_type' => $product_type,
                    'rule' => $rule
                ]);
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Product Type',
                    'rule_value' => $product_type,
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        // Then check for tag-based commission rules
        if (!empty($product_tags)) {
            $tags_array = is_array($product_tags) ? $product_tags : explode(',', $product_tags);
            $tags_array = array_map('trim', $tags_array);
            
            $query = "SELECT * FROM commission_rules 
                     WHERE status = 'active' 
                     AND rule_type = 'product_tag' 
                     AND LOWER(rule_value) IN (" . implode(',', array_fill(0, count($tags_array), 'LOWER(?)')) . ")
                     ORDER BY commission_percentage DESC 
                     LIMIT 1";
            addLog("Checking tag-based commission rules", [
                'tags' => $tags_array,
                'query' => $query
            ]);
            
            $stmt = $conn->prepare($query);
            $stmt->execute($tags_array);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rule) {
                addLog("Found tag-based commission rate", [
                    'rate' => $rule['commission_percentage'],
                    'tags' => $tags_array,
                    'rule' => $rule
                ]);
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Product Tag',
                    'rule_value' => $rule['rule_value'],
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        // If no specific rule found, get default rate
        $query = "SELECT * FROM commission_rules 
                 WHERE status = 'active' 
                 AND rule_type = 'default' 
                 LIMIT 1";
        addLog("Checking default commission rate", ['query' => $query]);
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rule) {
            addLog("Using default commission rate", [
                'rate' => $rule['commission_percentage'],
                'rule' => $rule
            ]);
            return [
                'rate' => floatval($rule['commission_percentage']),
                'rule_type' => 'Default',
                'rule_value' => 'All Products',
                'rule_id' => $rule['id']
            ];
        }
        
        addLog("No commission rate found", [], 'error');
        return [
            'rate' => 0,
            'rule_type' => 'No Rule',
            'rule_value' => 'None',
            'rule_id' => null
        ];
        
    } catch (Exception $e) {
        addLog("Error getting commission rate", [
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'product_type' => $product_type,
            'tags' => $product_tags
        ], 'error');
        return [
            'rate' => 0,
            'rule_type' => 'Error',
            'rule_value' => $e->getMessage(),
            'rule_id' => null
        ];
    }
}

try {
    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        throw new Exception('Unauthorized access');
    }

    addLog("Starting commission calculation process");

    $db = new Database();
    $conn = $db->getConnection();
    $shopify = new ShopifyAPI();
    
    // Start transaction
    $conn->beginTransaction();
    addLog("Database transaction started");

    // Delete existing pending commissions
    $stmt = $conn->prepare("DELETE FROM commissions WHERE status = 'pending'");
    $stmt->execute();
    $deleted_count = $stmt->rowCount();
    addLog("Deleted existing pending commissions", ['count' => $deleted_count]);

    // Get all orders that don't have commissions yet
    $query = "
        SELECT o.*, c.first_name, c.last_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.id NOT IN (
            SELECT DISTINCT order_id FROM commissions WHERE status != 'pending'
        )
        AND o.customer_id IS NOT NULL
        AND o.line_items IS NOT NULL
    ";
    addLog("Fetching orders to process", ['query' => $query]);
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_orders = count($orders);
    addLog("Found orders to process", ['count' => $total_orders]);

    if ($total_orders === 0) {
        // Log the current orders and commissions state
        $stmt = $conn->query("SELECT COUNT(*) as total FROM orders");
        $totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $conn->query("SELECT COUNT(*) as total FROM commissions");
        $totalCommissions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $conn->query("SELECT COUNT(*) as total FROM orders WHERE customer_id IS NOT NULL AND line_items IS NOT NULL");
        $ordersWithCustomer = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $context = [
            'total_orders' => $totalOrders,
            'total_commissions' => $totalCommissions,
            'orders_with_customer' => $ordersWithCustomer
        ];
        
        addLog("No new orders found to process", $context, 'error');
        throw new Exception('No new orders found to process. Check logs for details.');
    }

    foreach ($orders as $order) {
        addLog("Processing order", [
            'order_number' => $order['order_number'],
            'customer_id' => $order['customer_id'],
            'customer_name' => $order['first_name'] . ' ' . $order['last_name']
        ]);
        
        // Decode line items from JSON
        $line_items = json_decode($order['line_items'], true);
        if (empty($line_items)) {
            addLog("No line items found in order", [
                'order_id' => $order['id'], 
                'order_number' => $order['order_number'],
                'line_items_raw' => $order['line_items']
            ], 'error');
            continue;
        }
        
        addLog("Processing line items", [
            'order_number' => $order['order_number'],
            'items_count' => count($line_items)
        ]);

        $total_commission = 0;
        $processed_items = [];
        
        foreach ($line_items as $item) {
            addLog("Processing line item", [
                'title' => $item['title'],
                'product_id' => $item['product_id'],
                'price' => $item['price'],
                'quantity' => $item['quantity']
            ]);
            
            // Get product details from Shopify API
            $product = $shopify->getProductById($item['product_id']);
            
            if ($product) {
                addLog("Product details retrieved", [
                    'product_id' => $item['product_id'],
                    'title' => $product['title'],
                    'type' => $product['product_type'],
                    'tags' => $product['tags'],
                    'status' => $product['status'],
                    'vendor' => $product['vendor']
                ]);
                
                // Get commission rate based on product type and tags
                $rate = getCommissionRate($conn, $product['product_type'], $product['tags']);
                
                if ($rate['rate'] > 0) {
                    $commission = ($item['price'] * $item['quantity']) * ($rate['rate'] / 100);
                    $total_commission += $commission;
                    
                    $processed_items[] = [
                        'title' => $item['title'],
                        'product_id' => $item['product_id'],
                        'price' => $item['price'],
                        'quantity' => $item['quantity'],
                        'rate' => $rate['rate'],
                        'rule_type' => $rate['rule_type'],
                        'rule_value' => $rate['rule_value'],
                        'rule_id' => $rate['rule_id'],
                        'commission' => $commission
                    ];
                    
                    addLog("Commission calculated for item", [
                        'title' => $item['title'],
                        'price' => $item['price'],
                        'quantity' => $item['quantity'],
                        'rate' => $rate['rate'],
                        'rule_type' => $rate['rule_type'],
                        'rule_value' => $rate['rule_value'],
                        'rule_id' => $rate['rule_id'],
                        'commission' => $commission
                    ]);
                } else {
                    addLog("No commission rate found for product", [
                        'product_id' => $item['product_id'],
                        'title' => $item['title'],
                        'type' => $product['product_type'],
                        'tags' => $product['tags']
                    ], 'error');
                }
            } else {
                addLog("Product not found in Shopify", [
                    'product_id' => $item['product_id'],
                    'title' => $item['title']
                ], 'error');
            }
        }

        // Insert commission record
        if ($total_commission > 0) {
            $query = "
                INSERT INTO commissions (
                    order_id, agent_id, amount, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, 'pending', NOW(), NOW()
                )
            ";
            addLog("Inserting commission record", [
                'order_number' => $order['order_number'],
                'customer_id' => $order['customer_id'],
                'amount' => $total_commission,
                'query' => $query
            ]);
            
            $stmt = $conn->prepare($query);
            $stmt->execute([
                $order['id'],
                $order['customer_id'],
                $total_commission
            ]);
            
            addLog("Commission record inserted", [
                'order_number' => $order['order_number'],
                'amount' => $total_commission,
                'items' => $processed_items
            ]);

            $response['processed_orders'][] = [
                'order_number' => $order['order_number'],
                'customer_name' => $order['first_name'] . ' ' . $order['last_name'],
                'commission_amount' => $total_commission,
                'items' => $processed_items
            ];
            $response['total_commission'] += $total_commission;
        } else {
            addLog("No commission calculated for order", [
                'order_id' => $order['id'],
                'order_number' => $order['order_number'],
                'items_processed' => count($line_items)
            ], 'error');
        }
    }

    // Commit transaction
    $conn->commit();
    addLog("Database transaction committed successfully");

    $response['success'] = true;
    $response['message'] = 'Commissions calculated successfully';

} catch (Throwable $e) {  // Changed from Exception to Throwable to catch all errors
    // Rollback transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
        addLog("Database transaction rolled back", [], 'error');
    }
    
    addLog("Error occurred", [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'error');
    
    $response['success'] = false;
    $response['message'] = 'An error occurred: ' . $e->getMessage();
} finally {
    // Ensure we always return valid JSON
    try {
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    } catch (JsonException $e) {
        // If JSON encoding fails, return a simple error response
        echo json_encode([
            'success' => false,
            'message' => 'Failed to encode response: ' . $e->getMessage()
        ]);
    }
}
