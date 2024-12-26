<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is agent
if (!isset($_SESSION['email']) || !is_agent()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

if (!isset($_POST['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Order ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get agent details
    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? AND is_agent = 1");
    $stmt->execute([$_SESSION['email']]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception("Agent not found");
    }

    $order_id = $_POST['order_id'];

    // Get order details with customer info
    $query = "SELECT 
        o.*,
        c.first_name as customer_first_name,
        c.last_name as customer_last_name,
        c.email as customer_email,
        c.phone as customer_phone,
        DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
        DATE_FORMAT(o.created_at, '%b %d, %Y %h:%i %p') as formatted_created_date
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ? AND (o.customer_id = ?)";

    $stmt = $conn->prepare($query);
    $stmt->execute([$order_id, $agent['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    // Decode JSON fields
    $order['shipping_address'] = json_decode($order['shipping_address'], true);
    $order['billing_address'] = json_decode($order['billing_address'], true);
    $order['line_items'] = json_decode($order['line_items'], true);
    $order['discount_codes'] = json_decode($order['discount_codes'], true);

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
                                    <?php
                                    $billing = $order['billing_address'];
                                    echo $billing['name'] . "<br>";
                                    if (!empty($billing['company'])) echo $billing['company'] . "<br>";
                                    echo $billing['address1'] . "<br>";
                                    if (!empty($billing['address2'])) echo $billing['address2'] . "<br>";
                                    echo $billing['city'] . ", " . $billing['province_code'] . " " . $billing['postal_code'] . "<br>";
                                    echo $billing['country'] . "<br>";
                                    if (!empty($billing['phone'])) echo "Phone: " . $billing['phone'];
                                    ?>
                                </td>
                                <td>
                                    <strong>Shipping Address:</strong><br>
                                    <?php
                                    $shipping = $order['shipping_address'];
                                    echo $shipping['name'] . "<br>";
                                    if (!empty($shipping['company'])) echo $shipping['company'] . "<br>";
                                    echo $shipping['address1'] . "<br>";
                                    if (!empty($shipping['address2'])) echo $shipping['address2'] . "<br>";
                                    echo $shipping['city'] . ", " . $shipping['province_code'] . " " . $shipping['postal_code'] . "<br>";
                                    echo $shipping['country'] . "<br>";
                                    if (!empty($shipping['phone'])) echo "Phone: " . $shipping['phone'];
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr class="heading">
                    <td>Item</td>
                    <td>SKU</td>
                    <td>Quantity</td>
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
                    <td><?php echo $item['quantity']; ?></td>
                    <td class="text-right"><?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                </tr>
                <?php endforeach; ?>

                <tr class="total">
                    <td colspan="3" class="text-right">Subtotal:</td>
                    <td class="text-right"><?php echo number_format($order['subtotal_price'], 2); ?></td>
                </tr>

                <?php if (!empty($order['discount_codes'])): ?>
                    <?php foreach ($order['discount_codes'] as $discount): ?>
                    <tr class="total">
                        <td colspan="3" class="text-right">Discount (<?php echo $discount['code']; ?>):</td>
                        <td class="text-right">-<?php echo number_format(floatval($discount['amount']) ?: 0.00, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (floatval($order['total_shipping']) > 0): ?>
                <tr class="total">
                    <td colspan="3" class="text-right">Shipping:</td>
                    <td class="text-right"><?php echo number_format($order['total_shipping'], 2); ?></td>
                </tr>
                <?php endif; ?>

                <?php if (floatval($order['total_tax']) > 0): ?>
                <tr class="total">
                    <td colspan="3" class="text-right">Tax:</td>
                    <td class="text-right"><?php echo number_format($order['total_tax'], 2); ?></td>
                </tr>
                <?php endif; ?>

                <tr class="total">
                    <td colspan="3" class="text-right"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?php echo number_format($order['total_price'], 2); ?></strong></td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html' => $html
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
