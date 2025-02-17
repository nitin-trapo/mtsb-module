<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php-error.log');

// Start session and include required files
session_start();

try {
    require_once '../config/database.php';
    require_once '../includes/functions.php';
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// For testing purposes, set admin role
$_SESSION['role'] = 'admin';

// Check if user is logged in and is admin
if (!function_exists('is_logged_in') || !function_exists('is_admin')) {
    die("Required functions are not defined");
}

if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit;
}

// Initialize database connection
try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Get dashboard statistics
$stats = [
    'total_agents' => 0,
    'total_orders' => 0,
    'total_commissions' => 0,
    'pending_commissions' => 0
];

try {
    // Get total agents
    $stmt = $conn->query("SELECT COUNT(*) FROM customers WHERE is_agent = 1 AND status = 'active'");
    $stats['total_agents'] = $stmt->fetchColumn();

    // Get total commissions with debugging
    $commission_query = "
        SELECT 
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(*) as total_count,
            GROUP_CONCAT(DISTINCT status) as statuses
        FROM commissions 
        WHERE status != 'cancelled'";
    
    $stmt = $conn->query($commission_query);
    $commission_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_commissions'] = $commission_stats['total_amount'];
    
    // Debug info
    error_log("Commission Stats: " . print_r($commission_stats, true));
    error_log("Query: " . $commission_query);

    // Get total orders
    $stmt = $conn->query("SELECT COUNT(*) FROM orders");
    $stats['total_orders'] = $stmt->fetchColumn();

    // Get pending commissions
    $stmt = $conn->query("SELECT COUNT(*) FROM commissions WHERE status = 'pending'");
    $stats['pending_commissions'] = $stmt->fetchColumn();

    // Get recent orders
    $stmt = $conn->query("
        SELECT 
            o.shopify_order_id,
            o.order_number,
            o.total_price,
            o.currency,
            o.created_at,
            o.financial_status,
            o.fulfillment_status,
            c.first_name,
            c.last_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        ORDER BY o.created_at DESC 
        LIMIT 5
    ");
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get store currency for stats
    $stmt = $conn->query("SELECT currency FROM orders ORDER BY created_at DESC LIMIT 1");
    $default_currency = $stmt->fetchColumn() ?: 'USD';

    // Get recent commissions
    $stmt = $conn->query("
        SELECT 
            cm.*, 
            c.first_name, 
            c.last_name,
            o.currency
        FROM commissions cm
        LEFT JOIN customers c ON cm.agent_id = c.id 
        LEFT JOIN orders o ON cm.order_id = o.id
        ORDER BY cm.created_at DESC 
        LIMIT 5
    ");
    $recent_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Query Error: " . $e->getMessage());
    die("Error fetching dashboard data: " . $e->getMessage());
}

// Set page title and include header
$page_title = "Dashboard";
try {
    include 'includes/header.php';
} catch (Exception $e) {
    die("Error loading header: " . $e->getMessage());
}
?>

<div class="container-fluid py-4">
    <!-- Stats Cards Row -->
    <div class="row">
        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card stats-card agents">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">Total Agents</h5>
                            <h3 class="mb-0"><?php echo number_format($stats['total_agents']); ?></h3>
                        </div>
                        <div class="avatar avatar-lg ">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card stats-card orders">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">Total Orders</h5>
                            <h3 class="mb-0"><?php echo number_format($stats['total_orders']); ?></h3>
                        </div>
                        <div class="avatar avatar-lg">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card stats-card commissions">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">Total Commissions</h5>
                            <h3 class="mb-0"><?php 
                                $currency = $default_currency;
                                $symbol = match($currency) {
                                    'USD' => '$',
                                    'EUR' => '€',
                                    'GBP' => '£',
                                    'INR' => '₹',
                                    'MYR' => 'RM ',
                                    default => $currency . ' '
                                };
                                echo $symbol . number_format($stats['total_commissions'], 2); 
                            ?></h3>
                        </div>
                        <div class="avatar avatar-lg">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card stats-card pending">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">Pending Commissions</h5>
                            <h3 class="mb-0"><?php echo number_format($stats['pending_commissions']); ?></h3>
                        </div>
                        <div class="avatar avatar-lg">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Data Row -->
    <div class="row">
        <!-- Recent Orders -->
        <div class="col-xl-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Recent Orders</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Order Number</th>
                                    <th>Agent</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_orders)): ?>
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                                        <td><?php 
                                            $currency = $order['currency'] ?: $default_currency;
                                            $symbol = match($currency) {
                                                'USD' => '$',
                                                'EUR' => '€',
                                                'GBP' => '£',
                                                'INR' => '₹',
                                                'MYR' => 'RM ',
                                                default => $currency . ' '
                                            };
                                            echo $symbol . number_format($order['total_price'], 2); 
                                        ?></td>
                                        <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($order['financial_status']) {
                                                    'paid' => 'success',
                                                    'pending' => 'warning',
                                                    'refunded' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($order['financial_status'] ?? 'unknown'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No recent orders</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Commissions -->
        <div class="col-xl-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Recent Commissions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_commissions)): ?>
                                    <?php foreach ($recent_commissions as $commission): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($commission['first_name'] . ' ' . $commission['last_name']); ?></td>
                                        <td><?php 
                                            $currency = $commission['currency'] ?: $default_currency;
                                            $symbol = match($currency) {
                                                'USD' => '$',
                                                'EUR' => '€',
                                                'GBP' => '£',
                                                'INR' => '₹',
                                                'MYR' => 'RM ',
                                                default => $currency . ' '
                                            };
                                            echo $symbol . number_format($commission['amount'], 2); 
                                        ?></td>
                                        <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $commission['status'] === 'paid' ? 'success' : 
                                                    ($commission['status'] === 'pending' ? 'warning' : 'secondary'); 
                                            ?>">
                                                <?php echo ucfirst(htmlspecialchars($commission['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No commissions found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
try {
    include 'includes/footer.php';
} catch (Exception $e) {
    error_log("Error loading footer: " . $e->getMessage());
    echo "</div></body></html>";
}
?>
