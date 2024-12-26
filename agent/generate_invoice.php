<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Prevent any output before PDF generation
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

// Check if user is logged in and is agent
if (!is_logged_in() || !is_agent()) {
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

        // Verify the commission belongs to the current agent
        if (!isset($_SESSION['customer_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'agent') {
            logError("Unauthorized access attempt", [
                'session' => $_SESSION
            ]);
            throw new Exception("Unauthorized access");
        }

        $agent_id = $_SESSION['customer_id'];
        logError("Checking commission access", [
            'commission_id' => $commission_id,
            'agent_id' => $agent_id,
            'session' => $_SESSION
        ]);
        
        // Get commission details with agent verification
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
                (c.amount / o.total_price) as rate
            FROM commissions c
            LEFT JOIN customers a ON c.agent_id = a.id
            LEFT JOIN orders o ON c.order_id = o.id
            WHERE c.id = :commission_id 
            AND c.agent_id = :agent_id
        ";

        logError("Running commission query", [
            'query' => $query,
            'params' => [
                'commission_id' => $commission_id,
                'agent_id' => $agent_id
            ]
        ]);

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':commission_id', $commission_id, PDO::PARAM_INT);
        $stmt->bindParam(':agent_id', $agent_id, PDO::PARAM_INT);
        $stmt->execute();
        $commission = $stmt->fetch(PDO::FETCH_ASSOC);

        logError("Query result", [
            'commission' => $commission,
            'agent_id' => $agent_id,
            'commission_id' => $commission_id
        ]);

        if (!$commission) {
            throw new Exception("Commission not found or unauthorized access");
        }

        // Process line items
        $line_items = json_decode($commission['line_items'], true);
        $items_with_rules = [];
        $total_amount = 0;
        $total_commission = 0;

        if (!empty($line_items) && is_array($line_items)) {
            foreach ($line_items as $item) {
                // Extract product details
                $product_type = '';
                $product_tags = [];
                
                // Get product type from the item name
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
                
                $items_with_rules[] = [
                    'product' => $item['title'] ?? 'Unknown Product',
                    'type' => $product_type ?: 'Not specified',
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

        // Calculate weighted average commission rate
        $weighted_total = 0;
        foreach ($items_with_rules as $item) {
            $weighted_total += ($item['total'] * $item['commission_percentage']);
        }
        $overall_rate = $total_amount > 0 ? $weighted_total / $total_amount : 0;

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
                $image_file = __DIR__ . '/../assets/images/logo.png';
                if (file_exists($image_file)) {
                    $this->Image($image_file, 15, 10, 40, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
                }
                
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

        // Create new PDF instance
        $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        logError("PDF instance created", [
            'commission_id' => $commission_id
        ]);

        // Set document information
        $pdf->SetCreator('Shopify Agent Module');
        $pdf->SetAuthor('TRAPO');
        $pdf->SetTitle('Commission Invoice #' . $commission_id);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);

        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 15);

        // Add a page
        $pdf->AddPage();

        logError("PDF page added", [
            'commission_id' => $commission_id
        ]);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Invoice number and date
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Invoice #COMM-' . str_pad($commission_id, 6, "0", STR_PAD_LEFT), 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 10);

        // Agent and Invoice Details section
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(95, 5, 'Agent Details:', 0, 0);
        $pdf->Cell(95, 5, 'Invoice Details:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);

        // Left column content (Agent Details)
        $pdf->Ln(2);
        $agent_details = $commission['agent_first_name'] . ' ' . $commission['agent_last_name'] . "\n";
        $agent_details .= $commission['agent_email'] . "\n";
        if ($commission['agent_phone']) {
            $agent_details .= 'Phone: ' . $commission['agent_phone'] . "\n";
        }
        $pdf->MultiCell(95, 5, $agent_details, 0, 'L', 0, 0);

        // Right column content (Invoice Details)
        $invoice_details = "Invoice Date: " . date('F d, Y', strtotime($commission['created_at'])) . "\n";
        $invoice_details .= "Order Date: " . date('F d, Y', strtotime($commission['order_date'])) . "\n";
        $invoice_details .= "Order Number: #" . $commission['order_number'];
        $pdf->MultiCell(95, 5, $invoice_details, 0, 'L', 0, 1);

        // Commission Details Table
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);

        // Table Header
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(70, 7, 'Product', 1, 0, 'L', 1);
        $pdf->Cell(20, 7, 'Type', 1, 0, 'L', 1);
        $pdf->Cell(15, 7, 'Qty', 1, 0, 'C', 1);
        $pdf->Cell(25, 7, 'Price', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, 'Total', 1, 0, 'R', 1);
        $pdf->Cell(20, 7, 'Rate', 1, 0, 'R', 1);
        $pdf->Cell(20, 7, 'Comm.', 1, 1, 'R', 1);

        // Table Content
        $pdf->SetFont('helvetica', '', 9);
        $subtotal_amount = 0;
        $subtotal_commission = 0;

        foreach ($items_with_rules as $item) {
            // Calculate row height based on product name length
            $product_name = $item['product'];
            $lines_product = $pdf->getNumLines($product_name, 70);
            
            // Format type to be in two lines if needed
            $type_text = $item['type'];
            $type_words = explode(' ', $type_text);
            if (count($type_words) > 1) {
                $middle = ceil(count($type_words) / 2);
                $type_line1 = implode(' ', array_slice($type_words, 0, $middle));
                $type_line2 = implode(' ', array_slice($type_words, $middle));
                $type_text = $type_line1 . "\n" . $type_line2;
            }
            $lines_type = $pdf->getNumLines($type_text, 20);
            
            // Use the maximum number of lines for row height
            $row_height = max(7, max($lines_product, $lines_type) * 5);

            // Start of row
            $pdf->MultiCell(70, $row_height, $product_name, 1, 'L', 0, 0);
            $pdf->MultiCell(20, $row_height, $type_text, 1, 'L', 0, 0);
            $pdf->Cell(15, $row_height, $item['quantity'], 1, 0, 'C');
            $pdf->Cell(25, $row_height, $currency_symbol . number_format($item['price'], 2) . ' ', 1, 0, 'R');
            $pdf->Cell(25, $row_height, $currency_symbol . number_format($item['total'], 2) . ' ', 1, 0, 'R');
            $pdf->Cell(20, $row_height, number_format($item['commission_percentage'], 1) . '% ', 1, 0, 'R');
            $pdf->Cell(20, $row_height, $currency_symbol . number_format($item['commission_amount'], 2) . ' ', 1, 1, 'R');

            $subtotal_amount += $item['total'];
            $subtotal_commission += $item['commission_amount'];
        }

        // Subtotal row
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(130, 7, 'Subtotal:', 1, 0, 'R', 1);
        $pdf->Cell(25, 7, $currency_symbol . number_format($subtotal_amount, 2) . ' ', 1, 0, 'R', 1);
        $pdf->Cell(20, 7, '', 1, 0, 'R', 1);
        $pdf->Cell(20, 7, $currency_symbol . number_format($subtotal_commission, 2) . ' ', 1, 1, 'R', 1);

        // Total row with darker background
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(130, 8, 'TOTAL:', 1, 0, 'R', 1);
        $pdf->Cell(25, 8, $currency_symbol . number_format($total_amount, 2) . ' ', 1, 0, 'R', 1);
        $pdf->Cell(20, 8, number_format($overall_rate, 1) . '% ', 1, 0, 'R', 1);
        $pdf->Cell(20, 8, $currency_symbol . number_format($total_commission, 2) . ' ', 1, 1, 'R', 1);
        
        // Notes section
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 7, 'Notes:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, '1. This invoice is generated automatically based on the order details and applicable commission rules.
2. Commission rates are calculated based on product types and any special rules that may apply.
3. All amounts are shown in ' . $commission['currency'] . '.
4. For any queries regarding this invoice, please contact support with the invoice number.', 0, 'L');
        
        logError("PDF content generation complete", [
            'commission_id' => $commission_id
        ]);

        // Return the PDF as a string
        return $pdf->Output('', 'S');

    } catch (Exception $e) {
        logError("Error in generate_invoice: " . $e->getMessage(), [
            'commission_id' => $commission_id,
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}

// Only execute if called directly
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    try {
        $commission_id = isset($_GET['commission_id']) ? intval($_GET['commission_id']) : 0;
        $pdf_content = generate_invoice($commission_id);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="commission_invoice_' . $commission_id . '.pdf"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdf_content;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo "Error generating invoice: " . htmlspecialchars($e->getMessage());
    }
}
