<?php
$line_items = json_decode($commission['line_items'], true);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Commission Invoice</title>
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
        .product-type {
            display: inline-block;
            padding: 2px 6px;
            background-color: #14325A;
            color: white;
            border-radius: 3px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .rule-type {
            display: inline-block;
            padding: 2px 6px;
            background-color: #757575;
            color: white;
            border-radius: 3px;
            font-size: 10px;
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
        .discount {
            color: #F44336;
        }
        .agent-info {
            margin-bottom: 30px;
        }
        .agent-info p {
            margin: 5px 0;
        }
        .agent-info strong {
            color: #14325A;
            display: inline-block;
            width: 150px;
        }
        .items-section {
            margin-top: 20px;
        }
        .agent-commission {
            font-weight: bold;
            font-size: 14px;
            color: #14325A;
        }
        .commission-amount {
            font-size: 14px;
            color: #14325A;
        }
        .commission-rate {
            font-size: 12px;
            color: #666;
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
                <div class="invoice-title">Commission Invoice</div>
                <div class="details-row">Invoice #: COMM-<?php echo str_pad($commission['id'], 6, "0", STR_PAD_LEFT); ?></div>
                <div class="details-row">Invoice Date: <?php echo date('d/m/Y'); ?></div>
                <div class="details-row">Order #: <?php echo htmlspecialchars($commission['order_number']); ?></div>
                <div class="details-row">Order Date: <?php echo $commission['formatted_created_date']; ?></div>
            </td>
        </tr>
    </table>

    <table class="address-table">
        <tr>
            <td style="width: 50%; padding-right: 20px;">
                <div class="address-title">Agent Details</div>
                <div class="address-content">
                    <?php echo htmlspecialchars($commission['agent_first_name'] . ' ' . $commission['agent_last_name']); ?><br>
                    <?php echo htmlspecialchars($commission['agent_email']); ?><br>
                    <?php if (!empty($commission['agent_phone'])): ?>
                        <?php echo htmlspecialchars($commission['agent_phone']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($commission['business_registration_number'])): ?>
                        Business Reg. No: <?php echo htmlspecialchars($commission['business_registration_number']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($commission['tax_identification_number'])): ?>
                        Tax ID: <?php echo htmlspecialchars($commission['tax_identification_number']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($commission['ic_number'])): ?>
                        IC Number: <?php echo htmlspecialchars($commission['ic_number']); ?>
                    <?php endif; ?>
                </div>
            </td>
            <td style="width: 50%; padding-left: 20px;">
                <div class="address-title">Order Details</div>
                <div class="address-content">
                    Order Total: <?php echo number_format($commission['order_total'], 2); ?><br>
                    Order Status: <?php echo ucfirst($commission['status']); ?><br>
                    Processed Date: <?php echo $commission['formatted_processed_date']; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30%;">Product</th>
                <th style="width: 15%;">Type</th>
                <th style="width: 10%;">Qty</th>
                <th style="width: 12%;">Price</th>
                <th style="width: 12%;">Total</th>
                <th style="width: 10%;">Rate</th>
                <th style="width: 11%;">Commission</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items_with_commission as $item): ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($item['title']); ?>
                    <?php if (!empty($item['variant_title'])): ?>
                        <br><small><?php echo htmlspecialchars($item['variant_title']); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="product-type"><?php echo htmlspecialchars($item['rule_type']); ?></span>
                </td>
                <td style="text-align: center;"><?php echo $item['quantity']; ?></td>
                <td style="text-align: right;"><?php echo number_format($item['price'], 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($item['total'], 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($item['commission_rate'], 1); ?>%</td>
                <td style="text-align: right;"><?php echo number_format($item['commission_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td class="total-label">Subtotal:</td>
            <td class="total-amount"><?php echo number_format($commission['order_total'], 2); ?></td>
        </tr>
        <?php if (!empty($commission['adjusted_by'])): ?>
        <tr>
            <td class="total-label">Adjusted By:</td>
            <td class="total-amount"><?php echo htmlspecialchars($commission['adjusted_by_name']); ?></td>
        </tr>
        <?php endif; ?>
        <tr class="total-row final">
            <td class="total-label">Total Commission:</td>
            <td class="total-amount"><?php echo number_format($commission['commission_amount'], 2); ?></td>
        </tr>
    </table>

    <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
        <div style="margin-bottom: 10px;">
            <strong>Status:</strong> <?php echo ucfirst($commission['status']); ?>
        </div>
        <?php if ($commission['adjusted_by_name']): ?>
            <div style="margin-bottom: 10px;">
                <strong>Adjusted By:</strong> <?php echo htmlspecialchars($commission['adjusted_by_name']); ?>
            </div>
        <?php endif; ?>
        <?php if ($commission['paid_by_name']): ?>
            <div style="margin-bottom: 10px;">
                <strong>Paid By:</strong> <?php echo htmlspecialchars($commission['paid_by_name']); ?>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Paid Date:</strong> <?php echo $commission['paid_at']; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="terms-section">
        <div class="terms-title">Terms & Conditions</div>
        <ul class="terms-list">
            <li>This commission invoice is generated based on the order details and applicable commission rules.</li>
            <li>Commission rates are determined by product type and other applicable factors.</li>
            <li>Any adjustments to the commission amounts are subject to admin approval.</li>
            <li>Payment will be processed according to the agreed payment schedule.</li>
            <li>Please retain this invoice for your records.</li>
        </ul>
    </div>

    <div class="thank-you">
        Thank you for your partnership!
    </div>

    <table class="footer-table">
        <tr>
            <td class="footer-cell">
                <div class="footer-title">Contact Information</div>
                Email: support@mtsb.com<br>
                Phone: +1234567890
            </td>
            <td class="footer-cell" style="text-align: right;">
                <div class="footer-title">Invoice Generated</div>
                Date: <?php echo date('d/m/Y'); ?><br>
                Time: <?php echo date('H:i:s'); ?>
            </td>
        </tr>
    </table>
</body>
</html>
