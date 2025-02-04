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
require_once __DIR__ . '/../config/tables.php';

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

function getCommissionRate($conn, $product_type, $product_tags) {
    try {
        // First check for product type rules
        if (!empty($product_type)) {
            $query = "SELECT * FROM commission_rules 
                     WHERE status = 'active' 
                     AND rule_type = 'product_type' 
                     AND LOWER(rule_value) = LOWER(?)
                     LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$product_type]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rule) {
                return [
                    'rate' => floatval($rule['commission_percentage']),
                    'rule_type' => 'Product Type',
                    'rule_value' => $rule['rule_value']
                ];
            }
        }

        // Check for tag rules
        if (!empty($product_tags)) {
            foreach ($product_tags as $tag) {
                $query = "SELECT * FROM commission_rules 
                         WHERE status = 'active' 
                         AND rule_type = 'product_tag' 
                         AND LOWER(rule_value) = LOWER(?)
                         LIMIT 1";
                
                $stmt = $conn->prepare($query);
                $stmt->execute([trim($tag)]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($rule) {
                    return [
                        'rate' => floatval($rule['commission_percentage']),
                        'rule_type' => 'Product Tag',
                        'rule_value' => $rule['rule_value']
                    ];
                }
            }
        }

        // Get default rule
        $query = "SELECT * FROM commission_rules 
                 WHERE status = 'active' 
                 AND rule_type = 'default'
                 LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rule) {
            return [
                'rate' => floatval($rule['commission_percentage']),
                'rule_type' => 'Default',
                'rule_value' => $rule['rule_value']
            ];
        }

        // If no rules found, return default values
        return [
            'rate' => 5.0, // Default 5%
            'rule_type' => 'System Default',
            'rule_value' => 'Default 5%'
        ];
    } catch (Exception $e) {
        logError("Error in getCommissionRate: " . $e->getMessage(), [
            'product_type' => $product_type,
            'product_tags' => $product_tags
        ]);
        
        // Return safe default values on error
        return [
            'rate' => 5.0,
            'rule_type' => 'System Default',
            'rule_value' => 'Default 5%'
        ];
    }
}

