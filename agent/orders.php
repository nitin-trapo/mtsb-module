<?php
session_start();
$page_title = 'My Orders';
$use_datatables = true;
include 'includes/header.php';

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an agent
if (!is_logged_in() || !is_agent()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Get store's default currency
    $stmt = $conn->query("SELECT currency FROM orders ORDER BY created_at DESC LIMIT 1");
    $default_currency = $stmt->fetchColumn() ?: 'MYR';

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

    // Debug: Print session email
    error_log("Agent Email: " . $_SESSION['email']);

    // Get agent details
    $stmt = $conn->prepare("
        SELECT c.* 
        FROM customers c
        WHERE c.email = ? AND c.is_agent = 1
    ");
    $stmt->execute([$_SESSION['email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug: Print agent details
    error_log("Agent Details: " . print_r($agent, true));

    if (!$agent) {
        throw new Exception("No agent profile found for your email (" . $_SESSION['email'] . ")");
    }

    // Get all orders for this agent with full details
    $query = "
        SELECT 
            o.*,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            c.email as customer_email,
            JSON_UNQUOTE(JSON_EXTRACT(o.metafields, '$.customer_email')) as order_customer_email,
            JSON_UNQUOTE(JSON_EXTRACT(o.metafields, '$.billing_name')) as billing_name,
            DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i:%s') as sort_date,
            DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_date,
            COALESCE(com.amount, 0) as commission_amount,
            COALESCE(com.status, 'pending') as commission_status
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN commissions com ON o.id = com.order_id
        WHERE o.agent_id = ? OR o.customer_id = ?
        ORDER BY o.created_at DESC
    ";
    
    // Debug: Print query
    error_log("Query: " . str_replace(['?', '?'], [$agent['id'], $agent['id']], $query));
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$agent['id'], $agent['id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug: Print orders count
    error_log("Found " . count($orders) . " orders");
    if (!empty($orders)) {
        error_log("First Order: " . print_r($orders[0], true));
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Error in orders.php: " . $error);
}
?>

<!-- Debug Info -->
<?php if (isset($_SESSION['debug']) && $_SESSION['debug']): ?>
<div class="container-fluid py-2">
    <div class="alert alert-info">
        <h6>Debug Information:</h6>
        <pre><?php 
            echo "Session Email: " . $_SESSION['email'] . "\n";
            echo "Agent ID: " . ($agent['id'] ?? 'Not found') . "\n";
            echo "Number of Orders: " . (isset($orders) ? count($orders) : 'No orders array') . "\n";
            if (isset($error)) echo "Error: " . $error . "\n";
        ?></pre>
    </div>
</div>
<?php endif; ?>

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
                                            <th>Total</th>
                                            <th>Commission</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): 
                                            $currency = $order['currency'] ?? $default_currency;
                                            $currency_symbol = $currency_symbols[$currency] ?? ($currency === 'MYR' ? 'RM ' : $currency);
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong>
                                                </td>
                                                <td>
                                                    <?php 
                                                    // Display billing name if available, otherwise fallback to customer name
                                                    $displayName = !empty($order['billing_name']) ? $order['billing_name'] : 
                                                                 ($order['customer_first_name'] . ' ' . $order['customer_last_name']);
                                                    echo htmlspecialchars($displayName);
                                                    
                                                    // Display order email if available, otherwise fallback to customer email
                                                    $displayEmail = !empty($order['order_customer_email']) ? $order['order_customer_email'] : $order['customer_email'];
                                                    if ($displayEmail) {
                                                        echo '<br><small class="text-muted">' . htmlspecialchars($displayEmail) . '</small>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo $currency_symbol . number_format($order['total_price'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($order['commission_amount'] > 0): ?>
                                                        <span class="text-success">
                                                            <?php echo $currency_symbol . number_format($order['commission_amount'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not calculated</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?php if ($order['commission_status'] == 'approved'): ?>
                                                            <span class="badge bg-success">Approved</span>
                                                        <?php elseif ($order['commission_status'] == 'pending'): ?>
                                                            <span class="badge bg-warning">Commission Pending</span>
                                                        <?php elseif ($order['commission_status'] == 'paid'): ?>
                                                            <span class="badge bg-success">Commission Paid</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-sort="<?php echo $order['sort_date']; ?>">
                                                    <?php echo $order['formatted_date']; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $order['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
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
<div class="modal fade" id="orderModal" tabindex="-1" aria-labelledby="orderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderModalLabel">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalAlerts"></div>
                <div class="order-details-content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary view-invoice" onclick="viewInvoice(currentOrderId)">
                    <i class="fas fa-file-invoice me-2"></i>View Invoice
                </button>
                <button type="button" class="btn btn-success send-invoice" style="display: none;">
                    <i class="fas fa-paper-plane me-2"></i>Send Invoice
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentOrderId = null;

function viewDetails(orderId) {
    currentOrderId = orderId;
    const modal = $('#orderModal');
    const content = modal.find('.order-details-content');
    
    content.html('<div class="text-center"><div class="spinner-border" role="status"></div><p>Loading...</p></div>');
    modal.modal('show');
    
    $.post('ajax/get_order_details.php', { order_id: orderId })
        .done(function(response) {
            if (response.success) {
                const order = response.order;
                console.log('Order details:', order); // Debug log
                
                // Calculate discount amount
                let discountHtml = '';
                if (order.discount_codes && order.discount_codes.length > 0) {
                    order.discount_codes.forEach(discount => {
                        discountHtml += `
                        <tr>
                            <th>Discount:</th>
                            <td>${discount.code} (-${formatCurrency(parseFloat(discount.amount) || 0.00, order.currency)})</td>
                        </tr>`;
                    });
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
                                            <td>${order.formatted_processed_date || order.formatted_created_date}</td>
                                        </tr>
                                        <tr>
                                            <th>Subtotal:</th>
                                            <td>${formatCurrency(order.subtotal_price, order.currency)}</td>
                                        </tr>
                                        ${discountHtml}
                                        ${parseFloat(order.total_shipping) > 0 ? `
                                        <tr>
                                            <th>Shipping:</th>
                                            <td>${formatCurrency(order.total_shipping, order.currency)}</td>
                                        </tr>
                                        ` : ''}
                                        ${parseFloat(order.total_tax) > 0 ? `
                                        <tr>
                                            <th>Tax:</th>
                                            <td>${formatCurrency(order.total_tax, order.currency)}</td>
                                        </tr>
                                        ` : ''}
                                        <tr>
                                            <th>Total Amount:</th>
                                            <td><strong>${formatCurrency(order.total_price, order.currency)}</strong></td>
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
                                                        ${formatCurrency(order.commission_amount, order.currency)}
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
                                            <th>AgentEmail:</th>
                                            <td>${order.customer_email || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Agent Phone:</th>
                                            <td>${order.customer_phone || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <th>Customer Email:</th>
                                            <td>${order.metafields?.customer_email || 'N/A'}</td>
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
                                            <th>Price</th>
                                            <th>Quantity</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${formatOrderItems(order.line_items, order.currency)}
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="4" class="text-end">Subtotal:</th>
                                            <td class="text-end">${formatCurrency(order.subtotal_price, order.currency)}</td>
                                        </tr>
                                        ${order.discount_codes && order.discount_codes.length > 0 ? 
                                            order.discount_codes.map(discount => `
                                                <tr>
                                                    <th colspan="4" class="text-end">Discount (${discount.code}):</th>
                                                    <td class="text-end">-${formatCurrency(parseFloat(discount.amount) || 0.00, order.currency)}</td>
                                                </tr>`
                                            ).join('') : ''
                                        }
                                        ${parseFloat(order.total_shipping) > 0 ? `
                                        <tr>
                                            <th colspan="4" class="text-end">Shipping:</th>
                                            <td class="text-end">${formatCurrency(order.total_shipping, order.currency)}</td>
                                        </tr>
                                        ` : ''}
                                        ${parseFloat(order.total_tax) > 0 ? `
                                        <tr>
                                            <th colspan="4" class="text-end">Tax:</th>
                                            <td class="text-end">${formatCurrency(order.total_tax, order.currency)}</td>
                                        </tr>
                                        ` : ''}
                                        <tr>
                                            <th colspan="4" class="text-end"><strong>Total:</strong></th>
                                            <td class="text-end"><strong>${formatCurrency(order.total_price, order.currency)}</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                
                content.html(html);
                
                // Store order ID for invoice actions
                modal.data('orderId', orderId);
                
                // Update send invoice button text if metafields email exists
                const sendInvoiceBtn = modal.find('.send-invoice');
                if (order.metafields?.customer_email) {
                    sendInvoiceBtn.html(`<i class="fas fa-paper-plane me-2"></i>Send Invoice to Customer`);
                    sendInvoiceBtn.show();
                    sendInvoiceBtn.off('click').on('click', function() {
                        sendInvoice(orderId);
                    });
                } else {
                    sendInvoiceBtn.hide();
                }
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

function viewInvoice(orderId) {
    if (!orderId) return;
    window.open(`invoice.php?order_id=${orderId}`, '_blank');
}

function sendInvoice(orderId) {
    const btn = $('.send-invoice');
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Sending...');
    
    $.post('ajax/send_invoice.php', { order_id: orderId })
        .done(function(response) {
            if (response.success) {
                // Close the order details modal
                $('#orderModal').modal('hide');
                
                // Show success popup
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Invoice has been sent successfully',
                    confirmButtonColor: '#198754'
                });
            } else {
                showModalAlert('danger', response.error || 'Failed to send invoice');
            }
        })
        .fail(function(jqXHR) {
            let errorMessage = 'Failed to send invoice';
            try {
                const response = JSON.parse(jqXHR.responseText);
                errorMessage = response.error || errorMessage;
            } catch (e) {
                console.error('Error parsing error response:', e);
            }
            showModalAlert('danger', errorMessage);
        })
        .always(function() {
            btn.prop('disabled', false).html(originalHtml);
        });
}

function showModalAlert(type, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    $('#modalAlerts').html(alertHtml);
    
    // Auto hide after 5 seconds
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
}

function formatAddress(address) {
    if (!address) return 'N/A';
    
    const parts = [];
    if (address.name) parts.push(address.name);
    if (address.company) parts.push(address.company);
    if (address.address1) parts.push(address.address1);
    if (address.address2) parts.push(address.address2);
    if (address.city) {
        let cityLine = address.city;
        if (address.province_code) cityLine += ', ' + address.province_code;
        if (address.postal_code) cityLine += ' ' + address.postal_code;
        parts.push(cityLine);
    }
    if (address.country) parts.push(address.country);
    if (address.phone) parts.push('Phone: ' + address.phone);
    
    return parts.join('<br>');
}

function formatOrderItems(items, currency) {
    if (!items || !items.length) return '<tr><td colspan="5" class="text-center">No items found</td></tr>';
    
    return items.map(item => `
        <tr>
            <td>${item.title}${item.variant_title ? `<br><small class="text-muted">${item.variant_title}</small>` : ''}</td>
            <td>${item.sku || 'N/A'}</td>
            <td>${formatCurrency(item.price, currency)}</td>
            <td>${item.quantity}</td>
            <td class="text-end">${formatCurrency(parseFloat(item.price) * parseInt(item.quantity), currency)}</td>
        </tr>
    `).join('');
}

function formatCurrency(amount, currency = 'USD') {
    if (!amount) return '0.00';
    
    const symbols = {
        'USD': '$',
        'EUR': '€',
        'GBP': '£',
        'INR': '₹',
        'MYR': 'RM ',
        'CAD': 'C$',
        'AUD': 'A$',
        'JPY': '¥',
        'CNY': '¥',
        'HKD': 'HK$'
    };
    
    const symbol = symbols[currency] ?? currency + ' ';
    return symbol + parseFloat(amount).toFixed(2);
}

function getFinancialStatusColor(status) {
    const colorMap = {
        'paid': 'success',
        'pending': 'warning',
        'refunded': 'danger',
        'partially_refunded': 'warning',
        'authorized': 'info',
        'partially_paid': 'warning',
        'voided': 'secondary'
    };
    return colorMap[status] || 'secondary';
}

function getFulfillmentStatusColor(status) {
    const colorMap = {
        'fulfilled': 'success',
        'partial': 'warning',
        'unfulfilled': 'secondary',
        'restocked': 'danger'
    };
    return colorMap[status] || 'secondary';
}

function getCommissionStatusColor(status) {
    const colorMap = {
        'pending': 'warning',
        'approved': 'success',
        'paid': 'success'
    };
    return colorMap[status] || 'secondary';
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded');
    if (typeof $.fn.DataTable !== 'undefined') {
        console.log('DataTable plugin found');
        if ($.fn.DataTable.isDataTable('#ordersTable')) {
            $('#ordersTable').DataTable().destroy();
        }
        
        $('#ordersTable').DataTable({
            order: [[5, 'desc']], // Sort by date column (index 5) in descending order
            pageLength: 25, // Show 25 entries per page
            language: {
                search: "Search orders:",
                lengthMenu: "Show _MENU_ orders per page",
                info: "Showing _START_ to _END_ of _TOTAL_ orders",
                infoEmpty: "No orders available",
                emptyTable: "No orders found"
            },
            columnDefs: [
                { orderable: false, targets: [6] } // Disable sorting on Actions column
            ]
        });
        console.log('DataTable initialized');
    } else {
        console.error('DataTable plugin not found');
    }
});

</script>

<?php include 'includes/footer.php'; ?>
