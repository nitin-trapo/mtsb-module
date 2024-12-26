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
    echo json_encode(['error' => 'Customer ID is required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get customer details
$stmt = $conn->prepare("
    SELECT c.*, 
           COUNT(DISTINCT o.id) as total_orders,
           COALESCE(SUM(o.total_price), 0) as total_spent,
           MAX(o.currency) as currency
    FROM customers c
    LEFT JOIN orders o ON c.id = o.customer_id
    WHERE c.id = ?
    GROUP BY c.id
");
$stmt->execute([$_GET['id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

// Get recent orders
$stmt = $conn->prepare("
    SELECT o.*, 
           COALESCE(SUM(cm.amount), 0) as commission_amount
    FROM orders o
    LEFT JOIN commissions cm ON o.id = cm.order_id
    WHERE o.customer_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$stmt->execute([$_GET['id']]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get commission history if customer is an agent
$commission_history = [];
if ($customer['is_agent']) {
    $stmt = $conn->prepare("
        SELECT cm.*,
               o.order_number,
               o.total_price as order_amount,
               o.currency
        FROM commissions cm
        LEFT JOIN orders o ON cm.order_id = o.id
        WHERE cm.agent_id = ?
        ORDER BY cm.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_GET['id']]);
    $commission_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set default currency if none found
$currency = $customer['currency'] ?? 'INR';
$currency_symbol = $currency === 'INR' ? '₹' : $currency . ' ';
?>

<div class="row">
    <div class="col-md-6">
        <h5>Customer Information</h5>
        <table class="table">
            <tr>
                <th>Name:</th>
                <td><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
            </tr>
            <tr>
                <th>Email:</th>
                <td><?php echo htmlspecialchars($customer['email']); ?></td>
            </tr>
            <tr>
                <th>Shopify ID:</th>
                <td><?php echo htmlspecialchars($customer['shopify_customer_id']); ?></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td><span class="badge bg-<?php echo ($customer['status'] ?? 'active') === 'active' ? 'success' : 'danger'; ?>">
                    <?php echo ucfirst($customer['status'] ?? 'active'); ?>
                </span></td>
            </tr>
            <tr>
                <th>Agent Status:</th>
                <td><span class="badge bg-<?php echo $customer['is_agent'] ? 'success' : 'secondary'; ?>">
                    <?php echo $customer['is_agent'] ? 'Sales Agent' : 'Customer'; ?>
                </span></td>
            </tr>
            <?php if ($customer['is_agent']): ?>
            <tr>
                <th>Commission Rate:</th>
                <td><?php echo number_format($customer['commission_rate'], 2); ?>%</td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Total Orders:</th>
                <td><?php echo $customer['total_orders']; ?></td>
            </tr>
            <tr>
                <th>Total Spent:</th>
                <td><?php echo $currency_symbol . number_format($customer['total_spent'], 2); ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5>Recent Orders</h5>
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_orders as $order): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                    <td><?php 
                        $order_currency = $order['currency'] ?? $currency;
                        echo ($order_currency === 'INR' ? '₹' : $order_currency . ' ') . number_format($order['total_price'], 2); 
                    ?></td>
                    <td><span class="badge bg-<?php echo get_order_status_class($order['financial_status']); ?>">
                        <?php echo ucfirst($order['financial_status'] ?? 'pending'); ?>
                    </span></td>
                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($customer['is_agent'] && !empty($commission_history)): ?>
<div class="row mt-4">
    <div class="col-12">
        <h5>Recent Commissions</h5>
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Order Amount</th>
                    <th>Commission</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commission_history as $commission): ?>
                <tr>
                    <td><?php echo htmlspecialchars($commission['order_number']); ?></td>
                    <td><?php 
                        $comm_currency = $commission['currency'] ?? $currency;
                        echo ($comm_currency === 'INR' ? '₹' : $comm_currency . ' ') . number_format($commission['order_amount'], 2); 
                    ?></td>
                    <td><?php echo ($comm_currency === 'INR' ? '₹' : $comm_currency . ' ') . number_format($commission['amount'], 2); ?></td>
                    <td><span class="badge bg-<?php echo get_commission_status_class($commission['status'] ?? 'pending'); ?>">
                        <?php echo ucfirst($commission['status'] ?? 'pending'); ?>
                    </span></td>
                    <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
function get_order_status_class($status) {
    switch ($status) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'refunded':
            return 'danger';
        case 'partially_refunded':
            return 'info';
        default:
            return 'secondary';
    }
}

function get_commission_status_class($status) {
    switch ($status) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}
?>
