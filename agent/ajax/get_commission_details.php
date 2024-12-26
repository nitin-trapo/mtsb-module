<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

// Function to find applicable commission rule
function getCommissionRate($conn, $product_type, $product_tags) {
    error_log("Getting commission rate - Type: " . $product_type . ", Tags: " . json_encode($product_tags));
    
    try {
        // First try to get rate by product type
        if (!empty($product_type)) {
            $type_query = "SELECT * 
                          FROM commission_rules 
                          WHERE status = 'active' 
                          AND rule_type = 'product_type' 
                          AND LOWER(rule_value) = LOWER(:product_type)
                          LIMIT 1";
            
            $stmt = $conn->prepare($type_query);
            $stmt->bindParam(':product_type', $product_type);
            if ($stmt->execute()) {
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($rule) {
                    error_log("Found rule by product type: " . json_encode($rule));
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Product Type',
                        'rule_value' => $product_type,
                        'rule_id' => $rule['id']
                    ];
                }
            }
        }
        
        // Then try by product tags
        if (!empty($product_tags)) {
            $tags_array = is_array($product_tags) ? $product_tags : explode(',', $product_tags);
            $tags_array = array_map('trim', $tags_array);
            $placeholders = str_repeat('?,', count($tags_array) - 1) . '?';
            
            $tag_query = "SELECT * 
                         FROM commission_rules 
                         WHERE status = 'active' 
                         AND rule_type = 'product_tag' 
                         AND LOWER(rule_value) IN (" . implode(',', array_fill(0, count($tags_array), 'LOWER(?)')) . ")
                         ORDER BY commission_percentage DESC
                         LIMIT 1";
            
            $stmt = $conn->prepare($tag_query);
            if ($stmt->execute($tags_array)) {
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($rule) {
                    error_log("Found rule by product tag: " . json_encode($rule));
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Product Tag',
                        'rule_value' => $rule['rule_value'],
                        'rule_id' => $rule['id']
                    ];
                }
            }
        }
        
        // Finally, get default rate
        $default_query = "SELECT * 
                        FROM commission_rules 
                        WHERE status = 'active' 
                        AND rule_type = 'default' 
                        LIMIT 1";
        $stmt = $conn->prepare($default_query);
        if ($stmt->execute()) {
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                error_log("Using default rule: " . json_encode($rule));
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Default',
                    'rule_value' => 'All Products',
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        error_log("No commission rule found");
        return [
            'rate' => 0,
            'rule_type' => 'No Rule',
            'rule_value' => 'None',
            'rule_id' => null
        ];
        
    } catch (Exception $e) {
        error_log("Error in getCommissionRate: " . $e->getMessage());
        return [
            'rate' => 0,
            'rule_type' => 'Error',
            'rule_value' => $e->getMessage(),
            'rule_id' => null
        ];
    }
}

$db = new Database();
$conn = $db->getConnection();

