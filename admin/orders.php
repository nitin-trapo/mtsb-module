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
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address, '$.first_name')), '') as customer_first_name,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address, '$.last_name')), '') as customer_last_name,
            a.first_name as agent_first_name,
            a.last_name as agent_last_name,
            a.email as agent_email,
            DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i:%s') as sort_date,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            COALESCE(a.commission_rate, 0) as agent_commission_rate,
            o.customer_id,
            COALESCE(com.amount, 0) as base_commission,
            COALESCE(com.total_discount, 0) as total_discount,
            COALESCE(com.actual_commission, 0) as actual_commission,
            COALESCE(com.status, '') as commission_status,
            CASE 
                WHEN com.id IS NULL THEN 'Not Calculated'
                ELSE com.status
            END as commission_calculation_status
        FROM orders o
        LEFT JOIN customers a ON o.customer_id = a.id
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
                                    <th>Agent</th>
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
                                        <td>
                                            <?php 
                                                echo htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name']);
                                                
                                                // Get customer email from metafields
                                                $metafields = json_decode($order['metafields'], true);
                                                $customerEmail = isset($metafields['customer_email']) ? $metafields['customer_email'] : 'N/A';
                                                
                                                echo '<br><small class="text-muted">' . htmlspecialchars($customerEmail) . '</small>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($order['agent_first_name']) || !empty($order['agent_last_name'])) {
                                                echo htmlspecialchars($order['agent_first_name'] . ' ' . $order['agent_last_name']);
                                                if (!empty($order['agent_email'])) {
                                                    echo '<br><small class="text-muted">' . htmlspecialchars($order['agent_email']) . '</small>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">No Agent</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end" data-sort="<?php echo $order['total_price']; ?>">
                                            <?php echo $currency_symbol . number_format($order['total_price'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($order['commission_calculation_status'] === 'Not Calculated'): ?>
                                                <button type="button" class="btn btn-sm btn-warning calculate-commission" 
                                                        data-order-id="<?php echo $order['id']; ?>"
                                                        onclick="calculateCommission(<?php echo $order['id']; ?>)">
                                                    Calculate Commission
                                                </button>
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
                                                <?php echo ucfirst($order['fulfillment_status'] ?? 'unfulfilled'); ?>
                                            </span>
                                        </td>
                                        <td data-sort="<?php echo $order['sort_date']; ?>">
                                            <?php echo $order['formatted_date']; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $order['id']; ?>)">
                                                    <i class="fas fa-eye me-1"></i>View
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
                
                // Debug order data
                console.log('Order Data:', order);
                console.log('Discount Codes:', order.discount_codes);
                
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
                                                ${order.commission_calculation_status === 'Not Calculated' 
                                                    ? '<button type="button" class="btn btn-sm btn-warning calculate-commission" ' +
                                                      'data-order-id="' + order.id + '" ' +
                                                      'onclick="calculateCommission(' + order.id + ')">' +
                                                      'Calculate Commission</button>'
                                                    : `<div>
                                                        <span class="badge bg-primary text-end">${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.base_commission).toFixed(2)}</span>
                                                        ${parseFloat(order.commission_discount) > 0 
                                                            ? `<br><small class="text-danger fw-bold">Total Discount: -${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.commission_discount).toFixed(2)}</small>
                                                               <br><small class="text-success fw-bold">Commission: ${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(order.actual_commission).toFixed(2)}</small>`
                                                            : ''
                                                        }
                                                       </div>`
                                                }
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Commission Status:</th>
                                            <td>
                                                ${order.commission_calculation_status === 'Not Calculated' 
                                                    ? '<span class="badge bg-secondary">Not Calculated</span>'
                                                    : `<span class="badge bg-${order.commission_status === 'paid' ? 'success' : 'info'}">${order.commission_status.toUpperCase()}</span>`
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
                                    <h6 class="card-title">Agent Information</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th width="35%">Name:</th>
                                            <td>${order.customer_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Agent Email:</th>
                                            <td>${order.customer_email || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Customer Email:</th>
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
                                        ${order.discount_codes && Array.isArray(order.discount_codes) ? `
                                            <tr class="table-light">
                                                <td colspan="4" class="text-end"><strong>Total Discounts:</strong></td>
                                                <td class="text-end">-${order.currency === 'MYR' ? 'RM' : order.currency} ${
                                                    order.discount_codes.reduce((total, discount) => total + parseFloat(discount.amount || 0), 0).toFixed(2)
                                                }</td>
                                            </tr>
                                            ${order.discount_codes.map(discount => `
                                                <tr class="table-light">
                                                    <td colspan="4" class="text-end text-muted">
                                                        <small>
                                                            <span class="badge bg-light text-dark border">${discount.code}</span>
                                                            <span class="ms-2 text-danger">-${order.currency === 'MYR' ? 'RM' : order.currency} ${parseFloat(discount.amount).toFixed(2)}</span>
                                                        </small>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            `).join('')}
                                        ` : ''}
                                        ${order.total_tax > 0 ? `
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
    
    const parts = [];
    if (address.address1) parts.push(address.address1);
    if (address.address2) parts.push(address.address2);
    if (address.city) parts.push(address.city);
    if (address.province) parts.push(address.province);
    if (address.country) parts.push(address.country);
    if (address.zip) parts.push(address.zip);
    
    return parts.join(', ');
}

function calculateCommission(orderId) {
    if (!confirm('Are you sure you want to calculate commission for this order?')) {
        return;
    }

    const btn = $(`.btn[onclick="calculateCommission(${orderId})"]`);
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Calculating...');

    $.post('ajax/calculate_single_commission.php', {
        order_id: orderId
    })
    .done(function(response) {
        if (response.success) {
            alert('Commission calculated successfully!');
            location.reload();
        } else {
            alert(response.error || 'Failed to calculate commission');
            btn.prop('disabled', false).html(originalHtml);
        }
    })
    .fail(function() {
        alert('Failed to calculate commission');
        btn.prop('disabled', false).html(originalHtml);
    });
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
