<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

class InvoicePDF extends TCPDF {
    private $headerLogo = '';
    private $headerText = 'INVOICE';
    
    public function Header() {
        // Logo
        if ($this->headerLogo) {
            $this->Image($this->headerLogo, 10, 10, 30);
        }
        
        // Title
        $this->SetFont('helvetica', 'B', 20);
        $this->Cell(0, 15, $this->headerText, 0, true, 'C', 0);
        
        // Line break
        $this->Ln(10);
    }
    
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0);
    }
    
    public function generateInvoice($order) {
        // Clear any previous output and turn off error display
        if (ob_get_length()) ob_clean();
        error_reporting(0);
        ini_set('display_errors', 0);
        
        // Debug information
        error_log("Order data: " . print_r($order, true));
        
        $this->AddPage();
        
        // Order Information
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 10, 'Order #' . $order['order_number'], 0, 1);
        $this->SetFont('helvetica', '', 10);
        
        // Get the date with fallback options
        $formatted_date = '';
        if (!empty($order['formatted_processed_date'])) {
            $formatted_date = $order['formatted_processed_date'];
        } elseif (!empty($order['processed_at'])) {
            try {
                $date = new DateTime($order['processed_at']);
                $date->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $formatted_date = $date->format('M d, Y h:i A');
            } catch (Exception $e) {
                error_log("Error formatting date: " . $e->getMessage());
                $formatted_date = date('M d, Y h:i A');
            }
        } else {
            $formatted_date = date('M d, Y h:i A');
        }
        
        $this->Cell(0, 10, 'Date: ' . $formatted_date, 0, 1);
        
        // Customer Information
        $this->Ln(5);
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(95, 10, 'Bill To:', 0, 0);
        $this->Cell(95, 10, 'Ship To:', 0, 1);
        
        $this->SetFont('helvetica', '', 10);
        
        // Billing Address
        $billing = is_array($order['billing_address']) ? $order['billing_address'] : json_decode($order['billing_address'], true);
        if ($billing) {
            $this->MultiCell(95, 5, 
                $billing['name'] . "\n" .
                $billing['address1'] . "\n" .
                ($billing['address2'] ? $billing['address2'] . "\n" : '') .
                $billing['city'] . ', ' . $billing['province_code'] . ' ' . $billing['zip'] . "\n" .
                $billing['country'],
                0, 'L', 0, 0);
        }
        
        // Shipping Address
        $shipping = is_array($order['shipping_address']) ? $order['shipping_address'] : json_decode($order['shipping_address'], true);
        if ($shipping) {
            $this->MultiCell(95, 5,
                $shipping['name'] . "\n" .
                $shipping['address1'] . "\n" .
                ($shipping['address2'] ? $shipping['address2'] . "\n" : '') .
                $shipping['city'] . ', ' . $shipping['province_code'] . ' ' . $shipping['zip'] . "\n" .
                $shipping['country'],
                0, 'L', 0, 1);
        }
        
        $this->Ln(10);
        
        // Items Table Header
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(80, 8, 'Item', 1, 0, 'L', true);
        $this->Cell(30, 8, 'Price', 1, 0, 'R', true);
        $this->Cell(30, 8, 'Quantity', 1, 0, 'R', true);
        $this->Cell(40, 8, 'Total', 1, 1, 'R', true);
        
        // Items
        $this->SetFont('helvetica', '', 10);
        $items = is_array($order['line_items']) ? $order['line_items'] : json_decode($order['line_items'], true);
        $subtotal = 0;
        
        if (is_array($items)) {
            foreach ($items as $item) {
                $this->MultiCell(80, 8, $item['title'], 1, 'L', 0, 0);
                $this->Cell(30, 8, $order['currency'] . ' ' . number_format($item['price'], 2), 1, 0, 'R');
                $this->Cell(30, 8, $item['quantity'], 1, 0, 'R');
                $item_total = $item['price'] * $item['quantity'];
                $this->Cell(40, 8, $order['currency'] . ' ' . number_format($item_total, 2), 1, 1, 'R');
                $subtotal += $item_total;
            }
            
            // Add totals
            $this->Ln(10);
            $this->SetFont('helvetica', 'B', 10);
            
            // Debug information
            error_log("Subtotal: " . $subtotal);
            error_log("Discount code: " . ($order['discount_code'] ?? 'none'));
            error_log("Discount amount: " . ($order['discount_amount'] ?? 0));
            
            // Subtotal
            $this->Cell(135, 6, '', 0, 0);
            $this->Cell(30, 6, 'Subtotal:', 0, 0, 'R');
            $this->Cell(25, 6, $this->formatCurrency($subtotal, $order['currency']), 0, 1, 'R');

            // Discount if applicable
            if (!empty($order['discount_code']) && floatval($order['discount_amount']) > 0) {
                $this->Cell(135, 6, '', 0, 0);
                $this->Cell(30, 6, 'Discount (' . $order['discount_code'] . '):', 0, 0, 'R');
                $this->Cell(25, 6, '- ' . $this->formatCurrency(floatval($order['discount_amount']), $order['currency']), 0, 1, 'R');
            }

            // Shipping if applicable
            if (isset($order['total_shipping']) && floatval($order['total_shipping']) > 0) {
                $this->Cell(135, 6, '', 0, 0);
                $this->Cell(30, 6, 'Shipping:', 0, 0, 'R');
                $this->Cell(25, 6, $this->formatCurrency(floatval($order['total_shipping']), $order['currency']), 0, 1, 'R');
            }

            // Tax if applicable
            if (isset($order['total_tax']) && floatval($order['total_tax']) > 0) {
                $this->Cell(135, 6, '', 0, 0);
                $this->Cell(30, 6, 'Tax:', 0, 0, 'R');
                $this->Cell(25, 6, $this->formatCurrency(floatval($order['total_tax']), $order['currency']), 0, 1, 'R');
            }

            // Total
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(135, 8, '', 0, 0);
            $this->Cell(30, 8, 'Total:', 0, 0, 'R');
            $this->Cell(25, 8, $this->formatCurrency(floatval($order['total_price']), $order['currency']), 0, 1, 'R');
        }
        
        return $this;
    }
    
    private function formatCurrency($amount, $currency) {
        return $currency . ' ' . number_format($amount, 2);
    }
}
