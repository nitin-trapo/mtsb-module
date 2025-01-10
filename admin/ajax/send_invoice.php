<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';
require_once '../../classes/InvoicePDF.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Set up logging
$logDir = __DIR__ . '/../../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/invoice_sending.log';

function logInvoiceMessage($message, $context = []) {
    global $logFile;
    try {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
        error_log($logMessage, 3, $logFile);
    } catch (Exception $e) {
        error_log("Failed to write to invoice log: " . $e->getMessage());
    }
}

// Check for webhook request
$isWebhook = isset($_POST['webhook_request']) || 
             isset($_SERVER['HTTP_X_WEBHOOK_REQUEST']) || 
             (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] === 'Shopify Webhook');

// Only check session for non-webhook requests
if (!$isWebhook && (!is_logged_in() || !is_admin())) {
    logInvoiceMessage("Unauthorized access attempt", ['ip' => $_SERVER['REMOTE_ADDR']]);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['order_id'])) {
    logInvoiceMessage("Missing order_id parameter");
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Order ID is required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    logInvoiceMessage("Starting invoice generation", [
        'order_id' => $_POST['order_id'],
        'is_webhook' => $isWebhook
    ]);
    
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            c.email as customer_email,
            c.phone as customer_phone,
            DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date,
            JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].code')) as discount_code_1,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[0].amount')) AS DECIMAL(10,2)) as discount_amount_1,
            JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[1].code')) as discount_code_2,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(o.discount_codes, '$[1].amount')) AS DECIMAL(10,2)) as discount_amount_2
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$_POST['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        logInvoiceMessage("Order not found", ['order_id' => $_POST['order_id']]);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Order not found']);
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
        
        // Attempt to get customer email from metafields
        $metafields = json_decode($order['metafields'], true);
        $metafields_customer_email = $metafields['customer_email'] ?? null;

        // Validate and use metafields customer email or fallback to customer email
        $primary_email = $metafields_customer_email ?: $order['customer_email'];
        
        if (empty($primary_email)) {
            logInvoiceMessage("No valid email found for sending invoice", ['order_id' => $_POST['order_id']]);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No valid email found for sending invoice']);
            exit;
        }

        // Recipients
        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
        $mail->addAddress($primary_email, $order['customer_first_name'] . ' ' . $order['customer_last_name']);
        
        // Add CC if customer email is different from primary email
        if (!empty($order['customer_email']) && $order['customer_email'] !== $primary_email) {
            $mail->addCC($order['customer_email']);
        }
        
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
                        ' . (!empty($order['discount_code_1']) ? '
                        <div class="amount-row">
                            <span class="amount-label">Discount (' . htmlspecialchars($order['discount_code_1']) . '):</span> 
                            <span>-' . $order['currency'] . ' ' . number_format($order['discount_amount_1'], 2) . '</span>
                        </div>' : '') . '
                        ' . (!empty($order['discount_code_2']) ? '
                        <div class="amount-row">
                            <span class="amount-label">Discount (' . htmlspecialchars($order['discount_code_2']) . '):</span> 
                            <span>-' . $order['currency'] . ' ' . number_format($order['discount_amount_2'], 2) . '</span>
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
        
        // Log successful email
        $stmt = $conn->prepare("
            INSERT INTO email_logs (order_id, email_type, recipient_email, status, sent_at)
            VALUES (?, 'invoice', ?, 'sent', NOW())
        ");
        $stmt->execute([$order['id'], $primary_email]);
        
        logInvoiceMessage("Invoice sent successfully to " . $primary_email, ['order_id' => $_POST['order_id']]);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Invoice sent successfully to ' . $primary_email
        ]);
        
    } catch (Exception $e) {
        // Clean up temporary PDF file
        if (file_exists($pdfFile)) {
            unlink($pdfFile);
        }
        
        // Log email error
        $stmt = $conn->prepare("
            INSERT INTO email_logs (order_id, email_type, recipient_email, status, error_message, sent_at)
            VALUES (?, 'invoice', ?, 'failed', ?, NOW())
        ");
        $stmt->execute([
            $order['id'],
            $primary_email,
            'Mailer Error: ' . $e->getMessage()
        ]);
        
        logInvoiceMessage("Failed to send invoice email: " . $e->getMessage(), ['order_id' => $_POST['order_id']]);
        error_log("Failed to send invoice email: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Failed to send email: ' . $e->getMessage()
        ]);
    }
    
} catch (PDOException $e) {
    logInvoiceMessage("Database error while sending invoice: " . $e->getMessage(), ['order_id' => $_POST['order_id']]);
    error_log("Database error while sending invoice: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    logInvoiceMessage("General error while sending invoice: " . $e->getMessage(), ['order_id' => $_POST['order_id']]);
    error_log("General error while sending invoice: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
