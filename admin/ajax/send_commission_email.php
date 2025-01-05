<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Enable error logging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Function to log errors
function logEmailError($message, $context = []) {
    $logFile = __DIR__ . '/../../logs/email_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Add request information to context
    $context['request'] = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'post_data' => $_POST ?? [],
        'session_data' => $_SESSION ?? []
    ];
    
    // Add debug backtrace
    $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    
    $contextStr = !empty($context) ? ' Context: ' . json_encode($context, JSON_PRETTY_PRINT) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    error_log($logMessage, 3, $logFile);
}

try {
    // Log the start of the process
    logEmailError("Starting commission email process", [
        'commission_id' => $_POST['commission_id'] ?? null
    ]);

    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        logEmailError("Unauthorized access attempt", [
            'is_logged_in' => is_logged_in(),
            'is_admin' => is_admin()
        ]);
        throw new Exception('Unauthorized access');
    }

    if (!isset($_POST['commission_id'])) {
        logEmailError("Commission ID not provided");
        throw new Exception('Commission ID is required');
    }

    $commission_id = intval($_POST['commission_id']);
    if ($commission_id <= 0) {
        logEmailError("Invalid commission ID format", [
            'raw_commission_id' => $_POST['commission_id'],
            'parsed_commission_id' => $commission_id
        ]);
        throw new Exception('Invalid commission ID format');
    }

    // Log database connection attempt
    logEmailError("Attempting database connection");
    
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        logEmailError("Database connection failed");
        throw new Exception('Database connection failed');
    }

    // Log successful database connection
    logEmailError("Database connection successful");

    // Get commission details with agent email
    $query = "
        SELECT 
            c.*,
            o.order_number,
            o.currency,
            a.email as agent_email,
            a.first_name as agent_first_name,
            a.last_name as agent_last_name
        FROM commissions c
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN customers a ON c.agent_id = a.id
        WHERE c.id = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$commission_id]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commission) {
        logEmailError("Commission not found in database", [
            'commission_id' => $commission_id,
            'query' => $query
        ]);
        throw new Exception('Commission not found');
    }

    logEmailError("Commission data retrieved successfully", [
        'commission_id' => $commission_id,
        'order_number' => $commission['order_number'] ?? null
    ]);

    if (empty($commission['agent_email'])) {
        logEmailError("Agent email not found", [
            'commission_id' => $commission_id,
            'agent_id' => $commission['agent_id'] ?? null
        ]);
        throw new Exception('Agent email not found');
    }

    // Load mail configuration
    $mail_config = require '../../config/mail.php';
    
    if (empty($mail_config['host']) || empty($mail_config['username']) || empty($mail_config['password'])) {
        logEmailError("Incomplete mail configuration");
        throw new Exception('Incomplete mail configuration');
    }

    // Generate PDF invoice
    try {
        logEmailError("Starting PDF generation", [
            'commission_id' => $commission_id
        ]);

        require_once __DIR__ . '/../generate_invoice.php';
        
        // Generate the PDF content and save to storage
        $pdf_content = generate_invoice($commission_id);
        
        if (empty($pdf_content)) {
            logEmailError("PDF generation produced empty content", [
                'commission_id' => $commission_id
            ]);
            throw new Exception('Failed to generate PDF invoice: Empty content');
        }
        
        // Get the saved PDF path
        $storage_dir = __DIR__ . '/../../storage/invoice';
        $filename = 'commission_' . $commission_id . '.pdf';
        $filepath = $storage_dir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            logEmailError("Generated PDF file not found", [
                'commission_id' => $commission_id,
                'filepath' => $filepath
            ]);
            throw new Exception('Generated PDF file not found');
        }
        
        logEmailError("PDF generated and saved successfully", [
            'commission_id' => $commission_id,
            'filepath' => $filepath,
            'filesize' => filesize($filepath)
        ]);

        // Create a new PHPMailer instance
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $mail_config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mail_config['username'];
            $mail->Password = $mail_config['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // Recipients
            $mail->setFrom($mail_config['username'], 'Commission System');
            $mail->addAddress($commission['agent_email'], $commission['agent_first_name'] . ' ' . $commission['agent_last_name']);

            // Attach the PDF
            $mail->addAttachment($filepath, 'commission_' . $commission_id . '.pdf');

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Commission Invoice for Order #' . $commission['order_number'];
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #333;'>Commission Invoice</h2>
                
                <p>Dear {$commission['agent_first_name']},</p>
                
                <p>Please find attached your commission invoice for Order #{$commission['order_number']}.</p>
                
                <h3 style='color: #444;'>Order Details:</h3>
                <ul style='list-style: none; padding-left: 0;'>
                    <li>Order Number: #{$commission['order_number']}</li>
                    <li>Commission Amount: {$commission['currency']} {$commission['amount']}</li>
                    <li>Status: " . ucfirst($commission['status']) . "</li>
                </ul>
                
                <p style='margin-top: 30px; color: #666; font-size: 12px;'>
                    This is an automated email. Please do not reply.
                </p>
            </div>";

            $mail->send();
            
            // Delete the temporary PDF file
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            logEmailError("Email sent successfully", [
                'commission_id' => $commission_id,
                'to' => $commission['agent_email']
            ]);

            // Return success response
            echo json_encode([
                'success' => true,
                'message' => 'Invoice sent successfully'
            ]);

        } catch (Exception $e) {
            logEmailError("Email sending failed", [
                'commission_id' => $commission_id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to send email: " . $e->getMessage());
        }

    } catch (Exception $e) {
        logEmailError("Failed to generate PDF invoice", [
            'error' => $e->getMessage(),
            'commission_id' => $commission_id
        ]);
        throw new Exception("Failed to generate PDF invoice: " . $e->getMessage());
    }

} catch (Exception $e) {
    logEmailError("Error in send_commission_email.php", [
        'error' => $e->getMessage(),
        'commission_id' => $commission_id ?? null
    ]);
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
