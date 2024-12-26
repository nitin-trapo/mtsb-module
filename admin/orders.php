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

// Handle order status update
if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $order_id = $_POST['order_id'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
    $stmt->execute([$status, $order_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle agent assignment
if (isset($_POST['action']) && $_POST['action'] == 'assign_agent') {
    $order_id = $_POST['order_id'];
    $agent_id = $_POST['agent_id'];
    
    $stmt = $conn->prepare("UPDATE orders SET agent_id = ? WHERE id = ?");
    $stmt->execute([$agent_id, $order_id]);
    
    // Recalculate commission if needed
    // Add your commission calculation logic here
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Get all orders with customer and agent details
try {
    // Get store's default currency
    $stmt = $conn->query("SELECT currency FROM orders ORDER BY created_at DESC LIMIT 1");
    $default_currency = $stmt->fetchColumn() ?: 'USD';

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

    $stmt = $conn->query("
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            COALESCE(a.first_name, '') as agent_first_name,
            COALESCE(a.last_name, '') as agent_last_name,
            DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i:%s') as sort_date,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            COALESCE(a.commission_rate, 0) as agent_commission_rate,
            COALESCE(com.amount, 0) as commission_amount,
            COALESCE(com.status, 'pending') as commission_status
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN customers a ON o.agent_id = a.id
        LEFT JOIN commissions com ON o.id = com.order_id
        ORDER BY o.created_at DESC
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all potential agents (including customers who made orders)
    $stmt = $conn->query("
        SELECT DISTINCT
            c.id,
            c.first_name,
            c.last_name,
            c.commission_rate,
            c.is_agent,
            CASE 
                WHEN c.is_agent = 1 THEN 'Agent'
                ELSE 'Customer'
            END as type
        FROM customers c
        LEFT JOIN orders o ON c.id = o.customer_id
        WHERE c.status = 'active'
        AND (c.is_agent = 1 OR o.id IS NOT NULL)
        ORDER BY c.is_agent DESC, c.first_name, c.last_name
    ");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// Set page title
$page_title = 'Orders';

// Include header
include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Orders</h5>
                    <button type="button" class="btn btn-primary" onclick="syncOrders()">
                        <i class="fas fa-sync-alt me-2"></i>Sync Orders
                    </button>
                </div>
                <div class="card-body">
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
                                ?>
                                    <tr>
                                        <td data-sort="<?php echo $order['id']; ?>"><?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name']); ?></td>
                                        <td class="text-end" data-sort="<?php echo $order['total_price']; ?>">
                                            <?php echo $currency_symbol . number_format($order['total_price'], 2); ?>
                                        </td>
                                        <td class="text-end" data-sort="<?php echo $order['commission_amount']; ?>">
                                            <?php if ($order['commission_amount'] > 0): ?>
                                                <span class="badge <?php 
                                                    echo $order['commission_status'] === 'paid' ? 'bg-success' : 
                                                        ($order['commission_status'] === 'approved' ? 'bg-warning' : 'bg-primary'); 
                                                ?>" data-bs-toggle="tooltip" 
                                                   title="Status: <?php echo ucfirst($order['commission_status']); ?>">
                                                    RM <?php echo number_format($order['commission_amount'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not calculated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php echo get_financial_status_color($order['financial_status']); ?>">
                                                <?php echo ucfirst($order['financial_status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php echo get_fulfillment_status_color($order['fulfillment_status'] ?? 'unfulfilled'); ?>">
                                                <?php echo ucfirst($order['fulfillment_status'] ?? 'unfulfilled'); ?>
                                            </span>
                                        </td>
                                        <td data-sort="<?php echo $order['sort_date']; ?>">
                                            <?php echo $order['formatted_date']; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($order['customer_id']): ?>
                                            <button type="button" class="btn btn-sm btn-success" onclick="calculateCommission(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-calculator"></i>
                                            </button>
                                            <?php endif; ?>
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

<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="order-details-content">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary view-invoice">
                    <i class="fas fa-file-invoice me-1"></i>View Invoice
                </button>
                <button type="button" class="btn btn-success send-invoice">
                    <i class="fas fa-paper-plane me-1"></i>Send Invoice
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function syncOrders() {
    if (confirm('Are you sure you want to sync orders from Shopify?')) {
        $.post('ajax/sync_orders.php', function(response) {
            if (response.success) {
                alert('Orders synced successfully!');
                location.reload();
            } else {
                alert('Error syncing orders: ' + response.message);
            }
        });
    }
}

function assignAgent(orderId, agentId) {
    $.post('orders.php', {
        action: 'assign_agent',
        order_id: orderId,
        agent_id: agentId
    }, function(response) {
        if (!response.success) {
            alert('Error assigning agent');
        }
    });
}

function updateStatus(orderId, status) {
    $.post('orders.php', {
        action: 'update_status',
        order_id: orderId,
        status: status
    }, function(response) {
        if (!response.success) {
            alert('Error updating status');
        }
    });
}

function viewDetails(orderId) {
    const modal = $('#orderModal');
    const content = modal.find('.order-details-content');
    
    content.html('<div class="text-center"><div class="spinner-border" role="status"></div><p>Loading...</p></div>');
    modal.modal('show');
    
    $.post('ajax/get_order_details.php', { order_id: orderId })
        .done(function(response) {
            if (response.success) {
                const order = response.order;
                
                // Get customer email from metafields
                let customerEmail = 'N/A';
                if (order.metafields && order.metafields.customer_email) {
                    customerEmail = order.metafields.customer_email;
                }
                
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Order Information</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th width="35%">Order Number:</th>
                                            <td>#${order.order_number}</td>
                                        </tr>
                                        <tr>
                                            <th>Order Date:</th>
                                            <td>${order.processed_at}</td>
                                        </tr>
                                        <tr>
                                            <th>Total Amount:</th>
                                            <td>${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_price).toFixed(2)}</td>
                                        </tr>
                                        <tr>
                                            <th>Financial Status:</th>
                                            <td><span class="badge bg-${getFinancialStatusColor(order.financial_status)}">${capitalizeFirst(order.financial_status)}</span></td>
                                        </tr>
                                        <tr>
                                            <th>Fulfillment Status:</th>
                                            <td><span class="badge bg-${getFulfillmentStatusColor(order.fulfillment_status)}">${capitalizeFirst(order.fulfillment_status || 'unfulfilled')}</span></td>
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
                                            <th width="35%">Commission Amount:</th>
                                            <td>
                                                ${parseFloat(order.commission_amount) > 0 
                                                    ? `<span class="badge bg-${order.commission_status === 'paid' ? 'success' : 'info'}">
                                                        ${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.commission_amount).toFixed(2)}
                                                       </span>`
                                                    : '<span class="badge bg-secondary">Not calculated</span>'
                                                }
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Commission Status:</th>
                                            <td>
                                                ${parseFloat(order.commission_amount) > 0 
                                                    ? `<span class="badge bg-${order.commission_status === 'paid' ? 'success' : 'info'}">
                                                        ${order.commission_status.toUpperCase()}
                                                       </span>`
                                                    : '<span class="badge bg-secondary">N/A</span>'
                                                }
                                            </td>
                                        </tr>
                                        ${order.commission_date ? `
                                        <tr>
                                            <th>Commission Date:</th>
                                            <td>${order.commission_date}</td>
                                        </tr>
                                        ` : ''}
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
                                            <th width="35%">Name:</th>
                                            <td>${order.customer_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Email:</th>
                                            <td>${order.customer_email || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Metafields Email:</th>
                                            <td>${customerEmail}</td>
                                        </tr>
                                        <tr>
                                            <th>Phone:</th>
                                            <td>${order.customer_phone || 'N/A'}</td>
                                        </tr>
                                        ${order.agent_first_name ? `
                                        <tr>
                                            <th>Agent:</th>
                                            <td>${order.agent_first_name} ${order.agent_last_name}</td>
                                        </tr>
                                        ` : ''}
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title">Addresses</h6>
                                    <div class="row">
                                        <div class="col-sm-6">
                                            <h6 class="card-subtitle mb-2 text-muted">Billing Address</h6>
                                            ${formatAddress(order.billing_address)}
                                        </div>
                                        <div class="col-sm-6">
                                            <h6 class="card-subtitle mb-2 text-muted">Shipping Address</h6>
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
                                            <th class="text-center">Qty</th>
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
                                                <td><small>${item.sku}</small></td>
                                                <td class="text-center">${item.quantity}</td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(item.price).toFixed(2)}</td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(item.total).toFixed(2)}</td>
                                            </tr>
                                        `).join('')}
                                        <tr class="table-light">
                                            <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                            <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.subtotal_price).toFixed(2)}</td>
                                        </tr>
                                        ${order.total_shipping > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Shipping:</strong></td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_shipping).toFixed(2)}</td>
                                            </tr>
                                        ` : ''}
                                        ${order.total_tax > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Tax:</strong></td>
                                                <td class="text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_tax).toFixed(2)}</td>
                                            </tr>
                                        ` : ''}
                                        ${order.total_discounts > 0 ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Discount:</strong></td>
                                                <td class="text-end">-${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.total_discounts).toFixed(2)}</td>
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
                
                // Store order ID for invoice actions
                modal.data('orderId', orderId);
                
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

function formatAddress(address) {
    if (!address) return 'N/A';
    
    return `
        <address>
            ${address.name}<br>
            ${address.address1}<br>
            ${address.address2 ? address.address2 + '<br>' : ''}
            ${address.city}, ${address.province_code} ${address.zip}<br>
            ${address.country}
        </address>
    `;
}

function formatCurrency(amount, currency) {
    if (!amount) return '0.00';
    if (!currency) currency = 'INR';
    
    const symbols = {
        'INR': '₹',
        'USD': '$',
        'EUR': '€',
        'GBP': '£'
    };
    
    const symbol = symbols[currency] ?? currency + ' ';
    return symbol + parseFloat(amount).toFixed(2);
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
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

function calculateCommission(orderId) {
    if (!confirm('Are you sure you want to calculate commission for this order?')) {
        return;
    }

    $.ajax({
        url: 'ajax/calculate_single_commission.php',
        type: 'POST',
        data: { order_id: orderId },
        beforeSend: function() {
            // Show loading state
            $(`button[onclick="calculateCommission(${orderId})"]`)
                .prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin"></i>');
        },
        success: function(response) {
            if (response.success) {
                // Show success message with details
                let message = 'Commission calculated successfully!\n\n';
                message += `Total Commission: RM ${response.commission_amount.toFixed(2)}\n\n`;
                message += 'Processed Items:\n';
                response.processed_items.forEach(item => {
                    message += `${item.title}: RM ${item.commission.toFixed(2)} (${item.rate}%)\n`;
                });
                alert(message);
                
                // Refresh the page to show updated data
                location.reload();
            } else {
                alert('Error: ' + (response.message || 'Failed to calculate commission'));
            }
        },
        error: function(xhr) {
            let errorMessage = 'Failed to calculate commission';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = response.message || errorMessage;
            } catch (e) {
                console.error('Error parsing error response:', e);
            }
            alert('Error: ' + errorMessage);
        },
        complete: function() {
            // Reset button state
            $(`button[onclick="calculateCommission(${orderId})"]`)
                .prop('disabled', false)
                .html('<i class="fas fa-calculator"></i>');
        }
    });
}

$(document).ready(function() {
    // Initialize DataTable
    $('#ordersTable').DataTable({
        order: [[6, 'desc']], // Sort by date column (7th column, 0-based index) in descending order
        pageLength: 25,
        language: {
            search: "Search orders:",
            lengthMenu: "Show _MENU_ orders per page",
            info: "Showing _START_ to _END_ of _TOTAL_ orders",
            infoEmpty: "Showing 0 to 0 of 0 orders",
            infoFiltered: "(filtered from _MAX_ total orders)"
        },
        columnDefs: [
            { targets: [2, 3], className: 'text-end' }, // Amount and Commission columns
            { targets: [4, 5], className: 'text-center' }, // Status columns
            { targets: -1, orderable: false } // Actions column
        ]
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Initialize select2 for agent selection
    $('.agent-select').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
    
    // Handle invoice actions
    $('.view-invoice').click(function() {
        const orderId = $('#orderModal').data('orderId');
        window.open(`ajax/view_invoice.php?order_id=${orderId}`, '_blank');
    });
    
    $('.send-invoice').click(function() {
        const orderId = $('#orderModal').data('orderId');
        const btn = $(this);
        
        if (confirm('Are you sure you want to send the invoice to the customer?')) {
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Sending...');
            
            $.post('ajax/send_invoice.php', { order_id: orderId }, function(response) {
                if (response.success) {
                    alert('Invoice sent successfully!');
                } else {
                    alert('Failed to send invoice: ' + response.error);
                }
            }).fail(function() {
                alert('Failed to send invoice. Please try again.');
            }).always(function() {
                btn.prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>Send Invoice');
            });
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
