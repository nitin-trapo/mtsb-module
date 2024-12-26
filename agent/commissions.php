<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get agent details
$stmt = $conn->prepare("
    SELECT c.* 
    FROM customers c
    JOIN users u ON c.email = u.email
    WHERE u.id = ? AND c.is_agent = 1
");
$stmt->execute([$_SESSION['user_id']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {
    die("Agent profile not found");
}

// Get all commissions for this agent with related details
$stmt = $conn->prepare("
    SELECT 
        cm.*,
        o.order_number,
        o.total_price as order_amount,
        o.currency,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email
    FROM commissions cm
    LEFT JOIN orders o ON cm.order_id = o.id
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE cm.agent_id = ?
    ORDER BY cm.created_at DESC
");
$stmt->execute([$agent['id']]);
$commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'pending' => 0,
    'approved' => 0,
    'paid' => 0
];

foreach ($commissions as $commission) {
    $totals[$commission['status']] += $commission['amount'];
}

// Set page title and enable DataTables
$page_title = 'My Commissions';
$use_datatables = true;

// Include header
include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2>My Commissions</h2>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Commissions</h5>
                    <h3>RM <?php echo number_format($totals['pending'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Approved Commissions</h5>
                    <h3>RM <?php echo number_format($totals['approved'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Paid Commissions</h5>
                    <h3>RM <?php echo number_format($totals['paid'], 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Commissions Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="commissionsTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Order Amount</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commissions as $commission): 
                            $currency = $commission['currency'] ?? 'MYR';
                            $currency_symbol = $currency === 'MYR' ? 'RM ' : ($currency_symbols[$currency] ?? $currency);
                        ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo htmlspecialchars($commission['order_number']); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    echo htmlspecialchars($commission['customer_first_name'] . ' ' . $commission['customer_last_name']);
                                    if ($commission['customer_email']) {
                                        echo '<br><small class="text-muted">' . htmlspecialchars($commission['customer_email']) . '</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong><?php echo $currency_symbol . number_format($commission['order_amount'], 2); ?></strong>
                                </td>
                                <td>
                                    <strong class="text-success">
                                        <?php echo $currency_symbol . number_format($commission['amount'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo get_status_color($commission['status']); ?>">
                                        <?php echo ucfirst(htmlspecialchars($commission['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($commission['created_at'])); ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $commission['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Commission Details Modal -->
<div class="modal fade" id="commissionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Commission Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Destroy existing DataTable instance if it exists
    if ($.fn.DataTable.isDataTable('#commissionsTable')) {
        $('#commissionsTable').DataTable().destroy();
    }
    
    // Initialize DataTable
    $('#commissionsTable').DataTable({
        pageLength: 25,
        order: [[5, 'desc']],
        language: {
            search: "Search commissions:",
            lengthMenu: "Show _MENU_ commissions per page",
            info: "Showing _START_ to _END_ of _TOTAL_ commissions",
            infoEmpty: "No commissions available",
            emptyTable: "No commissions found"
        }
    });
});

function viewDetails(commissionId) {
    const modal = new bootstrap.Modal(document.getElementById('commissionModal'));
    $('#commissionModal .modal-body').html('<div class="text-center p-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading commission details...</p></div>');
    modal.show();
    
    $.post('ajax/get_commission_details.php', { commission_id: commissionId })
        .done(function(response) {
            $('#commissionModal .modal-body').html(response);
        })
        .fail(function(xhr, status, error) {
            $('#commissionModal .modal-body').html('<div class="alert alert-danger m-3">Error loading commission details: ' + error + '</div>');
        });
}
</script>

<?php include 'includes/footer.php'; ?>
