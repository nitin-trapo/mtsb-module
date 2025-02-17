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
    echo json_encode(['error' => 'Agent ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get agent details with total orders and spent amount
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            c.business_registration_number,
            c.tax_identification_number,
            c.ic_number,
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(o.total_price), 0) as total_spent,
            o.currency
        FROM customers c
        LEFT JOIN orders o ON c.id = o.customer_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$_GET['id']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Agent not found']);
        exit;
    }

    // Parse bank statement header if exists
    $bankStatementInfo = null;
    if (!empty($agent['bank_account_header'])) {
        $bankStatementInfo = json_decode($agent['bank_account_header'], true);
    }

    // Get recent orders
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            COALESCE(SUM(c.amount), 0) as commission_amount
        FROM orders o
        LEFT JOIN commissions c ON o.id = c.order_id
        WHERE o.customer_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$agent['id']]);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Basic Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <tr>
                        <th width="40%">Name</th>
                        <td><?php echo !empty($agent['first_name']) || !empty($agent['last_name']) ? 
                            htmlspecialchars(trim($agent['first_name'] . ' ' . $agent['last_name'])) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><?php echo !empty($agent['email']) ? htmlspecialchars($agent['email']) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Phone</th>
                        <td><?php echo !empty($agent['phone']) ? htmlspecialchars($agent['phone']) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <span class="badge bg-<?php echo $agent['status'] == 'active' ? 'success' : 'danger'; ?>">
                                <?php echo ucfirst($agent['status'] ?? 'inactive'); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Total Orders</th>
                        <td><?php echo $agent['total_orders'] > 0 ? number_format($agent['total_orders']) : '0'; ?></td>
                    </tr>
                    <tr>
                        <th>Total Spent</th>
                        <td><?php echo $agent['total_spent'] > 0 ? 
                            number_format($agent['total_spent'], 2) . ' ' . $agent['currency'] : 
                            '0.00 ' . ($agent['currency'] ?? 'USD'); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Registration Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <tr>
                        <th width="40%">Business Registration No.</th>
                        <td><?php echo htmlspecialchars($agent['business_registration_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Tax Identification No. (TIN)</th>
                        <td><?php echo htmlspecialchars($agent['tax_identification_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>IC Number</th>
                        <td><?php echo htmlspecialchars($agent['ic_number'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Recent Orders</h5>
            </div>
            <div class="card-body">
                <?php if ($recent_orders): ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $order): ?>
                        <tr>
                            <td><?php echo $order['order_number']; ?></td>
                            <td><?php echo date('M j, Y', strtotime($order['created_at'])); ?></td>
                            <td><?php echo number_format($order['total_price'], 2) . ' ' . $order['currency']; ?></td>
                            <td><?php echo number_format($order['commission_amount'], 2) . ' ' . $order['currency']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted">No orders found.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($agent['is_agent']): ?>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Bank Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <tr>
                        <th width="40%">Bank Name</th>
                        <td><?php echo !empty($agent['bank_name']) ? htmlspecialchars($agent['bank_name']) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Bank Account</th>
                        <td><?php echo !empty($agent['bank_account_number']) ? htmlspecialchars($agent['bank_account_number']) : 'N/A'; ?></td>
                    </tr>
                    <?php if (!empty($bankStatementInfo) && !empty($bankStatementInfo['url'])): ?>
                    <tr>
                        <th>Bank Statement</th>
                        <td>
                            <a href="<?php echo htmlspecialchars($bankStatementInfo['url']); ?>" 
                               target="_blank" 
                               class="btn btn-sm btn-primary">
                                <i class="<?php echo getFileIcon($bankStatementInfo['extension']); ?> me-1"></i>
                                View Statement
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

function getFileIcon($extension) {
    switch (strtolower($extension)) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'jpeg':
        case 'png':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
    }
}
