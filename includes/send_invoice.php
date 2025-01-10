<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class InvoiceSender {
    private $db;
    private $conn;
    private $mail;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        $this->initializeMailer();
    }
    
    private function initializeMailer() {
        $this->mail = new PHPMailer(true);
        
        // Server settings
        $this->mail->isSMTP();
        $this->mail->Host = SMTP_HOST;
        $this->mail->SMTPAuth = true;
        $this->mail->Username = SMTP_USERNAME;
        $this->mail->Password = SMTP_PASSWORD;
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = SMTP_PORT;
        
        // Default sender
        $this->mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    }
    
    public function sendInvoice($order_id) {
        try {
            // Get order details
            $stmt = $this->conn->prepare("
                SELECT o.*, c.email as customer_email, c.first_name as customer_first_name,
                       c.last_name as customer_last_name, c.is_agent,
                       com.amount as commission_amount, com.agent_id
                FROM orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN commissions com ON o.id = com.order_id
                WHERE o.id = ?
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                throw new Exception("Order not found");
            }
            
            // Generate invoice HTML
            $invoiceHtml = $this->generateInvoiceHtml($order);
            
            // Send to customer
            $this->sendCustomerInvoice($order, $invoiceHtml);
            
            // If there's an agent, send commission details
            if ($order['agent_id']) {
                $this->sendAgentCommissionDetails($order);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error sending invoice: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateInvoiceHtml($order) {
        $line_items = json_decode($order['line_items'], true);
        $shipping_address = json_decode($order['shipping_address'], true);
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .invoice { max-width: 800px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .order-details { margin-bottom: 20px; }
                .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .total-section { text-align: right; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="invoice">
                <div class="header">
                    <h1>Invoice</h1>
                    <p>Order #' . htmlspecialchars($order['order_number']) . '</p>
                </div>
                
                <div class="order-details">
                    <h3>Billing Details</h3>
                    <p>' . htmlspecialchars($shipping_address['name']) . '<br>
                    ' . htmlspecialchars($shipping_address['address1']) . '<br>
                    ' . htmlspecialchars($shipping_address['city']) . ', ' . htmlspecialchars($shipping_address['province']) . '<br>
                    ' . htmlspecialchars($shipping_address['country']) . ' ' . htmlspecialchars($shipping_address['zip']) . '</p>
                </div>
                
                <table class="items-table">
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>';
        
        foreach ($line_items as $item) {
            $html .= '
                    <tr>
                        <td>' . htmlspecialchars($item['name']) . '</td>
                        <td>' . htmlspecialchars($item['quantity']) . '</td>
                        <td>' . htmlspecialchars($order['currency']) . ' ' . number_format($item['price'], 2) . '</td>
                        <td>' . htmlspecialchars($order['currency']) . ' ' . number_format($item['price'] * $item['quantity'], 2) . '</td>
                    </tr>';
        }
        
        $html .= '
                </table>
                
                <div class="total-section">
                    <p>Subtotal: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['subtotal_price'], 2) . '</p>
                    <p>Shipping: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['total_shipping'], 2) . '</p>
                    <p>Tax: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['total_tax'], 2) . '</p>
                    <h3>Total: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['total_price'], 2) . '</h3>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    private function sendCustomerInvoice($order, $invoiceHtml) {
        $this->mail->clearAddresses();
        $this->mail->addAddress($order['customer_email'], $order['customer_first_name'] . ' ' . $order['customer_last_name']);
        $this->mail->isHTML(true);
        $this->mail->Subject = 'Invoice for Order #' . $order['order_number'];
        $this->mail->Body = $invoiceHtml;
        $this->mail->AltBody = 'Please view this email in an HTML compatible email client';
        
        $this->mail->send();
    }
    
    private function sendAgentCommissionDetails($order) {
        // Get agent details
        $stmt = $this->conn->prepare("SELECT email, first_name, last_name FROM customers WHERE id = ?");
        $stmt->execute([$order['agent_id']]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$agent) {
            throw new Exception("Agent not found");
        }
        
        $commissionHtml = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .commission { max-width: 600px; margin: 0 auto; padding: 20px; }
            </style>
        </head>
        <body>
            <div class="commission">
                <h2>Commission Details</h2>
                <p>Order #' . htmlspecialchars($order['order_number']) . '</p>
                <p>Commission Amount: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['commission_amount'], 2) . '</p>
                <p>Order Total: ' . htmlspecialchars($order['currency']) . ' ' . number_format($order['total_price'], 2) . '</p>
                <p>Order Date: ' . date('Y-m-d H:i:s', strtotime($order['created_at'])) . '</p>
            </div>
        </body>
        </html>';
        
        $this->mail->clearAddresses();
        $this->mail->addAddress($agent['email'], $agent['first_name'] . ' ' . $agent['last_name']);
        $this->mail->isHTML(true);
        $this->mail->Subject = 'Commission Details for Order #' . $order['order_number'];
        $this->mail->Body = $commissionHtml;
        $this->mail->AltBody = 'Please view this email in an HTML compatible email client';
        
        $this->mail->send();
    }
}
