<?php
// Ensure all required variables are available
if (!isset($commission) || !isset($items_with_rules) || !isset($total_amount)) {
    throw new Exception('Required variables are not set');
}

// Helper functions
function formatNumber($number, $decimals = 2) {
    return number_format(floatval($number), $decimals);
}

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!-- Order Items -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">Commission Details</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th>Applied Rule</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Commission</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_with_rules as $item): ?>
                    <tr>
                        <td>
                            <?php echo e($item['product']); ?>
                            <?php if (!empty($item['variant_title'])): ?>
                            <br><small class="text-muted"><?php echo e($item['variant_title']); ?></small>
                            <?php endif; ?>
                            <?php if ($item['sku'] !== 'N/A'): ?>
                            <br><small class="text-muted">SKU: <?php echo e($item['sku']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-info"><?php echo e($item['type']); ?></span></td>
                        <td class="text-center"><?php echo intval($item['quantity']); ?></td>
                        <td class="text-end">RM <?php echo formatNumber($item['price']); ?></td>
                        <td class="text-end">RM <?php echo formatNumber($item['total']); ?></td>
                        <td>
                            <?php echo e($item['rule_type']); ?>
                            <?php if (!empty($item['adjustment_reason'])): ?>
                            <br><small class="text-muted"><?php echo e($item['adjustment_reason']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo formatNumber($item['commission_percentage'], 1); ?>%</td>
                        <td class="text-end">RM <?php echo formatNumber($item['commission_amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-primary fw-bold">
                        <td colspan="4">Total</td>
                        <td class="text-end">RM <?php echo formatNumber($total_amount); ?></td>
                        <td></td>
                        <td class="text-end"><?php 
                            echo formatNumber(($total_amount > 0 ? ($commission['amount'] / $total_amount * 100) : 0), 1); 
                        ?>%</td>
                        <td class="text-end">RM <?php echo formatNumber($commission['amount']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Order and Commission Information -->
<div class="row mt-4">
    <!-- Left Column -->
    <div class="col-md-6">
        <!-- Order Information -->
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Order Information</h6>
                <table class="table table-sm mb-0">
                    <tr>
                        <td width="40%">Order Number:</td>
                        <td>#<?php echo e($commission['order_number']); ?></td>
                    </tr>
                    <?php if (!empty($commission['order_amount'])): ?>
                    <tr>
                        <td>Order Amount:</td>
                        <td>RM <?php echo formatNumber($commission['order_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($commission['order_date'])): ?>
                    <tr>
                        <td>Order Date:</td>
                        <td><?php echo date('M d, Y', strtotime($commission['order_date'])); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Customer Information</h6>
                <table class="table table-sm mb-0">
                    <?php if (!empty($commission['customer_first_name']) || !empty($commission['customer_last_name'])): ?>
                    <tr>
                        <td width="40%">Name:</td>
                        <td><?php echo e(trim($commission['customer_first_name'] . ' ' . $commission['customer_last_name'])); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($commission['customer_email'])): ?>
                    <tr>
                        <td>Email:</td>
                        <td><?php echo e($commission['customer_email']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($billing_address)): ?>
                    <tr>
                        <td>Billing Address:</td>
                        <td><?php echo formatAddress($billing_address); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address)): ?>
                    <tr>
                        <td>Shipping Address:</td>
                        <td><?php echo formatAddress($shipping_address); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-md-6">
        <!-- Commission Information -->
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Commission Information</h6>
                <table class="table table-sm mb-0">
                    <tr>
                        <td width="40%">Status:</td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $commission['status'] === 'paid' ? 'success' : 
                                    ($commission['status'] === 'approved' ? 'warning' : 'info'); 
                            ?>">
                                <?php echo strtoupper($commission['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td>Created Date:</td>
                        <td><?php echo e($commission['formatted_date']); ?></td>
                    </tr>
                    <?php if (!empty($commission['adjusted_by'])): ?>
                    <tr>
                        <td>Adjusted By:</td>
                        <td><?php echo e($commission['adjusted_by_name']); ?></td>
                    </tr>
                    <?php if (!empty($commission['adjustment_reason'])): ?>
                    <tr>
                        <td>Adjustment Reason:</td>
                        <td><?php echo e($commission['adjustment_reason']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($commission['paid_by'])): ?>
                    <tr>
                        <td>Paid By:</td>
                        <td><?php echo e($commission['paid_by_name']); ?></td>
                    </tr>
                    <?php if (!empty($commission['paid_at'])): ?>
                    <tr>
                        <td>Paid Date:</td>
                        <td><?php echo date('M d, Y', strtotime($commission['paid_at'])); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if (!empty($note)): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Order Notes</h6>
                <p class="mb-0"><?php echo e($note); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
