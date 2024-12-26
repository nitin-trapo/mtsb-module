<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';
require_once '../../classes/InvoicePDF.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    die('Unauthorized access');
}

if (!isset($_GET['order_id'])) {
    die('Order ID is required');
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            c.email as customer_email,
            c.phone as customer_phone,
            DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
            (SELECT SUM(quantity * price) FROM order_items WHERE order_id = o.id) as subtotal_price,
            JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].code')) as discount_code,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].amount')) AS DECIMAL(10,2)) as discount_amount
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$_GET['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        die('Order not found');
    }

    // Generate PDF invoice
    $pdf = new InvoicePDF('P', 'mm', 'A4');
    $pdf->generateInvoice($order);
    
    // Output PDF directly to browser
    $pdf->Output('Invoice_' . $order['order_number'] . '.pdf', 'I');
    
} catch (Exception $e) {
    die('Error generating invoice: ' . $e->getMessage());
}
