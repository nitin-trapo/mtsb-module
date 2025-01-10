<?php
require_once '../config/database.php';
require_once '../classes/ShopifyAPI.php';

try {
    // Initialize Shopify API
    $shopify = new ShopifyAPI();
    
    // Register webhook for order creation
    $endpoint = '/admin/api/2023-10/webhooks.json';
    $data = [
        'webhook' => [
            'topic' => 'orders/create',
            'address' => 'https://your-domain.com/shopify-agent-module/webhooks/order_created.php',
            'format' => 'json'
        ]
    ];
    
    $response = $shopify->makeApiCall($endpoint, 'POST', $data);
    
    if (isset($response['webhook']['id'])) {
        echo "Webhook registered successfully! Webhook ID: " . $response['webhook']['id'];
    } else {
        echo "Error registering webhook: " . print_r($response, true);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
