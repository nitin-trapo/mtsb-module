<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

class InvoicePDF extends TCPDF {
    private $headerLogo = '';
    
    public function Header() {
        // Logo
        $this->Image(dirname(__DIR__) . '/assets/images/mtsb-logo.png', 10, 10, 30);
        
        // INVOICE text and details on the right
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY(120, 10);
        $this->Cell(80, 10, 'INVOICE', 0, 1, 'R');
        
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(120, 20);
        $this->Cell(40, 6, 'Issue Date:', 0, 0, 'R');
        $this->Cell(40, 6, date('d/m/Y'), 0, 1, 'R');
        
        $this->SetXY(120, 26);
        $this->Cell(40, 6, 'Invoice No.:', 0, 0, 'R');
        $this->Cell(40, 6, 'MT-CP' . str_pad($this->getAliasNumPage(), 4, '0', STR_PAD_LEFT), 0, 1, 'R');
        
        // Line break
        $this->Ln(20);
    }
    
    public function Footer() {
        $this->SetY(-50);
        
        // Terms section
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 6, 'Terms:', 0, 1, 'L');
        
        $this->SetFont('helvetica', '', 9);
        $terms = array(
            'This is a computer generated invoice and does not require signature.',
            'For warranty and returns related information, please contact our customer support.',
            'Payment can be made payable to Millenium Trapo Sdn. Bhd. Account No.: 564164996568 (Maybank)'
        );
        
        foreach($terms as $term) {
            $this->Cell(0, 5, '• ' . $term, 0, 1, 'L');
        }
        
        $this->Ln(5);
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 10, 'Thank you for your purchase.', 0, 1, 'C');
    }
    
    public function generateInvoice($order) {
        if (ob_get_length()) ob_clean();
        
        $this->AddPage();
        
        // Billing and Shipping Details
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(95, 8, 'Billing Details', 0, 0);
        $this->Cell(95, 8, 'Shipping Details', 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        
        // Billing Address
        $billing = json_decode($order['billing_address'], true);
        if ($billing) {
            $this->MultiCell(95, 6, 
                "Attn " . $billing['name'] . "\n" .
                "MILLENNIUM AUTOBEYOND SDN BHD\n" .
                $billing['address1'] . "\n" .
                ($billing['address2'] ? $billing['address2'] . "\n" : '') .
                $billing['city'] . ", " . $billing['province_code'] . " " . $billing['zip'],
                0, 'L', 0, 0);
        }
        
        // Shipping Address
        $shipping = json_decode($order['shipping_address'], true);
        if ($shipping) {
            $this->MultiCell(95, 6,
                "Attn " . $shipping['name'] . "\n" .
                "BYD CHERAS MILLENNIUM AUTOBEYOND\n" .
                $shipping['address1'] . "\n" .
                ($shipping['address2'] ? $shipping['address2'] . "\n" : '') .
                $shipping['city'] . ", " . $shipping['province_code'] . " " . $shipping['zip'],
                0, 'L', 0, 1);
        }
        
        $this->Ln(10);
        
        // Items Table Header
        $this->SetFillColor(20, 50, 90);
        $this->SetTextColor(255);
        $this->SetFont('helvetica', 'B', 10);
        
        $this->Cell(80, 8, 'Description', 1, 0, 'L', true);
        $this->Cell(20, 8, 'Qty', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Unit Price', 1, 0, 'R', true);
        $this->Cell(30, 8, 'Subtotal', 1, 0, 'R', true);
        $this->Cell(30, 8, 'Tax', 1, 0, 'R', true);
        $this->Cell(30, 8, 'Total', 1, 1, 'R', true);
        
        // Reset text color
        $this->SetTextColor(0);
        $this->SetFont('helvetica', '', 9);
        
        // Get line items
        $line_items = json_decode($order['line_items'], true);
        $subtotal = 0;
        
        foreach ($line_items as $item) {
            $unit_price = $item['price'];
            $item_subtotal = $unit_price * $item['quantity'];
            $subtotal += $item_subtotal;
            
            // Original price with strikethrough if there's a discount
            $original_price = isset($item['original_price']) ? $item['original_price'] : $unit_price;
            $discount = $original_price - $unit_price;
            
            $price_text = "RM" . number_format($original_price, 2);
            if ($discount > 0) {
                $price_text .= "\n(Discount\nRM" . number_format($discount, 2) . ")";
            }
            
            $this->Cell(80, 8, $item['title'], 1, 0, 'L');
            $this->Cell(20, 8, $item['quantity'], 1, 0, 'C');
            $this->Cell(30, 8, $price_text, 1, 0, 'R');
            $this->Cell(30, 8, "RM" . number_format($item_subtotal, 2), 1, 0, 'R');
            $this->Cell(30, 8, "RM0.00", 1, 0, 'R');
            $this->Cell(30, 8, "RM" . number_format($item_subtotal, 2), 1, 1, 'R');
        }
        
        // Totals section
        $this->Ln(5);
        $this->SetFont('helvetica', '', 10);
        
        // Calculate totals
        $total_discount = 0;
        $discount_codes = json_decode($order['discount_codes'], true);
        if (!empty($discount_codes)) {
            foreach ($discount_codes as $discount) {
                $total_discount += floatval($discount['amount']);
            }
        }
        
        $final_total = $subtotal - $total_discount;
        
        // Right-aligned totals
        $this->SetX(110);
        $this->Cell(50, 6, 'Subtotal', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, 'RM' . number_format($subtotal, 2) . ' MYR', 0, 1, 'R');
        
        $this->SetX(110);
        $this->Cell(50, 6, 'Discount', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, '-RM' . number_format($total_discount, 2) . ' MYR', 0, 1, 'R');
        
        $this->SetX(110);
        $this->Cell(50, 6, 'Subtotal after discount', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, 'RM' . number_format($final_total, 2) . ' MYR', 0, 1, 'R');
        
        $this->SetFont('helvetica', 'B', 10);
        $this->SetX(110);
        $this->Cell(50, 6, 'Total', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, 'RM' . number_format($final_total, 2) . ' MYR', 0, 1, 'R');
        
        $this->SetX(110);
        $this->Cell(50, 6, 'Paid by customer', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, 'RM0.00 MYR', 0, 1, 'R');
        
        $this->SetX(110);
        $this->Cell(50, 6, 'Outstanding (Customer owes)', 0, 0, 'R');
        $this->Cell(40, 6, ':', 0, 0, 'C');
        $this->Cell(40, 6, 'RM' . number_format($final_total, 2) . ' MYR', 0, 1, 'R');
    }
}
