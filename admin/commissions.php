<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Handle commission status update
if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $commission_id = $_POST['commission_id'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE commissions SET status = ? WHERE id = ?");
    $stmt->execute([$status, $commission_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle commission amount update
if (isset($_POST['action']) && $_POST['action'] == 'update_amount') {
    $commission_id = $_POST['commission_id'];
    $amount = $_POST['amount'];
    
    $stmt = $conn->prepare("UPDATE commissions SET amount = ? WHERE id = ?");
    $stmt->execute([$amount, $commission_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Get all commissions with related details
$stmt = $conn->query("
    SELECT 
        cm.*,
        o.order_number,
        o.total_price as order_amount,
        c.first_name as agent_first_name,
        c.last_name as agent_last_name,
        c.email as agent_email
    FROM commissions cm
    LEFT JOIN orders o ON cm.order_id = o.id
    LEFT JOIN customers c ON cm.agent_id = c.id
    ORDER BY cm.created_at DESC
");
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

// Set page title
$page_title = 'Manage Commissions';

// Include header
include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2>Manage Commissions</h2>
        </div>
        <div class="col text-end">
            <button type="button" class="btn btn-success me-2" id="refreshAllCommissions">
                <i class="fas fa-sync-alt"></i> Refresh All Commissions
            </button>
            <button type="button" class="btn btn-primary" id="calculateAllCommissions">
                Calculate All Commissions
            </button>
        </div>
    </div>

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

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="commissionsTable" class="table table-striped">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Agent</th>
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
                                <?php echo $commission['agent_first_name'] . ' ' . $commission['agent_last_name']; ?>
                                <br>
                                <small class="text-muted"><?php echo $commission['agent_email']; ?></small>
                            </td>
                            <td>RM <?php echo number_format($commission['order_amount'], 2); ?></td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">RM</span>
                                    <input type="number" class="form-control form-control-sm commission-amount" 
                                           value="<?php echo $commission['amount']; ?>" 
                                           onchange="updateAmount(<?php echo $commission['id']; ?>, this.value)"
                                           step="0.01" min="0">
                                </div>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" 
                                        onchange="updateStatus(<?php echo $commission['id']; ?>, this.value)">
                                    <option value="pending" <?php echo $commission['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $commission['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="paid" <?php echo $commission['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                </select>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="viewDetails(<?php echo $commission['id']; ?>)">
                                    View
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
            order: [[5, 'desc']]
        });
        
        // Refresh All Commissions
        $('#refreshAllCommissions').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true)
               .html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Refreshing...');
            
            // Use calculate_all_commissions with refresh parameter
            $.ajax({
                url: 'ajax/calculate_all_commissions.php',
                method: 'POST',
                data: { refresh: 'true' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        toastr.success(response.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        toastr.error(response.message || 'Failed to refresh commissions');
                        btn.prop('disabled', false)
                           .html('<i class="fas fa-sync-alt"></i> Refresh All Commissions');
                    }
                },
                error: function(xhr) {
                    toastr.error('Error refreshing commissions');
                    btn.prop('disabled', false)
                       .html('<i class="fas fa-sync-alt"></i> Refresh All Commissions');
                }
            });
        });
        
        // Calculate All Commissions
        $('#calculateAllCommissions').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Calculating...');
            
            $.ajax({
                url: 'ajax/calculate_all_commissions.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        toastr.success(response.message);
                        if (response.processed_orders.length > 0) {
                            // Refresh the page to show new commissions
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        }
                    } else {
                        toastr.error(response.message || 'Failed to calculate commissions');
                    }
                },
                error: function(xhr) {
                    toastr.error('Error calculating commissions');
                },
                complete: function() {
                    btn.prop('disabled', false).text('Calculate All Commissions');
                }
            });
        });
    });

    function updateAmount(commissionId, amount) {
        $.post('commissions.php', {
            action: 'update_amount',
            commission_id: commissionId,
            amount: amount
        }, function(response) {
            if (!response.success) {
                alert('Error updating amount');
            }
        });
    }

    function updateStatus(commissionId, status) {
        $.post('commissions.php', {
            action: 'update_status',
            commission_id: commissionId,
            status: status
        }, function(response) {
            if (response.success) {
                location.reload(); // Reload page after successful status update
            } else {
                alert('Error updating status');
            }
        });
    }

    function viewDetails(commissionId) {
        const modal = new bootstrap.Modal(document.getElementById('commissionModal'));
        $('#commissionModal .modal-body').html('<div class="text-center p-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading commission details...</p></div>');
        modal.show();
        
        $.get('ajax/get_commission_details.php', { id: commissionId })
            .done(function(response) {
                $('#commissionModal .modal-body').html(response);
            })
            .fail(function(xhr, status, error) {
                $('#commissionModal .modal-body').html('<div class="alert alert-danger m-3">Error loading commission details: ' + error + '</div>');
            });
    }

    function generateInvoice(commissionId) {
        window.location.href = 'generate_invoice.php?commission_id=' + commissionId;
    }
</script>

<?php include 'includes/footer.php'; ?>
