<?php
$line_items = json_decode($order['line_items'], true);
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
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 130px; padding: 0;">
                <div class="logo">MTSB</div>
            </td>
            <td class="invoice-details">
                <div class="invoice-title">COMMISSION NOTE</div>
                <div class="details-row">Date: <?php echo $order['created_at']; ?></div>
                <div class="details-row">Order No.: <?php echo $order['order_number']; ?></div>
            </td>
        </tr>
    </table>

    <div class="agent-info">
        <div class="address-title">Agent Information</div>
        <?php 
        $agent = json_decode($order['agent_details'], true);
        ?>
        <div class="address-content">
            <p><strong>Name:</strong> <?php echo htmlspecialchars($agent['name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($agent['email']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($agent['phone']); ?></p>
            <p><strong>Business Registration No:</strong> <?php echo htmlspecialchars($agent['business_registration_number'] ?? 'N/A'); ?></p>
            <p><strong>Tax Identification No:</strong> <?php echo htmlspecialchars($agent['tax_identification_number'] ?? 'N/A'); ?></p>
            <p><strong>IC Number:</strong> <?php echo htmlspecialchars($agent['ic_number'] ?? 'N/A'); ?></p>
        </div>
    </div>

    <div class="items-section">
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25%">Description</th>
                    <th style="width: 12%">Type</th>
                    <th style="width: 13%">Applied Rule</th>
                    <th style="width: 8%; text-align: center;">Qty</th>
                    <th style="width: 12%; text-align: right;">Unit Price</th>
                    <th style="width: 15%; text-align: right;">Total</th>
                    <th style="width: 15%; text-align: right;">Commission</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($line_items as $item): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($item['title']); ?>
                        <?php if (!empty($item['variant_title'])): ?>
                            <br><small>(<?php echo htmlspecialchars($item['variant_title']); ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($item['product_type'])): ?>
                            <span class="product-type"><?php echo htmlspecialchars($item['product_type']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="rule-type"><?php echo htmlspecialchars($item['rule_value']); ?></span>
                        <br><small><?php echo number_format($item['commission_percentage'], 1); ?>%</small>
                    </td>
                    <td style="text-align: center"><?php echo $item['quantity']; ?></td>
                    <td style="text-align: right">RM <?php echo number_format($item['price'], 2); ?></td>
                    <td style="text-align: right">RM <?php echo number_format($item['total'], 2); ?></td>
                    <td style="text-align: right">RM <?php echo number_format($item['commission_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <table class="totals-table">
        <tr>
            <td class="total-label">Total Commission Amount:</td>
            <td class="total-amount">RM <?php echo number_format($order['total_commission'], 2); ?></td>
        </tr>
        <?php if ($order['discount'] != 0): ?>
        <tr>
            <td class="total-label">Adjustment Amount:</td>
            <td class="total-amount discount">-RM <?php echo number_format(abs($order['discount']), 2); ?></td>
        </tr>
        <?php endif; ?>
        <tr class="total-row final">
            <td class="total-label">Final Commission Amount:</td>
            <td class="total-amount">RM <?php echo number_format($order['final_commission'], 2); ?></td>
        </tr>
    </table>

    <div class="terms-section">
        <div class="terms-title">Terms & Conditions:</div>
        <ul class="terms-list">
            <li>This commission invoice is generated based on the successful sales and delivery of products.</li>
            <li>Commission payment will be processed according to the company's commission payout schedule.</li>
            <li>This is a computer generated invoice and does not require signature.</li>
            <li>For any queries regarding commission, please contact our agent support team.</li>
        </ul>
    </div>

    <?php if ($order['status'] !== 'pending'): ?>
    <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
        <div style="margin-bottom: 10px;">
            <strong>Status:</strong> <?php echo ucfirst($order['status']); ?>
        </div>
        <?php if ($order['adjusted_by_name']): ?>
        <div style="margin-bottom: 5px;">
            <strong>Adjusted By:</strong> <?php echo htmlspecialchars($order['adjusted_by_name']); ?>
            on <?php echo date('d/m/Y H:i', strtotime($order['adjusted_at'])); ?>
        </div>
        <?php if ($order['adjustment_reason']): ?>
        <div style="margin-bottom: 5px;">
            <strong>Reason:</strong> <?php echo htmlspecialchars($order['adjustment_reason']); ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($order['paid_by_name']): ?>
        <div style="margin-bottom: 5px;">
            <strong>Paid By:</strong> <?php echo htmlspecialchars($order['paid_by_name']); ?>
            on <?php echo date('d/m/Y H:i', strtotime($order['paid_at'])); ?>
        </div>
        <?php if ($order['payment_note']): ?>
        <div style="margin-bottom: 5px;">
            <strong>Payment Note:</strong> <?php echo htmlspecialchars($order['payment_note']); ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="thank-you">
        Thank you for your service.
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
