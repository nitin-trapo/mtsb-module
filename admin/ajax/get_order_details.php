<?php
// Prevent PHP errors from being displayed
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

try {
    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        throw new Exception("Unauthorized access");
    }

    $db = new Database();
    $conn = $db->getConnection();

    if (!isset($_POST['order_id'])) {
        throw new Exception("Order ID is required");
    }

    $order_id = $_POST['order_id'];

    // Get order details
    $query = "SELECT 
        o.*,
        c.first_name as agent_first_name,
        c.last_name as agent_last_name,
        c.email as agent_email,
        c.phone as agent_phone,
        c.is_agent,
        c.commission_rate,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
        DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date,
        o.metafields,
        o.discount_codes,
        COALESCE(com.amount, 0) as base_commission,
        COALESCE(com.total_discount, 0) as commission_discount,
        COALESCE(com.actual_commission, 0) as actual_commission,
        COALESCE(com.status, 'pending') as commission_status,
        COALESCE(com.created_at, '') as commission_date,
        CASE 
            WHEN com.id IS NULL THEN 'Not Calculated'
            ELSE 'Calculated'
        END as commission_calculation_status
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN commissions com ON o.id = com.order_id
    WHERE o.id = ?";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    // Debug discount codes
    error_log("Discount Codes from DB: " . print_r($order['discount_codes'], true));

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);
    $order['discount_applications'] = json_decode($order['discount_applications'], true) ?? [];

    // Parse metafields if exists
    if (!empty($order['metafields'])) {
        $metafields = json_decode($order['metafields'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $order['metafields'] = $metafields;
        } else {
            $order['metafields'] = null;
        }
    } else {
        $order['metafields'] = null;
    }

    // Format customer name
    $order['customer_name'] = trim($order['customer_first_name'] . ' ' . $order['customer_last_name']);
    
    // Format dates
    $order['formatted_created_date'] = date('M d, Y h:i A', strtotime($order['created_at']));
    $order['formatted_processed_date'] = !empty($order['formatted_processed_date']) ? 
        date('M d, Y h:i A', strtotime($order['formatted_processed_date'])) : null;

    // Calculate totals from line items
    $subtotal = 0;
    $line_items = [];
    if (is_array($order['line_items'])) {
        foreach ($order['line_items'] as $item) {
            $item_total = floatval($item['price']) * intval($item['quantity']);
            $subtotal += $item_total;
            
            // Get variant title and SKU
            $variant_title = isset($item['variant_title']) && !empty($item['variant_title']) ? 
                $item['variant_title'] : '';
            $sku = isset($item['sku']) && !empty($item['sku']) ? 
                $item['sku'] : 'N/A';
            
            $line_items[] = [
                'title' => $item['title'],
                'variant_title' => $variant_title,
                'sku' => $sku,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item_total
            ];
        }
    }

    // Process discount codes
    $processed_discount_codes = [];
    if (!empty($order['discount_codes'])) {
        foreach ($order['discount_codes'] as $discount) {
            // Only add the code
            $processed_discount_codes[] = [
                'code' => $discount['code'] ?? 'N/A'
            ];
        }
    }

    // Calculate total discounts
    $total_discounts = floatval($order['total_discounts'] ?? 0);

    // Get discount information
    $discount_code = '';
    $discount_amount = 0;
    if (!empty($processed_discount_codes)) {
        $first_discount = reset($processed_discount_codes);
        $discount_code = $first_discount['code'] ?? '';
        $discount_amount = floatval($first_discount['value'] ?? 0);
    }

    // Calculate final totals
    $total_shipping = floatval($order['total_shipping'] ?? 0);
    $total_tax = floatval($order['total_tax'] ?? 0);
    $total_price = floatval($order['total_price'] ?? 0);

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'created_at' => $order['created_at'],
            'processed_at' => $order['formatted_processed_date'],
            'financial_status' => $order['financial_status'],
            'fulfillment_status' => $order['fulfillment_status'],
            'currency' => $order['currency'],
            'total_price' => $order['total_price'],
            'subtotal_price' => $subtotal,
            'total_tax' => $total_tax,
            'total_discounts' => $total_discounts,
            'metafields' => $order['metafields'],
            'email' => $order['email'] ?? '',
            'customer_email' => $order['customer_email'],
            'customer_phone' => $order['customer_phone'],
            'agent_first_name' => $order['agent_first_name'] ?? '',
            'agent_last_name' => $order['agent_last_name'] ?? '',
            'agent_email' => $order['agent_email'] ?? '',
            'agent_phone' => $order['agent_phone'] ?? '',
            'is_agent' => (bool)($order['is_agent'] ?? false),
            'commission_rate' => $order['commission_rate'] ?? 0,
            'shipping_address' => $order['shipping_address'],
            'billing_address' => $order['billing_address'],
            'line_items' => $line_items,
            'discount_codes' => $order['discount_codes'],
            'discount_applications' => $order['discount_applications'],
            'base_commission' => $order['base_commission'],
            'commission_discount' => $order['commission_discount'],
            'actual_commission' => $order['actual_commission'],
            'commission_status' => $order['commission_status'],
            'commission_calculation_status' => $order['commission_calculation_status']
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
