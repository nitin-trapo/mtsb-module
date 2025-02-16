<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Prevent any output before PDF generation
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit;
}

// Enable error logging
ini_set('display_errors', 0);
error_reporting(E_ALL);

function logError($message, $context = []) {
    $logFile = __DIR__ . '/../logs/invoice_generation.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Add request information to context
    $context['request'] = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'get_data' => $_GET ?? [],
        'post_data' => $_POST ?? [],
        'session_data' => $_SESSION ?? []
    ];
    
    // Add debug backtrace
    $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    
    $contextStr = !empty($context) ? ' Context: ' . json_encode($context, JSON_PRETTY_PRINT) : '';
    $logMessage = "[{$timestamp}] {$message}{$contextStr}\n";
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
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

function getCommissionRate($conn, $product_type, $product_tags) {
    try {
        // First check for product type rules
        if (!empty($product_type)) {
            $query = "SELECT * FROM commission_rules 
                     WHERE status = 'active' 
                     AND rule_type = 'product_type' 
                     AND LOWER(rule_value) = LOWER(?)
                     LIMIT 1";
            logError("Checking product type commission rules", [
                'product_type' => $product_type,
                'query' => $query
            ]);
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$product_type]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rule) {
                logError("Found product type specific rule", $rule);
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_id' => $rule['id'],
                    'rule_type' => 'Product Type',
                    'rule_value' => $rule['rule_value']
                ];
            }
        }
        
        // If no product type rule found, check for default rule
        $query = "SELECT * FROM commission_rules 
                 WHERE status = 'active' 
                 AND rule_type = 'all'
                 LIMIT 1";
        logError("Checking default commission rules", ['query' => $query]);
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rule) {
            logError("Found default rule", $rule);
            return [
                'rate' => floatval($rule['commission_percentage']),
                'rule_id' => $rule['id'],
                'rule_type' => 'Default',
                'rule_value' => 'All Products'
            ];
        }
        
        // If no rule found at all, return default values
        logError("No commission rule found, using default values");
        return [
            'rate' => 0,
            'rule_id' => 0,
            'rule_type' => 'Default',
            'rule_value' => 'All Products'
        ];
        
    } catch (Exception $e) {
        logError("Error in getCommissionRate: " . $e->getMessage());
        return [
            'rate' => 0,
            'rule_id' => 0,
            'rule_type' => 'Default',
            'rule_value' => 'All Products'
        ];
    }
}