try {
    if (!isset($_POST['commission_id'])) {
        throw new Exception('Commission ID is required');
    }

    $commission_id = intval($_POST['commission_id']);

    // Get commission details with related information
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            o.order_number,
            o.total_price as order_amount,
            o.currency,
            o.line_items,
            cust.first_name as customer_first_name,
            cust.last_name as customer_last_name,
            cust.email as customer_email,
            a.first_name as agent_first_name,
            a.last_name as agent_last_name,
            a.email as agent_email
        FROM commissions c
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN customers cust ON o.customer_id = cust.id
        LEFT JOIN customers a ON c.agent_id = a.id
        WHERE c.id = ? AND c.agent_id = (
            SELECT c2.id 
            FROM customers c2 
            JOIN users u ON c2.email = u.email 
            WHERE u.id = ? AND c2.is_agent = 1
        )
    ");

    $stmt->execute([$commission_id, $_SESSION['user_id']]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commission) {
        throw new Exception('Commission not found or unauthorized');
    }

    // Decode line items
    $line_items = json_decode($commission['line_items'], true);
    $items_with_rules = [];
    $total_amount = 0;
    $total_commission = 0;

    if (!empty($line_items) && is_array($line_items)) {
        foreach ($line_items as $item) {
            error_log("Processing line item: " . json_encode($item));
            
            // Extract product details
            $product_type = '';
            $product_tags = [];
            
            // Get product type from the item name (format: "Product Name - Options")
            $title_parts = explode(' - ', $item['name']);
            if (!empty($title_parts[0])) {
                $product_type = trim($title_parts[0]);
            }
            
            // Map common product types
            $product_type_map = [
                'BYD' => 'TRAPO CLASSIC',
                'Trapo Tint' => 'OFFLINE SERVICE',
                'Trapo Coating' => 'OFFLINE SERVICE'
            ];
            
            foreach ($product_type_map as $key => $mapped_type) {
                if (stripos($product_type, $key) !== false) {
                    $product_type = $mapped_type;
                    break;
                }
            }
            
            // Get commission rate and rule info
            $commission_info = getCommissionRate($conn, $product_type, $product_tags);
            error_log("Commission info for item: " . json_encode([
                'item_title' => $item['title'],
                'product_type' => $product_type,
                'product_tags' => $product_tags,
                'commission_info' => $commission_info
            ]));
            
            // Calculate commission
            $item_price = 0;
            if (isset($item['price_set']['shop_money']['amount'])) {
                $item_price = floatval($item['price_set']['shop_money']['amount']);
            } elseif (isset($item['price'])) {
                $item_price = floatval($item['price']);
            }
            
            // Get actual quantity and total price from the order item
            $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
            $item_total = $item_price * $item_quantity;
            
            // Apply any discounts if present
            $discount = 0;
            if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                $discount = floatval($item['total_discount']);
            }
            
            $commission_amount = $item_total * ($commission_info['rate'] / 100);
            
            // Store item details with rule information
            $items_with_rules[] = [
                'product' => $item['title'] ?? 'Unknown Product',
                'type' => $product_type ?: 'Not specified',
                'quantity' => $item_quantity,
                'price' => $item_price,
                'total' => $item_total,
                'discount' => $discount,
                'rule_type' => $commission_info['rule_type'],
                'rule_value' => $commission_info['rule_value'],
                'commission_percentage' => $commission_info['rate'],
                'commission_amount' => $commission_amount,
                'rule_id' => $commission_info['rule_id']
            ];

            $total_amount += $item_total;
            $total_commission += $commission_amount;
        }
    }

    // Get currency from order
    $currency = $commission['currency'] ?? 'MYR';
    $currency_symbol = $currency === 'MYR' ? 'RM ' : ($currency_symbols[$currency] ?? $currency);

    // Build the HTML response with improved layout
    $response = '
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Order Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td><strong>Order Number:</strong></td>
                            <td>#' . htmlspecialchars($commission['order_number']) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Order Amount:</strong></td>
                            <td>' . $currency_symbol . number_format($commission['order_amount'], 2) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td><span class="badge ' . 
                                (($commission['status'] == 'paid') ? 'bg-success' : 
                                ($commission['status'] == 'approved' ? 'bg-info' : 'bg-warning')) . '">' 
                                . ucfirst(htmlspecialchars($commission['status'] ?? 'pending')) . '</span></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td><strong>Customer Name:</strong></td>
                            <td>' . htmlspecialchars(trim($commission['customer_first_name'] . ' ' . $commission['customer_last_name'])) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Customer Email:</strong></td>
                            <td>' . htmlspecialchars($commission['customer_email']) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Commission Date:</strong></td>
                            <td>' . date('d M Y H:i', strtotime($commission['created_at'])) . '</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Commission Details</h5>
            <span class="badge bg-light text-dark">Total Commission: ' . $currency_symbol . number_format($total_commission, 2) . '</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 200px;">Product</th>
                            <th>Type</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Total</th>
                            <th>Applied Rule</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Commission</th>
                        </tr>
                    </thead>
                    <tbody>';

    foreach ($items_with_rules as $item) {
        $response .= '
            <tr>
                <td>' . htmlspecialchars($item['product']) . '</td>
                <td><span class="badge bg-info">' . htmlspecialchars($item['type']) . '</span></td>
                <td class="text-center">' . htmlspecialchars($item['quantity']) . '</td>
                <td class="text-end">' . $currency_symbol . number_format($item['price'], 2) . '</td>
                <td class="text-end">' . $currency_symbol . number_format($item['discount'], 2) . '</td>
                <td class="text-end">' . $currency_symbol . number_format($item['total'], 2) . '</td>
                <td>' . htmlspecialchars($item['rule_type']) . 
                    ($item['rule_value'] !== 'All Products' ? ': <span class="badge bg-secondary">' . htmlspecialchars($item['rule_value']) . '</span>' : '') . '</td>
                <td class="text-end">' . number_format($item['commission_percentage'], 1) . '%</td>
                <td class="text-end">' . $currency_symbol . number_format($item['commission_amount'], 2) . '</td>
            </tr>';
    }

    $response .= '
                        <tr class="table-primary fw-bold">
                            <td colspan="5">Total</td>
                            <td class="text-end">' . $currency_symbol . number_format($total_amount, 2) . '</td>
                            <td colspan="2"></td>
                            <td class="text-end">' . $currency_symbol . number_format($total_commission, 2) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary view-invoice" data-commission-id="' . $commission_id . '">View Invoice</button>
        <button type="button" class="btn btn-success send-invoice" data-commission-id="' . $commission_id . '">Send Invoice</button>
    </div>
    <script>
        document.querySelector(".view-invoice").addEventListener("click", function() {
            const commissionId = this.getAttribute("data-commission-id");
            window.open(`generate_invoice.php?commission_id=${commissionId}`, "_blank");
        });

        document.querySelector(".send-invoice").addEventListener("click", function() {
            const commissionId = this.getAttribute("data-commission-id");
            const button = this;
            
            // Disable button and show loading state
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...`;
            
            fetch("ajax/send_invoice.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: `commission_id=${commissionId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    toastr.success(data.message);
                } else {
                    toastr.error(data.message || "Failed to send invoice");
                }
            })
            .catch(error => {
                toastr.error("An error occurred while sending the invoice");
            })
            .finally(() => {
                // Reset button state
                button.disabled = false;
                button.innerHTML = "Send Invoice";
            });
        });
    </script>';

    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    echo '<div class="alert alert-danger mb-0">
            <i class="fas fa-exclamation-circle me-2"></i>
            Error: ' . htmlspecialchars($e->getMessage()) . '
          </div>';
    error_log("Error in get_commission_details.php: " . $e->getMessage());
}
