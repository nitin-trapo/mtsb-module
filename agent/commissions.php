<?php
session_start();
require_once '../config/database.php';
require_once '../config/tables.php';
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
    FROM " . TABLE_CUSTOMERS . " c
    WHERE c.email = ? AND c.is_agent = 1
");
$stmt->execute([$_SESSION['user_email']]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agent) {
    die("Agent profile not found");
}

// Get all commissions for this agent with related details, excluding pending status
$stmt = $conn->prepare("
    SELECT 
        cm.*,
        o.order_number,
        o.total_price as order_amount,
        o.currency,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email,
        DATE_FORMAT(cm.created_at, '%b %d, %Y') as formatted_date
    FROM " . TABLE_COMMISSIONS . " cm
    LEFT JOIN " . TABLE_ORDERS . " o ON cm.order_id = o.id
    LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
    WHERE cm.agent_id = ? AND cm.status != 'pending'
    ORDER BY cm.created_at DESC
");
$stmt->execute([$agent['id']]);
$commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'approved' => 0,
    'paid' => 0
];

foreach ($commissions as $commission) {
    if (isset($totals[$commission['status']])) {
        $totals[$commission['status']] += $commission['amount'];
    }
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
        <div class="col-md-6">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Approved Commissions</h5>
                    <h3>RM <?php echo number_format($totals['approved'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Paid Commissions</h5>
                    <h3>RM <?php echo number_format($totals['paid'], 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="commissionsTable" class="table table-striped">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Order Amount</th>
                            <th>Commission Amount</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commissions as $commission): ?>
                        <tr>
                            <td><?php echo $commission['order_number']; ?></td>
                            <td>
                                <?php echo $commission['customer_first_name'] . ' ' . $commission['customer_last_name']; ?><br>
                                <small class="text-muted"><?php echo $commission['customer_email']; ?></small>
                            </td>
                            <td>RM <?php echo number_format($commission['order_amount'], 2); ?></td>
                            <td>
                                <div>
                                    RM <?php echo number_format($commission['amount'], 2); ?>
                                    <?php if (!empty($commission['adjustment_reason'])): ?>
                                        <span class="badge bg-info" title="This commission was adjusted">
                                            <i class="fas fa-info-circle"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php 
                                    if ($commission['status'] === 'paid') {
                                        echo 'bg-success';
                                    } elseif ($commission['status'] === 'approved') {
                                        echo 'bg-warning';
                                    } else {
                                        echo 'bg-secondary';
                                    }
                                ?> text-white">
                                    <?php echo ucfirst($commission['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $commission['formatted_date']; ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="viewDetails(<?php echo $commission['id']; ?>)">
                                    <i class="fas fa-eye me-1"></i>View
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
<div class="modal fade" id="commissionModal" tabindex="-1" aria-labelledby="commissionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="commissionModalLabel">Commission Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="loadingSpinner" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading commission details...</p>
                </div>
                <div id="modalContent" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#commissionsTable').DataTable({
        order: [[5, 'desc']], // Sort by date column by default
        pageLength: 25,
        language: {
            search: "Search commissions:",
            lengthMenu: "Show _MENU_ commissions per page",
            info: "Showing _START_ to _END_ of _TOTAL_ commissions",
            infoEmpty: "Showing 0 to 0 of 0 commissions",
            infoFiltered: "(filtered from _MAX_ total commissions)"
        }
    });
});

function viewDetails(commissionId) {
    const modal = new bootstrap.Modal(document.getElementById('commissionModal'));
    const modalBody = $('#commissionModal .modal-body');
    
    // Show loading spinner
    modalBody.html('<div class="text-center p-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading commission details...</p></div>');
    modal.show();
    
    $.post('ajax/get_commission_details.php', { commission_id: commissionId })
        .done(function(response) {
            if (response.success) {
                modalBody.html(response.html);
            } else {
                modalBody.html('<div class="alert alert-danger">' + (response.error || 'An error occurred while loading commission details.') + '</div>');
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            modalBody.html('<div class="alert alert-danger">Failed to load commission details. Please try again later.</div>');
        });
}
</script>

<?php include 'includes/footer.php'; ?>
