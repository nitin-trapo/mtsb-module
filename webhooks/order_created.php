<?php
require_once '../config/database.php';
require_once '../classes/ShopifyAPI.php';
require_once '../includes/functions.php';

// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set up logging
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/webhook_orders.log';

function logMessage($message, $context = []) {
    global $logFile;
    try {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
        error_log($logMessage, 3, $logFile);
        
        // Also log to PHP error log for redundancy
        error_log($message . $contextStr);
    } catch (Exception $e) {
        error_log("Failed to write to log file: " . $e->getMessage());
    }
}

try {
    // Get the webhook payload
    $data = file_get_contents('php://input');
    logMessage("Received webhook data", ['data' => $data]);
    
    $webhook = json_decode($data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received: " . json_last_error_msg());
    }

    // Verify webhook
    $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    logMessage("Verifying webhook", ['hmac' => $hmac]);
    
    // Skip verification in development/testing
    $verified = true;
    // $verified = verify_webhook($data, $hmac);

    if (!$verified) {
        logMessage("Webhook verification failed");
        header('HTTP/1.1 401 Unauthorized');
        exit('Webhook verification failed');
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    // Enable PDO error mode
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $shopify = new ShopifyAPI();
    
    // Extract order details
    if (!isset($webhook['id'])) {
        throw new Exception("Missing order ID in webhook data");
    }
    
    $shopify_order_id = $webhook['id'];
    $order_number = $webhook['name'] ?? ('#' . ($webhook['order_number'] ?? $shopify_order_id));
    
    logMessage("Processing new order", [
        'order_number' => $order_number,
        'shopify_order_id' => $shopify_order_id
    ]);
    
    // Start transaction
    $conn->beginTransaction();
    logMessage("Started database transaction");
    
    try {
        // Check if order already exists
        $stmt = $conn->prepare("SELECT id FROM orders WHERE shopify_order_id = ?");
        $stmt->execute([$shopify_order_id]);
        if ($stmt->fetch()) {
            logMessage("Order already exists", ['shopify_order_id' => $shopify_order_id]);
            throw new Exception("Order already exists");
        }
        
        // First, sync customer if present
        $customer_id = null;
        if (isset($webhook['customer']) && !empty($webhook['customer']['id'])) {
            logMessage("Syncing customer", ['customer_id' => $webhook['customer']['id']]);
            
            // Check if customer exists
            $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
            $stmt->execute([$webhook['customer']['id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                $customer_id = $customer['id'];
                logMessage("Customer found", ['customer_id' => $customer_id]);
            } else {
                // Sync customer from Shopify
                $shopify_customer = $shopify->getCustomerById($webhook['customer']['id']);
                if ($shopify_customer) {
                    $customer_id = $shopify->syncCustomer($shopify_customer);
                    logMessage("Customer synced", ['customer_id' => $customer_id]);
                }
            }
        }
        
        // Prepare order data
        $order_data = [
            'shopify_order_id' => $shopify_order_id,
            'order_number' => $order_number,
            'email' => $webhook['email'] ?? '',
            'total_price' => $webhook['total_price'] ?? 0,
            'subtotal_price' => $webhook['subtotal_price'] ?? 0,
            'total_tax' => $webhook['total_tax'] ?? 0,
            'total_shipping' => isset($webhook['shipping_lines'][0]) ? $webhook['shipping_lines'][0]['price'] : 0,
            'currency' => $webhook['currency'] ?? 'MYR',
            'financial_status' => $webhook['financial_status'] ?? '',
            'fulfillment_status' => $webhook['fulfillment_status'] ?? null,
            'processed_at' => $webhook['processed_at'] ?? null,
            'created_at' => $webhook['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $webhook['updated_at'] ?? date('Y-m-d H:i:s'),
            'line_items' => json_encode($webhook['line_items'] ?? []),
            'shipping_address' => json_encode($webhook['shipping_address'] ?? null),
            'billing_address' => json_encode($webhook['billing_address'] ?? null),
            'discount_codes' => json_encode($webhook['discount_codes'] ?? []),
            'discount_applications' => json_encode($webhook['discount_applications'] ?? []),
            'note_attributes' => json_encode($webhook['note_attributes'] ?? [])
        ];
        
        // Add customer ID if we have it
        if ($customer_id) {
            $order_data['customer_id'] = $customer_id;
        }
        
        logMessage("Preparing to insert order", ['order_data' => $order_data]);
        
        // Build SQL query
        $columns = implode(', ', array_keys($order_data));
        $placeholders = ':' . implode(', :', array_keys($order_data));
        $sql = "INSERT INTO orders ({$columns}) VALUES ({$placeholders})";
        
        logMessage("Executing SQL query", ['sql' => $sql]);
        
        // Insert order
        $stmt = $conn->prepare($sql);
        foreach ($order_data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        
        $stmt->execute();
        $order_id = $conn->lastInsertId();
        
        logMessage("Order inserted successfully", [
            'order_id' => $order_id,
            'shopify_order_id' => $shopify_order_id
        ]);
        
        // Get order metafields
        try {
            $metafields_endpoint = "orders/{$shopify_order_id}/metafields.json";
            $metafields_response = $shopify->makeApiCall($metafields_endpoint);
            $metafields_json = null;
            
            if (!empty($metafields_response['metafields'])) {
                $metafields = [];
                foreach ($metafields_response['metafields'] as $metafield) {
                    if ($shopify->isJson($metafield['value'])) {
                        $metafields[$metafield['key']] = json_decode($metafield['value'], true);
                    } else {
                        $metafields[$metafield['key']] = $metafield['value'];
                    }
                }
                
                // Special handling for customer_email in JSON format
                if (isset($metafields['customer_email'])) {
                    if (is_string($metafields['customer_email']) && $shopify->isJson($metafields['customer_email'])) {
                        $customer_email_data = json_decode($metafields['customer_email'], true);
                        if (isset($customer_email_data['value'])) {
                            $metafields['customer_email'] = $customer_email_data['value'];
                        }
                    }
                }
                
                $metafields_json = !empty($metafields) ? json_encode($metafields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            }
            
            logMessage("Retrieved order metafields", ['metafields' => $metafields_json]);
        } catch (Exception $e) {
            logMessage("Error fetching metafields", [
                'error' => $e->getMessage(),
                'order_id' => $shopify_order_id
            ]);
            $metafields_json = null;
        }
        
        // Calculate commission if customer is an agent
        if ($customer_id && $customer['is_agent'] == 1) {
            logMessage("Calculating commission for agent", [
                'customer_id' => $customer_id,
                'order_total' => $order_data['total_price']
            ]);
            
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
                            logMessage("Retrieved product details", [
                                'product_id' => $item['product_id'],
                                'product_type' => $product_type
                            ]);
                        }
                    } catch (Exception $e) {
                        logMessage("Error fetching product details", [
                            'product_id' => $item['product_id'],
                            'error' => $e->getMessage()
                        ]);
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
                        
                        logMessage("Calculated commission for item", [
                            'item_title' => $item['title'],
                            'price' => $item_price,
                            'quantity' => $item_quantity,
                            'total' => $item_total,
                            'rate' => $rate,
                            'commission' => $commission
                        ]);
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
            
            logMessage("Commission calculation complete", [
                'base_commission' => $total_commission,
                'total_discount' => $total_discount,
                'final_commission' => $final_commission_amount,
                'commission_details' => $commission_details,
                'discount_details' => $discount_details
            ]);
            
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
                
                logMessage("Commission saved successfully", [
                    'commission_id' => $commission_id,
                    'order_id' => $order_id,
                    'commission_amount' => $final_commission_amount
                ]);
            } catch (PDOException $e) {
                logMessage("Error saving commission", [
                    'error' => $e->getMessage(),
                    'order_id' => $order_id,
                    'commission_details' => $commission_details
                ]);
                throw $e;
            }
        }
        
        // Commit transaction
        $conn->commit();
        logMessage("Database transaction committed");
        
        logMessage("Order processed successfully", [
            'order_id' => $order_id,
            'order_number' => $order_number
        ]);

        // Send invoice via AJAX
        try {
            // Use local file path for reliability
            $invoice_file = __DIR__ . '/../admin/ajax/send_invoice.php';
            if (!file_exists($invoice_file)) {
                throw new Exception("Invoice file not found at: " . $invoice_file);
            }

            // Prepare POST data
            $postData = [
                'order_id' => $order_id,
                'webhook_request' => true // Flag to indicate this is from webhook
            ];

            // Set up cURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "http://" . $_SERVER['HTTP_HOST'] . "/shopify-agent-module/admin/ajax/send_invoice.php",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Shopify Webhook',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'X-Webhook-Request: true'
                ]
            ]);

            logMessage("Sending invoice request", [
                'order_id' => $order_id,
                'url' => "http://" . $_SERVER['HTTP_HOST'] . "/shopify-agent-module/admin/ajax/send_invoice.php"
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);
            $error = curl_error($ch);
            
            $responseData = json_decode($body, true);
            curl_close($ch);

            if ($httpCode == 200 && $responseData && isset($responseData['success']) && $responseData['success']) {
                logMessage("Invoice sent successfully via AJAX", [
                    'order_id' => $order_id,
                    'response' => $responseData
                ]);
            } else {
                $errorMsg = "Failed to send invoice. ";
                $errorMsg .= $error ? "cURL Error: " . $error : "";
                $errorMsg .= $responseData ? " Response: " . json_encode($responseData) : "";
                $errorMsg .= " HTTP Code: " . $httpCode;
                
                throw new Exception($errorMsg);
            }
        } catch (Exception $e) {
            logMessage("Error sending invoice via AJAX", [
                'error' => $e->getMessage(),
                'order_id' => $order_id
            ]);
            
            // Try direct include as fallback
            try {
                logMessage("Attempting direct include fallback for invoice", ['order_id' => $order_id]);
                $_POST = ['order_id' => $order_id, 'webhook_request' => true];
                require __DIR__ . '/../admin/ajax/send_invoice.php';
            } catch (Exception $e2) {
                logMessage("Fallback invoice sending also failed", [
                    'error' => $e2->getMessage(),
                    'order_id' => $order_id
                ]);
            }
        }
        
        header('HTTP/1.1 200 OK');
        echo json_encode(['success' => true, 'message' => 'Order processed successfully']);
        
    } catch (PDOException $e) {
        logMessage("Database error", [
            'error' => $e->getMessage(),
            'sql' => $e->getSQLState()
        ]);
        $conn->rollBack();
        throw $e;
    } catch (Exception $e) {
        logMessage("Error processing order", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function verify_webhook($data, $hmac_header) {
    $calculated_hmac = base64_encode(hash_hmac('sha256', $data, SHOPIFY_WEBHOOK_SECRET, true));
    return hash_equals($hmac_header, $calculated_hmac);
}
