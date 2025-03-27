<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Location: ../login.php');
    exit;
}

// Initialize database connection
$db = new Database();
$conn = $db->getConnection();

// Get all commissions with related details
$query = "
    SELECT 
        cm.*,
        o.order_number,
        o.total_price as order_amount,
        o.currency,
        o.created_at as order_date,
        c.first_name as agent_first_name,
        c.last_name as agent_last_name,
        c.email as agent_email,
        CONCAT(c.first_name, ' ', c.last_name) as agent_name,
        cm.adjustment_reason
    FROM commissions cm
    LEFT JOIN orders o ON cm.order_id = o.id
    LEFT JOIN customers c ON cm.agent_id = c.id
    ORDER BY cm.created_at DESC
";
$commissions = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="commissions_export_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start the Excel file with proper headers
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
echo "<Worksheet ss:Name=\"Commissions\">\n";
echo "<Table>\n";

// Add header row
echo "<Row>\n";
echo "<Cell><Data ss:Type=\"String\">Order Number</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Agent Name</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Agent Email</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Order Amount</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Commission Amount</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Actual Commission</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Total Discount</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Status</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Order Date</Data></Cell>\n";
echo "<Cell><Data ss:Type=\"String\">Adjustment Reason</Data></Cell>\n";
echo "</Row>\n";

// Add data rows
foreach ($commissions as $commission) {
    $currency_symbol = ($commission['currency'] === 'MYR') ? 'RM ' : '$';
    $formatted_order_date = !empty($commission['order_date']) ? date('Y-m-d H:i:s', strtotime($commission['order_date'])) : '';
    
    echo "<Row>\n";
    echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($commission['order_number']) . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($commission['agent_name']) . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($commission['agent_email']) . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"Number\">" . number_format($commission['order_amount'], 2, '.', '') . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"Number\">" . number_format($commission['amount'], 2, '.', '') . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"Number\">" . number_format($commission['actual_commission'], 2, '.', '') . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"Number\">" . number_format($commission['total_discount'], 2, '.', '') . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"String\">" . ucfirst(htmlspecialchars($commission['status'])) . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($formatted_order_date) . "</Data></Cell>\n";
    echo "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($commission['adjustment_reason'] ?? '') . "</Data></Cell>\n";
    echo "</Row>\n";
}

// Close the Excel file
echo "</Table>\n";
echo "</Worksheet>\n";
echo "</Workbook>\n";
exit;
