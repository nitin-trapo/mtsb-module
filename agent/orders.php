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
        WHERE o.agent_id = ?
        ORDER BY o.created_at DESC
    ");

    $stmt->execute([$_SESSION['user_id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        SELECT * 
        FROM " . TABLE_CUSTOMERS . "
        WHERE email = ? AND is_agent = 1
    ");
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug: Print agent details
    error_log("Agent Details: " . print_r($agent, true));

    if (!$agent) {
        throw new Exception("No agent profile found for your email (" . $_SESSION['user_email'] . ")");
    }

    include 'includes/header.php';

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
            echo "Session Email: " . $_SESSION['user_email'] . "\n";
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
<div class="modal fade" id="orderModal" tabindex="-1" aria-labelledby="orderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderModalLabel">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="order-details-content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewDetails(orderId) {
    const modal = $('#orderModal');
    const content = modal.find('.order-details-content');
    
    // Show loading
    content.html('<div class="text-center"><div class="spinner-border text-primary" role="status"></div><div class="mt-2">Loading order details...</div></div>');
    modal.modal('show');
    
    // Load order details
    $.post('ajax/get_order_details.php', { order_id: orderId })
        .done(function(response) {
            if (response.success) {
                const order = response.order;
                let html = `
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Order Information</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Order Number:</strong></td>
                                            <td>#${order.order_number}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Created:</strong></td>
                                            <td>${order.formatted_created_date}</td>
                                        </tr>
                                        ${order.formatted_processed_date ? `
                                        <tr>
                                            <td><strong>Processed:</strong></td>
                                            <td>${order.formatted_processed_date}</td>
                                        </tr>
                                        ` : ''}
                                        <tr>
                                            <td><strong>Financial Status:</strong></td>
                                            <td>
                                                <span class="badge bg-${getStatusClass(order.financial_status)}">
                                                    ${(order.financial_status || 'PENDING').toUpperCase()}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Fulfillment Status:</strong></td>
                                            <td>
                                                <span class="badge bg-${getStatusClass(order.fulfillment_status)}">
                                                    ${(order.fulfillment_status || 'UNFULFILLED').toUpperCase()}
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Customer Information</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Name:</strong></td>
                                            <td>${order.customer_first_name} ${order.customer_last_name}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Email:</strong></td>
                                            <td>${order.customer_email}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Phone:</strong></td>
                                            <td>${order.customer_phone || 'N/A'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Commission Details</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Amount:</strong></td>
                                            <td>${order.formatted_commission}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Status:</strong></td>
                                            <td>
                                                <span class="badge bg-${getStatusClass(order.commission_status)}">
                                                    ${(order.commission_status || 'PENDING').toUpperCase()}
                                                </span>
                                            </td>
                                        </tr>
                                        ${order.commission_date ? `
                                        <tr>
                                            <td><strong>Date:</strong></td>
                                            <td>${order.commission_date}</td>
                                        </tr>
                                        ` : ''}
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Order Summary</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td><strong>Subtotal:</strong></td>
                                            <td class="text-end">${order.formatted_subtotal}</td>
                                        </tr>
                                        ${parseFloat(order.total_shipping) > 0 ? `
                                        <tr>
                                            <td><strong>Shipping:</strong></td>
                                            <td class="text-end">${order.formatted_shipping}</td>
                                        </tr>
                                        ` : ''}
                                        ${parseFloat(order.total_tax) > 0 ? `
                                        <tr>
                                            <td><strong>Tax:</strong></td>
                                            <td class="text-end">${order.formatted_tax}</td>
                                        </tr>
                                        ` : ''}
                                        <tr>
                                            <td><strong>Total:</strong></td>
                                            <td class="text-end"><strong>${order.formatted_total}</strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    ${order.billing_address || order.shipping_address ? `
                    <div class="row mb-3">
                        ${order.shipping_address ? `
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Shipping Address</h6>
                                    <address class="mb-0">
                                        ${formatAddress(order.shipping_address)}
                                    </address>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                        ${order.billing_address ? `
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Billing Address</h6>
                                    <address class="mb-0">
                                        ${formatAddress(order.billing_address)}
                                    </address>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    ` : ''}

                    ${order.note ? `
                    <div class="card mt-3">
                        <div class="card-body">
                            <h6 class="card-title">Order Notes</h6>
                            <p class="mb-0">${order.note}</p>
                        </div>
                    </div>
                    ` : ''}
                `;
                content.html(html);
            } else {
                content.html(`
                    <div class="alert alert-danger">
                        ${response.error || 'Failed to load order details'}
                    </div>
                `);
            }
        })
        .fail(function(jqXHR) {
            let errorMessage = 'Failed to load order details';
            try {
                const response = JSON.parse(jqXHR.responseText);
                if (response.error) {
                    errorMessage = response.error;
                }
            } catch (e) {}
            content.html(`
                <div class="alert alert-danger">
                    ${errorMessage}
                </div>
            `);
        });
}

function formatAddress(address) {
    if (!address) return 'N/A';
    try {
        const addr = typeof address === 'string' ? JSON.parse(address) : address;
        const parts = [];
        if (addr.name) parts.push(addr.name);
        if (addr.company) parts.push(addr.company);
        if (addr.address1) parts.push(addr.address1);
        if (addr.address2) parts.push(addr.address2);
        if (addr.city) {
            let cityLine = addr.city;
            if (addr.province_code) cityLine += ', ' + addr.province_code;
            if (addr.postal_code) cityLine += ' ' + addr.postal_code;
            parts.push(cityLine);
        }
        if (addr.country) parts.push(addr.country);
        if (addr.phone) parts.push('Phone: ' + addr.phone);
        return parts.join('<br>');
    } catch (e) {
        console.error('Error parsing address:', e);
        return 'Invalid address format';
    }
}

function getStatusClass(status) {
    switch (status?.toLowerCase()) {
        case 'completed':
        case 'paid':
        case 'fulfilled':
            return 'success';
        case 'pending':
        case 'partially_fulfilled':
            return 'warning';
        case 'cancelled':
        case 'refunded':
            return 'danger';
        case 'processing':
        case 'authorized':
            return 'primary';
        default:
            return 'secondary';
    }
}
</script>

<script>
window.addEventListener('load', function() {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        $('#ordersTable').DataTable({
            order: [[5, 'desc']], // Sort by date column
            pageLength: 25,
            language: {
                search: "Search orders:",
                lengthMenu: "Show _MENU_ orders per page",
                info: "Showing _START_ to _END_ of _TOTAL_ orders",
                infoEmpty: "No orders available",
                emptyTable: "No orders found"
            },
            columnDefs: [
                { orderable: false, targets: [7] }, // Actions column
                { 
                    targets: [3, 4], // Currency columns
                    render: function(data, type, row) {
                        if (type === 'sort') {
                            return data.replace(/[^\d.-]/g, '');
                        }
                        return data;
                    }
                },
                {
                    targets: [6], // Status column
                    render: function(data, type, row) {
                        if (type === 'sort') {
                            return $(data).text();
                        }
                        return data;
                    }
                }
            ]
        });
    } else {
        console.error('jQuery or DataTables not loaded');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
