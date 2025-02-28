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
            o.discount_codes,
            CONCAT(a.first_name, ' ', a.last_name) as agent_name,
            a.email as agent_email,
            a.business_registration_number,
            a.tax_identification_number,
            a.ic_number,
            u.name as adjusted_by_name,
            p.name as paid_by_name
        FROM commissions c
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN customers a ON c.agent_id = a.id
        LEFT JOIN users u ON c.adjusted_by = u.id
        LEFT JOIN users p ON c.paid_by = p.id
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
                        'rule_type' => 'Default Rule',
                        'rule_value' => 'Default Rate',
                        'rule_id' => $rule['id']
                    ];
                }
            }
            
            logError("No commission rule found");
            return [
                'rate' => 0,
                'rule_type' => 'Default Rule',
                'rule_value' => 'Default Rate',
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
                'rule_type' => 'Default Rule',
                'rule_value' => 'Default Rate',
                'rule_id' => null
            ];
        }
    }

    if (!empty($line_items) && is_array($line_items)) {
        if (!empty($commission['adjusted_by'])) {
            logError("Processing adjusted commission", [
                'commission_id' => $commission['id'],
                'adjusted_by' => $commission['adjusted_by']
            ]);
            
            // For adjusted commissions, calculate commission percentage based on total commission
            $total_amount = 0;
            foreach ($line_items as $item) {
                $item_price = 0;
                if (isset($item['price_set']['shop_money']['amount'])) {
                    $item_price = floatval($item['price_set']['shop_money']['amount']);
                } elseif (isset($item['price'])) {
                    $item_price = floatval($item['price']);
                }
                
                $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                $item_total = $item_price * $item_quantity;
                
                // Apply any discounts
                if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                    $item_total -= floatval($item['total_discount']);
                }
                
                $total_amount += $item_total;
            }
            
            // Calculate the commission rate based on total commission
            $total_commission = floatval($commission['amount']);
            $commission_rate = ($total_amount > 0) ? ($total_commission / $total_amount * 100) : 0;
            
            logError("Calculated commission rate for adjusted commission", [
                'total_amount' => $total_amount,
                'total_commission' => $total_commission,
                'commission_rate' => $commission_rate
            ]);
            
            // Process each item with the calculated rate
            foreach ($line_items as $item) {
                logError("Processing line item", [
                    'item_title' => $item['title'] ?? 'N/A',
                    'raw_item' => $item
                ]);
                
                // Get product type
                $product_type = '';
                
                // Get product type from Shopify API
                if (isset($item['product_id'])) {
                    try {
                        $shop_domain = SHOPIFY_SHOP_DOMAIN;
                        $access_token = SHOPIFY_ACCESS_TOKEN;
                        $api_version = SHOPIFY_API_VERSION;
                        $url = "https://{$shop_domain}/admin/api/{$api_version}/products/{$item['product_id']}.json";
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "X-Shopify-Access-Token: {$access_token}",
                            "Content-Type: application/json"
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        
                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        
                        if ($http_code === 200) {
                            $product_data = json_decode($response, true);
                            if (isset($product_data['product']['product_type']) && !empty($product_data['product']['product_type'])) {
                                $product_type = strtoupper($product_data['product']['product_type']);
                                logError("Got product type from Shopify API", [
                                    'product_id' => $item['product_id'],
                                    'type' => $product_type
                                ]);
                            }
                        } else {
                            logError("Failed to get product type from Shopify API", [
                                'product_id' => $item['product_id'],
                                'http_code' => $http_code,
                                'response' => $response
                            ]);
                        }
                        
                        curl_close($ch);
                    } catch (Exception $e) {
                        logError("Error fetching from Shopify API", [
                            'error' => $e->getMessage(),
                            'product_id' => $item['product_id']
                        ]);
                    }
                }
                
                // Special case for Coating and Tint
                if (empty($product_type) && isset($item['title'])) {
                    if (stripos($item['title'], 'Coating') !== false) {
                        $product_type = 'OFFLINE SERVICE';
                        logError("Got product type from title (Coating)", ['type' => $product_type]);
                    } elseif (stripos($item['title'], 'Tint') !== false) {
                        $product_type = 'OFFLINE SERVICE';
                        logError("Got product type from title (Tint)", ['type' => $product_type]);
                    }
                }
                
                // If still no match, use default
                if (empty($product_type)) {
                    $product_type = 'TRAPO CLASSIC'; // Default product type
                    logError("Using default product type", ['product_type' => $product_type]);
                }
                
                // Calculate item total
                $item_price = 0;
                if (isset($item['price_set']['shop_money']['amount'])) {
                    $item_price = floatval($item['price_set']['shop_money']['amount']);
                } elseif (isset($item['price'])) {
                    $item_price = floatval($item['price']);
                }
                
                $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                $item_total = $item_price * $item_quantity;
                
                // Apply any discounts
                if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                    $item_total -= floatval($item['total_discount']);
                }
                
                // Calculate commission using the total commission rate
                $commission_amount = $item_total * ($commission_rate / 100);
                
                logError("Final line item details", [
                    'product' => $item['title'],
                    'type' => $product_type,
                    'commission_rate' => $commission_rate,
                    'commission_amount' => $commission_amount
                ]);
                
                $items_with_rules[] = [
                    'product' => $item['title'],
                    'variant_title' => $item['variant_title'] ?? '',
                    'sku' => $item['sku'] ?? 'N/A',
                    'type' => $product_type,
                    'quantity' => $item_quantity,
                    'price' => $item_price,
                    'total' => $item_total,
                    'rule_type' => 'Manual Adjustment',
                    'rule_value' => number_format($commission_rate, 1) . '% (Adjusted)',
                    'commission_percentage' => $commission_rate,
                    'commission_amount' => $commission_amount
                ];
            }
        } else {
            // For non-adjusted commissions, use commission rules
            foreach ($line_items as $item) {
                // Get product type
                $product_type = '';
                
                // Get product type from Shopify API
                if (isset($item['product_id'])) {
                    try {
                        $shop_domain = SHOPIFY_SHOP_DOMAIN;
                        $access_token = SHOPIFY_ACCESS_TOKEN;
                        $api_version = SHOPIFY_API_VERSION;
                        $url = "https://{$shop_domain}/admin/api/{$api_version}/products/{$item['product_id']}.json";
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "X-Shopify-Access-Token: {$access_token}",
                            "Content-Type: application/json"
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        
                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        
                        if ($http_code === 200) {
                            $product_data = json_decode($response, true);
                            if (isset($product_data['product']['product_type']) && !empty($product_data['product']['product_type'])) {
                                $product_type = strtoupper($product_data['product']['product_type']);
                                logError("Got product type from Shopify API", [
                                    'product_id' => $item['product_id'],
                                    'type' => $product_type
                                ]);
                            }
                        } else {
                            logError("Failed to get product type from Shopify API", [
                                'product_id' => $item['product_id'],
                                'http_code' => $http_code,
                                'response' => $response
                            ]);
                        }
                        
                        curl_close($ch);
                    } catch (Exception $e) {
                        logError("Error fetching from Shopify API", [
                            'error' => $e->getMessage(),
                            'product_id' => $item['product_id']
                        ]);
                    }
                }
                
                // Special case for Coating and Tint
                if (empty($product_type) && isset($item['title'])) {
                    if (stripos($item['title'], 'Coating') !== false) {
                        $product_type = 'OFFLINE SERVICE';
                        logError("Got product type from title (Coating)", ['type' => $product_type]);
                    } elseif (stripos($item['title'], 'Tint') !== false) {
                        $product_type = 'OFFLINE SERVICE';
                        logError("Got product type from title (Tint)", ['type' => $product_type]);
                    }
                }
                
                // If still no match, use default
                if (empty($product_type)) {
                    $product_type = 'TRAPO CLASSIC'; // Default product type
                    logError("Using default product type", ['product_type' => $product_type]);
                }
                
                // Get product tags
                $product_tags = isset($item['tags']) ? explode(',', $item['tags']) : [];
                
                // Get commission rate and rule info
                $commission_info = getCommissionRate($conn, $product_type, $product_tags);
                
                // Calculate item total
                $item_price = 0;
                if (isset($item['price_set']['shop_money']['amount'])) {
                    $item_price = floatval($item['price_set']['shop_money']['amount']);
                } elseif (isset($item['price'])) {
                    $item_price = floatval($item['price']);
                }
                
                $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                $item_total = $item_price * $item_quantity;
                
                // Apply any discounts
                if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                    $item_total -= floatval($item['total_discount']);
                }
                
                // Calculate commission based on rules
                $commission_amount = $item_total * ($commission_info['rate'] / 100);
                
                $items_with_rules[] = [
                    'product' => $item['title'],
                    'variant_title' => $item['variant_title'] ?? '',
                    'sku' => $item['sku'] ?? 'N/A',
                    'type' => $product_type,
                    'quantity' => $item_quantity,
                    'price' => $item_price,
                    'total' => $item_total,
                    'rule_type' => $commission_info['rule_type'],
                    'rule_value' => $commission_info['rule_value'],
                    'commission_percentage' => $commission_info['rate'],
                    'commission_amount' => $commission_amount
                ];
                
                $total_amount += $item_total;
                $total_commission += $commission_amount;
            }
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
                                <td>
                                    ' . $currency_symbol . ' ' . 
                                    ((!empty($commission['adjustment_reason'])) ? 
                                    number_format($commission['amount'], 2) : 
                                    number_format($commission['amount'], 2)) . '
                                    ' . (!empty($commission['adjustment_reason']) ? '
                                    <span class="badge bg-info" title="This commission was adjusted">
                                        <i class="fas fa-info-circle"></i> Adjusted
                                    </span>' : '') . '
                                    ' . ($commission['status'] !== 'paid' ? '
                                    <button type="button" class="btn btn-sm btn-primary ms-2 adjust-commission" data-commission-id="' . $commission_id . '">
                                        <i class="fas fa-edit me-1"></i>Adjust
                                    </button>
                                    ' : '') . '
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge ' . 
                                    ($commission['status'] === 'paid' ? 'bg-success' : 
                                    ($commission['status'] === 'approved' ? 'bg-warning' : 
                                    ($commission['status'] === 'pending' ? 'bg-info' : 'bg-primary'))) . 
                                    ' text-white">' . ucfirst($commission['status']) . '</span>
                                    ' . ($commission['status'] === 'pending' ? '
                                    <button type="button" class="btn btn-warning btn-sm ms-2 approve-commission">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>' : '') . '
                                    ' . ($commission['status'] === 'approved' ? '
                                    <button type="button" class="btn btn-success btn-sm ms-2 mark-as-paid">
                                        <i class="fas fa-dollar-sign me-1"></i>Mark as Paid
                                    </button>' : '') . '
                                </td>
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
                            <tr>
                                <th>Business Registration Number:</th>
                                <td>' . htmlspecialchars($commission['business_registration_number']) . '</td>
                            </tr>
                            <tr>
                                <th>Tax Identification Number (TIN):</th>
                                <td>' . htmlspecialchars($commission['tax_identification_number']) . '</td>
                            </tr>
                            <tr>
                                <th>IC Number:</th>
                                <td>' . htmlspecialchars($commission['ic_number']) . '</td>
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

    if ($commission['amount'] > 0 && $commission['status'] === 'paid') {
        $html .= '
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title">Payment Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <th>Paid By:</th>
                            <td>' . htmlspecialchars($commission['paid_by_name']) . '</td>
                        </tr>
                        <tr>
                            <th>Paid At:</th>
                            <td>' . date('M d, Y h:i A', strtotime($commission['paid_at'])) . '</td>
                        </tr>
                        <tr>
                            <th>Payment Note:</th>
                            <td>' . nl2br(htmlspecialchars($commission['payment_note'])) . '</td>
                        </tr>
                        <tr>
                            <th>Payment Receipt:</th>
                            <td>
                                <a href="../assets/uploads/receipts/' . htmlspecialchars($commission['payment_receipt']) . '" 
                                   class="btn btn-sm btn-primary" target="_blank">
                                    <i class="fas fa-download me-1"></i>View Receipt
                                </a>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>';
    }

    $html .= '
            </div>
        </div>


        <!-- Adjust Commission Form -->
        <div id="adjustCommissionForm" class="card mb-4" style="display: none;">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0">Adjust Commission</h6>
            </div>
            <div class="card-body">
                <form id="commissionAdjustmentForm" enctype="multipart/form-data">
                    <input type="hidden" name="commission_id" value="' . $commission_id . '">
                    <div class="mb-3">
                        <label for="adjustedAmount" class="form-label">Adjusted Commission Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">' . $currency_symbol . '</span>
                            <input type="number" class="form-control" id="adjustedAmount" name="amount" 
                                   step="0.01" min="0" required 
                                   value="' . number_format($commission['amount'], 2, ".", "") . '">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="adjustmentReason" class="form-label">Reason for Adjustment</label>
                        <textarea class="form-control" id="adjustmentReason" name="adjustment_reason" 
                                rows="3" required placeholder="Please explain why this commission is being adjusted"></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary cancel-adjustment">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Adjustment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Form -->
        <div id="paymentForm" class="card mb-4" style="display: none;">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0">Mark Commission as Paid</h6>
            </div>
            <div class="card-body">
                <form id="commissionPaymentForm" enctype="multipart/form-data">
                    <input type="hidden" name="commission_id" value="' . $commission_id . '">
                    <div class="mb-3">
                        <label for="paymentNote" class="form-label">Payment Note</label>
                        <textarea class="form-control" id="paymentNote" name="payment_note" 
                                  rows="3" required placeholder="Enter payment details or notes"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="paymentReceipt" class="form-label">Payment Receipt (PDF or Image)</label>
                        <input type="file" class="form-control" id="paymentReceipt" name="payment_receipt" 
                               accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="text-muted">Accepted formats: PDF, JPG, JPEG, PNG</small>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary cancel-payment">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Payment</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Order Items</h5>
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
                        <tr class="table-info">
                            <td colspan="4" class="text-end fw-bold">Totals:</td>
                            <td class="text-end fw-bold">' . $currency_symbol . ' ' . number_format($total_amount, 2) . '</td>
                            <td class="text-end fw-bold">Overall Rate:</td>
                            <td class="text-end fw-bold">' . number_format($overall_rate, 1) . '%</td>
                            <td class="text-end fw-bold">' . $currency_symbol . ' ' . number_format(!empty($commission['adjustment_reason']) ? $commission['amount'] : $commission['actual_commission'], 2) . '</td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold">Total Discount:</td>
                            <td colspan="2" class="text-end fw-bold text-danger">-' . $currency_symbol . ' ' . number_format($commission['total_discount'], 2) . '</td>
                        </tr>';
    
    // Add discount code display
    if (!empty($commission['discount_codes'])) {
        logError("Discount Codes found: " . print_r($commission['discount_codes'], true));
        $discount_codes = json_decode($commission['discount_codes'], true);
        if (!empty($discount_codes)) {
            $first_discount = reset($discount_codes);
            $discount_code = $first_discount['code'] ?? '';
            if (!empty($discount_code)) {
                $html .= '<tr class="table-light">
                            <td colspan="6" class="text-end fw-bold">Discount Code:</td>
                            <td colspan="2" class="text-end fw-bold">' . htmlspecialchars($discount_code) . '</td>
                        </tr>';
            }
        }
    } else {
        logError("No discount codes found for commission ID: " . $commission_id);
    }

    $html .= '
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold">Final Commission:</td>
                            <td colspan="2" class="text-end fw-bold fs-5 text-success">' . $currency_symbol . ' ' . number_format(!empty($commission['adjustment_reason']) ? $commission['amount'] : $commission['amount'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>';

    $html .= '
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary view-invoice" onclick="viewInvoice(' . $commission_id . ')">View Invoice</button>
        <button type="button" class="btn btn-success send-invoice" data-commission-id="' . $commission_id . '">Send Invoice</button>
    </div>';

    // Clear any buffered output before sending JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'html' => $html
    ]);

} catch (Exception $e) {
    // Clear any buffered output before sending error response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    logError("Error in get_commission_details.php", [
        'error' => $e->getMessage(),
        'commission_id' => isset($commission_id) ? $commission_id : null
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
