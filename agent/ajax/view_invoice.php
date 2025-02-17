<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/tables.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in and is agent
if (!isset($_SESSION['user_email']) || !is_agent()) {
    die('Unauthorized access');
}

if (!isset($_GET['order_id'])) {
    die('Order ID is required');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get agent details
    $stmt = $conn->prepare("SELECT id, email FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception("Agent not found");
    }

    // Get order details with customer info and verify agent's access
    $query = "SELECT 
        o.*,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email,
        c.phone as customer_phone,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date
    FROM " . TABLE_ORDERS . " o
    LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
    WHERE o.id = ? AND (o.agent_id = ? OR c.email = ?)";

    $stmt = $conn->prepare($query);
    $stmt->execute([$_GET['order_id'], $agent['id'], $agent['email']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found or you don't have permission to view it");
    }

    // Keep JSON fields as strings for the template
    $order['shipping_address'] = is_string($order['shipping_address']) ? $order['shipping_address'] : json_encode($order['shipping_address']);
    $order['billing_address'] = is_string($order['billing_address']) ? $order['billing_address'] : json_encode($order['billing_address']);
    $order['line_items'] = is_string($order['line_items']) ? $order['line_items'] : json_encode($order['line_items']);
    $order['discount_codes'] = is_string($order['discount_codes']) ? $order['discount_codes'] : json_encode($order['discount_codes'] ?? []);
    $order['discount_applications'] = is_string($order['discount_applications']) ? $order['discount_applications'] : json_encode($order['discount_applications'] ?? []);

    // Calculate subtotal for the template
    $line_items = json_decode($order['line_items'], true);
    $subtotal = 0;
    foreach ($line_items as $item) {
        $subtotal += floatval($item['price']) * intval($item['quantity']);
    }

    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    // Create new PDF document
    $dompdf = new Dompdf($options);
    
    // Load the invoice template
    ob_start();
    include dirname(__DIR__, 2) . '/templates/invoice.php';
    $html = ob_get_clean();
    
    // Load HTML into Dompdf
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream('Invoice_' . $order['order_number'] . '.pdf', array('Attachment' => false));

} catch (Exception $e) {
    die('Error generating invoice: ' . $e->getMessage());
}
