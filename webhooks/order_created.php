<?php
require_once '../config/database.php';
require_once '../config/shopify_config.php';
require_once '../classes/ShopifyAPI.php';
require_once '../includes/functions.php';

function log_step($step, $data = '') {
    $log = date('Y-m-d H:i:s') . " | $step";
    if ($data) {
        $log .= " | " . json_encode($data);
    }
    $log .= "\n";
    file_put_contents(__DIR__ . '/../logs/webhook.log', $log, FILE_APPEND);
}

// Webhook verification function
function verify_webhook($data, $hmac_header) {
    log_step('Verify Webhook Function', ['hmac' => $hmac_header]);
    
    // Check if constant is defined
    if (!defined('SHOPIFY_WEBHOOK_SECRET')) {
        log_step('Secret Error', ['error' => 'SHOPIFY_WEBHOOK_SECRET constant not defined']);
        return false;
    }
    
    $shared_secret = SHOPIFY_WEBHOOK_SECRET;
    log_step('Secret Check', [
        'secret_exists' => !empty($shared_secret),
        'secret_length' => strlen($shared_secret)
    ]);
    
    if (empty($shared_secret)) {
        log_step('Missing Secret', ['error' => 'Webhook secret not configured']);
        return false;
    }
    
    try {
        $calculated_hmac = base64_encode(hash_hmac('sha256', $data, $shared_secret, true));
        log_step('HMAC Calculation', [
            'calculated_hmac_length' => strlen($calculated_hmac),
            'received_hmac_length' => strlen($hmac_header)
        ]);
        
        $result = hash_equals($hmac_header, $calculated_hmac);
        log_step('HMAC Result', [
            'match' => $result,
            'calculated_hmac' => $calculated_hmac,
            'received_hmac' => $hmac_header
        ]);
        
        return $result;
    } catch (Exception $e) {
        log_step('HMAC Error', ['error' => $e->getMessage()]);
        return false;
    }
}

