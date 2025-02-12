<?php
require_once 'config/database.php';
require_once 'config/shopify_config.php';
require_once 'classes/ShopifyAPI.php';

try {
    $api = new ShopifyAPI();
    $customerId = '7533175898367';
    
    // Get customer details
    $customerResponse = $api->makeApiCall("customers/$customerId.json");
    if (empty($customerResponse['customer'])) {
        die("Customer not found\n");
    }
    
    $customer = $customerResponse['customer'];
    $fullName = trim($customer['first_name'] . ' ' . $customer['last_name']);
    
    echo "\n=== Customer Details ===\n";
    echo "ID: $customerId\n";
    echo "Name: " . ($fullName ?: 'N/A') . "\n";
    echo "Email: {$customer['email']}\n";
    echo "Phone: " . ($customer['phone'] ?: 'N/A') . "\n";
    echo "Created: " . date('Y-m-d H:i:s', strtotime($customer['created_at'])) . "\n";
    echo "Updated: " . date('Y-m-d H:i:s', strtotime($customer['updated_at'])) . "\n";
    
    // Get customer metafields
    $metafieldsResponse = $api->makeApiCall("customers/$customerId/metafields.json");
    $metafields = $metafieldsResponse['metafields'] ?? [];
    
    if (!empty($metafields)) {
        echo "\n=== Metafields (" . count($metafields) . " found) ===\n";
        
        // Group metafields by namespace for better readability
        $groupedMetafields = [];
        foreach ($metafields as $metafield) {
            $namespace = $metafield['namespace'] ?: 'default';
            if (!isset($groupedMetafields[$namespace])) {
                $groupedMetafields[$namespace] = [];
            }
            $groupedMetafields[$namespace][] = $metafield;
        }
        
        foreach ($groupedMetafields as $namespace => $fields) {
            echo "\nNamespace: $namespace\n";
            echo str_repeat('-', strlen($namespace) + 11) . "\n";
            foreach ($fields as $field) {
                $value = $field['value'];
                
                // Try to decode JSON values for better display
                if ($field['type'] === 'json' || $field['type'] === 'list.single_line_text_field') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $value = json_encode($decoded, JSON_PRETTY_PRINT);
                    }
                }
                
                // Clean up the value for display
                if (is_string($value) && strlen($value) > 100) {
                    $value = substr($value, 0, 97) . '...';
                }
                
                echo "• {$field['key']}\n";
                echo "  Type: {$field['type']}\n";
                echo "  Value: " . str_replace("\n", "\n         ", $value) . "\n";
                if (!empty($field['description'])) {
                    echo "  Description: {$field['description']}\n";
                }
                echo "\n";
            }
        }
        
        echo "\n=== Important Fields Summary ===\n";
        $importantKeys = [
            'business_registration_numb',
            'tax_identification_number_',
            'individual_ic_number',
            'bank_name_e',
            'bank_account_no',
            'bank_statement_header'
        ];
        
        $found = false;
        foreach ($metafields as $field) {
            if (in_array($field['key'], $importantKeys)) {
                echo "{$field['key']}: {$field['value']}\n";
                $found = true;
            }
        }
        if (!$found) {
            echo "No important business/banking metafields found.\n";
        }
    } else {
        echo "\nNo metafields found.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