function generate_invoice($commission_id = null) {
    try {
        logError("Starting invoice generation", [
            'commission_id' => $commission_id
        ]);

        // Initialize database connection
        $db = new Database();
        $conn = $db->getConnection();
        
        if (!$conn) {
            logError("Database connection failed");
            throw new Exception("Database connection failed");
        }

        // Get commission ID from parameter or URL
        if ($commission_id === null) {
            $commission_id = isset($_GET['commission_id']) ? intval($_GET['commission_id']) : 0;
            logError("Using commission ID from GET parameter", [
                'commission_id' => $commission_id,
                'raw_get_id' => $_GET['commission_id'] ?? null
            ]);
        }

        if (!$commission_id) {
            logError("Invalid commission ID", [
                'commission_id' => $commission_id,
                'type' => gettype($commission_id)
            ]);
            throw new Exception("Invalid commission ID");
        }

        logError("Commission ID validated", [
            'commission_id' => $commission_id
        ]);

        // Get commission details
        $query = "
            SELECT 
                c.*,
                a.first_name as agent_first_name,
                a.last_name as agent_last_name,
                a.email as agent_email,
                a.phone as agent_phone,
                o.order_number,
                o.total_price as order_total,
                o.currency,
                o.created_at as order_date,
                o.line_items,
                u.name as adjusted_by_name,
                p.name as paid_by_name
            FROM commissions c
            LEFT JOIN customers a ON c.agent_id = a.id
            LEFT JOIN orders o ON c.order_id = o.id
            LEFT JOIN users u ON c.adjusted_by = u.id
            LEFT JOIN users p ON c.paid_by = p.id
            WHERE c.id = ?
        ";

        $stmt = $conn->prepare($query);
        $stmt->execute([$commission_id]);
        $commission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commission) {
            throw new Exception("Commission not found");
        }

        // Process line items
        $line_items = json_decode($commission['line_items'], true);
        $items_with_rules = [];
        $total_amount = 0;
        $total_commission = 0;
        $is_adjusted = !empty($commission['adjusted_by']);

        logError("Raw line items data", [
            'line_items' => $line_items
        ]);

        if (!empty($line_items) && is_array($line_items)) {
            if ($is_adjusted) {
                logError("Processing adjusted commission", [
                    'commission_id' => $commission['id'],
                    'adjusted_by' => $commission['adjusted_by']
                ]);

                // Get total amount from line items
                $total_amount = 0;
                foreach ($line_items as $item) {
                    $total_amount += floatval($item['price']) * intval($item['quantity']);
                }

                // Calculate commission rate based on adjusted amount
                $commission_rate = ($commission['amount'] / $total_amount) * 100;
                logError("Calculated commission rate for adjusted commission", [
                    'total_amount' => $total_amount,
                    'total_commission' => $commission['amount'],
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

                    // Calculate commission for this item
                    $item_price = floatval($item['price']) * intval($item['quantity']);
                    $commission_amount = ($item_price * $commission_rate) / 100;

                    $items_with_rules[] = [
                        'product' => $item['title'],
                        'variant_title' => $item['variant_title'] ?? '',
                        'sku' => $item['sku'] ?? 'N/A',
                        'type' => $product_type,
                        'quantity' => $item_quantity,
                        'price' => $item_price,
                        'total' => $item_total,
                        'commission_percentage' => $commission_rate,
                        'commission_amount' => $commission_amount,
                        'rule_type' => 'Manual Adjustment',
                        'rule_value' => number_format($commission_rate, 1) . '% (Adjusted)'
                    ];

                    logError("Final line item details", [
                        'product' => $item['title'],
                        'type' => $product_type,
                        'commission_rate' => $commission_rate,
                        'commission_amount' => $commission_amount
                    ]);
                }
            } else {
                // Process normal commission
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
                    $item_price = floatval($item['price']) * intval($item['quantity']);
                    $commission_amount = ($item_price * $commission_info['rate']) / 100;

                    $items_with_rules[] = [
                        'product' => $item['title'],
                        'variant_title' => $item['variant_title'] ?? '',
                        'sku' => $item['sku'] ?? 'N/A',
                        'type' => $product_type,
                        'quantity' => $item_quantity,
                        'price' => $item_price,
                        'total' => $item_total,
                        'commission_percentage' => $commission_info['rate'],
                        'commission_amount' => $commission_amount,
                        'rule_type' => $commission_info['rule_type'],
                        'rule_value' => $commission_info['rule_value']
                    ];

                    logError("Final line item details", [
                        'product' => $item['title'],
                        'type' => $product_type,
                        'commission_rate' => $commission_info['rate'],
                        'commission_amount' => $commission_amount
                    ]);

                    $total_amount += $item_total;
                    $total_commission += $commission_amount;
                }
            }

            // Calculate totals
            if ($is_adjusted) {
                $total_commission = $commission['amount'];
            }

            logError("Final totals", [
                'total_amount' => $total_amount,
                'total_commission' => $total_commission
            ]);
        }

        // Calculate overall rate
        $overall_rate = ($total_amount > 0) ? ($total_commission / $total_amount * 100) : 0;

        // Clear any output buffers
        ob_end_clean();

        // Get currency symbol
        $currency_symbol = match($commission['currency']) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'INR' => '₹',
            'MYR' => 'RM ',
            default => $commission['currency'] . ' '
        };

        // Format dates
        $invoice_date = date('F d, Y', strtotime($commission['created_at']));
        $order_date = date('F d, Y', strtotime($commission['order_date']));

        // Prepare billing address
        $billing_address = [
            'name' => $commission['agent_first_name'] . ' ' . $commission['agent_last_name'],
            'company' => 'MILLENNIUM AUTOBEYOND SDN BHD',
            'address' => 'No 691, Batu 5, Jalan Cheras',
            'city' => 'Kuala Lumpur',
            'state' => 'KUL',
            'country' => 'Malaysia',
            'postal_code' => '56100'
        ];

        // Set order variable for the template
        $order = [
            'id' => $commission['id'],
            'created_at' => $invoice_date,
            'order_number' => $commission['order_number'],
            'total_price' => $commission['amount'],
            'line_items' => json_encode($items_with_rules),
            'billing_address' => json_encode($billing_address),
            'shipping_address' => json_encode($billing_address)
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
        $dompdf->stream('Commission_Invoice_MT-CP' . str_pad($commission['id'], 4, '0', STR_PAD_LEFT) . '.pdf', array('Attachment' => false));
        
    } catch (Exception $e) {
        logError("Error generating invoice: " . $e->getMessage(), [
            'commission_id' => $commission_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw new Exception("Failed to generate PDF invoice: " . $e->getMessage());
    }
}

// Only execute if called directly
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    try {
        $commission_id = isset($_GET['commission_id']) ? intval($_GET['commission_id']) : 0;
        generate_invoice($commission_id);
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
}
