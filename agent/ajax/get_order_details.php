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
    // Check if user is logged in and is agent
    if (!isset($_SESSION['email']) || !is_agent()) {
        throw new Exception("Unauthorized access");
    }

    $db = new Database();
    $conn = $db->getConnection();

    if (!isset($_POST['order_id'])) {
        throw new Exception("Order ID is required");
    }

    // Get agent details
    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception("Agent not found");
    }

    $order_id = $_POST['order_id'];

    // Get order details
    $query = "SELECT 
        o.*,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email,
        c.phone as customer_phone,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
        DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date,
        o.metafields,
        COALESCE(com.amount, 0) as commission_amount,
        COALESCE(com.status, 'pending') as commission_status,
        COALESCE(com.created_at, '') as commission_date
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN commissions com ON o.id = com.order_id
    WHERE o.id = ? AND (o.customer_id = ?)";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id, $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);

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
    $order['formatted_processed_date'] = !empty($order['processed_at']) ? 
        date('M d, Y h:i A', strtotime($order['processed_at'])) : null;

    // Calculate totals from line items
    $subtotal = 0;
    $line_items = [];
    if (is_array($order['line_items'])) {
        foreach ($order['line_items'] as $item) {
            $item_total = floatval($item['price']) * intval($item['quantity']);
            $subtotal += $item_total;
            $line_items[] = [
                'title' => $item['title'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item_total
            ];
        }
    }

    // Ensure numeric values
    $order['total_discounts'] = floatval($order['total_discounts']);
    $order['total_shipping'] = floatval($order['total_shipping']);
    $order['total_tax'] = floatval($order['total_tax']);
    $order['subtotal_price'] = floatval($order['subtotal_price']);
    $order['commission_amount'] = floatval($order['commission_amount']);

    echo json_encode(['success' => true, 'order' => $order]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
