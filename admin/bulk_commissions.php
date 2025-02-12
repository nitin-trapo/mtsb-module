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

// Get list of agents
$stmt = $conn->prepare("
    SELECT 
        email,
        first_name,
        last_name 
    FROM customers 
    WHERE is_agent = 1 
    ORDER BY email
");
$stmt->execute();
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
    #commissionModal .adjust-commission{
        display: none;
    }
    #commissionModal .modal-footer .send-invoice,
    #commissionModal .modal-footer .view-invoice{
        display:none;
    }
    #commissionModal .mark-as-paid{
        display:none;
    }
    #commissionModal .approve-commission{
        display:none;
    }
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2>Bulk Commissions</h2>
        </div>
    </div>

    <!-- Search Form -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="searchForm">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label for="agentEmailSearch">Agent Email</label>
                            <select class="form-select" id="agentEmailSearch" name="agent_email">
                                <option value="">All Agents</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo htmlspecialchars($agent['email']); ?>">
                                        <?php echo htmlspecialchars($agent['email']); ?> (<?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label for="commissionStatus">Commission Status</label>
                            <select class="form-select" id="commissionStatus" name="status">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group w-100">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="row mb-4" id="statusCards">
        <div class="col-md-6">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Commissions</h5>
                    <h3><span id="pendingCount">0</span></h3>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Approved Commissions</h5>
                    <h3><span id="approvedCount">0</span></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Commissions Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Commission List</h5>
            <div class="bulk-actions" style="display: none;">
                <button type="button" class="btn btn-warning me-2" id="approveSelected" disabled>
                    <i class="fas fa-check me-1"></i>Approve Selected
                </button>
                <button type="button" class="btn btn-success" id="markPaidSelected" disabled>
                    <i class="fas fa-dollar-sign me-1"></i>Mark Paid Selected
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="commissionsTable" class="table table-striped">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" class="form-check-input" id="selectAll">
                            </th>
                            <th>Order #</th>
                            <th>Agent</th>
                            <th>Order Amount</th>
                            <th>Commission Amount</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Total Commission Amount: <span id="totalCommissionAmount">RM 0.00</span></h5>
                            <p class="mb-0">Processing payment for <span id="selectedCommissionCount">0</span> commission(s)</p>
                        </div>
                    </div>
                </div>

                <form id="paymentForm" class="needs-validation" novalidate>
                    <div class="form-group mb-3">
                        <label for="paymentReceipt" class="form-label">Payment Receipt <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="paymentReceipt" name="payment_receipt" 
                            accept=".pdf,image/*" required>
                        <div class="form-text">Upload PDF or image file of payment receipt</div>
                        <div class="invalid-feedback">Please upload a payment receipt</div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="paymentNotes" class="form-label">Payment Notes</label>
                        <textarea class="form-control" id="paymentNotes" name="payment_notes" rows="3" 
                            placeholder="Enter any additional notes about this payment"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" id="processPayment">
                    <i class="fas fa-check me-1"></i>Process Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Commission Details Modal -->
<div class="modal fade" id="commissionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <!-- Modal content will be loaded here -->
        </div>
    </div>
</div>

<!-- Add Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Add DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    let selectedCommissions = new Set();
    let table;
    
    // Initialize Select2
    $('#agentEmailSearch').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    // Initialize DataTable
    table = $('#commissionsTable').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: 'ajax/get_agent_commissions.php',
            data: function(d) {
                return {
                    email: $('#agentEmailSearch').val(),
                    status: $('#commissionStatus').val()
                };
            },
            dataSrc: function(json) {
                if (json.counts) {
                    $('#pendingCount').text(json.counts.pending || 0);
                    $('#approvedCount').text(json.counts.approved || 0);
                }
                return json.data || [];
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                render: function(data) {
                    let disabled = data.status !== 'pending' && data.status !== 'approved';
                    return '<input type="checkbox" class="form-check-input commission-checkbox" ' +
                           'value="' + data.id + '" ' +
                           (disabled ? 'disabled' : '') + '>';
                }
            },
            { 
                data: 'order_number',
                render: function(data) {
                    return data || 'N/A';
                }
            },
            { 
                data: null,
                render: function(data) {
                    return data.agent_name + '<br><small class="text-muted">' + data.agent_email + '</small>';
                }
            },
            { 
                data: 'order_amount',
                render: function(data) {
                    return 'RM ' + data;
                }
            },
            { 
                data: null,
                render: function(data) {
                    var amount = data.adjustment_reason ? data.amount : data.actual_commission;
                    var html = 'RM ' + Number(amount).toFixed(2);
                    if (data.adjustment_reason) {
                        html += ' <span class="badge bg-info" title="This commission was adjusted"><i class="fas fa-edit"></i></span>';
                    }
                    return html;
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    var bgClass = '';
                    switch(data.toLowerCase()) {
                        case 'paid':
                            bgClass = 'bg-success';
                            break;
                        case 'approved':
                            bgClass = 'bg-warning';
                            break;
                        case 'pending':
                            bgClass = 'bg-info';
                            break;
                        case 'calculated':
                            bgClass = 'bg-primary';
                            break;
                        default:
                            bgClass = 'bg-secondary';
                    }
                    return '<span class="badge ' + bgClass + ' text-white">' + 
                           data.charAt(0).toUpperCase() + data.slice(1) + 
                           '</span>';
                }
            },
            { 
                data: 'created_at'
            },
            {
                data: null,
                orderable: false,
                render: function(data) {
                    return '<button type="button" class="btn btn-sm btn-info" onclick="viewDetails(' + data.id + ')">' +
                           '<i class="fas fa-eye me-1"></i>View</button> ' +
                           '<button type="button" class="btn btn-sm btn-danger" onclick="deleteCommission(' + data.id + ')">' +
                           '<i class="fas fa-trash me-1"></i>Delete</button>';
                }
            }
        ],
        order: [[6, 'desc']],
        pageLength: 25,
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
        },
        deferLoading: 0
    });

    // Reset counts initially
    $('#pendingCount').text('0');
    $('#approvedCount').text('0');

    function updateBulkActionButtons() {
        const hasSelection = selectedCommissions.size > 0;
        $('.bulk-actions').toggle(hasSelection);
        
        // Get statuses of selected commissions and calculate total
        const selectedRows = Array.from(selectedCommissions).map(id => {
            return table.rows().data().toArray().find(row => row.id.toString() === id);
        }).filter(row => row);
        
        const hasPending = selectedRows.some(row => row.status === 'pending');
        const hasApproved = selectedRows.some(row => row.status === 'approved');
        
        // Calculate total commission amount
        const totalCommission = selectedRows.reduce((sum, row) => {
            const amount = row.adjustment_reason ? parseFloat(row.amount) : parseFloat(row.actual_commission);
            return sum + (isNaN(amount) ? 0 : amount);
        }, 0);
        
        $('#totalCommissionAmount').text('RM ' + totalCommission.toFixed(2));
        $('#selectedCommissionCount').text(selectedRows.length);
        
        $('#approveSelected').prop('disabled', !hasPending);
        $('#markPaidSelected').prop('disabled', !hasApproved);
    }

    // Handle select all checkbox
    $('#selectAll').on('change', function() {
        const isChecked = $(this).prop('checked');
        $('.commission-checkbox:not(:disabled)').prop('checked', isChecked).trigger('change');
    });

    // Handle individual checkboxes
    $(document).on('change', '.commission-checkbox', function() {
        const $checkbox = $(this);
        const commissionId = $checkbox.val();
        const isChecked = $checkbox.prop('checked');
        
        if (isChecked) {
            selectedCommissions.add(commissionId);
        } else {
            selectedCommissions.delete(commissionId);
        }
        
        updateBulkActionButtons();
    });

    // Handle bulk approve
    $('#approveSelected').on('click', function() {
        if (selectedCommissions.size === 0) return;

        Swal.fire({
            title: 'Confirm Approval',
            text: `Are you sure you want to approve ${selectedCommissions.size} commission(s)?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, approve',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'approve');
                formData.append('commission_ids', JSON.stringify(Array.from(selectedCommissions)));

                $.ajax({
                    url: 'ajax/process_bulk_commissions.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Success', response.message, 'success');
                            selectedCommissions.clear();
                            updateBulkActionButtons();
                            table.ajax.reload();
                        } else {
                            Swal.fire('Error', response.message || 'Failed to approve commissions', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Failed to approve commissions', 'error');
                    }
                });
            }
        });
    });

    // Handle mark as paid
    $('#markPaidSelected').on('click', function() {
        if (selectedCommissions.size === 0) return;
        
        // Reset form fields
        $('#paymentReceipt').val('');
        $('#paymentNotes').val('');
        
        // Update total amount
        updateBulkActionButtons(); // This will set the payment amount
        
        // Show payment modal
        $('#paymentModal').modal('show');
    });

    // Initialize form validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Handle payment form submission
    $('#processPayment').on('click', function() {
        const form = document.getElementById('paymentForm');
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }

        const formData = new FormData(form);
        formData.append('action', 'mark_paid');
        formData.append('commission_ids', JSON.stringify(Array.from(selectedCommissions)));

        Swal.fire({
            title: 'Confirm Payment',
            text: `Are you sure you want to process payment for ${selectedCommissions.size} commission(s)?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, process payment',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/process_bulk_commissions.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $('#paymentModal').modal('hide');
                            Swal.fire('Success', response.message, 'success');
                            selectedCommissions.clear();
                            updateBulkActionButtons();
                            table.ajax.reload();
                        } else {
                            Swal.fire('Error', response.message || 'Failed to process payment', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Failed to process payment', 'error');
                    }
                });
            }
        });
    });

    // Search form submission
    $('#searchForm').on('submit', function(e) {
        e.preventDefault();
        selectedCommissions.clear();
        updateBulkActionButtons();
        table.ajax.reload();
    });
});

// View Details Function
function viewDetails(commissionId) {
    const modal = $('#commissionModal');
    const modalContent = modal.find('.modal-content');
    
    // Show loading spinner
    modalContent.html(`
        <div class="modal-header">
            <h5 class="modal-title">Commission Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `);
    
    modal.modal('show');
    
    // Fetch commission details
    $.ajax({
        url: 'ajax/get_commission_details.php',
        method: 'GET',
        data: { id: commissionId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                modalContent.html(response.html);
            } else {
                Swal.fire('Error', response.message || 'Failed to load commission details', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load commission details', 'error');
        }
    });
}

// Delete Commission Function
function deleteCommission(commissionId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This commission will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'ajax/delete_commission.php',
                method: 'POST',
                data: { id: commissionId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Deleted!', 'Commission has been deleted.', 'success');
                        table.ajax.reload();
                    } else {
                        Swal.fire('Error', response.message || 'Failed to delete commission', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to delete commission', 'error');
                }
            });
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
