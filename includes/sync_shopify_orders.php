<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/shopify_config.php';

function sync_shopify_orders() {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get all agents
    $stmt = $conn->prepare("
        SELECT c.* 
        FROM " . TABLE_CUSTOMERS . " c
        WHERE c.is_agent = 1 AND c.status = 'active'
    ");
    $stmt->execute();
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            // Check if order already exists
            $stmt = $conn->prepare("SELECT id FROM " . TABLE_ORDERS . " WHERE order_number = ?");
            $stmt->execute([$order['order_number']]);
            $existing_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing_order) {
                // Get customer from database
                $stmt = $conn->prepare("SELECT id, agent_id FROM " . TABLE_CUSTOMERS . " WHERE id = ?");
                $stmt->execute([$order['customer']['id']]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($customer) {
                    // Use existing customer's agent
                    $agent_id = $customer['agent_id'];
                } else {
                    // Assign to first available agent if no customer found
                    $agent_id = $agents[0]['id'] ?? null;
                }
                
                if ($agent_id) {
                    // Insert order
                    $stmt = $conn->prepare("
                        INSERT INTO " . TABLE_ORDERS . " 
                        (order_number, customer_id, agent_id, total_price, status, created_at, discount_applications) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $order['order_number'],
                        $order['customer']['id'], // Use Shopify customer ID directly
                        $agent_id,
                        $order['total_price'],
                        $order['financial_status'],
                        $order['created_at'],
                        json_encode($order['discount_applications'] ?? [])
                    ]);
                    
                    // Calculate and insert commission
                    $commission_amount = calculate_commission($order['total_price']);
                    $order_id = $conn->lastInsertId();
                    
                    $stmt = $conn->prepare("
                        INSERT INTO " . TABLE_COMMISSIONS . "
                        (order_id, agent_id, amount, status, created_at)
                        VALUES (?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$order_id, $agent_id, $commission_amount]);
                    
                    error_log("Synced order: " . $order['order_number'] . " for agent: " . $agent_id);
                }
            }
        }
    }
}

function calculate_commission($total_price) {
    // Calculate 10% commission
    return $total_price * 0.10;
}

// Run sync
sync_shopify_orders();

echo "Orders synced successfully!";
?>
