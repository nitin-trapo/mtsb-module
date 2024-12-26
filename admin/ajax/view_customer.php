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

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get customer details with total orders and spent amount
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(o.total_price), 0) as total_spent,
            o.currency
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
        SELECT 
            o.*,
            COALESCE(cm.amount, 0) as commission_amount,
            cm.status as commission_status,
            COALESCE(o.financial_status, 'pending') as financial_status,
            DATE_FORMAT(o.processed_at, '%b %d, %Y') as formatted_processed_date
        FROM orders o
        LEFT JOIN commissions cm ON o.id = cm.order_id
        WHERE o.customer_id = ?
        ORDER BY 
            CASE WHEN o.processed_at IS NULL THEN 1 ELSE 0 END,
            o.processed_at DESC,
            o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customer['id']]);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format currency symbol
    $currency_symbol = match($customer['currency']) {
        'INR' => '₹',
        'MYR' => 'RM ',
        default => $customer['currency'] . ' '
    };
?>

<div class="row">
    <div class="col-md-6">
        <h5>Customer Information</h5>
        <table class="table">
            <tr>
                <th>Customer ID:</th>
                <td><?php echo $customer['shopify_customer_id']; ?></td>
            </tr>
            <tr>
                <th>Name:</th>
                <td><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
            </tr>
            <tr>
                <th>Email:</th>
                <td><?php echo htmlspecialchars($customer['email']); ?></td>
            </tr>
            <tr>
                <th>Phone:</th>
                <td><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></td>
            </tr>
            <tr>
                <th>Total Orders:</th>
                <td><?php echo $customer['total_orders']; ?></td>
            </tr>
            <tr>
                <th>Total Spent:</th>
                <td><?php echo $currency_symbol . number_format($customer['total_spent'], 2); ?></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td>
                    <span class="badge bg-<?php echo $customer['status'] === 'active' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($customer['status']); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Agent Status:</th>
                <td>
                    <span class="badge bg-<?php echo $customer['is_agent'] ? 'info' : 'secondary'; ?>">
                        <?php echo $customer['is_agent'] ? 'Agent' : 'Customer'; ?>
                    </span>
                </td>
            </tr>
            <?php if ($customer['is_agent']): ?>
            <tr>
                <th>Commission Rate:</th>
                <td><?php echo $customer['commission_rate']; ?>%</td>
            </tr>
            <?php endif; ?>

        </table>
    </div>

    <div class="col-md-6">
        <h5>Recent Orders</h5>
        <?php if ($recent_orders): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Processed Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_orders as $order): ?>
                <tr>
                    <td><?php echo $order['order_number']; ?></td>
                    <td><?php echo $order['formatted_processed_date'] ?: 'Not processed'; ?></td>
                    <td><?php echo $currency_symbol . number_format($order['total_price'], 2); ?></td>
                    <td>
                        <span class="badge bg-<?php 
                            echo $order['financial_status'] === 'paid' ? 'success' : 
                                ($order['financial_status'] === 'pending' ? 'warning' : 'info'); 
                        ?>">
                            <?php echo ucfirst($order['financial_status'] ?? 'pending'); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted">No orders found for this customer.</p>
        <?php endif; ?>
    </div>
</div>

<?php
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>
