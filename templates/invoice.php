<?php
$subtotal = 0;
$total_discount = 0;
$discount_codes = json_decode($order['discount_codes'], true);
if (!empty($discount_codes)) {
    foreach ($discount_codes as $discount) {
        $total_discount += floatval($discount['amount']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice</title>
    <style>
        @page {
            margin: 25px 30px;
            size: A4 portrait;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 11px;
            color: #000;
            line-height: 1.3;
        }
        .header {
            width: 100%;
            margin-bottom: 20px;
        }
        .header-table {
            width: 100%;
            margin-bottom: 25px;
            border-collapse: collapse;
        }
        .logo {
            width: 80px;
            height: 80px;
            background-color: #14325A;
            color: white;
            text-align: center;
            line-height: 80px;
            font-size: 28px;
            font-weight: bold;
        }
        .invoice-details {
            text-align: right;
            padding-right: 0;
            vertical-align: top;
            padding-top: 5px;
        }
        .invoice-title {
            color: #14325A;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .details-row {
            margin: 3px 0;
            font-size: 12px;
        }
        .address-section {
            width: 100%;
            margin-bottom: 20px;
        }
        .address-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: separate;
            border-spacing: 0;
        }
        .address-title {
            font-weight: bold;
            color: #14325A;
            font-size: 13px;
            margin-bottom: 6px;
        }
        .address-content {
            line-height: 1.4;
            font-size: 11px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background-color: #14325A;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-size: 11px;
            font-weight: normal;
        }
        .items-table td {
            padding: 8px 6px;
            border: 1px solid #ddd;
            font-size: 11px;
            vertical-align: top;
        }
        .items-table .sku {
            color: #666;
            font-size: 10px;
            margin-top: 3px;
        }
        .total-box {
            text-align: right;
            margin: -60px 0 30px 0;
            font-size: 18px;
            font-weight: bold;
            color: #14325A;
            line-height: 1.2;
        }
        .total-label {
            font-size: 11px;
            color: #14325A;
            font-weight: normal;
        }
        .totals-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: separate;
            border-spacing: 0 6px;
        }
        .total-label {
            text-align: right;
            padding-right: 15px;
            width: 70%;
            font-size: 11px;
        }
        .total-amount {
            text-align: right;
            width: 30%;
            color: #14325A;
            font-size: 11px;
        }
        .total-row.final {
            font-weight: bold;
        }
        .terms-section {
            margin: 25px 0;
        }
        .terms-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 11px;
        }
        .terms-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .terms-list li {
            margin-bottom: 5px;
            padding-left: 12px;
            position: relative;
            line-height: 1.3;
            font-size: 10px;
        }
        .terms-list li:before {
            content: "•";
            position: absolute;
            left: 0;
            top: -1px;
        }
        .thank-you {
            text-align: center;
            margin: 25px 0;
            font-weight: bold;
            font-size: 12px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .company-footer {
            margin-top: 50px;
            font-size: 11px;
        }
        .footer-table {
            width: 100%;
            margin-top: 30px;
            border-collapse: separate;
            border-spacing: 0 3px;
        }
        .footer-cell {
            vertical-align: top;
            width: 50%;
            font-size: 10px;
            line-height: 1.4;
        }
        .footer-title {
            font-weight: bold;
            margin-bottom: 4px;
            font-size: 11px;
        }
        .price-original {
            text-decoration: line-through;
            color: #999;
            font-size: 10px;
            margin-bottom: 2px;
        }
        .price-discounted {
            margin-bottom: 2px;
        }
        .price-discount {
            color: #666;
            font-size: 10px;
        }
        .strikethrough {
            text-decoration: line-through;
            color: #999;
            font-size: 10px;
        }
        .discount-text {
            color: #666;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 130px; padding: 0;">
                <div class="logo">MTSB</div>
            </td>
            <td class="invoice-details">
                <div class="invoice-title">INVOICE</div>
                <div class="details-row">Order Date: <?php echo date('d/m/Y', strtotime($order['created_at'])); ?></div>
                <div class="details-row">Invoice No.: MT-CP<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></div>
            </td>
        </tr>
    </table>

    <table class="address-table">
        <tr>
            <td style="width: 50%; padding-right: 50px;">
                <div class="address-title">Billing Details</div>
                <div class="address-content">
                    <?php 
                    $billing = json_decode($order['billing_address'], true);
                    if ($billing): 
                    ?>
                    Attn <?php echo htmlspecialchars($billing['name']); ?><br>
                    MILLENNIUM AUTOBEYOND SDN BHD<br>
                    No 691, Batu 5, Jalan Cheras<br>
                    Taman Mutiara Barat<br>
                    Kuala Lumpur, KUL 56100
                    <?php endif; ?>
                </div>
            </td>
            <td style="width: 50%;">
                <div class="address-title">Shipping Details</div>
                <div class="address-content">
                    <?php 
                    $shipping = json_decode($order['shipping_address'], true);
                    if ($shipping): 
                    ?>
                    Attn <?php echo htmlspecialchars($shipping['name']); ?><br>
                    BYD CHERAS MILLENNIUM AUTOBEYOND<br>
                    No 691, Batu 5, Jalan Cheras<br>
                    Kuala Lumpur, KUL 56000
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 40%">Description</th>
                <th style="width: 10%; text-align: center;">Qty</th>
                <th style="width: 15%; text-align: right;">Unit Price</th>
                <th style="width: 15%; text-align: right;">Subtotal</th>
                <th style="width: 10%; text-align: right;">Tax</th>
                <th style="width: 10%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $line_items = json_decode($order['line_items'], true);
            foreach ($line_items as $item):
                $unit_price = $item['price'];
                $item_subtotal = $unit_price * $item['quantity'];
                $subtotal += $item_subtotal;
                $original_price = isset($item['original_price']) ? $item['original_price'] : $unit_price;
                $discount = $original_price - $unit_price;
            ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($item['title']); ?>
                    <?php if (!empty($item['sku'])): ?>
                        <div class="sku">SKU: <?php echo htmlspecialchars($item['sku']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align: center"><?php echo $item['quantity']; ?></td>
                <td style="text-align: right">
                    <?php if ($discount > 0): ?>
                        <div class="price-original">RM<?php echo number_format($original_price, 2); ?></div>
                        <div class="price-discounted">RM<?php echo number_format($unit_price, 2); ?></div>
                        <div class="price-discount">(Discount RM<?php echo number_format($discount, 2); ?>)</div>
                    <?php else: ?>
                        RM<?php echo number_format($unit_price, 2); ?>
                    <?php endif; ?>
                </td>
                <td style="text-align: right">RM<?php echo number_format($item_subtotal, 2); ?></td>
                <td style="text-align: right">RM0.00</td>
                <td style="text-align: right">RM<?php echo number_format($item_subtotal, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td class="total-label">Subtotal :</td>
            <td class="total-amount">RM<?php echo number_format($subtotal, 2); ?> MYR</td>
        </tr>
        <tr>
            <td class="total-label">Discount :</td>
            <td class="total-amount">-RM<?php echo number_format($total_discount, 2); ?> MYR</td>
        </tr>
        <tr>
            <td class="total-label">Subtotal after discount :</td>
            <td class="total-amount">RM<?php echo number_format($subtotal - $total_discount, 2); ?> MYR</td>
        </tr>
        <tr class="total-row final">
            <td class="total-label">Total :</td>
            <td class="total-amount">RM<?php echo number_format($subtotal - $total_discount, 2); ?> MYR</td>
        </tr>
        <tr>
            <td class="total-label">Paid by customer :</td>
            <td class="total-amount">RM0.00 MYR</td>
        </tr>
        <tr class="total-row final">
            <td class="total-label">Outstanding (Customer owes) :</td>
            <td class="total-amount">RM<?php echo number_format($subtotal - $total_discount, 2); ?> MYR</td>
        </tr>
    </table>

    <div class="terms-section">
        <div class="terms-title">Terms:</div>
        <ul class="terms-list">
            <li>This is a computer generated invoice and does not require signature.</li>
            <li>For warranty and returns related information, please contact our customer support.</li>
            <li>Payment can be made payable to Millenium Trapo Sdn. Bhd. Account No.: 564164996568 (Maybank)</li>
        </ul>
    </div>

    <div class="thank-you">
        Thank you for your purchase.
    </div>

    <table class="footer-table">
        <tr>
            <td class="footer-cell">
                <div class="footer-title">Company</div>
                MILLENNIUM TRAPO SDN. BHD. (1524667-W)<br>
                Lot 2097, Off Jalan Kuching, Mukim Batu<br>
                Segambut, Kuala Lumpur 51200<br>
                Malaysia
            </td>
            <td class="footer-cell" style="text-align: right;">
                <div class="footer-title">Support</div>
                cscommercial.my@trapo.com<br>
                06-2889 922
            </td>
        </tr>
    </table>
</body>
</html>
