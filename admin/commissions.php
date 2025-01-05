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
$query = "
    SELECT 
        cm.*,
        o.order_number,
        o.total_price as order_amount,
        c.first_name as agent_first_name,
        c.last_name as agent_last_name,
        c.email as agent_email,
        CONCAT(c.first_name, ' ', c.last_name) as agent_name,
        cm.adjustment_reason
    FROM commissions cm
    LEFT JOIN orders o ON cm.order_id = o.id
    LEFT JOIN customers c ON cm.agent_id = c.id
    ORDER BY cm.created_at DESC
";
$commissions = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

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
                                <?php echo $commission['agent_name']; ?><br>
                                <small class="text-muted"><?php echo $commission['agent_email']; ?></small>
                            </td>
                            <td>RM <?php echo number_format($commission['order_amount'], 2); ?></td>
                            <td>
                                <div>
                                    RM <?php echo number_format($commission['amount'], 2); ?>
                                    <?php if (!empty($commission['adjustment_reason'])): ?>
                                        <span class="badge bg-info" title="This commission was adjusted">
                                            <i class="fas fa-edit"></i>
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
                                    } elseif ($commission['status'] === 'pending') {
                                        echo 'bg-info';
                                    } else {
                                        echo 'bg-primary';
                                    }
                                ?> text-white">
                                    <?php echo ucfirst($commission['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="viewDetails(<?php echo $commission['id']; ?>)">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="deleteCommission(<?php echo $commission['id']; ?>)">
                                    <i class="fas fa-trash me-1"></i>Delete
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
                        alert(response.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        alert(response.message || 'Failed to refresh commissions');
                        btn.prop('disabled', false)
                           .html('<i class="fas fa-sync-alt"></i> Refresh All Commissions');
                    }
                },
                error: function(xhr) {
                    alert('Error refreshing commissions');
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
                        alert(response.message);
                        if (response.processed_orders.length > 0) {
                            // Refresh the page to show new commissions
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        }
                    } else {
                        alert(response.message || 'Failed to calculate commissions');
                    }
                },
                error: function(xhr) {
                    alert('Error calculating commissions');
                },
                complete: function() {
                    btn.prop('disabled', false).text('Calculate All Commissions');
                }
            });
        });
    });

    function viewDetails(commissionId) {
        const modal = $('#commissionModal');
        const modalBody = modal.find('.modal-body');
        
        // Show loading spinner
        modalBody.html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary mb-2" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div>Loading commission details...</div>
            </div>
        `);
        
        modal.modal('show');
        
        $.get('ajax/get_commission_details.php', { id: commissionId })
            .done(function(response) {
                if (response.success) {
                    modalBody.html(response.html);
                } else {
                    modalBody.html(
                        '<div class="alert alert-danger">' + 
                        (response.error || 'Failed to load commission details') + 
                        '</div>'
                    );
                }
            })
            .fail(function() {
                modalBody.html(
                    '<div class="alert alert-danger">Failed to load commission details</div>'
                );
            });
    }

    function deleteCommission(commissionId) {
        if (!confirm('Are you sure you want to delete this commission? This action cannot be undone.')) {
            return;
        }

        $.post('ajax/delete_commission.php', {
            commission_id: commissionId
        })
        .done(function(response) {
            if (response.success) {
                alert('Commission deleted successfully');
                // Reload the page to refresh the commission list
                location.reload();
            } else {
                alert(response.error || 'Failed to delete commission');
            }
        })
        .fail(function() {
            alert('Failed to delete commission');
        });
    }
</script>

<?php include 'includes/footer.php'; ?>
