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
    echo json_encode(['error' => 'Commission ID is required']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get commission details with order and agent info
$stmt = $conn->prepare("
    SELECT cm.*,
           o.order_number,
           o.total_price as order_amount,
           o.shopify_order_id,
           c.first_name as agent_first_name,
           c.last_name as agent_last_name,
           c.email as agent_email,
           c.commission_rate as agent_commission_rate
    FROM commissions cm
    LEFT JOIN orders o ON cm.order_id = o.id
    LEFT JOIN customers c ON cm.agent_id = c.id
    WHERE cm.id = ?
");
$stmt->execute([$_GET['id']]);
$commission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$commission) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Commission not found']);
    exit;
}

// Get order items and their commission rules
$stmt = $conn->prepare("
    SELECT oi.*,
           cr.rule_type,
           cr.rule_value,
           cr.commission_percentage
    FROM order_items oi
    LEFT JOIN commission_rules cr ON 
        (cr.rule_type = 'product_type' AND cr.rule_value = oi.product_type) OR
        (cr.rule_type = 'product_tag' AND FIND_IN_SET(cr.rule_value, oi.tags))
    WHERE oi.order_id = ?
");
$stmt->execute([$commission['order_id']]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-md-6">
        <h5>Commission Information</h5>
        <table class="table">
            <tr>
                <th>Commission ID:</th>
                <td><?php echo $commission['id']; ?></td>
            </tr>
            <tr>
                <th>Order Number:</th>
                <td><?php echo $commission['order_number']; ?></td>
            </tr>
            <tr>
                <th>Order Amount:</th>
                <td>$<?php echo number_format($commission['order_amount'], 2); ?></td>
            </tr>
            <tr>
                <th>Commission Amount:</th>
                <td>$<?php echo number_format($commission['amount'], 2); ?></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td><span class="badge bg-<?php echo $commission['status'] === 'paid' ? 'success' : 
                    ($commission['status'] === 'approved' ? 'info' : 'warning'); ?>">
                    <?php echo ucfirst($commission['status']); ?>
                </span></td>
            </tr>
            <tr>
                <th>Created Date:</th>
                <td><?php echo date('M d, Y H:i:s', strtotime($commission['created_at'])); ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h5>Agent Information</h5>
        <table class="table">
            <tr>
                <th>Agent Name:</th>
                <td><?php echo $commission['agent_first_name'] . ' ' . $commission['agent_last_name']; ?></td>
            </tr>
            <tr>
                <th>Agent Email:</th>
                <td><?php echo $commission['agent_email']; ?></td>
            </tr>
            <tr>
                <th>Base Commission Rate:</th>
                <td><?php echo $commission['agent_commission_rate']; ?>%</td>
            </tr>
        </table>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <h5>Order Items & Commission Rules</h5>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Applied Rule</th>
                    <th>Commission %</th>
                    <th>Commission Amount</th>
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
                    <td>
                        <?php if ($item['rule_type']): ?>
                            <?php echo ucfirst($item['rule_type']) . ': ' . $item['rule_value']; ?>
                        <?php else: ?>
                            Default Rate
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['commission_percentage'] ?? $commission['agent_commission_rate']; ?>%</td>
                    <td>$<?php 
                        $commission_percentage = $item['commission_percentage'] ?? $commission['agent_commission_rate'];
                        $item_commission = ($item['price'] * $item['quantity']) * ($commission_percentage / 100);
                        echo number_format($item_commission, 2);
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row mt-3">
    <div class="col-12">
        <div class="d-flex justify-content-end">
            <?php if ($commission['status'] !== 'paid'): ?>
            <button type="button" class="btn btn-warning me-2" onclick="adjustCommission(<?php echo $commission['id']; ?>)">
                <i class="fas fa-edit"></i> Adjust Commission
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary me-2" onclick="generateInvoice(<?php echo $commission['id']; ?>)">
                <i class="fas fa-file-invoice"></i> Generate Invoice
            </button>
            <button type="button" class="btn btn-success" onclick="sendEmail(<?php echo $commission['id']; ?>)">
                <i class="fas fa-envelope"></i> Send Email
            </button>
        </div>
    </div>
</div>

<?php if ($commission['notes']): ?>
<div class="row mt-4">
    <div class="col-12">
        <h5>Notes</h5>
        <div class="card">
            <div class="card-body">
                <?php echo nl2br(htmlspecialchars($commission['notes'])); ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
