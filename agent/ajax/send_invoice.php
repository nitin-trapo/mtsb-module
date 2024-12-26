<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';
require_once '../../classes/InvoicePDF.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if (!isset($_SESSION['email']) || !is_agent()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Order ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

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
        JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].code')) as discount_code,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].amount')) AS DECIMAL(10,2)) as discount_amount
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ? AND o.customer_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id, $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found or does not belong to this agent");
    }

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);
    $order['metafields'] = json_decode($order['metafields'], true);

    if (empty($order['metafields']['customer_email'])) {
        throw new Exception("No metafield email found for this order");
    }

    // Generate PDF invoice
    $pdf = new InvoicePDF('P', 'mm', 'A4');
    $pdf->generateInvoice($order);
    
    // Save PDF to temporary file
    $pdfFile = tempnam(sys_get_temp_dir(), 'invoice_');
    $pdf->Output($pdfFile, 'F');
    
    // Load mail configuration
    $mail_config = require_once '../../config/mail.php';
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = $mail_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mail_config['username'];
        $mail->Password = $mail_config['password'];
        $mail->SMTPSecure = $mail_config['encryption'];
        $mail->Port = $mail_config['port'];
        
        // Recipients
        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
        $mail->addAddress($order['metafields']['customer_email'], $order['customer_first_name'] . ' ' . $order['customer_last_name']);
        $mail->addReplyTo($mail_config['from_email'], $mail_config['from_name']);
        
        // Attach PDF
        $mail->addAttachment($pdfFile, 'Invoice_' . $order['order_number'] . '.pdf');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Invoice for Order #{$order['order_number']}";
        
        // Get the date with fallback options
        $formatted_date = '';
        if (!empty($order['formatted_processed_date'])) {
            $formatted_date = $order['formatted_processed_date'];
        } elseif (!empty($order['processed_at'])) {
            $formatted_date = date('M d, Y h:i A', strtotime($order['processed_at']));
        } else {
            $formatted_date = date('M d, Y h:i A', strtotime($order['created_at']));
        }

        // Email body
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #333;'>Thank You for Your Order!</h2>
            <p>Dear {$order['customer_first_name']},</p>
            <p>Please find attached the invoice for your order #{$order['order_number']} placed on {$formatted_date}.</p>
            <div style='margin: 20px 0; padding: 20px; background-color: #f8f9fa; border-radius: 5px;'>
                <h3 style='margin-top: 0;'>Order Summary</h3>
                <p><strong>Order Number:</strong> #{$order['order_number']}</p>
                <p><strong>Order Date:</strong> {$formatted_date}</p>
                <p><strong>Total Amount:</strong> " . format_currency($order['total_price'], $order['currency']) . "</p>
            </div>
            <p>If you have any questions about your order, please don't hesitate to contact us.</p>
            <p>Thank you for your business!</p>
            <br>
            <p>Best regards,<br>Your Sales Team</p>
        </div>";

        // Send email
        $mail->send();
        
        // Clean up temporary PDF file
        if (file_exists($pdfFile)) {
            unlink($pdfFile);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        // Clean up temporary PDF file
        if (file_exists($pdfFile)) {
            unlink($pdfFile);
        }
        throw new Exception("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
    
} catch (PDOException $e) {
    error_log("Database error while sending invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error while sending invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
