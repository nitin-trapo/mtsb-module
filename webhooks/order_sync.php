<?php
require_once '../config/database.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';
require_once '../classes/ShopifyAPI.php';

// Set error logging
ini_set('display_errors', 0);
error_reporting(E_ALL);
$logFile = __DIR__ . '/../logs/webhook.log';

function logWebhook($message, $data = []) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}" . (!empty($data) ? " Data: " . json_encode($data) : "") . "\n";
    error_log($logMessage, 3, $logFile);
}

try {
    // Verify Shopify webhook
    $hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    $data = file_get_contents('php://input');
    $verified = verify_webhook($data, $hmac_header);

    if (!$verified) {
        http_response_code(401);
        logWebhook("Webhook verification failed");
        exit('Webhook verification failed');
    }

    // Parse order data
    $order = json_decode($data, true);
    if (empty($order) || !isset($order['id'])) {
        throw new Exception("Invalid order data received");
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Check if order exists
        $stmt = $conn->prepare("SELECT id FROM orders WHERE shopify_order_id = ?");
        $stmt->execute([$order['id']]);
        $existing_order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get or create customer
        $customer_id = null;
        if (!empty($order['customer'])) {
            $stmt = $conn->prepare("
                INSERT INTO customers (
                    shopify_customer_id, email, first_name, last_name, phone,
                    accepts_marketing, total_spent, orders_count, tags,
                    addresses, default_address, tax_exempt, verified_email,
                    status, last_sync_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW()
                )
                ON DUPLICATE KEY UPDATE
                    email = VALUES(email),
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    phone = VALUES(phone),
                    accepts_marketing = VALUES(accepts_marketing),
                    total_spent = VALUES(total_spent),
                    orders_count = VALUES(orders_count),
                    tags = VALUES(tags),
                    addresses = VALUES(addresses),
                    default_address = VALUES(default_address),
                    tax_exempt = VALUES(tax_exempt),
                    verified_email = VALUES(verified_email),
                    last_sync_at = NOW()
            ");

            $customer = $order['customer'];
            $stmt->execute([
                $customer['id'],
                $customer['email'] ?? null,
                $customer['first_name'] ?? null,
                $customer['last_name'] ?? null,
                $customer['phone'] ?? null,
                $customer['accepts_marketing'] ?? false,
                $customer['total_spent'] ?? 0,
                $customer['orders_count'] ?? 0,
                json_encode($customer['tags'] ?? []),
                json_encode($customer['addresses'] ?? []),
                json_encode($customer['default_address'] ?? null),
                $customer['tax_exempt'] ?? false,
                $customer['verified_email'] ?? false
            ]);

            if (!$stmt->rowCount()) {
                $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
                $stmt->execute([$customer['id']]);
                $customer_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $customer_id = $customer_row['id'];
            } else {
                $customer_id = $conn->lastInsertId();
            }
        }

        // Prepare order data
        $order_data = [
            'shopify_order_id' => $order['id'],
            'customer_id' => $customer_id,
            'order_number' => $order['order_number'] ?? null,
            'email' => $order['email'] ?? null,
            'phone' => $order['phone'] ?? null,
            'total_price' => $order['total_price'] ?? 0,
            'subtotal_price' => $order['subtotal_price'] ?? 0,
            'total_tax' => $order['total_tax'] ?? 0,
            'total_discounts' => $order['total_discounts'] ?? 0,
            'total_line_items_discount' => $order['total_line_items_price_set']['shop_money']['amount'] ?? 0,
            'total_order_discount' => $order['current_total_discounts'] ?? 0,
            'discount_applications' => json_encode($order['discount_applications'] ?? []),
            'total_shipping' => $order['total_shipping_price_set']['shop_money']['amount'] ?? 0,
            'currency' => $order['currency'],
            'shopify_status' => $order['status'] ?? null,
            'financial_status' => $order['financial_status'] ?? null,
            'fulfillment_status' => $order['fulfillment_status'] ?? null,
            'payment_gateway_names' => json_encode($order['payment_gateway_names'] ?? []),
            'shipping_address' => json_encode($order['shipping_address'] ?? null),
            'billing_address' => json_encode($order['billing_address'] ?? null),
            'note' => $order['note'] ?? null,
            'tags' => json_encode($order['tags'] ?? []),
            'discount_codes' => json_encode($order['discount_codes'] ?? []),
            'shipping_lines' => json_encode($order['shipping_lines'] ?? []),
            'tax_lines' => json_encode($order['tax_lines'] ?? []),
            'refunds' => json_encode($order['refunds'] ?? []),
            'line_items' => json_encode($order['line_items'] ?? []),
            'processed_at' => $order['processed_at'] ? date('Y-m-d H:i:s', strtotime($order['processed_at'])) : null,
            'closed_at' => $order['closed_at'] ? date('Y-m-d H:i:s', strtotime($order['closed_at'])) : null,
            'cancelled_at' => $order['cancelled_at'] ? date('Y-m-d H:i:s', strtotime($order['cancelled_at'])) : null,
            'cancel_reason' => $order['cancel_reason'] ?? null,
            'last_sync_at' => date('Y-m-d H:i:s')
        ];

        if ($existing_order) {
            // Update existing order
            $set_clauses = [];
            $values = [];
            foreach ($order_data as $key => $value) {
                $set_clauses[] = "{$key} = ?";
                $values[] = $value;
            }
            $values[] = $existing_order['id'];

            $stmt = $conn->prepare("
                UPDATE orders 
                SET " . implode(', ', $set_clauses) . "
                WHERE id = ?
            ");
            $stmt->execute($values);
            
            logWebhook("Order updated", ['shopify_order_id' => $order['id'], 'local_id' => $existing_order['id']]);
        } else {
            // Insert new order
            $columns = implode(', ', array_keys($order_data));
            $placeholders = implode(', ', array_fill(0, count($order_data), '?'));
            
            $stmt = $conn->prepare("
                INSERT INTO orders ({$columns})
                VALUES ({$placeholders})
            ");
            $stmt->execute(array_values($order_data));
            
            $order_id = $conn->lastInsertId();
            logWebhook("New order created", ['shopify_order_id' => $order['id'], 'local_id' => $order_id]);
        }

        // Commit transaction
        $conn->commit();
        
        // Calculate commission automatically
        if ($customer_id) {
            $local_order_id = $existing_order['id'] ?? $order_id;
            calculate_commission($conn, $local_order_id);
        }

        http_response_code(200);
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    logWebhook("Error processing webhook", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function verify_webhook($data, $hmac_header) {
    $calculated_hmac = base64_encode(hash_hmac('sha256', $data, SHOPIFY_APP_SECRET, true));
    return hash_equals($calculated_hmac, $hmac_header);
}

function calculate_commission($conn, $order_id) {
    try {
        // Get order details
        $stmt = $conn->prepare("
            SELECT o.*, c.first_name, c.last_name, c.email
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || empty($order['customer_id'])) {
            throw new Exception("Order not found or no customer associated");
        }

        // Calculate commission using the same logic as calculate_single_commission.php
        $line_items = json_decode($order['line_items'], true);
        $total_commission = 0;

        if (!empty($line_items) && is_array($line_items)) {
            foreach ($line_items as $item) {
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

                // Get commission rate using the same function from calculate_single_commission.php
                $stmt = $conn->prepare("
                    SELECT * FROM commission_rules 
                    WHERE status = 'active' 
                    AND rule_type = 'product_type' 
                    AND LOWER(rule_value) = LOWER(?)
                    LIMIT 1
                ");
                $stmt->execute([$product_type]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);

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
                }
            }
        }

        // Save commission
        if ($total_commission > 0) {
            $stmt = $conn->prepare("
                INSERT INTO commissions (order_id, agent_id, amount, status, created_at, updated_at)
                VALUES (?, ?, ?, 'pending', NOW(), NOW())
                ON DUPLICATE KEY UPDATE amount = ?, updated_at = NOW()
            ");
            
            $stmt->execute([
                $order_id,
                $order['customer_id'],
                $total_commission,
                $total_commission
            ]);
            
            logWebhook("Commission calculated", [
                'order_id' => $order_id,
                'agent_id' => $order['customer_id'],
                'amount' => $total_commission
            ]);
        }

    } catch (Exception $e) {
        logWebhook("Error calculating commission", [
            'order_id' => $order_id,
            'error' => $e->getMessage()
        ]);
    }
}
