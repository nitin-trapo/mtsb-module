<?php
require_once '../config/database.php';
require_once '../config/shopify_config.php';

function sync_shopify_orders() {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Fetch orders from Shopify
    $url = "https://" . SHOPIFY_SHOP_DOMAIN . "/admin/api/" . SHOPIFY_API_VERSION . "/orders.json?status=any&limit=50";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    curl_close($ch);
    
    if (!empty($result['orders'])) {
        foreach ($result['orders'] as $order) {
            // Check if order exists
            $stmt = $conn->prepare("SELECT id FROM " . TABLE_ORDERS . " WHERE shopify_order_id = ?");
            $stmt->execute([$order['id']]);
            $existing_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing_order) {
                // Get customer details
                $customer_email = $order['customer']['email'];
                
                // Find agent for this customer
                $stmt = $conn->prepare("
                    SELECT c.id as agent_id 
                    FROM " . TABLE_CUSTOMERS . " c
                    WHERE c.is_agent = 1 
                    AND c.status = 'active'
                    AND EXISTS (
                        SELECT 1 
                        FROM " . TABLE_CUSTOMERS . " cust 
                        WHERE cust.email = ? 
                        AND cust.agent_id = c.id
                    )
                ");
                $stmt->execute([$customer_email]);
                $agent = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($agent) {
                    // Insert order
                    $stmt = $conn->prepare("
                        INSERT INTO " . TABLE_ORDERS . " 
                        (shopify_order_id, order_number, customer_id, agent_id, total_price, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $order['id'],
                        $order['order_number'],
                        $order['customer']['id'],
                        $agent['agent_id'],
                        $order['total_price'],
                        $order['financial_status'],
                        $order['created_at']
                    ]);
                    
                    error_log("Synced order: " . $order['order_number'] . " for agent: " . $agent['agent_id']);
                }
            }
        }
    }
}

// Run sync
sync_shopify_orders();
?>
