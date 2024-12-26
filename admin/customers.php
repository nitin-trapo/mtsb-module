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
                    <button type="button" class="btn btn-primary" onclick="syncCustomers()">
                        <i class="fas fa-sync-alt me-2"></i>Sync Customers
                    </button>
                </div>
                <div class="card-body">
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
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="viewDetails(<?php echo $customer['id']; ?>)">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
$(document).ready(function() {
    $('.datatable').DataTable();
});

function syncCustomers() {
    if (confirm('Are you sure you want to sync customers from Shopify?')) {
        $.post('ajax/sync_customers.php', function(response) {
            if (response.success) {
                alert('Customers synced successfully!');
                location.reload();
            } else {
                alert('Error syncing customers: ' + response.message);
            }
        });
    }
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
</script>

<?php include 'includes/footer.php'; ?>
