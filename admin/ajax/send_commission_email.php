<?php
// Enable error display temporarily for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', dirname(dirname(__DIR__)) . '/logs/php_errors.log');

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';
require_once '../../config/shopify_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
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
                    'rule_type' => 'Default',
                    'rule_value' => 'Default',
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        // If no rules found, return 0%
        return [
            'rate' => 0,
            'rule_type' => 'Default',
            'rule_value' => 'Default',
            'rule_id' => null
        ];
    } catch (Exception $e) {
        error_log('Error in getCommissionRate: ' . $e->getMessage());
        return [
            'rate' => 0,
            'rule_type' => 'Default',
            'rule_value' => 'Default',
            'rule_id' => null
        ];
    }
}

// Function to send JSON response and exit
function sendJsonResponse($success, $message, $error = null) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'error' => $error
    ]);
    exit;
}

// Create logs directory if it doesn't exist
$logsDir = dirname(dirname(__DIR__)) . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0777, true);
}

try {
    // Check if user is logged in and is admin
    if (!function_exists('is_logged_in') || !function_exists('is_admin')) {
        throw new Exception('Required functions not found');
    }

    if (!is_logged_in() || !is_admin()) {
        sendJsonResponse(false, null, 'Unauthorized access');
    }

    if (!isset($_POST['commission_id'])) {
        sendJsonResponse(false, null, 'Commission ID is required');
    }

    $commission_id = intval($_POST['commission_id']);
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Get commission details with all required data
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

    $stmt->execute([$commission_id]);
    $commission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commission) {
        sendJsonResponse(false, null, 'Commission not found');
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

        // Calculate commission percentage based on adjusted amount
        $commission_percentage = 0;
        if ($total_amount > 0) {
            $commission_percentage = ($commission['commission_amount'] / $total_amount) * 100;
        }

        // Apply the calculated percentage to each item
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

            $item_commission = ($item_total * $commission_percentage) / 100;
            
            // Get product type from item or use default
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
            
            $items_with_rules[] = [
                'title' => $item['title'],
                'variant_title' => $item['variant_title'] ?? '',
                'sku' => $item['sku'] ?? 'N/A',
                'product_type' => $product_type,
                'quantity' => $item_quantity,
                'price' => $item_price,
                'total' => $item_total,
                'rule_type' => 'Manual Adjustment',
                'rule_value' => number_format($commission_percentage, 1) . '% (Adjusted)',
                'commission_percentage' => $commission_percentage,
                'commission_amount' => $item_commission
            ];

            $total_commission += $item_commission;
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

            $total_commission += $commission_amount;
            $total_amount += $item_total;
        }
    }

    // Calculate any discounts
    $discount = 0;
    if ($commission['amount'] != $total_commission) {
        // Calculate discount as the difference between calculated total and actual amount
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

    // Prepare data for template
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

    // Set up file paths
    $pdf_filename = 'Commission_Note_MT-CP' . str_pad($commission_id, 4, '0', STR_PAD_LEFT) . '.pdf';
    $storage_dir = dirname(dirname(__DIR__)) . '/storage/invoice';
    
    // Create storage directory if it doesn't exist
    if (!is_dir($storage_dir)) {
        if (!mkdir($storage_dir, 0777, true)) {
            throw new Exception('Failed to create storage directory');
        }
    }
    
    $pdf_path = $storage_dir . '/' . $pdf_filename;

    // Generate PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', dirname(dirname(__DIR__)));
    
    $dompdf = new Dompdf($options);

    // Get the invoice template content
    ob_start();
    $template_path = dirname(dirname(__DIR__)) . '/templates/commission_invoice.php';
    if (!file_exists($template_path)) {
        throw new Exception('Invoice template not found');
    }
    include $template_path;
    $html = ob_get_clean();

    if (empty($html)) {
        throw new Exception('Failed to generate invoice HTML');
    }

    // Load HTML into Dompdf
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Save PDF to file
    $pdf_content = $dompdf->output();
    if (empty($pdf_content)) {
        throw new Exception('Failed to generate PDF content');
    }

    if (file_put_contents($pdf_path, $pdf_content) === false) {
        throw new Exception('Failed to save PDF file');
    }

    if (!file_exists($pdf_path)) {
        throw new Exception('PDF file not created');
    }

    // Load mail configuration
    $mail_config_path = dirname(dirname(__DIR__)) . '/config/mail.php';
    if (!file_exists($mail_config_path)) {
        throw new Exception('Mail configuration file not found');
    }
    $mail_config = require $mail_config_path;

    // Validate mail configuration
    if (!isset($mail_config['host']) || !isset($mail_config['username']) || !isset($mail_config['password'])) {
        throw new Exception('Invalid mail configuration');
    }

    // Send email with PDF attachment
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $mail_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mail_config['username'];
        $mail->Password = $mail_config['password'];
        $mail->SMTPSecure = $mail_config['encryption'];
        $mail->Port = $mail_config['port'];

        // Recipients
        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
        $mail->addAddress($commission['agent_email'], $agent_details['name']);

        // Attachments
        $mail->addAttachment($pdf_path, $pdf_filename);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Commission Note - Order #' . $commission['order_number'];
        $mail->Body = '
            <p>Dear ' . htmlspecialchars($agent_details['name']) . ',</p>
            <p>Please find attached your commission note for Order #' . htmlspecialchars($commission['order_number']) . '.</p>
            <p>Best regards,<br>MILLENNIUM TRAPO SDN. BHD.</p>
        ';

        $mail->send();
        
        // Delete temporary PDF file
        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }

        sendJsonResponse(true, 'Commission note sent successfully to ' . $commission['agent_email']);

    } catch (Exception $e) {
        // Delete temporary PDF file if it exists
        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }
        
        throw new Exception('Email could not be sent: ' . $mail->ErrorInfo);
    }

} catch (Exception $e) {
    error_log('Error in send_commission_email.php: ' . $e->getMessage());
    sendJsonResponse(false, null, $e->getMessage());
}
