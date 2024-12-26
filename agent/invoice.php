<?php
ob_start(); // Start output buffering at the very beginning
session_start();
require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['order_id'])) {
    die('Order ID is required');
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get agent details
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone FROM customers WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$agent) {
        die('Agent not found');
    }

    // Get order details with security check
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            c.email as customer_email,
            c.phone as customer_phone,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            o.metafields,
            o.total_discounts,
            o.total_shipping,
            o.total_tax,
            o.subtotal_price,
            o.discount_codes
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ? AND (o.agent_id = ? OR o.customer_id = ?)
        LIMIT 1
    ");
    
    $stmt->execute([$_GET['order_id'], $agent['id'], $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die('Order not found or access denied');
    }

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['metafields'] = json_decode($order['metafields'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);

    // Format customer name
    $order['customer_name'] = trim($order['customer_first_name'] . ' ' . $order['customer_last_name']);

    // Get currency symbol
    $currency_symbol = match($order['currency']) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'INR' => '₹',
        'MYR' => 'RM',
        default => $order['currency'] . ' '
    };

    // Format dates
    $invoice_date = date('F d, Y h:i A', strtotime($order['created_at']));

    // Set up logo path
    $logo_path = '../assets/images/logo.png';

    $logo_base64 = '';
    if (file_exists($logo_path)) {
        error_log("Logo file found at: " . $logo_path);
        $logo_type = pathinfo($logo_path, PATHINFO_EXTENSION);
        $logo_data = file_get_contents($logo_path);
        $logo_base64 = 'data:image/' . $logo_type . ';base64,' . base64_encode($logo_data);
        error_log("Logo base64 length: " . strlen($logo_base64));
    } else {
        error_log("Logo file not found at: " . $logo_path);
    }
    
    // Start output buffering for template
    ob_start();
    
    // Include the invoice template
    include '../templates/invoice_template.php';
    
    // Get the HTML content
    $html = ob_get_clean();

    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('isFontSubsettingEnabled', true);
    $options->set('debugKeepTemp', true);
    $options->set('debugCss', true);
    
    // Create new PDF instance
    $dompdf = new Dompdf($options);
    
    // Load HTML content
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();

    // Clean any remaining output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream PDF
    $dompdf->stream("invoice-" . $order['order_number'] . ".pdf", array("Attachment" => false));
    exit();

} catch (Exception $e) {
    // Log error and show generic message
    error_log("Invoice Error: " . $e->getMessage());
    die('An error occurred while generating the invoice');
}
