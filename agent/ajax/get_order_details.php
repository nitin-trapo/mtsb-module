<?php
// Prevent PHP errors from being displayed
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

session_start();
require_once '../../config/database.php';
require_once '../../config/tables.php';
require_once '../../includes/functions.php';

try {
    // Check if user is logged in and is agent
    if (!isset($_SESSION['user_email']) || !is_agent()) {
        throw new Exception("Unauthorized access");
    }

    if (!isset($_POST['order_id'])) {
        throw new Exception("Order ID is required");
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get agent details
    $stmt = $conn->prepare("SELECT id FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception("Agent not found");
    }

    $order_id = intval($_POST['order_id']);

    // Get order details with security check
    $query = "SELECT 
        o.*,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(o.metafields, '$.customer_email')),
            c.email,
            'N/A'
        ) as customer_email,
        c.phone as customer_phone,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
        DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date,
        o.metafields,
        COALESCE(cm.amount, 0) as commission_amount,
        COALESCE(cm.status, 'pending') as commission_status,
        DATE_FORMAT(cm.created_at, '%b %d, %Y %h:%i %p') as commission_date
    FROM " . TABLE_ORDERS . " o
    LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
    LEFT JOIN " . TABLE_COMMISSIONS . " cm ON o.id = cm.order_id
    WHERE o.id = ? AND (o.agent_id = ? OR o.customer_id = ?)
    LIMIT 1";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id, $agent['id'], $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found or unauthorized");
    }

    // Process metafields if exists
    if (!empty($order['metafields'])) {
        $metafields = json_decode($order['metafields'], true);
        if (isset($metafields['customer_email'])) {
            $order['customer_email'] = $metafields['customer_email'];
        }
    }

    // Format currency values
    $currency = $order['currency'] ?? 'MYR';
    $currency_symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'INR' => '₹',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'JPY' => '¥',
        'CNY' => '¥',
        'HKD' => 'HK$',
        'MYR' => 'RM '
    ];
    $currency_symbol = $currency_symbols[$currency] ?? ($currency . ' ');

    // Add formatted currency values
    $order['formatted_total'] = $currency_symbol . number_format($order['total_price'], 2);
    $order['formatted_subtotal'] = $currency_symbol . number_format($order['subtotal_price'], 2);
    $order['formatted_tax'] = $currency_symbol . number_format($order['total_tax'], 2);
    $order['formatted_shipping'] = $currency_symbol . number_format($order['total_shipping'], 2);
    $order['formatted_commission'] = $currency_symbol . number_format($order['commission_amount'], 2);

    echo json_encode([
        'success' => true,
        'order' => $order
    ]);

} catch (Exception $e) {
    error_log("Error in get_order_details.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