try {
    log_step('Webhook Start');
    
    // Log request details
    log_step('Request Details', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'headers' => [
            'hmac' => isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']) ? 'present' : 'missing',
            'topic' => $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? 'missing',
            'shop' => $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? 'missing'
        ]
    ]);

    // Check if this is a webhook request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'])) {
        log_step('Invalid Request', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'hmac_present' => isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'])
        ]);
        http_response_code(401);
        exit('Invalid request method or missing Shopify HMAC header');
    }

    // Get the webhook payload
    $data = file_get_contents('php://input');
    log_step('Raw Data Received', ['length' => strlen($data)]);
    
    $webhook = json_decode($data, true);
    log_step('JSON Decode Result', [
        'success' => json_last_error() === JSON_ERROR_NONE,
        'error_code' => json_last_error(),
        'error_message' => json_last_error_msg()
    ]);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_step('Invalid JSON', ['error' => json_last_error_msg()]);
        throw new Exception("Invalid JSON data received: " . json_last_error_msg());
    }

    // Verify webhook
    $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    log_step('Verifying Webhook', [
        'hmac_length' => strlen($hmac),
        'data_sample' => substr($data, 0, 50) . '...' // Log first 50 chars of data
    ]);

    $verified = verify_webhook($data, $hmac);
    log_step('Verification Result', ['verified' => $verified]);

    if (!$verified) {
        log_step('Verification Failed', [
            'hmac' => $hmac,
            'data_length' => strlen($data)
        ]);
        http_response_code(401);
        exit('Webhook verification failed');
    }
    log_step('Webhook Verified');

    // Extract order details
    if (!isset($webhook['id'])) {
        log_step('Missing Order ID');
        throw new Exception("Missing order ID in webhook data");
    }
    
    $shopify_order_id = $webhook['id'];
    $order_number = $webhook['name'] ?? ('#' . ($webhook['order_number'] ?? $shopify_order_id));
    log_step('Order Details', ['id' => $shopify_order_id, 'number' => $order_number]);
    
    // Track order status but don't skip
    $is_cancelled = !empty($webhook['cancelled_at']);
    $cancel_reason = $webhook['cancel_reason'] ?? null;
    $financial_status = $webhook['financial_status'] ?? 'unknown';
    
    if ($is_cancelled || $financial_status === 'voided') {
        log_step('Processing Cancelled/Voided Order', [
            'reason' => $cancel_reason,
            'status' => $financial_status
        ]);
    }

    $database = new Database();
    $conn = $database->getConnection();
    log_step('Database Connected');

    // Set proper transaction isolation
    $conn->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
    
    // Start transaction
    $conn->beginTransaction();
    log_step('Transaction Started');
    
    try {
        // Check if order already exists
        $stmt = $conn->prepare("SELECT id FROM orders WHERE shopify_order_id = ?");
        $stmt->execute([$webhook['id']]);
        
        if ($stmt->fetch()) {
            log_step('Order Already Exists');
            $conn->commit();
            http_response_code(200);
            exit('Order already processed');
        }
        
        log_step('Order Is New');
        
        // First, sync customer if present
        $customer_id = null;
        if (isset($webhook['customer']) && !empty($webhook['customer']['id'])) {
            log_step('Syncing Customer', ['customer_id' => $webhook['customer']['id']]);
            // Check if customer exists
            $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
            $stmt->execute([$webhook['customer']['id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                $customer_id = $customer['id'];
            } else {
                // Sync customer from Shopify
                $shopify = new ShopifyAPI();
                $shopify_customer = $shopify->getCustomerById($webhook['customer']['id']);
                if ($shopify_customer) {
                    $customer_id = $shopify->syncCustomer($shopify_customer);
                }
            }
        }
        
        // Process the order
        log_step('Processing Order');
        
        // Prepare order data
        $order_data = [
            'shopify_order_id' => $webhook['id'],
            'order_number' => $webhook['name'] ?? ('#' . $webhook['order_number']),
            'email' => $webhook['email'] ?? '',
            'total_price' => $webhook['total_price'] ?? 0,
            'subtotal_price' => $webhook['subtotal_price'] ?? 0,
            'total_tax' => $webhook['total_tax'] ?? 0,
            'total_shipping' => isset($webhook['shipping_lines'][0]) ? $webhook['shipping_lines'][0]['price'] : 0,
            'financial_status' => $webhook['financial_status'] ?? '',
            'fulfillment_status' => $webhook['fulfillment_status'] ?? null,
            'currency' => $webhook['currency'] ?? 'MYR',
            'cancelled_at' => $webhook['cancelled_at'] ?? null,
            'cancel_reason' => $webhook['cancel_reason'] ?? null,
            'processed_at' => $webhook['processed_at'] ?? date('Y-m-d H:i:s'),
            'created_at' => $webhook['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $webhook['updated_at'] ?? date('Y-m-d H:i:s'),
            'line_items' => json_encode($webhook['line_items'] ?? []),
            'shipping_lines' => json_encode($webhook['shipping_lines'] ?? []),
            'tax_lines' => json_encode($webhook['tax_lines'] ?? []),
            'shipping_address' => json_encode($webhook['shipping_address'] ?? null),
            'billing_address' => json_encode($webhook['billing_address'] ?? null),
            'discount_codes' => json_encode($webhook['discount_codes'] ?? []),
            'discount_applications' => json_encode($webhook['discount_applications'] ?? [])
        ];

        log_step('Order Data Prepared', [
            'id' => $order_data['shopify_order_id'],
            'number' => $order_data['order_number'],
            'total' => $order_data['total_price'],
            'status' => $order_data['financial_status'],
            'timestamps' => [
                'processed' => $order_data['processed_at'],
                'created' => $order_data['created_at'],
                'updated' => $order_data['updated_at']
            ]
        ]);
        
        // Add customer ID if we have it
        if ($customer_id) {
            $order_data['customer_id'] = $customer_id;
        }
        
        // Build SQL query
        $columns = implode(', ', array_keys($order_data));
        $placeholders = ':' . implode(', :', array_keys($order_data));
        $sql = "INSERT INTO orders ($columns) VALUES ($placeholders)";
        
        log_step('SQL Query', [
            'query' => $sql,
            'data' => array_map(function($value) {
                return is_string($value) ? substr($value, 0, 50) . '...' : $value;
            }, $order_data)
        ]);

        try {
            // Insert order
            $stmt = $conn->prepare($sql);
            foreach ($order_data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            $stmt->execute();
            $order_id = $conn->lastInsertId();
            log_step('Order Inserted', ['order_id' => $order_id]);

            // Get order metafields
            try {
                $metafields_endpoint = "orders/{$shopify_order_id}/metafields.json";
                $metafields_response = $shopify->makeApiCall($metafields_endpoint);
                
                if ($metafields_response && isset($metafields_response['metafields'])) {
                    $metafields = $metafields_response['metafields'];
                    $metafields_json = !empty($metafields) ? json_encode($metafields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                }
            } catch (Exception $e) {
                $metafields_json = null;
            }

            // Calculate commission if customer is an agent
            if ($customer_id && $customer['is_agent'] == 1) {
                // Process line items and calculate commission
                $line_items = json_decode($order_data['line_items'], true);
                $total_commission = 0;
                $processed_items = [];
                $commission_details = [];
                
                if (!empty($line_items) && is_array($line_items)) {
                    foreach ($line_items as $item) {
                        // Get commission rate based on product type
                        $product_type = '';
                        try {
                            if (isset($item['product_id'])) {
                                $product_details = $shopify->getProductById($item['product_id']);
                                if ($product_details && !empty($product_details['product_type'])) {
                                    $product_type = $product_details['product_type'];
                                }
                            }
                        } catch (Exception $e) {
                            $product_type = '';
                        }
                        
                        // Get commission rate from rules
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
                            $rate = floatval($rule['commission_percentage']);
                            
                            // Calculate commission for this item
                            $item_price = isset($item['price_set']['shop_money']['amount']) 
                                ? floatval($item['price_set']['shop_money']['amount'])
                                : floatval($item['price']);
                                
                            $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                            $item_total = $item_price * $item_quantity;
                            
                            $commission = $item_total * ($rate / 100);
                            $total_commission += $commission;
                            
                            $commission_details[] = [
                                'title' => $item['title'],
                                'product_id' => $item['product_id'],
                                'product_type' => $product_type,
                                'price' => $item_price,
                                'quantity' => $item_quantity,
                                'total' => $item_total,
                                'rate' => $rate,
                                'commission' => $commission,
                                'rule_id' => $rule['id']
                            ];
                        }
                    }
                }
                
                // Process discount codes if present
                $discount_codes = json_decode($order_data['discount_codes'], true);
                $total_discount = 0;
                $discount_details = [];
                
                if (is_array($discount_codes)) {
                    foreach ($discount_codes as $discount) {
                        if (isset($discount['amount'])) {
                            $discount_amount = floatval($discount['amount']);
                            $total_discount += $discount_amount;
                            
                            $discount_details[] = [
                                'code' => $discount['code'] ?? '',
                                'amount' => $discount_amount,
                                'type' => $discount['type'] ?? ''
                            ];
                        }
                    }
                }
                
                // Calculate final commission after discounts
                $final_commission_amount = max(0, $total_commission - $total_discount);
                
                try {
                    // Save commission with details
                    $stmt = $conn->prepare("
                        INSERT INTO commissions (
                            order_id, agent_id, amount, total_discount,
                            actual_commission, commission_details, discount_details,
                            status, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        $order_id,
                        $customer_id,
                        $final_commission_amount,
                        $total_discount,
                        $total_commission,
                        json_encode($commission_details),
                        json_encode($discount_details)
                    ]);
                    
                    $commission_id = $conn->lastInsertId();
                } catch (PDOException $e) {
                    throw $e;
                }
            }
            
            log_step('Attempting Transaction Commit', ['order_id' => $order_id]);
            
            try {
                // Commit transaction
                $result = $conn->commit();
                log_step('Transaction Commit Result', [
                    'order_id' => $order_id,
                    'success' => $result,
                    'inTransaction' => $conn->inTransaction(),
                    'errorInfo' => $conn->errorInfo()
                ]);
                
                if ($result === false) {
                    throw new Exception("Failed to commit transaction");
                }
                
                log_step('Transaction Committed Successfully', [
                    'order_id' => $order_id,
                    'inTransaction' => $conn->inTransaction()
                ]);

                // Reset connection settings
                $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
                
                // Verify order exists
                $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC);
                
                log_step('Order Verification', [
                    'order_id' => $order_id,
                    'exists' => !empty($exists),
                    'row' => $exists
                ]);
                
                // Close connection
                $conn = null; // Close connection
                log_step('Database Connection Closed');

            } catch (Exception $e) {
                log_step('Transaction Commit Error', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Send invoice via AJAX
            try {
                // Use local file path for reliability
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "http://" . $_SERVER['HTTP_HOST'] . "/shopify-agent-module/admin/ajax/send_invoice.php",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'order_id' => $order_id,
                        'webhook_request' => true
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/x-www-form-urlencoded'
                    ]
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $body = substr($response, $headerSize);
                $error = curl_error($ch);
                $responseData = json_decode($body, true);
                curl_close($ch);

                log_step('Invoice Request Sent', [
                    'order_id' => $order_id,
                    'http_code' => $httpCode,
                    'response' => $responseData
                ]);

            } catch (Exception $e) {
                log_step('Invoice Error', [
                    'error' => $e->getMessage(),
                    'order_id' => $order_id
                ]);
            }

            http_response_code(200);
            log_step('Webhook Processing Complete', [
                'order_id' => $order_id,
                'status' => 'success'
            ]);
            exit('Webhook processed successfully');

        } catch (PDOException $e) {
            log_step('Database Error', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null
            ]);
            throw $e;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        log_step('Transaction Error', ['error' => $e->getMessage()]);
        throw $e;
    }
    
} catch (Exception $e) {
    log_step('Webhook Error', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Error processing webhook: ' . $e->getMessage());
}
