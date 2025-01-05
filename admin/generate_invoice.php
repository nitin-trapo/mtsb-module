<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Prevent any output before PDF generation
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/../config/shopify_config.php';

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

        // Create new PDF document
        class MYPDF extends TCPDF {
            public function Header() {
                $this->SetFont('helvetica', 'B', 20);
                $this->Cell(0, 15, 'Commission Invoice', 0, true, 'C', 0, '', 0, false, 'M', 'M');
            }

            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(0, 10, 'This is a computer-generated invoice. No signature required.', 0, false, 'C', 0, '', 0, false, 'T', 'M');
                $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
            }
        }

        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Shopify Agent Module');
        $pdf->SetTitle('Commission Invoice #' . $commission_id);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);

        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 15);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Invoice number and status
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Invoice #COMM-' . str_pad($commission_id, 6, "0", STR_PAD_LEFT), 0, 1, 'R');
        $pdf->Cell(0, 5, 'Status: ' . ucfirst($commission['status']), 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 10);

        // Agent and Invoice Details
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(95, 5, 'Agent Details:', 0, 0);
        $pdf->Cell(95, 5, 'Invoice Details:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);

        // Agent Details
        $pdf->Ln(2);
        $agent_details = $commission['agent_first_name'] . ' ' . $commission['agent_last_name'] . "\n";
        $agent_details .= $commission['agent_email'] . "\n";
        if ($commission['agent_phone']) $agent_details .= $commission['agent_phone'];

        $pdf->MultiCell(95, 5, $agent_details, 0, 'L', 0, 0);

        // Invoice Details
        $invoice_details = "Invoice Date: " . $invoice_date . "\n";
        $invoice_details .= "Order Date: " . $order_date . "\n";
        $invoice_details .= "Order Number: #" . $commission['order_number'];

        $pdf->MultiCell(95, 5, $invoice_details, 0, 'L', 0, 1);

        // Adjustment Information if present
        if (!empty($commission['adjusted_by'])) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 5, 'Adjustment Information:', 0, 1);
            $pdf->SetFont('helvetica', '', 10);

            $adjustment_details = "Adjusted By: " . $commission['adjusted_by_name'] . "\n";
            $adjustment_details .= "Adjusted At: " . date('F d, Y h:i A', strtotime($commission['adjusted_at'])) . "\n";
            $adjustment_details .= "Reason: " . $commission['adjustment_reason'];

            $pdf->MultiCell(0, 5, $adjustment_details, 0, 'L', 0, 1);
        }

        // Payment Information if paid
        if ($commission['status'] === 'paid') {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 5, 'Payment Information:', 0, 1);
            $pdf->SetFont('helvetica', '', 10);

            $payment_details = "Paid By: " . $commission['paid_by_name'] . "\n";
            $payment_details .= "Paid At: " . date('F d, Y h:i A', strtotime($commission['paid_at'])) . "\n";
            if (!empty($commission['payment_note'])) {
                $payment_details .= "Payment Note: " . $commission['payment_note'];
            }

            $pdf->MultiCell(0, 5, $payment_details, 0, 'L', 0, 1);
        }

        // Commission Details Table
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);

        // Table Header
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(60, 7, 'Product', 1, 0, 'L', 1);
        $pdf->Cell(25, 7, 'Type', 1, 0, 'L', 1);
        $pdf->Cell(15, 7, 'Qty', 1, 0, 'C', 1);
        $pdf->Cell(25, 7, 'Price', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, 'Total', 1, 0, 'R', 1);
        $pdf->Cell(20, 7, 'Rate', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, 'Commission', 1, 1, 'R', 1);

        // Table Content
        $pdf->SetFont('helvetica', '', 9);
        foreach ($items_with_rules as $item) {
            $product_name = $item['product'];
            if (!empty($item['variant_title'])) {
                $product_name .= "\n" . $item['variant_title'];
            }
            if (!empty($item['sku'])) {
                $product_name .= "\nSKU: " . $item['sku'];
            }

            $lines = $pdf->getNumLines($product_name, 60);
            $row_height = max(7, $lines * 5);

            $pdf->MultiCell(60, $row_height, $product_name, 1, 'L', 0, 0);
            $pdf->Cell(25, $row_height, $item['type'], 1, 0, 'L');
            $pdf->Cell(15, $row_height, $item['quantity'], 1, 0, 'C');
            $pdf->Cell(25, $row_height, $currency_symbol . number_format($item['price'], 2), 1, 0, 'R');
            $pdf->Cell(25, $row_height, $currency_symbol . number_format($item['total'], 2), 1, 0, 'R');
            $pdf->Cell(20, $row_height, number_format($item['commission_percentage'], 1) . '%', 1, 0, 'R');
            $pdf->Cell(25, $row_height, $currency_symbol . number_format($item['commission_amount'], 2), 1, 1, 'R');
        }

        // Totals row
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(125, 7, 'Totals:', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, $currency_symbol . number_format($total_amount, 2), 1, 0, 'R', 1);
        $pdf->Cell(20, 7, number_format($overall_rate, 1) . '%', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, $currency_symbol . number_format($total_commission, 2), 1, 1, 'R', 1);

        // Notes
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 10);
        if ($is_adjusted) {
            $pdf->MultiCell(0, 5, 'Note: This invoice reflects manually adjusted commission amounts. All amounts are in ' . $commission['currency'] . '.', 0, 'L');

            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->MultiCell(0, 5, 'This commission has been manually adjusted. The commission amounts shown reflect the adjusted total of ' . $currency_symbol . number_format($total_commission, 2) . '.', 0, 'L');

            if (!empty($commission['adjustment_reason'])) {
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->MultiCell(0, 5, 'Adjustment Reason: ' . $commission['adjustment_reason'], 0, 'L');
            }
        } else {
            $pdf->MultiCell(0, 5, 'Note: All amounts are in ' . $commission['currency'] . '.', 0, 'L');
        }

        // Create storage directory if it doesn't exist
        $storage_dir = __DIR__ . '/../storage/invoice';
        if (!is_dir($storage_dir)) {
            mkdir($storage_dir, 0777, true);
        }

        // Generate filename using only commission_id
        $filename = 'commission_' . $commission_id . '.pdf';
        $filepath = $storage_dir . '/' . $filename;

        // Save PDF to storage
        $pdf->Output($filepath, 'F');

        // Also get the PDF content for email attachment
        $pdf_content = $pdf->Output('', 'S');

        return $pdf_content;
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
        $pdf_content = generate_invoice($commission_id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="commission_invoice_' . $commission_id . '.pdf"');
        echo $pdf_content;
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
}