// Check if user is logged in and is agent
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'agent') {
    logError("Unauthorized access attempt", [
        'session' => $_SESSION,
        'user_id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ]);
    header('Location: login.php');
    exit;
}

function generate_invoice($commission_id = null) {
    try {
        logError("Starting invoice generation", [
            'commission_id' => $commission_id,
            'session' => $_SESSION
        ]);

        // Initialize database connection
        $db = new Database();
        $conn = $db->getConnection();
        
        if (!$conn) {
            throw new Exception("Database connection failed");
        }

        // Get commission ID from parameter or URL
        if ($commission_id === null) {
            $commission_id = isset($_GET['commission_id']) ? intval($_GET['commission_id']) : 0;
        }

        if (!$commission_id) {
            throw new Exception("Invalid commission ID");
        }

        $agent_id = $_SESSION['user_id'];

        // Get commission details
        $query = "
            SELECT 
                c.*,
                o.order_number,
                o.total_price as order_total,
                o.currency,
                o.created_at as order_date,
                o.line_items,
                CONCAT(a.first_name, ' ', a.last_name) as agent_name,
                a.first_name as agent_first_name,
                a.last_name as agent_last_name,
                a.email as agent_email,
                a.phone as agent_phone,
                u.name as adjusted_by_name,
                p.name as paid_by_name
            FROM " . TABLE_COMMISSIONS . " c
            LEFT JOIN " . TABLE_ORDERS . " o ON c.order_id = o.id
            LEFT JOIN " . TABLE_CUSTOMERS . " a ON c.agent_id = a.id
            LEFT JOIN " . TABLE_USERS . " u ON c.adjusted_by = u.id
            LEFT JOIN " . TABLE_USERS . " p ON c.paid_by = p.id
            WHERE c.id = :commission_id 
            AND c.agent_id = :agent_id
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':commission_id', $commission_id, PDO::PARAM_INT);
        $stmt->bindParam(':agent_id', $agent_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            $error = $stmt->errorInfo();
            throw new Exception("Database error: " . ($error[2] ?? "Unknown error"));
        }

        $commission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commission) {
            throw new Exception("Commission not found or unauthorized access");
        }

        logError("Commission data retrieved", [
            'commission_id' => $commission_id,
            'agent_id' => $agent_id,
            'commission' => array_intersect_key($commission, array_flip(['id', 'order_number', 'amount', 'status']))
        ]);

        // Ensure we have the required data
        if (empty($commission['line_items'])) {
            throw new Exception("No line items found for this commission");
        }

        if (!isset($commission['amount']) || !is_numeric($commission['amount'])) {
            throw new Exception("Invalid commission amount");
        }

        // Process line items
        $line_items = json_decode($commission['line_items'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error decoding line items: " . json_last_error_msg());
        }

        if (empty($line_items) || !is_array($line_items)) {
            throw new Exception("No valid line items found");
        }

        $items_with_rules = [];
        $total_amount = 0;
        $total_commission = 0;
        $is_adjusted = !empty($commission['adjusted_by']);

        logError("Starting commission calculation", [
            'commission_id' => $commission_id,
            'is_adjusted' => $is_adjusted,
            'commission_amount' => $commission['amount'],
            'line_items_count' => count($line_items ?? [])
        ]);

        if (!empty($line_items) && is_array($line_items)) {
            if ($is_adjusted) {
                // For adjusted commissions, first get total amount
                foreach ($line_items as $item) {
                    $item_price = floatval($item['price'] ?? 0);
                    $item_quantity = intval($item['quantity'] ?? 0);
                    $total_amount += $item_price * $item_quantity;
                }

                // Calculate commission rate based on adjusted amount
                $commission_rate = $total_amount > 0 ? ($commission['amount'] / $total_amount) * 100 : 0;

                logError("Calculated adjusted commission rate", [
                    'total_amount' => $total_amount,
                    'commission_amount' => $commission['amount'],
                    'commission_rate' => $commission_rate
                ]);

                foreach ($line_items as $item) {
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
                    $commission_amount = ($item_total * $commission_rate) / 100;
                    $total_commission += $commission_amount;

                    // Get product type for display
                    $product_type = '';
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
                            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                                $product_data = json_decode($response, true);
                                if (isset($product_data['product']['product_type'])) {
                                    $product_type = strtoupper($product_data['product']['product_type']);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            logError("Error fetching product type: " . $e->getMessage());
                        }
                    }

                    // Special case for Coating and Tint
                    if (empty($product_type) && isset($item['title'])) {
                        if (stripos($item['title'], 'Coating') !== false || stripos($item['title'], 'Tint') !== false) {
                            $product_type = 'OFFLINE SERVICE';
                        }
                    }

                    // Default product type if none found
                    if (empty($product_type)) {
                        $product_type = 'TRAPO CLASSIC';
                    }

                    $items_with_rules[] = [
                        'name' => $item['title'] ?? 'Unknown Product',
                        'variant_title' => $item['variant_title'] ?? '',
                        'sku' => $item['sku'] ?? 'N/A',
                        'type' => $product_type,
                        'quantity' => $item_quantity,
                        'price' => $item_price,
                        'total' => $item_total,
                        'commission_rate' => $commission_rate,
                        'commission_amount' => $commission_amount,
                        'rule_type' => 'Manual Adjustment',
                        'rule_value' => number_format($commission_rate, 1) . '% (Adjusted)'
                    ];
                }
            } else {
                // For standard commissions, process each item with its commission rule
                foreach ($line_items as $item) {
                    // Get product type
                    $product_type = '';
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
                            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                                $product_data = json_decode($response, true);
                                if (isset($product_data['product']['product_type'])) {
                                    $product_type = strtoupper($product_data['product']['product_type']);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            logError("Error fetching product type: " . $e->getMessage());
                        }
                    }

                    // Special case for Coating and Tint
                    if (empty($product_type) && isset($item['title'])) {
                        if (stripos($item['title'], 'Coating') !== false || stripos($item['title'], 'Tint') !== false) {
                            $product_type = 'OFFLINE SERVICE';
                        }
                    }

                    // Default product type if none found
                    if (empty($product_type)) {
                        $product_type = 'TRAPO CLASSIC';
                    }

                    // Get product tags
                    $product_tags = isset($item['tags']) ? explode(',', $item['tags']) : [];

                    // Get commission rate and rule info
                    $commission_info = getCommissionRate($conn, $product_type, $product_tags);

                    // Calculate item total
                    $item_price = floatval($item['price'] ?? 0);
                    $item_quantity = intval($item['quantity'] ?? 0);
                    $item_total = $item_price * $item_quantity;

                    // Apply any discounts
                    if (isset($item['total_discount']) && floatval($item['total_discount']) > 0) {
                        $item_total -= floatval($item['total_discount']);
                    }

                    // Calculate commission based on rules
                    $commission_amount = ($item_total * $commission_info['rate']) / 100;
                    $total_commission += $commission_amount;
                    $total_amount += $item_total;

                    $items_with_rules[] = [
                        'name' => $item['title'] ?? 'Unknown Product',
                        'variant_title' => $item['variant_title'] ?? '',
                        'sku' => $item['sku'] ?? 'N/A',
                        'type' => $product_type,
                        'quantity' => $item_quantity,
                        'price' => $item_price,
                        'total' => $item_total,
                        'commission_rate' => $commission_info['rate'],
                        'commission_amount' => $commission_amount,
                        'rule_type' => $commission_info['rule_type'],
                        'rule_value' => $commission_info['rule_value']
                    ];
                }
            }
        }

        // For adjusted commissions, ensure we use the exact commission amount
        if ($is_adjusted) {
            $total_commission = $commission['amount'];
        }

        logError("Final calculations", [
            'total_amount' => $total_amount,
            'total_commission' => $total_commission,
            'items_count' => count($items_with_rules),
            'is_adjusted' => $is_adjusted
        ]);

        // Clear any output before PDF generation
        if (ob_get_length()) ob_clean();

        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        try {
            // Set document information
            $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor($commission['agent_name']);
            $pdf->SetTitle('Commission Invoice #' . $commission_id);

            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Set default monospaced font
            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

            // Set margins
            $pdf->SetMargins(15, 15, 15);

            // Set auto page breaks
            $pdf->SetAutoPageBreak(TRUE, 15);

            // Set image scale factor
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

            // Add a page
            $pdf->AddPage();

            // Set font
            $pdf->SetFont('helvetica', 'B', 20);

            // Title
            $pdf->Cell(0, 15, 'Commission Invoice', 0, 1, 'C');
            $pdf->Ln(10);

            // Agent Information
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Agent Information:', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, 'Name: ' . $commission['agent_name'], 0, 1);
            $pdf->Cell(0, 5, 'Email: ' . $commission['agent_email'], 0, 1);
            if (!empty($commission['agent_phone'])) {
                $pdf->Cell(0, 5, 'Phone: ' . $commission['agent_phone'], 0, 1);
            }
            $pdf->Ln(5);

            // Order Information
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Order Information:', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, 'Order #: ' . $commission['order_number'], 0, 1);
            $pdf->Cell(0, 5, 'Order Date: ' . date('F j, Y', strtotime($commission['order_date'])), 0, 1);
            $pdf->Cell(0, 5, 'Order Total: ' . $commission['currency'] . ' ' . number_format($commission['order_total'], 2), 0, 1);
            $pdf->Ln(5);

            // Items Table Header
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Commission Details:', 0, 1);
            $pdf->SetFont('helvetica', 'B', 9);

            // Table header
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(60, 7, 'Product', 1, 0, 'L', true);
            $pdf->Cell(20, 7, 'SKU', 1, 0, 'C', true);
            $pdf->Cell(15, 7, 'Qty', 1, 0, 'C', true);
            $pdf->Cell(25, 7, 'Price', 1, 0, 'R', true);
            $pdf->Cell(25, 7, 'Total', 1, 0, 'R', true);
            $pdf->Cell(20, 7, 'Rate', 1, 0, 'C', true);
            $pdf->Cell(25, 7, 'Commission', 1, 1, 'R', true);

            // Items
            $pdf->SetFont('helvetica', '', 9);
            foreach ($items_with_rules as $item) {
                $name = $item['name'];
                if (!empty($item['variant_title'])) {
                    $name .= ' - ' . $item['variant_title'];
                }

                // Use MultiCell for product name to handle long text
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->MultiCell(60, 7, $name, 1, 'L');
                $new_y = $pdf->GetY();
                $pdf->SetXY($x + 60, $y);

                $pdf->Cell(20, $new_y - $y, $item['sku'], 1, 0, 'C');
                $pdf->Cell(15, $new_y - $y, $item['quantity'], 1, 0, 'C');
                $pdf->Cell(25, $new_y - $y, number_format($item['price'], 2), 1, 0, 'R');
                $pdf->Cell(25, $new_y - $y, number_format($item['total'], 2), 1, 0, 'R');
                $pdf->Cell(20, $new_y - $y, number_format($item['commission_rate'], 1) . '%', 1, 0, 'C');
                $pdf->Cell(25, $new_y - $y, number_format($item['commission_amount'], 2), 1, 1, 'R');
            }

            // Totals
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(145, 7, 'Total', 1, 0, 'R', true);
            $pdf->Cell(20, 7, '', 1, 0, 'C', true);
            $pdf->Cell(25, 7, number_format($total_commission, 2), 1, 1, 'R', true);

            // Additional Information
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', '', 10);
            
            if ($is_adjusted) {
                $pdf->Cell(0, 5, 'Note: This commission has been manually adjusted by ' . $commission['adjusted_by_name'], 0, 1);
            }
            
            if (!empty($commission['paid_by_name'])) {
                $pdf->Cell(0, 5, 'Paid by: ' . $commission['paid_by_name'], 0, 1);
                $pdf->Cell(0, 5, 'Payment Date: ' . date('F j, Y', strtotime($commission['paid_at'])), 0, 1);
            }

            // Output the PDF
            $pdf->Output('Commission_Invoice_' . $commission_id . '.pdf', 'I');
            exit;

        } catch (Exception $e) {
            logError("PDF Generation Error: " . $e->getMessage(), [
                'commission_id' => $commission_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Failed to generate PDF: ' . $e->getMessage()]);
            exit;
        }
    } catch (Exception $e) {
        logError("Error generating invoice", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Clear any output that might have been sent
        if (ob_get_length()) ob_clean();
        
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Only execute if called directly
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    try {
        $commission_id = isset($_GET['commission_id']) ? intval($_GET['commission_id']) : 0;
        generate_invoice($commission_id);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo "Error generating invoice: " . htmlspecialchars($e->getMessage());
    }
}
