<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/shopify_config.php';
require_once '../../includes/functions.php';
require_once '../../classes/ShopifyAPI.php';

// Enable error logging
ini_set('display_errors', 0);
error_reporting(E_ALL);
$logFile = __DIR__ . '/../../logs/commission_details.log';

function logError($message, $context = []) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' Context: ' . json_encode($context) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
    error_log($logMessage, 3, $logFile);
}

function fetchProductTypeFromShopify($product_id) {
    $shop_domain = SHOPIFY_SHOP_DOMAIN;
    $access_token = SHOPIFY_ACCESS_TOKEN;
    $api_version = SHOPIFY_API_VERSION;

    $url = "https://{$shop_domain}/admin/api/{$api_version}/products/{$product_id}.json";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Shopify-Access-Token: {$access_token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        logError("Curl error fetching product type", ['error' => curl_error($ch)]);
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    $product_data = json_decode($response, true);
    
    if (isset($product_data['product']['product_type'])) {
        return $product_data['product']['product_type'];
    }
    
    return null;
}

try {
    // Check if user is logged in and is admin
    if (!is_logged_in() || !is_admin()) {
        throw new Exception('Unauthorized access');
    }

    if (!isset($_GET['id'])) {
        throw new Exception('Commission ID is required');
    }

    $commission_id = intval($_GET['id']);
    $db = new Database();
    $conn = $db->getConnection();

    logError("Starting commission details retrieval for ID: " . $commission_id);

    // Get commission details with order and agent information
    $query = "
        SELECT 
            c.*,
            o.order_number,
            o.total_price as order_amount,
            o.currency,
            o.line_items,
            o.metafields,
            CONCAT(a.first_name, ' ', a.last_name) as agent_name,
            a.email as agent_email,
            u.name as adjusted_by_name
        FROM commissions c
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN customers a ON c.agent_id = a.id
        LEFT JOIN users u ON c.adjusted_by = u.id
        WHERE c.id = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$commission_id]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commission) {
        echo json_encode(['success' => false, 'error' => 'Commission not found']);
        exit;
    }

    // Log the retrieved data for debugging
    logError("Retrieved commission data", [
        'commission_id' => $commission_id,
        'agent_name' => $commission['agent_name'] ?? null,
        'agent_email' => $commission['agent_email'] ?? null
    ]);

    // Get commission rules
    $rules_query = "SELECT * FROM commission_rules WHERE status = 'active'
        ORDER BY CASE rule_type 
            WHEN 'product_tag' THEN 1 
            WHEN 'product_type' THEN 2 
            WHEN 'default' THEN 3 
        END";
    
    $rules = $conn->query($rules_query)->fetchAll(PDO::FETCH_ASSOC);
    logError("Commission Rules from DB", $rules);

    // Initialize variables
    $items_with_rules = [];
    $total_commission = 0;
    $total_amount = 0;

    // Decode line items
    $line_items = json_decode($commission['line_items'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Error decoding line items: ' . json_last_error_msg());
    }

    logError("Line items decoded", ['count' => count($line_items)]);

    // Function to find applicable commission rule
    function getCommissionRate($conn, $product_type, $product_tags) {
        logError("Getting commission rate", [
            'product_type' => $product_type,
            'product_tags' => $product_tags
        ]);
        
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
                        logError("Found rule by product type", $rule);
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
                        logError("Found rule by product tag", $rule);
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
                    logError("Using default rule", $rule);
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Default',
                        'rule_value' => 'All Products',
                        'rule_id' => $rule['id']
                    ];
                }
            }
            
            logError("No commission rule found");
            return [
                'rate' => 0,
                'rule_type' => 'No Rule',
                'rule_value' => 'None',
                'rule_id' => null
            ];
            
        } catch (Exception $e) {
            logError("Error in getCommissionRate", [
                'error' => $e->getMessage(),
                'product_type' => $product_type,
                'product_tags' => $product_tags
            ]);
            return [
                'rate' => 0,
                'rule_type' => 'Error',
                'rule_value' => $e->getMessage(),
                'rule_id' => null
            ];
        }
    }

    if (!empty($line_items) && is_array($line_items)) {
        foreach ($line_items as $item) {
            logError("Processing line item", $item);
            
            // Fetch product type from Shopify API
            $product_type = null;
            if (isset($item['product_id'])) {
                $product_type = fetchProductTypeFromShopify($item['product_id']);
            }
            
            // If API fetch fails, fallback to existing method
            if (empty($product_type)) {
                $product_type = isset($item['product_type']) ? $item['product_type'] : 'Unknown';
            }
            
            $product_tags = isset($item['tags']) ? explode(',', $item['tags']) : [];
            
            // Get commission rate and rule info
            $commission_info = getCommissionRate($conn, $product_type, $product_tags);
            logError("Commission info for item", [
                'item_title' => $item['title'],
                'product_type' => $product_type,
                'product_tags' => $product_tags,
                'commission_info' => $commission_info
            ]);
            
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
            if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                $item_total -= floatval($item['total_discount']);
            }
            
            $commission_amount = $item_total * ($commission_info['rate'] / 100);
            
            // Store item details with rule information
            $items_with_rules[] = [
                'product' => $item['title'],
                'variant_title' => $item['variant_title'] ?? '',
                'sku' => $item['sku'] ?? 'N/A',
                'type' => $product_type ?: 'Not specified',
                'quantity' => $item_quantity,
                'price' => $item_price,
                'total' => $item_total,
                'rule_type' => $commission_info['rule_type'],
                'rule_value' => $commission_info['rule_value'],
                'commission_percentage' => $commission_info['rate'],
                'commission_amount' => $commission_amount,
                'rule_id' => $commission_info['rule_id']
            ];
            
            $total_amount += $item_total;
            $total_commission += $commission_amount;
            
            logError("Processed item details", [
                'item' => end($items_with_rules),
                'total_amount' => $total_amount,
                'total_commission' => $total_commission,
                'raw_price' => $item['price'],
                'raw_quantity' => $item['quantity'],
                'raw_total_discount' => $item['total_discount'] ?? '0.00'
            ]);
        }
    }

    // Calculate weighted average commission rate
    $weighted_total = 0;
    foreach ($items_with_rules as $item) {
        $weighted_total += ($item['total'] * $item['commission_percentage']);
    }
    $overall_rate = $total_amount > 0 ? $weighted_total / $total_amount : 0;

    // Get currency from order
    $currency = $commission['currency'] ?? 'MYR';
    $currency_symbol = $currency === 'MYR' ? 'RM' : $currency;
    
    // Format the HTML for commission details
    $html = '
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title">Commission Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Order Number:</th>
                            <td>#' . htmlspecialchars($commission['order_number']) . '</td>
                        </tr>
                        <tr>
                            <th>Order Amount:</th>
                            <td>' . $currency_symbol . ' ' . number_format($commission['order_amount'], 2) . '</td>
                        </tr>
                        <tr>
                            <th>Total Commission:</th>
                            <td>' . $currency_symbol . ' ' . number_format($commission['amount'], 2) . '
                                <button type="button" class="btn btn-sm btn-primary ms-2" onclick="showAdjustmentForm()">
                                    <i class="fas fa-edit me-1"></i>Adjust
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td><span class="badge ' . 
                                ($commission['status'] === 'paid' ? 'bg-success' : 
                                ($commission['status'] === 'approved' ? 'bg-warning' : 
                                ($commission['status'] === 'pending' ? 'bg-info' : 'bg-primary'))) . 
                                ' text-white">' . ucfirst($commission['status']) . '</span></td>
                        </tr>
                        <tr>
                            <th>Created Date:</th>
                            <td>' . date('M d, Y h:i A', strtotime($commission['created_at'])) . '</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title">Agent Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Agent Name:</th>
                            <td>' . htmlspecialchars($commission['agent_name']) . '</td>
                        </tr>
                        <tr>
                            <th>Agent Email:</th>
                            <td>' . htmlspecialchars($commission['agent_email']) . '</td>
                        </tr>
                    </table>
                </div>
            </div>';

    // Show adjustment details if commission was adjusted
    if (!empty($commission['adjusted_by'])) {
        $html .= '
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title">Adjustment Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Adjusted By:</th>
                            <td>' . htmlspecialchars($commission['adjusted_by_name']) . '</td>
                        </tr>
                        <tr>
                            <th>Adjusted At:</th>
                            <td>' . date('M d, Y h:i A', strtotime($commission['adjusted_at'])) . '</td>
                        </tr>
                        <tr>
                            <th>Reason:</th>
                            <td>' . nl2br(htmlspecialchars($commission['adjustment_reason'])) . '</td>
                        </tr>
                    </table>
                </div>
            </div>';
    }

    $html .= '
        </div>
    </div>

    <!-- Commission Adjustment Form -->
    <div id="adjustmentForm" class="card mb-3" style="display: none;">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">Adjust Commission</h6>
        </div>
        <div class="card-body">
            <form id="commissionAdjustmentForm">
                <input type="hidden" name="commission_id" value="' . $commission_id . '">
                <div class="mb-3">
                    <label for="adjustedAmount" class="form-label">New Commission Amount (' . $currency_symbol . ')</label>
                    <input type="number" class="form-control" id="adjustedAmount" name="amount" 
                           value="' . $commission['amount'] . '" step="0.01" min="0" required>
                </div>
                <div class="mb-3">
                    <label for="adjustmentReason" class="form-label">Reason for Adjustment</label>
                    <textarea class="form-control" id="adjustmentReason" name="adjustment_reason" 
                              rows="3" required placeholder="Please provide a reason for this adjustment"></textarea>
                </div>
                <div class="text-end">
                    <button type="button" class="btn btn-secondary" onclick="hideAdjustmentForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAdjustmentForm() {
            $("#adjustmentForm").slideDown();
        }

        function hideAdjustmentForm() {
            $("#adjustmentForm").slideUp();
        }

        $("#commissionAdjustmentForm").on("submit", function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize();
            const submitBtn = $(this).find("button[type=submit]");
            const originalText = submitBtn.html();
            
            submitBtn.prop("disabled", true).html(\'<i class="fas fa-spinner fa-spin"></i> Saving...\');
            
            $.post("ajax/adjust_commission.php", formData)
                .done(function(response) {
                    if (response.success) {
                        toastr.success("Commission adjusted successfully!");
                        location.reload();
                    } else {
                        toastr.error(response.error || "Failed to adjust commission");
                    }
                })
                .fail(function() {
                    toastr.error("Failed to adjust commission");
                })
                .always(function() {
                    submitBtn.prop("disabled", false).html(originalText);
                });
        });
    </script>';

   
    $html .= '
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Commission Details</h5>
            <span class="badge bg-light text-dark">Total Commission: ' . $currency_symbol . ' ' . number_format($commission['amount'], 2) . '</span>
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
                            <th class="text-end">Total</th>
                            <th>Applied Rule</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Commission</th>
                        </tr>
                    </thead>
                    <tbody>';

    foreach ($items_with_rules as $item) {
        $discount = isset($item['discount']) ? floatval($item['discount']) : 0;
        $html .= '
            <tr>
                <td>' . htmlspecialchars($item['product']) . 
                    ($item['variant_title'] ? '<br><small class="text-muted">Variant: ' . htmlspecialchars($item['variant_title']) . '</small>' : '') . 
                    '<br><small class="text-muted">SKU: ' . htmlspecialchars($item['sku']) . '</small></td>
                <td><span class="badge bg-info">' . htmlspecialchars($item['type']) . '</span></td>
                <td class="text-center">' . htmlspecialchars($item['quantity']) . '</td>
                <td class="text-end">' . $currency_symbol . ' ' . number_format($item['price'], 2) . '</td>
                <td class="text-end">' . $currency_symbol . ' ' . number_format($item['total'], 2) . '</td>
                <td>' . htmlspecialchars($item['rule_type']) . 
                    ($item['rule_value'] !== 'All Products' ? ': <span class="badge bg-secondary">' . htmlspecialchars($item['rule_value']) . '</span>' : '') . '</td>
                <td class="text-end">' . number_format($item['commission_percentage'], 1) . '%</td>
                <td class="text-end">' . $currency_symbol . ' ' . number_format($item['commission_amount'], 2) . '</td>
            </tr>';
    }

    $html .= '
                        <tr class="table-info fw-bold">
                            <td colspan="4" class="text-end">Totals:</td>
                            <td class="text-end">' . $currency_symbol . ' ' . number_format($total_amount, 2) . '</td>
                            <td>Overall Rate:</td>
                            <td class="text-end">' . number_format($overall_rate, 1) . '%</td>
                            <td class="text-end">' . $currency_symbol . ' ' . number_format($commission['amount'], 2) . '</td>
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
        <button type="button" class="btn btn-primary adjust-commission" data-commission-id="' . $commission_id . '">Adjust Commission</button>
    </div>
    <div id="adjustmentForm" class="card mb-3" style="display: none;">
        <div class="card-body">
            <h6 class="card-title">Adjust Commission</h6>
            <form id="commissionAdjustmentForm">
                <input type="hidden" name="commission_id" value="' . $commission_id . '">
                <div class="mb-3">
                    <label for="adjustedAmount" class="form-label">New Commission Amount (' . $currency_symbol . ')</label>
                    <input type="number" class="form-control" id="adjustedAmount" name="amount" 
                           value="' . $commission['amount'] . '" step="0.01" min="0" required>
                </div>
                <div class="mb-3">
                    <label for="adjustmentReason" class="form-label">Reason for Adjustment</label>
                    <textarea class="form-control" id="adjustmentReason" name="adjustment_reason" 
                              rows="3" required></textarea>
                </div>
                <div class="text-end">
                    <button type="button" class="btn btn-secondary" onclick="hideAdjustmentForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function showAdjustmentForm() {
            $("#adjustmentForm").slideDown();
        }

        function hideAdjustmentForm() {
            $("#adjustmentForm").slideUp();
        }

        $("#commissionAdjustmentForm").on("submit", function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize();
            const submitBtn = $(this).find("button[type=submit]");
            const originalText = submitBtn.html();
            
            submitBtn.prop("disabled", true).html(\'<i class="fas fa-spinner fa-spin"></i> Saving...\');
            
            $.post("ajax/adjust_commission.php", formData)
                .done(function(response) {
                    if (response.success) {
                        alert("Commission adjusted successfully!");
                        location.reload();
                    } else {
                        alert(response.error || "Failed to adjust commission");
                    }
                })
                .fail(function() {
                    alert("Failed to adjust commission");
                })
                .always(function() {
                    submitBtn.prop("disabled", false).html(originalText);
                });
        });

        document.querySelector(".adjust-commission").addEventListener("click", function() {
            showAdjustmentForm();
        });
    </script>';

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $html
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
