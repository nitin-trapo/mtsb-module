<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function debug_log($message) {
    echo "<!-- Debug: " . htmlspecialchars(print_r($message, true)) . " -->\n";
}

session_start();
require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    header('Location: login.php');
    exit;
}

$page_title = 'Dashboard';
$use_datatables = false; // We don't need datatables for this page

$db = new Database();
$conn = $db->getConnection();

// Get agent details
$stmt = $conn->prepare("
    SELECT c.* 
    FROM " . TABLE_CUSTOMERS . " c
    JOIN " . TABLE_USERS . " u ON c.email = u.email
    WHERE u.id = ? AND c.is_agent = 1
");
$stmt->execute([$_SESSION['user_id']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

debug_log("Agent Details:");
debug_log("- Agent ID: " . $agent['id']);
debug_log("- Agent Email: " . $agent['email']);

// Get agent statistics
$stats = [
    'total_orders' => 0,
    'total_commissions' => 0,
    'pending_commissions' => 0,
    'paid_commissions' => 0,
    'total_spent' => 0
];

// Get all orders for this agent from local database
$stmt = $conn->prepare("
    SELECT o.*, c.first_name, c.last_name,
           COALESCE(cm.status, 'pending') as commission_status,
           COALESCE(cm.amount, 0) as commission_amount
    FROM " . TABLE_ORDERS . " o
    LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
    LEFT JOIN " . TABLE_COMMISSIONS . " cm ON o.id = cm.order_id
    WHERE o.customer_id = ?
    ORDER BY o.created_at DESC
    LIMIT 10
");

debug_log("\nLocal Database Query:");
debug_log("- Query: " . str_replace('?', $agent['id'], $stmt->queryString));

$stmt->execute([$agent['id']]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

debug_log("- Number of local orders found: " . count($recent_orders));
if (empty($recent_orders)) {
    debug_log("- No local orders found for customer_id: " . $agent['id']);
} else {
    debug_log("- Local orders found: " . print_r($recent_orders, true));
}

// Get total orders count
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM " . TABLE_ORDERS . "
    WHERE customer_id = ?
");
$stmt->execute([$agent['id']]);
$stats['total_orders'] = $stmt->fetchColumn();

debug_log("- Total orders count: " . $stats['total_orders']);

// Get total spent
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_price), 0) as total_spent
    FROM " . TABLE_ORDERS . "
    WHERE customer_id = ?
");
$stmt->execute([$agent['id']]);
$stats['total_spent'] = $stmt->fetchColumn();

debug_log("- Total spent: " . $stats['total_spent']);

// Get total commissions
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_amount
    FROM " . TABLE_COMMISSIONS . "
    WHERE agent_id = ?
");
$stmt->execute([$agent['id']]);
$commission_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$stats['total_commissions'] = $commission_stats['pending_amount'] + $commission_stats['paid_amount'];
$stats['pending_commissions'] = $commission_stats['pending_amount'];
$stats['paid_commissions'] = $commission_stats['paid_amount'];

debug_log("- Commission stats: " . print_r($commission_stats, true));

// Get recent commissions with currency
$stmt = $conn->prepare("
    SELECT cm.*, o.order_number, o.currency
    FROM " . TABLE_COMMISSIONS . " cm
    LEFT JOIN " . TABLE_ORDERS . " o ON cm.order_id = o.id
    WHERE cm.agent_id = ?
    ORDER BY cm.created_at DESC 
    LIMIT 5
");
$stmt->execute([$agent['id']]);
$recent_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

debug_log("- Recent commissions: " . print_r($recent_commissions, true));

include 'includes/header.php'; ?>

<style>
.gap-2 {
    gap: 0.5rem !important;
}
.badge {
    white-space: nowrap;
}
</style>

<main class="flex-shrink-0">
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h2>Welcome, <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></h2>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Orders</h5>
                        <h2 class="mb-0"><?php echo number_format($stats['total_orders']); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Commissions</h5>
                        <h2 class="mb-0"><?php echo format_currency($stats['total_commissions'], $recent_orders[0]['currency'] ?? 'INR'); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">Pending Commissions</h5>
                        <h2 class="mb-0"><?php echo format_currency($stats['pending_commissions'], $recent_orders[0]['currency'] ?? 'INR'); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Spent</h5>
                        <h2 class="mb-0"><?php echo format_currency($stats['total_spent'], $recent_orders[0]['currency'] ?? 'INR'); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Recent Orders</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Order Amount</th>
                                        <th>Commission</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_orders)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No orders found. This could be because:
                                                <ul class="list-unstyled mt-2">
                                                    <li>1. No orders have been assigned to you yet</li>
                                                    <li>2. Orders haven't been synced from Shopify</li>
                                                    <li>3. Your agent ID might not be properly linked</li>
                                                </ul>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo format_currency($order['total_price'], $order['currency'] ?? 'INR'); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($order['commission_amount'] > 0): ?>
                                                    <span class="text-success">
                                                        <?php echo format_currency($order['commission_amount'], $order['currency'] ?? 'INR'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not calculated</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if ($order['commission_status'] == 'approved'): ?>
                                                        <span class="badge bg-success">Approved</span>
                                                    <?php elseif ($order['commission_status'] == 'pending'): ?>
                                                        <span class="badge bg-warning">Commission Pending</span>
                                                    <?php elseif ($order['commission_status'] == 'paid'): ?>
                                                        <span class="badge bg-success">Commission Paid</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Recent Commissions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_commissions)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center">No commissions found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_commissions as $commission): ?>
                                        <tr>
                                            <td><strong>#<?php echo htmlspecialchars($commission['order_number']); ?></strong></td>
                                            <td><?php echo format_currency($commission['amount'], $commission['currency'] ?? 'MYR'); ?></td>
                                            <td><span class="badge bg-<?php echo get_status_color($commission['status']); ?>"><?php echo ucfirst(htmlspecialchars($commission['status'])); ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
