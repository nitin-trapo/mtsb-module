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
$use_datatables = false;

$db = new Database();
$conn = $db->getConnection();

// Get agent details
$stmt = $conn->prepare("
    SELECT * 
    FROM " . TABLE_CUSTOMERS . "
    WHERE email = ? AND is_agent = 1
");

$stmt->execute([$_SESSION['user_email']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {
    // Log the error
    debug_log("Failed to find agent with email: " . $_SESSION['user_email']);
    // Redirect back to login
    session_destroy();
    header('Location: login.php?error=invalid_agent');
    exit;
}

debug_log("Agent Details:");
debug_log($agent);

// Get agent statistics
$stats = [
    'total_orders' => 0,
    'total_commissions' => 0,
    'pending_commissions' => 0,
    'paid_commissions' => 0,
    'total_spent' => 0
];

// Get all orders for this agent
$stmt = $conn->prepare("
    SELECT 
        o.*,
        CASE 
            WHEN o.customer_id = ? THEN c1.first_name
            ELSE c2.first_name
        END as customer_first_name,
        CASE 
            WHEN o.customer_id = ? THEN c1.last_name
            ELSE c2.last_name
        END as customer_last_name,
        COALESCE(cm.status, 'pending') as commission_status,
        COALESCE(cm.amount, 0) as commission_amount
    FROM " . TABLE_ORDERS . " o
    LEFT JOIN " . TABLE_CUSTOMERS . " c1 ON o.customer_id = c1.id
    LEFT JOIN " . TABLE_CUSTOMERS . " c2 ON o.agent_id = c2.id
    LEFT JOIN " . TABLE_COMMISSIONS . " cm ON o.id = cm.order_id
    WHERE o.agent_id = ? OR o.customer_id = ?
    ORDER BY o.created_at DESC
    LIMIT 10
");

debug_log("Executing orders query for agent/customer ID: " . $agent['id']);
$stmt->execute([$agent['id'], $agent['id'], $agent['id'], $agent['id']]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
debug_log("Found " . count($recent_orders) . " recent orders");

// Get total orders count
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM " . TABLE_ORDERS . "
    WHERE agent_id = ? OR customer_id = ?
");
$stmt->execute([$agent['id'], $agent['id']]);
$stats['total_orders'] = $stmt->fetchColumn();

// Get total spent
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_price), 0) as total_spent
    FROM " . TABLE_ORDERS . "
    WHERE agent_id = ? OR customer_id = ?
");
$stmt->execute([$agent['id'], $agent['id']]);
$stats['total_spent'] = $stmt->fetchColumn();

// Get commission statistics
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

$stats['total_commissions'] = $commission_stats ? ($commission_stats['pending_amount'] + $commission_stats['paid_amount']) : 0;
$stats['pending_commissions'] = $commission_stats ? $commission_stats['pending_amount'] : 0;
$stats['paid_commissions'] = $commission_stats ? $commission_stats['paid_amount'] : 0;

// Get recent commissions
$stmt = $conn->prepare("
    SELECT cm.*, o.order_number, o.currency
    FROM " . TABLE_COMMISSIONS . " cm
    LEFT JOIN " . TABLE_ORDERS . " o ON cm.order_id = o.id
    WHERE cm.agent_id = ?
    ORDER BY cm.created_at DESC 
    LIMIT 5
");
$stmt->execute([$agent['id']]);
$recent_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

debug_log("Stats:");
debug_log($stats);
debug_log("Recent Commissions:");
debug_log($recent_commissions);

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
                                                <?php 
                                                $customer_name = trim($order['customer_first_name'] . ' ' . $order['customer_last_name']);
                                                echo htmlspecialchars($customer_name ?: 'N/A'); 
                                                ?>
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
                                                <?php
                                                $status_class = '';
                                                switch ($order['financial_status']) {
                                                    case 'paid':
                                                        $status_class = 'bg-success';
                                                        break;
                                                    case 'pending':
                                                        $status_class = 'bg-warning';
                                                        break;
                                                    case 'refunded':
                                                        $status_class = 'bg-danger';
                                                        break;
                                                    default:
                                                        $status_class = 'bg-secondary';
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <?php echo ucfirst($order['financial_status'] ?? 'unknown'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span title="<?php echo htmlspecialchars($order['created_at']); ?>">
                                                    <?php echo date('M j, Y', strtotime($order['created_at'])); ?>
                                                </span>
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
