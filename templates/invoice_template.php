<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 12px;
            color: #000;
            line-height: 1.2;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table {
            margin-bottom: 5px;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo-cell {
            width: 100px;
        }
        .logo-cell img {
            width: 100px;
        }
        .invoice-header {
            text-align: right;
        }
        .invoice-title {
            font-size: 28px;
            color: #14284B;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 5px;
        }
        .invoice-details {
            color: #14284B;
            font-size: 12px;
            line-height: 1.6;
        }
        .header-border {
            border-bottom: 2px solid #14284B;
            margin-bottom: 30px;
        }
        .details-section {
            position: relative;
            margin-top: 30px;
            margin-bottom: 30px;
        }
        .billing-details {
            float: left;
            width: 280px;
        }
        .section-title {
            color: #14284B;
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 14px;
            text-transform: uppercase;
        }
        .details-content {
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .details-content div {
            margin-bottom: 3px;
        }
        .total-section {
            float: right;
            text-align: right;
            width: 200px;
        }
        .total-amount {
            font-size: 20px;
            color: #14284B;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .total-label {
            color: #14284B;
            font-size: 12px;
            text-align: right;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
            margin-bottom: 30px;
            clear: both;
        }
        .items-table th {
            background-color: #14284B;
            color: white;
            text-align: left;
            padding: 12px 15px;
            font-weight: normal;
            font-size: 12px;
            border: 1px solid #14284B;
        }
        .items-table td {
            padding: 12px 15px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        .sku {
            color: #666;
            font-size: 11px;
            margin-top: 4px;
        }
        .summary-table {
            width: 350px;
            margin-left: auto;
            margin-bottom: 30px;
            border-collapse: collapse;
            clear: both;
        }
        .summary-table td {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .summary-table tr:last-child td {
            border-bottom: 2px solid #14284B;
            font-weight: bold;
            padding: 12px 0;
        }
        .summary-table .label {
            color: #666;
            text-align: left;
        }
        .summary-table .amount {
            text-align: right;
            padding-left: 40px;
        }
        .discount-row td {
            color: #666;
            font-size: 11px;
            padding-left: 20px;
        }
        .footer {
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
            clear: both;
        }
        .footer-section {
            float: left;
            width: 280px;
        }
        .footer-title {
            color: #14284B;
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 12px;
        }
        .footer-content {
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                <?php if (!empty($logo_base64)): ?>
                <img src="<?php echo $logo_base64; ?>" alt="MTSB" style="max-width: 100px; height: auto;">
                <?php endif; ?>
            </td>
            <td class="invoice-header">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-details">
                    <div><strong>Issue Date:</strong> <?php echo date('m/d/Y', strtotime($order['created_at'])); ?></div>
                    <div><strong>Invoice No.:</strong> <?php echo $order['order_number']; ?></div>
                </div>
            </td>
        </tr>
    </table>
    <div class="header-border"></div>

    <div class="details-section">
        <div class="billing-details">
            <div class="section-title">Billing Details</div>
            <div class="details-content">
                <div><strong><?php echo htmlspecialchars($order['customer_name'] ?: 'Customer'); ?></strong></div>
                <div><?php echo htmlspecialchars($order['billing_address']['address1'] ?? ''); ?></div>
                <?php if (!empty($order['billing_address']['address2'])): ?>
                    <div><?php echo htmlspecialchars($order['billing_address']['address2']); ?></div>
                <?php endif; ?>
                <div>
                    <?php echo htmlspecialchars($order['billing_address']['city'] ?? ''); ?>, 
                    <?php echo htmlspecialchars($order['billing_address']['province_code'] ?? ''); ?> 
                    <?php echo htmlspecialchars($order['billing_address']['zip'] ?? ''); ?>
                </div>
                <div><?php echo htmlspecialchars($order['billing_address']['country'] ?? ''); ?></div>
                <?php if (!empty($order['customer_phone'])): ?>
                    <div>Phone: <?php echo htmlspecialchars($order['customer_phone']); ?></div>
                <?php endif; ?>
                <?php if (!empty($order['customer_email'])): ?>
                    <div>Email: <?php echo htmlspecialchars($order['customer_email']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="total-section">
            <div class="total-amount">RM<?php echo number_format($order['total_price'], 2); ?> MYR</div>
            <div class="total-label">TOTAL</div>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Subtotal</th>
                <th>Tax</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order['line_items'] as $item): ?>
            <tr>
                <td>
                    <?php echo $item['name']; ?>
                    <div class="sku">SKU: <?php echo $item['sku']; ?></div>
                </td>
                <td><?php echo $item['quantity']; ?></td>
                <td>RM<?php echo number_format($item['price'], 2); ?></td>
                <td>RM<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                <td>RM<?php echo number_format($item['tax_lines'][0]['price'] ?? 0, 2); ?></td>
                <td>RM<?php echo number_format(($item['price'] * $item['quantity']) + ($item['tax_lines'][0]['price'] ?? 0), 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="label">Subtotal:</td>
            <td class="amount">RM<?php echo number_format($order['subtotal_price'], 2); ?> MYR</td>
        </tr>
        <?php if (!empty($order['total_discounts']) && $order['total_discounts'] > 0): ?>
            <tr>
                <td class="label">Discount:</td>
                <td class="amount">-RM<?php echo number_format($order['total_discounts'], 2); ?> MYR</td>
            </tr>
            <?php 
            if (!empty($order['discount_codes'])) {
                $discounts = $order['discount_codes'];
                
                    foreach($discounts as $discount) {
                        if (isset($discount['code'])) {
                            echo '<tr class="discount-row"><td colspan="2">' . htmlspecialchars($discount['code']);
                            if (isset($discount['type']) && $discount['type'] === 'percentage' && isset($discount['amount'])) {
                                echo ' (-' . number_format($discount['amount'], 2) . '%)';
                            }
                            echo '</td></tr>';
                        }
                    }
                
            }
            ?>
        <?php endif; ?>
        <?php if (!empty($order['total_tax']) && $order['total_tax'] > 0): ?>
            <tr>
                <td class="label">Tax:</td>
                <td class="amount">RM<?php echo number_format($order['total_tax'], 2); ?> MYR</td>
            </tr>
        <?php endif; ?>
        <tr>
            <td class="label">Total:</td>
            <td class="amount">RM<?php echo number_format($order['total_price'], 2); ?> MYR</td>
        </tr>
        <tr>
            <td class="label">Paid by customer:</td>
            <td class="amount">RM0.00 MYR</td>
        </tr>
        <tr>
            <td class="label">Outstanding (Customer owes):</td>
            <td class="amount">RM<?php echo number_format($order['total_price'], 2); ?> MYR</td>
        </tr>
    </table>

    <?php if (!empty($order['note'])): ?>
    <div class="notes">
        <div class="notes-title">Notes:</div>
        <div><?php echo nl2br(htmlspecialchars($order['note'])); ?></div>
    </div>
    <?php endif; ?>

    <div class="terms">
        <div class="notes-title">Terms:</div>
        <ul>
            <li>This is a computer generated invoice and does not require signature.</li>
            <li>For warranty and returns related information, please contact our customer support.</li>
            <li>Payment can be made payable to Millenium Trapo Sdn. Bhd. Account No.: 564164996568 (Maybank)</li>
        </ul>
    </div>

    <div class="thank-you">
        Thank you for your purchase.
    </div>

    <div class="footer">
        <div class="footer-section">
            <div class="footer-title">Company</div>
            <div class="footer-content">
                MILLENNIUM TRAPO SDN. BHD. (1524667-W)<br>
                Lot 2097, Off Jalan Kuching, Mukim Batu<br>
                Segambut, Kuala Lumpur 51200<br>
                Malaysia
            </div>
        </div>
        <div class="footer-section">
            <div class="footer-title">Support</div>
            <div class="footer-content">
                cscommercial.my@trapo.com<br>
                06-2889 922
            </div>
        </div>
    </div>
</body>
</html>
