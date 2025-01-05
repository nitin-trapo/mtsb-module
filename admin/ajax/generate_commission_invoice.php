<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Commission ID is required');
}

try {
    $commission_id = intval($_GET['id']);
    
    $db = new Database();
    $conn = $db->getConnection();

    // Get commission details with all related information
    $query = "
        SELECT 
            c.*,
            o.order_number,
            o.total_price as order_amount,
            CONCAT(a.first_name, ' ', a.last_name) as agent_name,
            a.email as agent_email,
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
        http_response_code(404);
        exit('Commission not found');
    }

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('Shopify Agent Module');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Commission Invoice #' . $commission_id);

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 10);

    // Company Logo and Info
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Commission Invoice', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Invoice #: COMM-' . $commission_id, 0, 1, 'R');
    $pdf->Cell(0, 5, 'Date: ' . date('M d, Y', strtotime($commission['created_at'])), 0, 1, 'R');
    $pdf->Ln(10);

    // Agent Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Agent Information', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(30, 5, 'Name:', 0, 0);
    $pdf->Cell(0, 5, $commission['agent_name'], 0, 1);
    $pdf->Cell(30, 5, 'Email:', 0, 0);
    $pdf->Cell(0, 5, $commission['agent_email'], 0, 1);
    $pdf->Ln(10);

    // Commission Details
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Commission Details', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 5, 'Order Number:', 0, 0);
    $pdf->Cell(0, 5, '#' . $commission['order_number'], 0, 1);
    $pdf->Cell(40, 5, 'Order Amount:', 0, 0);
    $pdf->Cell(0, 5, 'RM ' . number_format($commission['order_amount'], 2), 0, 1);
    $pdf->Cell(40, 5, 'Commission Amount:', 0, 0);
    $pdf->Cell(0, 5, 'RM ' . number_format($commission['amount'], 2), 0, 1);
    $pdf->Cell(40, 5, 'Status:', 0, 0);
    $pdf->Cell(0, 5, ucfirst($commission['status']), 0, 1);
    $pdf->Ln(5);

    // Adjustment Information if adjusted
    if (!empty($commission['adjusted_by'])) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Adjustment Information', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(40, 5, 'Adjusted By:', 0, 0);
        $pdf->Cell(0, 5, $commission['adjusted_by_name'], 0, 1);
        $pdf->Cell(40, 5, 'Adjusted At:', 0, 0);
        $pdf->Cell(0, 5, date('M d, Y h:i A', strtotime($commission['adjusted_at'])), 0, 1);
        $pdf->Cell(40, 5, 'Reason:', 0, 0);
        $pdf->MultiCell(0, 5, $commission['adjustment_reason'], 0, 'L');
        $pdf->Ln(5);
    }

    // Payment Information if paid
    if ($commission['status'] === 'paid') {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Payment Information', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(40, 5, 'Paid By:', 0, 0);
        $pdf->Cell(0, 5, $commission['paid_by_name'], 0, 1);
        $pdf->Cell(40, 5, 'Paid At:', 0, 0);
        $pdf->Cell(0, 5, date('M d, Y h:i A', strtotime($commission['paid_at'])), 0, 1);
        $pdf->Cell(40, 5, 'Payment Note:', 0, 0);
        $pdf->MultiCell(0, 5, $commission['payment_note'], 0, 'L');
    }

    // Output PDF
    $pdf->Output('Commission_Invoice_' . $commission_id . '.pdf', 'I');

} catch (Exception $e) {
    http_response_code(500);
    exit('Error generating invoice: ' . $e->getMessage());
}
