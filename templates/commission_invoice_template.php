<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Commission Invoice</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            color: #14284B;
            margin: 0;
            padding: 0;
        }
        .invoice-info {
            margin-bottom: 20px;
            overflow: hidden;
        }
        .invoice-info-left {
            float: left;
            width: 50%;
        }
        .invoice-info-right {
            float: right;
            width: 50%;
            text-align: right;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #14284B;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th {
            background-color: #f4f4f4;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        .text-right {
            text-align: right;
        }
        .totals-table {
            width: 300px;
            float: right;
            margin-top: 20px;
        }
        .totals-table td {
            padding: 5px;
        }
        .totals-table .total-row {
            font-weight: bold;
            background-color: #f4f4f4;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Commission Invoice</h1>
    </div>

    <div class="invoice-info">
        <div class="invoice-info-left">
            <div class="section-title">Agent Details:</div>
            <div><?php echo htmlspecialchars($commission['agent_first_name'] . ' ' . $commission['agent_last_name']); ?></div>
            <div><?php echo htmlspecialchars($commission['agent_email']); ?></div>
            <?php if (!empty($commission['agent_phone'])): ?>
                <div><?php echo htmlspecialchars($commission['agent_phone']); ?></div>
            <?php endif; ?>
        </div>
        <div class="invoice-info-right">
            <div><strong>Invoice #:</strong> COMM-<?php echo str_pad($commission['id'], 6, "0", STR_PAD_LEFT); ?></div>
            <div><strong>Invoice Date:</strong> <?php echo $invoice_date; ?></div>
            <div><strong>Order Date:</strong> <?php echo $order_date; ?></div>
            <div><strong>Order #:</strong> <?php echo htmlspecialchars($commission['order_number']); ?></div>
        </div>
    </div>

    <table>
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
            <?php foreach ($items_with_rules as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['product']); ?></td>
                <td><?php echo htmlspecialchars($item['type']); ?></td>
                <td class="text-right"><?php echo $item['quantity']; ?></td>
                <td class="text-right"><?php echo $currency_symbol . number_format($item['price'], 2); ?></td>
                <td class="text-right"><?php echo $currency_symbol . number_format($item['total'], 2); ?></td>
                <td class="text-right"><?php echo number_format($item['commission_percentage'], 1); ?>%</td>
                <td class="text-right"><?php echo $currency_symbol . number_format($item['commission_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td><strong>Total Sales:</strong></td>
            <td class="text-right"><?php echo $currency_symbol . number_format($total_amount, 2); ?></td>
        </tr>
        <tr>
            <td><strong>Average Commission Rate:</strong></td>
            <td class="text-right"><?php echo number_format($overall_rate, 1); ?>%</td>
        </tr>
        <tr class="total-row">
            <td><strong>Total Commission:</strong></td>
            <td class="text-right"><?php echo $currency_symbol . number_format($total_commission, 2); ?></td>
        </tr>
    </table>

    <div class="footer">
        <p>This is a computer-generated invoice. No signature required.</p>
        <p>Commission rates are calculated based on product types and applicable rules.</p>
    </div>
</body>
</html>
