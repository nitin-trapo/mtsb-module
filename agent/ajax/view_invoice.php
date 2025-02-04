<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/tables.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is agent
if (!isset($_SESSION['user_email']) || !is_agent()) {
    header('HTTP/1.1 401 Unauthorized');
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    } else {
        echo 'Unauthorized access';
    }
    exit;
}

// Get order_id from either POST or GET
$order_id = $_POST['order_id'] ?? $_GET['order_id'] ?? null;

if (!$order_id) {
    header('HTTP/1.1 400 Bad Request');
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'error' => 'Order ID is required']);
    } else {
        echo 'Order ID is required';
    }
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get agent details
    $stmt = $conn->prepare("SELECT id FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['user_email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception("Agent not found");
    }

    // Get order details with customer info and verify agent's access
    $query = "SELECT 
        o.*,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email,
        c.phone as customer_phone,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
        DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date
    FROM " . TABLE_ORDERS . " o
    LEFT JOIN " . TABLE_CUSTOMERS . " c ON o.customer_id = c.id
    WHERE o.id = ? AND o.agent_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id, $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found or you don't have permission to view it");
    }

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);
    $order['discount_applications'] = json_decode($order['discount_applications'], true) ?? [];

    // Format customer name
    $order['customer_name'] = trim($order['customer_first_name'] . ' ' . $order['customer_last_name']);

    // Generate invoice HTML
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Invoice #<?php echo $order['order_number']; ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
            .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0, 0, 0, .15); }
            .invoice-box table { width: 100%; line-height: inherit; text-align: left; }
            .invoice-box table td { padding: 5px; vertical-align: top; }
            .invoice-box table tr.top table td { padding-bottom: 20px; }
            .invoice-box table tr.information table td { padding-bottom: 40px; }
            .invoice-box table tr.heading td { background: #eee; border-bottom: 1px solid #ddd; font-weight: bold; }
            .invoice-box table tr.details td { padding-bottom: 20px; }
            .invoice-box table tr.item td { border-bottom: 1px solid #eee; }
            .invoice-box table tr.item.last td { border-bottom: none; }
            .invoice-box table tr.total td:nth-child(4) { border-top: 2px solid #eee; font-weight: bold; }
            .text-right { text-align: right; }
            .mt-4 { margin-top: 1.5rem; }
            @media only print {
                .invoice-box { box-shadow: none; border: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="invoice-box">
            <table cellpadding="0" cellspacing="0">
                <tr class="top">
                    <td colspan="4">
                        <table>
                            <tr>
                                <td>
                                    <h2 style="margin-top: 0;">Invoice</h2>
                                    Invoice #: <?php echo $order['order_number']; ?><br>
                                    Created: <?php echo $order['formatted_processed_date'] ?: $order['formatted_created_date']; ?><br>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr class="information">
                    <td colspan="4">
                        <table>
                            <tr>
                                <td>
                                    <strong>Billing Address:</strong><br>
                                    <?php if ($order['billing_address']): ?>
                                        <?php echo $order['customer_name']; ?><br>
                                        <?php echo $order['billing_address']['address1']; ?><br>
                                        <?php if (!empty($order['billing_address']['address2'])): ?>
                                            <?php echo $order['billing_address']['address2']; ?><br>
                                        <?php endif; ?>
                                        <?php echo $order['billing_address']['city']; ?>, 
                                        <?php echo $order['billing_address']['province']; ?> 
                                        <?php echo $order['billing_address']['zip']; ?><br>
                                        <?php echo $order['billing_address']['country']; ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>Shipping Address:</strong><br>
                                    <?php if ($order['shipping_address']): ?>
                                        <?php echo $order['customer_name']; ?><br>
                                        <?php echo $order['shipping_address']['address1']; ?><br>
                                        <?php if (!empty($order['shipping_address']['address2'])): ?>
                                            <?php echo $order['shipping_address']['address2']; ?><br>
                                        <?php endif; ?>
                                        <?php echo $order['shipping_address']['city']; ?>, 
                                        <?php echo $order['shipping_address']['province']; ?> 
                                        <?php echo $order['shipping_address']['zip']; ?><br>
                                        <?php echo $order['shipping_address']['country']; ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr class="heading">
                    <td>Item</td>
                    <td>SKU</td>
                    <td class="text-right">Quantity</td>
                    <td class="text-right">Price</td>
                </tr>

                <?php foreach ($order['line_items'] as $item): ?>
                <tr class="item">
                    <td>
                        <?php echo $item['title']; ?>
                        <?php if (!empty($item['variant_title'])): ?>
                            <br><small><?php echo $item['variant_title']; ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['sku'] ?: 'N/A'; ?></td>
                    <td class="text-right"><?php echo $item['quantity']; ?></td>
                    <td class="text-right">
                        <?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                            number_format($item['price'] * $item['quantity'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <tr class="total">
                    <td colspan="3" class="text-right">Subtotal:</td>
                    <td class="text-right">
                        <?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                            number_format($order['subtotal_price'], 2); ?>
                    </td>
                </tr>

                <?php if (!empty($order['discount_codes'])): ?>
                    <tr class="total">
                        <td colspan="3" class="text-right">Discount:</td>
                        <td class="text-right text-danger">
                            -<?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                                number_format($order['total_discounts'], 2); ?>
                        </td>
                    </tr>
                    <?php foreach ($order['discount_codes'] as $discount): ?>
                        <tr>
                            <td colspan="3" class="text-right">
                                <small>
                                    <?php 
                                        echo $discount['code'];
                                        if (!empty($discount['type'])) {
                                            echo " ({$discount['type']}";
                                            if (isset($discount['value'])) {
                                                echo " {$discount['value']}% off";
                                            }
                                            echo ")";
                                        }
                                    ?>
                                </small>
                            </td>
                            <td class="text-right text-danger">
                                <small>
                                    -<?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                                        number_format($discount['amount'], 2); ?>
                                </small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($order['total_shipping'] > 0): ?>
                <tr class="total">
                    <td colspan="3" class="text-right">Shipping:</td>
                    <td class="text-right">
                        <?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                            number_format($order['total_shipping'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($order['total_tax'] > 0): ?>
                <tr class="total">
                    <td colspan="3" class="text-right">Tax:</td>
                    <td class="text-right">
                        <?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                            number_format($order['total_tax'], 2); ?>
                    </td>
                </tr>
                <?php endif; ?>

                <tr class="total">
                    <td colspan="3" class="text-right"><strong>Total:</strong></td>
                    <td class="text-right">
                        <strong>
                            <?php echo ($order['currency'] === 'MYR' ? 'RM' : $order['currency']) . ' ' . 
                                number_format($order['total_price'], 2); ?>
                        </strong>
                    </td>
                </tr>
            </table>

            <div class="mt-4 no-print">
                <button onclick="window.print();" style="padding: 5px 10px;">Print Invoice</button>
            </div>
        </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    // For AJAX requests, return JSON
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode([
            'success' => true,
            'html' => $html
        ]);
    } else {
        // For direct access (GET request), output HTML
        echo $html;
    }

} catch (Exception $e) {
    header('HTTP/1.1 400 Bad Request');
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        echo 'Error: ' . $e->getMessage();
    }
}
