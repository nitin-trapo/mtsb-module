<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';
require_once '../../classes/InvoicePDF.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['order_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Order ID is required']);
    exit;
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
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date,
            JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].code')) as discount_code,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].amount')) AS DECIMAL(10,2)) as discount_amount
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$_POST['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Order not found']);
        exit;
    }
    
    if (empty($order['customer_email'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Customer email not found']);
        exit;
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
        $mail->addAddress($order['customer_email'], $order['customer_first_name'] . ' ' . $order['customer_last_name']);
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
            try {
                $date = new DateTime($order['processed_at']);
                $date->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $formatted_date = $date->format('M d, Y h:i A');
            } catch (Exception $e) {
                error_log("Error formatting date: " . $e->getMessage());
                $formatted_date = date('M d, Y h:i A');
            }
        } else {
            $formatted_date = date('M d, Y h:i A');
        }
        
        // Email body with modern styling
        // Calculate subtotal from line items
        $line_items = json_decode($order['line_items'], true);
        $subtotal = 0;
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
        }
        
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding: 20px 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
                .footer { text-align: center; padding: 20px 0; color: #666; font-size: 12px; }
                .order-info { background: #fff; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .button { display: inline-block; padding: 10px 20px; background: #4CAF50; color: #fff; text-decoration: none; border-radius: 3px; }
                .amount-row { margin: 5px 0; }
                .amount-label { font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Thank You for Your Order!</h2>
                </div>
                
                <div class="content">
                    <p>Dear ' . htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name']) . ',</p>
                    
                    <p>Thank you for your order. Please find your invoice attached to this email.</p>
                    
                    <div class="order-info">
                        <h3>Order Details:</h3>
                        <p><strong>Order Number:</strong> #' . htmlspecialchars($order['order_number']) . '</p>
                        <p><strong>Order Date:</strong> ' . $formatted_date . '</p>
                        <div class="amount-row">
                            <span class="amount-label">Subtotal:</span> 
                            <span>' . $order['currency'] . ' ' . number_format($subtotal, 2) . '</span>
                        </div>
                        ' . (!empty($order['discount_code']) ? '
                        <div class="amount-row">
                            <span class="amount-label">Discount (' . htmlspecialchars($order['discount_code']) . '):</span> 
                            <span>-' . $order['currency'] . ' ' . number_format($order['discount_amount'], 2) . '</span>
                        </div>' : '') . '
                        <div class="amount-row">
                            <span class="amount-label">Total Amount:</span> 
                            <span>' . $order['currency'] . ' ' . number_format($order['total_price'], 2) . '</span>
                        </div>
                    </div>
                    
                    <p>If you have any questions about your order, please don\'t hesitate to contact us.</p>
                </div>
                
                <div class="footer">
                    <p>This email was sent by ' . $mail_config['from_name'] . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $message;
        $mail->AltBody = strip_tags(str_replace(
            ['<br>', '</p>', '</h2>', '</h3>'],
            ["\n", "\n\n", "\n\n", "\n\n"],
            $message
        ));
        
        // Send email
        $mail->send();
        
        // Clean up temporary PDF file
        unlink($pdfFile);
        
        // Log success
        $stmt = $conn->prepare("
            INSERT INTO email_logs (order_id, email_type, recipient_email, status, sent_at)
            VALUES (?, 'invoice', ?, 'sent', NOW())
        ");
        $stmt->execute([$_POST['order_id'], $order['customer_email']]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Invoice sent successfully to ' . $order['customer_email']
        ]);
        
    } catch (Exception $e) {
        // Clean up temporary PDF file
        if (file_exists($pdfFile)) {
            unlink($pdfFile);
        }
        
        // Log error
        $stmt = $conn->prepare("
            INSERT INTO email_logs (order_id, email_type, recipient_email, status, error_message, sent_at)
            VALUES (?, 'invoice', ?, 'failed', ?, NOW())
        ");
        $stmt->execute([
            $_POST['order_id'], 
            $order['customer_email'],
            'Mailer Error: ' . $e->getMessage()
        ]);
        
        error_log("Failed to send invoice email: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Failed to send email: ' . $e->getMessage()
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Database error while sending invoice: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error while sending invoice: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
