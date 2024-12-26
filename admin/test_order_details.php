<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/shopify_config.php';

// Function to make GraphQL API request
function shopifyGraphQL($query) {
    $url = "https://" . SHOPIFY_SHOP_DOMAIN . "/admin/api/2024-01/graphql.json";
    
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
    
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        throw new Exception("GraphQL API Request Error: " . $error);
    }
    
    return json_decode($response, true);
}

try {
    // Get order details using GraphQL
    $graphql_query = '{
      orders(first: 1, query: "name:MT-CP1027") {
        edges {
          node {
            id
            name
            displayFulfillmentStatus
            displayFinancialStatus
            createdAt
            totalPriceSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            subtotalPriceSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            totalDiscountsSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            customer {
              firstName
              lastName
              email
            }
            shippingAddress {
              address1
              city
              province
              zip
              country
            }
            lineItems(first: 50) {
              edges {
                node {
                  id
                  title
                  quantity
                  sku
                  lineItemGroup {
                    id
                    title
                  }
                  variant {
                    id
                    title
                    sku
                    price
                    product {
                      id
                      title
                      handle
                      productType
                      vendor
                      metafields(first: 10) {
                        edges {
                          node {
                            namespace
                            key
                            value
                          }
                        }
                      }
                    }
                  }
                  customAttributes {
                    key
                    value
                  }
                  discountAllocations {
                    allocatedAmount {
                      amount
                    }
                  }
                  originalUnitPrice
                }
              }
            }
          }
        }
      }
    }';
    
    $graphql_data = shopifyGraphQL($graphql_query);
    
    if (!empty($graphql_data['data']['orders']['edges'])) {
        $order = $graphql_data['data']['orders']['edges'][0]['node'];
        
        // Display Order Header
        echo "<div class='container mt-4'>";
        echo "<h1>Order {$order['name']}</h1>";
        
        // Order Details
        echo "<div class='card mb-4'>";
        echo "<div class='card-header'><h3>Order Details</h3></div>";
        echo "<div class='card-body'>";
        echo "<dl class='row'>";
        echo "<dt class='col-sm-3'>Order Status</dt>";
        echo "<dd class='col-sm-9'>{$order['displayFulfillmentStatus']} / {$order['displayFinancialStatus']}</dd>";
        
        echo "<dt class='col-sm-3'>Created At</dt>";
        echo "<dd class='col-sm-9'>" . date('Y-m-d H:i:s', strtotime($order['createdAt'])) . "</dd>";
        
        echo "<dt class='col-sm-3'>Total</dt>";
        echo "<dd class='col-sm-9'>{$order['totalPriceSet']['shopMoney']['amount']} {$order['totalPriceSet']['shopMoney']['currencyCode']}</dd>";
        
        echo "<dt class='col-sm-3'>Subtotal</dt>";
        echo "<dd class='col-sm-9'>{$order['subtotalPriceSet']['shopMoney']['amount']} {$order['subtotalPriceSet']['shopMoney']['currencyCode']}</dd>";
        
        echo "<dt class='col-sm-3'>Discounts</dt>";
        echo "<dd class='col-sm-9'>{$order['totalDiscountsSet']['shopMoney']['amount']} {$order['totalDiscountsSet']['shopMoney']['currencyCode']}</dd>";
        echo "</dl>";
        echo "</div>";
        echo "</div>";
        
        // Customer Information
        if (!empty($order['customer'])) {
            echo "<div class='card mb-4'>";
            echo "<div class='card-header'><h3>Customer Information</h3></div>";
            echo "<div class='card-body'>";
            echo "<dl class='row'>";
            echo "<dt class='col-sm-3'>Name</dt>";
            echo "<dd class='col-sm-9'>{$order['customer']['firstName']} {$order['customer']['lastName']}</dd>";
            echo "<dt class='col-sm-3'>Email</dt>";
            echo "<dd class='col-sm-9'>{$order['customer']['email']}</dd>";
            echo "</dl>";
            echo "</div>";
            echo "</div>";
        }
        
        // Line Items
        echo "<div class='card mb-4'>";
        echo "<div class='card-header'><h3>Line Items</h3></div>";
        echo "<div class='card-body'>";
        
        // Group items by lineItemGroup
        $grouped_items = [];
        foreach ($order['lineItems']['edges'] as $edge) {
            $item = $edge['node'];
            
            // Use vendor as primary grouping, fallback to lineItemGroup, then 'Ungrouped'
            $group_title = $item['variant']['product']['vendor'] ?? 
                           $item['lineItemGroup']['title'] ?? 
                           'Ungrouped Items';
            
            $group_id = $group_title; // Use title as group ID for better readability
            
            if (!isset($grouped_items[$group_id])) {
                $grouped_items[$group_id] = [
                    'title' => $group_title,
                    'items' => []
                ];
            }
            $grouped_items[$group_id]['items'][] = $item;
        }
        
        // Display grouped items
        foreach ($grouped_items as $group_id => $group) {
            echo "<div class='mb-4'>";
            echo "<h4 class='mb-3 text-primary'>{$group['title']}</h4>";
            
            foreach ($group['items'] as $item) {
                echo "<div class='card mb-2'>";
                echo "<div class='card-body'>";
                echo "<div class='d-flex justify-content-between align-items-center'>";
                echo "<div>";
                echo "<h5 class='mb-1'>{$item['title']}</h5>";
                
                // Display variant title if exists
                if (!empty($item['variant']['title']) && $item['variant']['title'] !== 'Default Title') {
                    echo "<small class='text-muted d-block'>{$item['variant']['title']}</small>";
                }
                
                echo "</div>";
                
                // Pricing and Quantity
                echo "<div class='text-end'>";
                echo "<span class='badge bg-secondary'>{$item['quantity']} × {$order['totalPriceSet']['shopMoney']['currencyCode']} " . 
                     number_format(floatval($item['variant']['price']), 2) . "</span>";
                echo "</div>";
                echo "</div>";
                
                // Additional details
                echo "<dl class='row mt-2 mb-0'>";
                echo "<dt class='col-sm-3'>SKU</dt>";
                echo "<dd class='col-sm-9'>" . htmlspecialchars($item['sku'] ?? 'N/A') . "</dd>";
                
                // Product Type and Vendor
                if (!empty($item['variant']['product']['productType'])) {
                    echo "<dt class='col-sm-3'>Product Type</dt>";
                    echo "<dd class='col-sm-9'>" . htmlspecialchars($item['variant']['product']['productType']) . "</dd>";
                }
                
                echo "</dl>";
                echo "</div>";
                echo "</div>";
            }
            echo "</div>"; // Close group div
        }
        
        echo "</div>";
        echo "</div>";
        echo "</div>";
        
    } else {
        echo "<div class='alert alert-warning'>No order data found</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>" . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .card-header {
            background-color: #f8f9fa;
        }
        dt {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
