<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get all existing commissions with their orders
    $query = "
        SELECT 
            c.id as commission_id,
            c.order_id,
            o.line_items,
            o.total_price as order_amount,
            o.currency
        FROM commissions c
        JOIN orders o ON c.order_id = o.id
    ";
    
    $stmt = $conn->query($query);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated_count = 0;
    
    foreach ($commissions as $commission) {
        $line_items = json_decode($commission['line_items'], true);
        $total_commission = 0;
        
        if (!empty($line_items) && is_array($line_items)) {
            foreach ($line_items as $item) {
                // Extract product type from item name
                $product_type = '';
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
                
                // Get commission rate from rules
                $query = "
                    SELECT commission_percentage 
                    FROM commission_rules 
                    WHERE status = 'active' 
                    AND (
                        (rule_type = 'product_type' AND LOWER(rule_value) = LOWER(?))
                        OR rule_type = 'all'
                    )
                    ORDER BY rule_type = 'product_type' DESC
                    LIMIT 1
                ";
                
                $stmt = $conn->prepare($query);
                $stmt->execute([$product_type]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $commission_rate = $rule ? floatval($rule['commission_percentage']) : 0;
                
                // Calculate commission for this item
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
                
                $item_commission = $item_total * ($commission_rate / 100);
                $total_commission += $item_commission;
            }
            
            // Update commission amount if it has changed
            $update_query = "UPDATE commissions SET amount = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->execute([$total_commission, $commission['commission_id']]);
            $updated_count++;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Successfully refreshed $updated_count commission records",
        'updated_count' => $updated_count
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error refreshing commissions: ' . $e->getMessage()
    ]);
}
