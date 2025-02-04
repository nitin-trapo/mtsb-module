<?php
session_start();
$page_title = 'My Orders';
$use_datatables = true;

require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an agent
if (!is_logged_in() || !is_agent()) {
    $_SESSION['error'] = 'Please login as an agent to access this page.';
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Get all orders for this agent with customer details
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i:%s') as sort_date,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            COALESCE(com.amount, 0) as base_commission,
            COALESCE(com.total_discount, 0) as total_discount,
            COALESCE(com.actual_commission, 0) as actual_commission,
            COALESCE(com.status, '') as commission_status,
            CASE 
                WHEN com.id IS NULL THEN 'Not Calculated'
                ELSE com.status
            END as commission_calculation_status
        FROM " . TABLE_ORDERS . " o
        LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
        LEFT JOIN " . TABLE_COMMISSIONS . " com ON o.id = com.order_id
        WHERE o.customer_id = :customer_id
        ORDER BY o.created_at DESC
    ");

    // Debug logging
    error_log("Fetching orders for customer ID: " . $_SESSION['user_id']);

    $stmt->bindParam(':customer_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug logging
    error_log("Found " . count($orders) . " orders");

    // Currency symbol mapping
    $currency_symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'INR' => '₹',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'JPY' => '¥',
        'CNY' => '¥',
        'HKD' => 'HK$',
        'MYR' => 'RM '
    ];

    // Get store's default currency
    $stmt = $conn->query("SELECT currency FROM " . TABLE_ORDERS . " ORDER BY created_at DESC LIMIT 1");
    $default_currency = $stmt->fetchColumn() ?: 'USD';

    // Debug: Print session variables
    error_log("Session Data: " . print_r($_SESSION, true));

    if (!isset($_SESSION['user_email'])) {
        throw new Exception("No email found in session. Please login again.");
    }

    // Get agent details
    $stmt = $conn->prepare("
        SELECT c.* 
        FROM " . TABLE_CUSTOMERS . " c
        WHERE c.email = :email 
        AND c.is_agent = 1 
        AND c.status = 'active'
        LIMIT 1
    ");
    
    $stmt->bindParam(':email', $_SESSION['user_email']);
    $stmt->execute();
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug: Print agent details
    error_log("Agent Details: " . print_r($agent, true));

    if (!$agent) {
        throw new Exception("No active agent profile found for email: " . $_SESSION['user_email']);
    }

    // Update session with correct agent ID if needed
    if ($_SESSION['user_id'] != $agent['id']) {
        $_SESSION['user_id'] = $agent['id'];
        error_log("Updated session user_id to match agent ID: " . $agent['id']);
    }

    // Verify the orders query after getting agent ID
    $orders_check = $conn->prepare("
        SELECT COUNT(*) as order_count 
        FROM " . TABLE_ORDERS . " 
        WHERE customer_id = :customer_id
    ");
    $orders_check->bindParam(':customer_id', $agent['id'], PDO::PARAM_INT);
    $orders_check->execute();
    $order_count = $orders_check->fetchColumn();
    error_log("Total orders found for agent: " . $order_count);

    include 'includes/header.php';

} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Error in orders.php: " . $error);
}
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">My Orders</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php else: ?>
                        <?php if (empty($orders)): ?>
                            <div class="alert alert-info">
                                No orders found. Orders will appear here once customers make purchases through your agent link.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="ordersTable" class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Customer</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-end">Commission</th>
                                            <th class="text-center">Payment Status</th>
                                            <th class="text-center">Fulfillment Status</th>
                                            <th>Date</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): 
                                            $currency = $order['currency'] ?? $default_currency;
                                            $currency_symbol = $currency_symbols[$currency] ?? $currency;
                                            
                                            // Format the processed date
                                            $processed_date = !empty($order['processed_at']) ? 
                                                date('Y-m-d H:i:s', strtotime($order['processed_at'])) : '';
                                            
                                            $display_date = !empty($order['processed_at']) ? 
                                                date('M d, Y h:i A', strtotime($order['processed_at'])) : 'Not processed';
                                            
                                            // Get customer email from metafields
                                            $metafields = json_decode($order['metafields'], true);
                                            $customerEmail = isset($metafields['customer_email']) ? $metafields['customer_email'] : 'N/A';
                                        ?>
                                            <tr>
                                                <td data-sort="<?php echo $order['id']; ?>">
                                                    #<?php echo htmlspecialchars($order['order_number']); ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        echo htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name']);
                                                        echo '<br><small class="text-muted">' . htmlspecialchars($customerEmail) . '</small>';
                                                    ?>
                                                </td>
                                                <td class="text-end" data-sort="<?php echo $order['total_price']; ?>">
                                                    <?php echo $currency_symbol . number_format($order['total_price'], 2); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($order['commission_calculation_status'] === 'Not Calculated'): ?>
                                                        <span class="badge bg-secondary">Not Calculated</span>
                                                    <?php else: ?>
                                                        <?php 
                                                            if ($order['total_discount'] > 0) {
                                                                echo '<div class="text-end">';
                                                                echo '<span class="badge bg-primary">' . $currency_symbol . number_format($order['base_commission'], 2) . '</span>';
                                                                echo '<br><small class="text-muted fw-bold">Commission: ' . $currency_symbol . number_format($order['actual_commission'], 2) . '</small>';
                                                                echo '<br><small class="text-danger fw-bold">Discount: ' . $currency_symbol . number_format($order['total_discount'], 2) . '</small>';
                                                                echo '</div>';
                                                            } else {
                                                                echo '<div class="text-end">';
                                                                echo '<span class="badge bg-secondary">' . $currency_symbol . number_format($order['base_commission'], 2) . '</span>';
                                                                echo '</div>';
                                                            }
                                                        ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo get_financial_status_color($order['financial_status']); ?>">
                                                        <?php echo ucfirst($order['financial_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo get_fulfillment_status_color($order['fulfillment_status'] ?? 'unfulfilled'); ?>">
                                                        <?php echo ucfirst($order['fulfillment_status'] ?? 'Unfulfilled'); ?>
                                                    </span>
                                                </td>
                                                <td data-sort="<?php echo strtotime($order['created_at']); ?>">
                                                    <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $order['id']; ?>)">
                                                        <i class="fas fa-eye me-1"></i>View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="order-details-content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary view-invoice">
                    <i class="fas fa-file-invoice me-1"></i>View Invoice
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
// Helper Functions
function formatAddress(address) {
    if (!address) return 'N/A';
    
    const parts = [];
    if (address.name) parts.push(address.name);
    if (address.company) parts.push(address.company);
    if (address.address1) parts.push(address.address1);
    if (address.address2) parts.push(address.address2);
    
    const cityParts = [];
    if (address.city) cityParts.push(address.city);
    if (address.province) cityParts.push(address.province);
    if (address.zip) cityParts.push(address.zip);
    if (cityParts.length) parts.push(cityParts.join(', '));
    
    if (address.country) parts.push(address.country);
    if (address.phone) parts.push(`Phone: ${address.phone}`);
    
    return parts.join('<br>');
}

function getFinancialStatusColor(status) {
    if (!status) return 'secondary';
    const colors = {
        'paid': 'success',
        'pending': 'warning',
        'refunded': 'info',
        'voided': 'danger'
    };
    return colors[status.toLowerCase()] || 'secondary';
}

function getFulfillmentStatusColor(status) {
    if (!status) return 'secondary';
    const colors = {
        'fulfilled': 'success',
        'partial': 'info',
        'unfulfilled': 'danger',
        'restocked': 'primary',
        'cancelled': 'secondary'
    };
    return colors[status.toLowerCase()] || 'light';
}

function capitalizeFirst(string) {
    if (!string) return '';
    return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
}

function viewDetails(orderId) {
    const modal = $('#orderModal');
    const content = modal.find('.order-details-content');
    
    content.html('<div class="text-center"><div class="spinner-border" role="status"></div><p>Loading...</p></div>');
    modal.modal('show');
    modal.data('orderId', orderId);
    
    $.post('ajax/get_order_details.php', { order_id: orderId })
        .done(function(response) {
            if (response.success) {
                const order = response.order;
                console.log('Order Data:', order);

                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Order Information</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th>Order Number:</th>
                                            <td>#${order.order_number}</td>
                                        </tr>
                                        <tr>
                                            <th>Created:</th>
                                            <td>${order.created_at}</td>
                                        </tr>
                                        <tr>
                                            <th>Financial Status:</th>
                                            <td><span class="badge bg-${getFinancialStatusColor(order.financial_status)}">${capitalizeFirst(order.financial_status)}</span></td>
                                        </tr>
                                        <tr>
                                            <th>Fulfillment Status:</th>
                                            <td><span class="badge bg-${getFulfillmentStatusColor(order.fulfillment_status)}">${capitalizeFirst(order.fulfillment_status)}</span></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Commission Information</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th>Base Commission:</th>
                                            <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.base_commission).toFixed(2)}</td>
                                        </tr>
                                        <tr>
                                            <th>Commission Discount:</th>
                                            <td class="text-end text-danger">-${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.commission_discount).toFixed(2)}</td>
                                        </tr>
                                        <tr>
                                            <th>Actual Commission:</th>
                                            <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.actual_commission).toFixed(2)}</td>
                                        </tr>
                                        <tr>
                                            <th>Commission Status:</th>
                                            <td class="text-end">
                                                <span class="badge bg-${order.commission_status === 'paid' ? 'success' : 'warning'}">
                                                    ${capitalizeFirst(order.commission_status || 'Pending')}
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Customer Information</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th>Name:</th>
                                            <td>${order.customer_name || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Email:</th>
                                            <td>${order.customer_email || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Phone:</th>
                                            <td>${order.customer_phone || 'N/A'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Addresses</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Billing Address:</strong><br>
                                            ${formatAddress(order.billing_address)}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Shipping Address:</strong><br>
                                            ${formatAddress(order.shipping_address)}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Order Items</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${order.line_items.map(item => `
                                            <tr>
                                                <td>
                                                    ${item.title}
                                                    ${item.variant_title ? `<br><small class="text-muted">${item.variant_title}</small>` : ''}
                                                </td>
                                                <td>${item.sku || 'N/A'}</td>
                                                <td class="text-end">${item.quantity}</td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(item.price).toFixed(2)}</td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(item.total).toFixed(2)}</td>
                                            </tr>
                                        `).join('')}

                                        <tr class="table-light">
                                            <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                            <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.subtotal_price).toFixed(2)}</td>
                                        </tr>

                                        ${order.discount_codes && Array.isArray(order.discount_codes) && order.discount_codes.length > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Total Discounts:</strong></td>
                                                <td class="text-end text-danger">-${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_discounts).toFixed(2)}</td>
                                            </tr>
                                            ${order.discount_codes.map(discount => `
                                                <tr class="table-light">
                                                    <td colspan="4" class="text-end text-muted">
                                                        <small>
                                                            <span class="badge bg-light text-dark border">${discount.code}</span>
                                                            ${discount.type ? `<span class="ms-1">(${discount.type}${discount.value ? ` ${discount.value}% off` : ''})</span>` : ''}
                                                            <span class="ms-2 text-danger">-${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(discount.amount).toFixed(2)}</span>
                                                        </small>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            `).join('')}
                                        ` : ''}

                                        ${parseFloat(order.total_shipping) > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Shipping:</strong></td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_shipping).toFixed(2)}</td>
                                            </tr>
                                        ` : ''}

                                        ${parseFloat(order.total_tax) > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Tax:</strong></td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_tax).toFixed(2)}</td>
                                            </tr>
                                        ` : ''}

                                        <tr class="table-light fw-bold">
                                            <td colspan="4" class="text-end">Total:</td>
                                            <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_price).toFixed(2)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                
                content.html(html);
            } else {
                content.html(`<div class="alert alert-danger">${response.error || 'Failed to load order details'}</div>`);
            }
        })
        .fail(function(jqXHR) {
            let errorMessage = 'Failed to load order details';
            try {
                const response = JSON.parse(jqXHR.responseText);
                errorMessage = response.error || errorMessage;
            } catch (e) {
                console.error('Error parsing error response:', e);
            }
            content.html(`<div class="alert alert-danger">${errorMessage}</div>`);
        });
}

// Document Ready Handler
document.addEventListener('DOMContentLoaded', function() {
    // Initialize DataTable
    if ($.fn.DataTable) {
        $('#ordersTable').DataTable({
            order: [[0, 'desc']], // Sort by first column (Order #) in descending order
            pageLength: 25,
            language: {
                search: "Search orders:",
                lengthMenu: "Show _MENU_ orders per page",
                info: "Showing _START_ to _END_ of _TOTAL_ orders",
                infoEmpty: "Showing 0 to 0 of 0 orders",
                infoFiltered: "(filtered from _MAX_ total orders)"
            }
        });
    }

    // Handle invoice actions
    $('.view-invoice').click(function() {
        const orderId = $('#orderModal').data('orderId');
        window.open(`ajax/view_invoice.php?order_id=${orderId}`, '_blank');
    });
});
</script>

<?php include 'includes/footer.php'; ?>
