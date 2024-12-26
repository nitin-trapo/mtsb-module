<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;
use Dompdf\Options;

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

    // Add customer name
    $order['customer_name'] = trim($order['customer_first_name'] . ' ' . $order['customer_last_name']);

    if (empty($order['metafields']['customer_email'])) {
        throw new Exception("No metafield email found for this order");
    }

    // Initialize Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('chroot', __DIR__ . '/../../');
    
    $dompdf = new Dompdf($options);
    
    // Get the logo and convert to base64
    $logo_path = __DIR__ . '/../../assets/images/logo.png';
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
    
    // Get the invoice template
    ob_start();
    include '../../templates/invoice_template.php';
    $html = ob_get_clean();
    
    // Load HTML into Dompdf
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    // Save PDF to temporary file
    $pdfFile = tempnam(sys_get_temp_dir(), 'invoice_');
    file_put_contents($pdfFile, $dompdf->output());
    
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
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Invoice for Order #' . $order['order_number'];
        
        // Email body
        $body = "Dear " . htmlspecialchars($order['customer_first_name']) . ",<br><br>";
        $body .= "Thank you for your order. Please find attached the invoice for your order #" . htmlspecialchars($order['order_number']) . ".<br><br>";
        $body .= "Order Details:<br>";
        $body .= "Order Number: " . htmlspecialchars($order['order_number']) . "<br>";
        $body .= "Order Date: " . htmlspecialchars($order['formatted_created_date']) . "<br>";
        $body .= "Total Amount: " . htmlspecialchars(number_format($order['total_price'], 2)) . " " . htmlspecialchars($order['currency']) . "<br><br>";
        $body .= "If you have any questions, please don't hesitate to contact us.<br><br>";
        $body .= "Best regards,<br>";
        $body .= htmlspecialchars($mail_config['from_name']);
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
        
        // Attach PDF invoice
        $mail->addAttachment($pdfFile, 'invoice_' . $order['order_number'] . '.pdf');
        
        $mail->send();
        
        // Delete temporary PDF file
        unlink($pdfFile);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        // Delete temporary PDF file
        unlink($pdfFile);
        throw new Exception("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
    
} catch (PDOException $e) {
    error_log("Database error while sending invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error while sending invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
