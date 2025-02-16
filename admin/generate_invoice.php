<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/shopify_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Helper function to fetch product type from Shopify
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($http_code === 200) {
        $product_data = json_decode($response, true);
        if (isset($product_data['product']['product_type']) && !empty($product_data['product']['product_type'])) {
            return strtoupper($product_data['product']['product_type']);
        }
    }
    
    curl_close($ch);
    return '';
}

// Helper function to get commission rate
function getCommissionRate($conn, $product_type, $product_tags) {
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
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Default Rule',
                    'rule_value' => 'Default Rate',
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        return [
            'rate' => 0,
            'rule_type' => 'Default Rule',
            'rule_value' => 'Default Rate',
            'rule_id' => null
        ];
        
    } catch (Exception $e) {
        return [
            'rate' => 0,
            'rule_type' => 'Default Rule',
            'rule_value' => 'Default Rate',
            'rule_id' => null
        ];
    }
}

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    die('Unauthorized access');
}

if (!isset($_GET['commission_id'])) {
    die('Commission ID is required');
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Fetch commission details with related information
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            a.first_name as agent_first_name,
            a.last_name as agent_last_name,
            a.email as agent_email,
            a.phone as agent_phone,
            a.business_registration_number,
            a.tax_identification_number,
            a.ic_number,
            o.order_number,
            o.total_price as order_total,
            o.line_items,
            o.processed_at,
            o.created_at,
            cr.commission_percentage as default_commission_rate,
            cr.id as default_rule_id,
            DATE_FORMAT(o.processed_at, '%b %d, %Y %h:%i %p') as formatted_processed_date,
            DATE_FORMAT(o.created_at, '%d/%m/%Y') as formatted_created_date,
            IF(c.adjusted_by IS NOT NULL, 
               (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = c.adjusted_by), 
               NULL
            ) as adjusted_by_name,
            IF(c.paid_by IS NOT NULL,
               (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = c.paid_by),
               NULL
            ) as paid_by_name
        FROM commissions c
        LEFT JOIN customers a ON c.agent_id = a.id
        LEFT JOIN orders o ON c.order_id = o.id
        LEFT JOIN commission_rules cr ON cr.rule_type = 'default' AND cr.status = 'active'
        WHERE c.id = ?
    ");
    
    $stmt->execute([$_GET['commission_id']]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$commission) {
        die('Commission not found');
    }

    // Fetch all active commission rules
    $rules_stmt = $conn->prepare("
        SELECT * FROM commission_rules 
        WHERE status = 'active' 
        AND (rule_type = 'product_type' OR rule_type = 'all')
    ");
    $rules_stmt->execute();
    $commission_rules = $rules_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Index rules by type and value
    $rules_by_type = [];
    foreach ($commission_rules as $rule) {
        if ($rule['rule_type'] === 'product_type') {
            $rules_by_type['product_type'][strtolower($rule['rule_value'])] = $rule;
        } else if ($rule['rule_type'] === 'all') {
            $rules_by_type['default'] = $rule;
        }
    }

    // Process line items and calculate commissions
    $line_items = json_decode($commission['line_items'], true);
    $items_with_rules = [];
    $total_amount = 0;
    $total_commission = 0;
    $total_discount = 0;

    if (!empty($commission['adjusted_by'])) {
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
        
        // Process each item with the calculated rate
        foreach ($line_items as $item) {
            // Get product type from Shopify API
            $product_type = '';
            if (isset($item['product_id'])) {
                $product_type = fetchProductTypeFromShopify($item['product_id']);
            }
            
            // Special case for Coating and Tint
            if (empty($product_type) && isset($item['title'])) {
                if (stripos($item['title'], 'Coating') !== false || stripos($item['title'], 'Tint') !== false) {
                    $product_type = 'OFFLINE SERVICE';
                }
            }
            
            // If still no match, use default
            if (empty($product_type)) {
                $product_type = 'TRAPO CLASSIC';
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
            
            $items_with_rules[] = [
                'title' => $item['title'],
                'variant_title' => $item['variant_title'] ?? '',
                'sku' => $item['sku'] ?? 'N/A',
                'product_type' => $product_type,
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
            // Get product type from Shopify API
            $product_type = '';
            if (isset($item['product_id'])) {
                $product_type = fetchProductTypeFromShopify($item['product_id']);
            }
            
            // Special case for Coating and Tint
            if (empty($product_type) && isset($item['title'])) {
                if (stripos($item['title'], 'Coating') !== false || stripos($item['title'], 'Tint') !== false) {
                    $product_type = 'OFFLINE SERVICE';
                }
            }
            
            // If still no match, use default
            if (empty($product_type)) {
                $product_type = 'TRAPO CLASSIC';
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
            
            // Find applicable commission rule
            $commission_rule = getCommissionRate($conn, $product_type, []);
            
            $commission_amount = $item_total * ($commission_rule['rate'] / 100);
            
            $items_with_rules[] = [
                'title' => $item['title'],
                'variant_title' => $item['variant_title'] ?? '',
                'sku' => $item['sku'] ?? 'N/A',
                'product_type' => $product_type,
                'quantity' => $item_quantity,
                'price' => $item_price,
                'total' => $item_total,
                'rule_type' => $commission_rule['rule_type'],
                'rule_value' => $commission_rule['rule_value'],
                'commission_percentage' => $commission_rule['rate'],
                'commission_amount' => $commission_amount
            ];
            
            $total_amount += $item_total;
            $total_commission += $commission_amount;
        }
    }

    // Calculate any discounts
    $discount = 0;
    if ($commission['amount'] != $total_commission) {
        $discount = $total_commission - $commission['amount'];
    }

    // Prepare agent details
    $agent_details = [
        'name' => $commission['agent_first_name'] . ' ' . $commission['agent_last_name'],
        'email' => $commission['agent_email'],
        'phone' => $commission['agent_phone'],
        'business_registration_number' => $commission['business_registration_number'],
        'tax_identification_number' => $commission['tax_identification_number'],
        'ic_number' => $commission['ic_number']
    ];

    // Set order variable for the template
    $order = [
        'id' => $commission['id'],
        'created_at' => $commission['formatted_created_date'],
        'order_number' => $commission['order_number'],
        'total_price' => $total_commission,
        'line_items' => json_encode($items_with_rules),
        'agent_details' => json_encode($agent_details),
        'status' => $commission['status'],
        'adjusted_by_name' => $commission['adjusted_by_name'],
        'paid_by_name' => $commission['paid_by_name'],
        'adjusted_at' => $commission['adjusted_at'],
        'paid_at' => $commission['paid_at'],
        'adjustment_reason' => $commission['adjustment_reason'],
        'payment_note' => $commission['payment_note'],
        'total_amount' => $total_amount,
        'total_commission' => $total_commission,
        'discount' => $discount,
        'final_commission' => $commission['amount']
    ];

    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    // Create new PDF document
    $dompdf = new Dompdf($options);
    
    // Load the commission invoice template
    ob_start();
    include dirname(__DIR__) . '/templates/commission_invoice.php';
    $html = ob_get_clean();
    
    // Load HTML into Dompdf
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream('Commission_Note_MT-CP' . str_pad($commission['id'], 4, '0', STR_PAD_LEFT) . '.pdf', array('Attachment' => false));
    
} catch (Exception $e) {
    die('Error generating invoice: ' . $e->getMessage());
}
