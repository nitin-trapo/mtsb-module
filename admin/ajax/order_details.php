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

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Order ID is required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get order details with customer and agent info
$stmt = $conn->prepare("
    SELECT o.*,
           c.first_name as customer_first_name,
           c.last_name as customer_last_name,
           c.email as customer_email,
           a.first_name as agent_first_name,
           a.last_name as agent_last_name,
           a.email as agent_email,
           COALESCE(cm.amount, 0) as commission_amount,
           cm.status as commission_status
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN customers a ON o.agent_id = a.id
    LEFT JOIN commissions cm ON o.id = cm.order_id
    WHERE o.id = ?
");
$stmt->execute([$_GET['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Order not found']);
    exit;
}

// Get order items
$stmt = $conn->prepare("
    SELECT * FROM order_items WHERE order_id = ?
");
$stmt->execute([$_GET['id']]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-md-6">
        <h5>Order Information</h5>
        <table class="table">
            <tr>
                <th>Order Number:</th>
                <td><?php echo $order['order_number']; ?></td>
            </tr>
            <tr>
                <th>Shopify Order ID:</th>
                <td><?php echo $order['shopify_order_id']; ?></td>
            </tr>
            <tr>
                <th>Total Amount:</th>
                <td>$<?php echo number_format($order['total_price'], 2); ?></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td><?php echo ucfirst($order['status']); ?></td>
            </tr>
            <tr>
                <th>Financial Status:</th>
                <td><?php echo ucfirst($order['financial_status']); ?></td>
            </tr>
            <tr>
                <th>Fulfillment Status:</th>
                <td><?php echo ucfirst($order['fulfillment_status'] ?? 'unfulfilled'); ?></td>
            </tr>
            <tr>
                <th>Created Date:</th>
                <td><?php echo date('M d, Y H:i:s', strtotime($order['created_at'])); ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5>Customer & Agent Information</h5>
        <table class="table">
            <tr>
                <th>Customer Name:</th>
                <td><?php echo $order['customer_first_name'] . ' ' . $order['customer_last_name']; ?></td>
            </tr>
            <tr>
                <th>Customer Email:</th>
                <td><?php echo $order['customer_email']; ?></td>
            </tr>
            <?php if ($order['agent_id']): ?>
            <tr>
                <th>Sales Agent:</th>
                <td><?php echo $order['agent_first_name'] . ' ' . $order['agent_last_name']; ?></td>
            </tr>
            <tr>
                <th>Agent Email:</th>
                <td><?php echo $order['agent_email']; ?></td>
            </tr>
            <tr>
                <th>Commission Amount:</th>
                <td>$<?php echo number_format($order['commission_amount'], 2); ?></td>
            </tr>
            <tr>
                <th>Commission Status:</th>
                <td><?php echo ucfirst($order['commission_status'] ?? 'Not Set'); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <h5>Order Items</h5>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_items as $item): ?>
                <tr>
                    <td>
                        <?php echo $item['title']; ?>
                        <?php if ($item['tags']): ?>
                        <br><small class="text-muted">Tags: <?php echo $item['tags']; ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['product_type']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>$<?php echo number_format($item['price'], 2); ?></td>
                    <td>$<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
