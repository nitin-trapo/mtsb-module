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

// Handle customer status update
if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $customer_id = $_POST['customer_id'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE customers SET status = ? WHERE id = ?");
    $stmt->execute([$status, $customer_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle making customer an agent
if (isset($_POST['action']) && $_POST['action'] == 'toggle_agent') {
    $customer_id = $_POST['customer_id'];
    $is_agent = $_POST['is_agent'];
    
    $stmt = $conn->prepare("UPDATE customers SET is_agent = ? WHERE id = ?");
    $stmt->execute([$is_agent, $customer_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Get store currency from the most recent order
$stmt = $conn->query("SELECT currency FROM orders ORDER BY created_at DESC LIMIT 1");
$currency_row = $stmt->fetch(PDO::FETCH_ASSOC);
$store_currency = $currency_row ? $currency_row['currency'] : 'INR'; // Default to INR if no orders exist

// Get all customers with total spent in store currency
$stmt = $conn->query("
    SELECT 
        c.*,
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total_price), 0) as total_spent
    FROM customers c
    LEFT JOIN orders o ON c.id = o.customer_id
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Customers';

// Include header
include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Customers</h5>
                    <div>
                        <div id="syncStatus" class="d-none alert alert-success me-3">
                            Customers synced successfully!
                        </div>
                        <button type="button" class="btn btn-primary" id="syncButton" onclick="syncCustomers()">
                            <i class="fas fa-sync-alt me-2"></i>Sync Customers
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="syncLoader" class="text-center mb-4 d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="mt-2">Syncing customers... Please wait.</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover datatable"
                               data-page-length="25"
                               data-order-column="0"
                               data-order-dir="desc">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Total Orders</th>
                                    <th>Total Spent</th>
                                    <th>Agent Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?php echo $customer['shopify_customer_id']; ?></td>
                                    <td><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td class="text-end"><?php echo $customer['total_orders']; ?></td>
                                    <td class="text-end"><?php 
                                        if ($store_currency === 'INR') {
                                            echo '₹';
                                        } elseif ($store_currency === 'MYR') {
                                            echo 'RM ';
                                        } else {
                                            echo htmlspecialchars($store_currency) . ' ';
                                        }
                                        echo number_format($customer['total_spent'], 2); 
                                    ?></td>
                                    <td class="text-center">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" 
                                                   onchange="toggleAgent(<?php echo $customer['id']; ?>, this.checked)"
                                                   <?php echo $customer['is_agent'] ? 'checked' : ''; ?>>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <select class="form-select form-select-sm" 
                                                onchange="updateStatus(<?php echo $customer['id']; ?>, this.value)">
                                            <option value="active" <?php echo $customer['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $customer['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-primary" 
                                                    onclick="viewDetails(<?php echo $customer['id']; ?>)">
                                                <i class="fas fa-eye me-1"></i>View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-info" onclick="editCustomer(<?php echo $customer['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomer(<?php echo $customer['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editCustomerForm">
                    <input type="hidden" id="editCustomerId" name="customer_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="editFirstName" name="first_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="editLastName" name="last_name">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" id="editPhone" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="editEmail" name="email" readonly>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Bank Name</label>
                            <input type="text" class="form-control" id="editBankName" name="bank_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="editBankAccountNumber" name="bank_account_number">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Bank Statement Header</label>
                            <input type="text" class="form-control" id="editBankAccountHeader" name="bank_account_header">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Agent Status</label>
                            <select class="form-select" id="editIsAgent" name="is_agent">
                                <option value="0">Not Agent</option>
                                <option value="1">Agent</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="editStatus" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveCustomer()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<style>
.modal-xl {
    max-width: 90%;
}
.modal-body {
    padding: 1.5rem;
}
.table th {
    width: 200px;
}
</style>

<script>
$(document).ready(function() {
    $('.datatable').DataTable();
});

function syncCustomers() {
    // Show loader and disable button
    const syncLoader = document.getElementById('syncLoader');
    const syncButton = document.getElementById('syncButton');
    const syncStatus = document.getElementById('syncStatus');
    
    syncLoader.classList.remove('d-none');
    syncButton.disabled = true;
    syncStatus.classList.add('d-none');
    
    // Make the AJAX call
    $.ajax({
        url: 'ajax/sync_customers.php',
        method: 'POST',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Show success message
                syncStatus.classList.remove('d-none');
                syncStatus.classList.remove('alert-danger');
                syncStatus.classList.add('alert-success');
                syncStatus.textContent = response.message || 'Customers synced successfully!';
                
                // Reload the page after 2 seconds to show updated data
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                // Show error message
                syncStatus.classList.remove('d-none');
                syncStatus.classList.remove('alert-success');
                syncStatus.classList.add('alert-danger');
                syncStatus.textContent = response.message || 'Error syncing customers';
            }
        },
        error: function(xhr, status, error) {
            // Show error message
            syncStatus.classList.remove('d-none');
            syncStatus.classList.remove('alert-success');
            syncStatus.classList.add('alert-danger');
            syncStatus.textContent = 'Error syncing customers: ' + error;
        },
        complete: function() {
            // Hide loader and enable button
            syncLoader.classList.add('d-none');
            syncButton.disabled = false;
        }
    });
}

function toggleAgent(customerId, isAgent) {
    $.post('customers.php', {
        action: 'toggle_agent',
        customer_id: customerId,
        is_agent: isAgent ? 1 : 0
    }, function(response) {
        if (!response.success) {
            alert('Error updating agent status');
        }
    });
}

function updateStatus(customerId, status) {
    $.post('customers.php', {
        action: 'update_status',
        customer_id: customerId,
        status: status
    }, function(response) {
        if (!response.success) {
            alert('Error updating status');
        }
    });
}

function viewDetails(customerId) {
    const modal = $('#customerModal');
    const modalBody = modal.find('.modal-body');
    modalBody.html('<div class="text-center"><div class="spinner-border" role="status"></div></div>');
    modal.modal('show');
    
    $.get('ajax/view_customer.php', { id: customerId }, function(response) {
        if (response.error) {
            modalBody.html('<div class="alert alert-danger">' + response.error + '</div>');
        } else {
            modalBody.html(response);
        }
    }).fail(function() {
        modalBody.html('<div class="alert alert-danger">Failed to load customer details</div>');
    });
}

function editCustomer(customerId) {
    // Fetch customer details
    $.ajax({
        url: 'ajax/get_customer.php',
        method: 'GET',
        data: { customer_id: customerId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const customer = response.customer;
                
                // Populate the form
                $('#editCustomerId').val(customer.id);
                $('#editFirstName').val(customer.first_name);
                $('#editLastName').val(customer.last_name);
                $('#editPhone').val(customer.phone);
                $('#editEmail').val(customer.email);
                $('#editBankName').val(customer.bank_name);
                $('#editBankAccountNumber').val(customer.bank_account_number);
                $('#editBankAccountHeader').val(customer.bank_account_header);
                $('#editIsAgent').val(customer.is_agent);
                $('#editStatus').val(customer.status);
                
                // Show the modal
                new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
            } else {
                alert('Error loading customer details: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading customer details');
        }
    });
}

function saveCustomer() {
    const formData = $('#editCustomerForm').serialize();
    
    $.ajax({
        url: 'ajax/update_customer.php',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Hide modal
                bootstrap.Modal.getInstance(document.getElementById('editCustomerModal')).hide();
                
                // Show success message and reload
                alert('Customer updated successfully');
                location.reload();
            } else {
                alert('Error updating customer: ' + response.message);
            }
        },
        error: function() {
            alert('Error updating customer');
        }
    });
}

function deleteCustomer(customerId) {
    if (confirm('Are you sure you want to delete this customer?')) {
        $.ajax({
            url: 'ajax/delete_customer.php',
            method: 'POST',
            data: { customer_id: customerId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Customer deleted successfully');
                    location.reload();
                } else {
                    alert('Error deleting customer: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting customer');
            }
        });
    }
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: '<?php echo $store_currency; ?>'
    }).format(amount);
}
</script>

<?php include 'includes/footer.php'; ?>
