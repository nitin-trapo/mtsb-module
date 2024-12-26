<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['order_id'])) {
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
            COALESCE(a.first_name, '') as agent_first_name,
            COALESCE(a.last_name, '') as agent_last_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN customers a ON o.agent_id = a.id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$_GET['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        die('Order not found');
    }
    
    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    
    // Set content type to PDF
    header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo htmlspecialchars($order['order_number']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-header { text-align: center; margin-bottom: 30px; }
        .invoice-details { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .address-block { width: 45%; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .items-table th { background-color: #f8f9fa; }
        .total-section { text-align: right; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="invoice-header">
        <h1>INVOICE</h1>
        <p>Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
        <p>Date: <?php echo date('M d, Y', strtotime($order['created_at'])); ?></p>
    </div>
    
    <div class="invoice-details">
        <div class="address-block">
            <h3>Bill To:</h3>
            <?php if ($order['billing_address']): ?>
                <p>
                    <?php echo htmlspecialchars($order['billing_address']['name']); ?><br>
                    <?php echo htmlspecialchars($order['billing_address']['address1']); ?><br>
                    <?php if (!empty($order['billing_address']['address2'])): ?>
                        <?php echo htmlspecialchars($order['billing_address']['address2']); ?><br>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($order['billing_address']['city']); ?>, 
                    <?php echo htmlspecialchars($order['billing_address']['province_code']); ?> 
                    <?php echo htmlspecialchars($order['billing_address']['zip']); ?><br>
                    <?php echo htmlspecialchars($order['billing_address']['country']); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="address-block">
            <h3>Ship To:</h3>
            <?php if ($order['shipping_address']): ?>
                <p>
                    <?php echo htmlspecialchars($order['shipping_address']['name']); ?><br>
                    <?php echo htmlspecialchars($order['shipping_address']['address1']); ?><br>
                    <?php if (!empty($order['shipping_address']['address2'])): ?>
                        <?php echo htmlspecialchars($order['shipping_address']['address2']); ?><br>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($order['shipping_address']['city']); ?>, 
                    <?php echo htmlspecialchars($order['shipping_address']['province_code']); ?> 
                    <?php echo htmlspecialchars($order['shipping_address']['zip']); ?><br>
                    <?php echo htmlspecialchars($order['shipping_address']['country']); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    
    <table class="items-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order['line_items'] as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['title']); ?></td>
                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                <td><?php echo htmlspecialchars($order['currency'] . ' ' . number_format($item['price'], 2)); ?></td>
                <td><?php echo htmlspecialchars($order['currency'] . ' ' . number_format($item['quantity'] * $item['price'], 2)); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="total-section">
        <p>Subtotal: <?php echo htmlspecialchars($order['currency'] . ' ' . number_format($order['subtotal_price'], 2)); ?></p>
        <?php if ($order['total_discounts'] > 0): ?>
        <p>Discount: -<?php echo htmlspecialchars($order['currency'] . ' ' . number_format($order['total_discounts'], 2)); ?></p>
        <?php endif; ?>
        <?php if ($order['total_shipping'] > 0): ?>
        <p>Shipping: <?php echo htmlspecialchars($order['currency'] . ' ' . number_format($order['total_shipping'], 2)); ?></p>
        <?php endif; ?>
        <?php if ($order['total_tax'] > 0): ?>
        <p>Tax: <?php echo htmlspecialchars($order['currency'] . ' ' . number_format($order['total_tax'], 2)); ?></p>
        <?php endif; ?>
        <h3>Total: <?php echo htmlspecialchars($order['currency'] . ' ' . number_format($order['total_price'], 2)); ?></h3>
    </div>
    
    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()">Print Invoice</button>
        <button onclick="window.close()">Close</button>
    </div>
</body>
</html>
<?php
} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}
