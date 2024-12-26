<?php
require_once __DIR__ . '/../config/shopify_config.php';

class ShopifyAPI {
    private $shop_domain;
    private $access_token;
    private $api_version;
    private $db;
    private $lastResponseHeaders;

    public function __construct() {
        if (!defined('SHOPIFY_SHOP_DOMAIN') || !defined('SHOPIFY_ACCESS_TOKEN') || !defined('SHOPIFY_API_VERSION')) {
            throw new Exception('Shopify configuration constants are not defined. Please check config/shopify_config.php');
        }
        
        $this->shop_domain = SHOPIFY_SHOP_DOMAIN;
        $this->access_token = SHOPIFY_ACCESS_TOKEN;
        $this->api_version = SHOPIFY_API_VERSION;
        
        $database = new Database();
        $this->db = $database->getConnection();
        // Set PDO to throw exceptions
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function makeApiCall($endpoint, $method = 'GET', $data = null) {
        $url = "https://{$this->shop_domain}/admin/api/{$this->api_version}/{$endpoint}";
        
        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: {$this->access_token}",
                'Content-Type: application/json'
            ]
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        
        if ($response === false) {
            $error = curl_error($curl);
            $errno = curl_errno($curl);
            curl_close($curl);
            throw new Exception("API Call Error ({$errno}): {$error}");
        }
        
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        $this->lastResponseHeaders = $this->parseHeaders($headerStr);
        
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode >= 400) {
            throw new Exception("API returned error code: {$httpCode}");
        }
        
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        return $decoded;
    }

    private function parseHeaders($headerStr) {
        $headers = [];
        $headerLines = explode("\r\n", $headerStr);
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        return $headers;
    }

    private function getLastResponseHeader($header) {
        return isset($this->lastResponseHeaders[$header]) ? $this->lastResponseHeaders[$header] : null;
    }

    private function logError($message, $context = []) {
        $logFile = __DIR__ . '/../logs/shopify_error.log';
        $logDir = dirname($logFile);
        
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logMessage = "[$timestamp] $message $contextStr\n";
        
        error_log($logMessage, 3, $logFile);
    }

    private function makeGraphQLCall($query) {
        try {
            $url = "https://" . $this->shop_domain . "/admin/api/2023-10/graphql.json";
            
            $headers = [
                "X-Shopify-Access-Token: " . $this->access_token,
                "Content-Type: application/json",
            ];

            $data = json_encode(['query' => $query]);

            $this->logError("GraphQL Request Details", [
                'url' => $url,
                'query' => $query
            ]);

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            
            // Create temp file for CURL debug info
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($curl, CURLOPT_STDERR, $verbose);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            
            // Get CURL debug info
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);

            // Log detailed request information
            $this->logError("GraphQL Request Details", [
                'url' => $url,
                'http_code' => $httpCode,
                'curl_verbose' => $verboseLog,
                'request_headers' => $headers,
                'request_data' => $data
            ]);

            if ($response === false) {
                $error = curl_error($curl);
                curl_close($curl);
                throw new Exception("CURL Error: $error");
            }

            curl_close($curl);

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON Decode Error: " . json_last_error_msg());
            }

            // Log response details
            $this->logError("GraphQL Response Details", [
                'http_code' => $httpCode,
                'response_size' => strlen($response),
                'decoded_structure' => array_keys($decoded)
            ]);

            return $decoded;
        } catch (Exception $e) {
            $this->logError("makeGraphQLCall failed", [
                'error' => $e->getMessage(),
                'query' => $query
            ]);
            throw $e;
        }
    }

    public function getProductTags() {
        try {
            $this->logError("Starting getProductTags using REST API with cursor pagination");
            $allTags = [];
            $duplicateTagCount = 0;
            $rawTagCount = 0;
            $tagFrequency = [];  // Track how many times each tag appears
            $nextUrl = "https://" . $this->shop_domain . "/admin/api/2023-10/products.json?fields=tags&limit=250";
            $page = 1;
            $retryCount = 0;
            $maxRetries = 3;

            while ($nextUrl !== null && $retryCount < $maxRetries) {
                try {
                    $this->logError("Making REST API call", [
                        'url' => $nextUrl,
                        'page' => $page,
                        'current_unique_tags' => count($allTags),
                        'total_raw_tags' => $rawTagCount,
                        'duplicate_tags' => $duplicateTagCount
                    ]);

                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_URL => $nextUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HEADER => true,
                        CURLOPT_HTTPHEADER => [
                            "X-Shopify-Access-Token: " . $this->access_token,
                            "Content-Type: application/json",
                            "Accept: application/json"
                        ],
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_TIMEOUT => 30
                    ]);

                    $response = curl_exec($curl);
                    
                    if ($response === false) {
                        throw new Exception("CURL Error: " . curl_error($curl));
                    }

                    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
                    
                    curl_close($curl);

                    if ($httpCode !== 200) {
                        throw new Exception("HTTP Error: " . $httpCode . " Response: " . $response);
                    }

                    // Split response into headers and body
                    $headerStr = substr($response, 0, $headerSize);
                    $body = substr($response, $headerSize);

                    // Parse headers
                    $headers = [];
                    foreach (explode("\r\n", $headerStr) as $line) {
                        if (strpos($line, ':') !== false) {
                            list($key, $value) = explode(':', $line, 2);
                            $headers[trim($key)] = trim($value);
                        }
                    }

                    // Parse JSON response
                    $data = json_decode($body, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("JSON Decode Error: " . json_last_error_msg());
                    }

                    if (!isset($data['products'])) {
                        throw new Exception("Invalid response structure: missing products array");
                    }

                    // Process products and extract tags
                    $pageTagStats = [
                        'total_tags' => 0,
                        'new_tags' => 0,
                        'duplicate_tags' => 0,
                        'products_with_tags' => 0
                    ];

                    foreach ($data['products'] as $product) {
                        if (!empty($product['tags'])) {
                            $pageTagStats['products_with_tags']++;
                            $productTags = array_map('trim', explode(',', $product['tags']));
                            foreach ($productTags as $tag) {
                                if (!empty($tag)) {
                                    $rawTagCount++;
                                    $pageTagStats['total_tags']++;
                                    
                                    // Track tag frequency
                                    if (!isset($tagFrequency[$tag])) {
                                        $tagFrequency[$tag] = 0;
                                    }
                                    $tagFrequency[$tag]++;

                                    if (!in_array($tag, $allTags)) {
                                        $allTags[] = $tag;
                                        $pageTagStats['new_tags']++;
                                    } else {
                                        $duplicateTagCount++;
                                        $pageTagStats['duplicate_tags']++;
                                    }
                                }
                            }
                        }
                    }

                    $this->logError("Page $page processed", [
                        'products_in_page' => count($data['products']),
                        'products_with_tags' => $pageTagStats['products_with_tags'],
                        'total_tags_in_page' => $pageTagStats['total_tags'],
                        'new_unique_tags' => $pageTagStats['new_tags'],
                        'duplicate_tags' => $pageTagStats['duplicate_tags'],
                        'current_total_unique' => count($allTags),
                        'current_total_raw' => $rawTagCount
                    ]);

                    // Check for Link header and extract next URL
                    $nextUrl = null;
                    if (isset($headers['Link'])) {
                        $links = explode(',', $headers['Link']);
                        foreach ($links as $link) {
                            if (strpos($link, 'rel="next"') !== false) {
                                if (preg_match('/<(.+?)>/', $link, $matches)) {
                                    $nextUrl = $matches[1];
                                    $this->logError("Found next page URL", ['next_url' => $nextUrl]);
                                    break;
                                }
                            }
                        }
                    }

                    if (!$nextUrl) {
                        $this->logError("No more pages to fetch");
                        break;
                    }

                    $page++;
                    $retryCount = 0;
                    usleep(500000);

                } catch (Exception $e) {
                    $retryCount++;
                    $this->logError("Error processing page $page (Attempt $retryCount of $maxRetries)", [
                        'error' => $e->getMessage()
                    ]);
                    
                    if ($retryCount >= $maxRetries) {
                        throw $e;
                    }
                    
                    sleep(2);
                }
            }

            // Sort tags
            sort($allTags);

            // Find most common tags
            arsort($tagFrequency);
            $mostCommonTags = array_slice($tagFrequency, 0, 10, true);

            $this->logError("Completed fetching tags", [
                'total_pages' => $page,
                'total_unique_tags' => count($allTags),
                'total_raw_tags' => $rawTagCount,
                'duplicate_tags' => $duplicateTagCount,
                'most_common_tags' => $mostCommonTags,
                'sample_unique_tags' => array_slice($allTags, 0, 10)
            ]);

            return $allTags;
        } catch (Exception $e) {
            $this->logError("Failed to fetch product tags", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function syncCustomer($customer) {
        if (empty($customer['id'])) {
            $this->logError("Cannot sync customer: missing ID", ['customer' => $customer]);
            return false;
        }

        try {
            // Check if customer exists
            $stmt = $this->db->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
            $stmt->execute([$customer['id']]);
            if ($stmt->fetch()) {
                $this->logError("Customer already exists", ['shopify_customer_id' => $customer['id']]);
                return false;
            }

            // Prepare customer data
            $customer_data = [
                'shopify_customer_id' => $customer['id'],
                'email' => $customer['email'] ?? '',
                'first_name' => $customer['first_name'] ?? '',
                'last_name' => $customer['last_name'] ?? '',
                'phone' => $customer['phone'] ?? '',
                'total_spent' => $customer['total_spent'] ?? 0,
                'orders_count' => $customer['orders_count'] ?? 0,
                'verified_email' => isset($customer['verified_email']) ? (int)$customer['verified_email'] : 0,
                'tax_exempt' => isset($customer['tax_exempt']) ? (int)$customer['tax_exempt'] : 0,
                'tags' => $customer['tags'] ?? '',
                'default_address' => json_encode($customer['default_address'] ?? null),
                'addresses' => json_encode($customer['addresses'] ?? []),
                'created_at' => $customer['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $customer['updated_at'] ?? date('Y-m-d H:i:s')
            ];

            $this->logError("Attempting to insert customer", [
                'shopify_customer_id' => $customer['id'],
                'email' => $customer['email'] ?? 'no email'
            ]);

            // Insert new customer
            $columns = implode(', ', array_keys($customer_data));
            $values = ':' . implode(', :', array_keys($customer_data));
            $stmt = $this->db->prepare("
                INSERT INTO customers ({$columns})
                VALUES ({$values})
            ");
            
            $stmt->execute($customer_data);
            
            $this->logError("Successfully synced customer", [
                'shopify_customer_id' => $customer['id'],
                'email' => $customer['email'] ?? 'no email'
            ]);
            
            return true;

        } catch (Exception $e) {
            $this->logError("Error syncing customer", [
                'shopify_customer_id' => $customer['id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function syncCustomers() {
        $db = new Database();
        $conn = $db->getConnection();
        $total_synced = 0;
        $page_size = 250;

        try {
            // Get existing customer IDs
            $stmt = $conn->query("SELECT shopify_customer_id FROM customers");
            $existing_customers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $endpoint = "customers.json?limit={$page_size}";
            
            do {
                $response = $this->makeApiCall($endpoint);
                if (empty($response['customers'])) {
                    break;
                }

                foreach ($response['customers'] as $customer) {
                    if (!in_array($customer['id'], $existing_customers)) {
                        if ($this->syncCustomer($customer)) {
                            $total_synced++;
                            $existing_customers[] = $customer['id'];
                        }
                    }
                }

                // Check for next page using Link header
                $link_header = $this->getLastResponseHeader('Link');
                if ($link_header && preg_match('/<([^>]*)>; rel="next"/', $link_header, $matches)) {
                    $endpoint = parse_url($matches[1], PHP_URL_QUERY);
                } else {
                    break;
                }

            } while (true);

            return $total_synced;

        } catch (Exception $e) {
            error_log("Error in syncCustomers: " . $e->getMessage());
            throw $e;
        }
    }

    public function getProductTypes() {
        try {
            $query = <<<'GRAPHQL'
            {
                productTypes: shop {
                    productTypes(first: 250) {
                        edges {
                            node
                        }
                    }
                }
            }
            GRAPHQL;

            $this->logError("Starting product types fetch");
            $result = $this->makeGraphQLCall($query);
            $types = [];
            
            if (isset($result['data']['productTypes']['productTypes']['edges'])) {
                foreach ($result['data']['productTypes']['productTypes']['edges'] as $edge) {
                    if (!empty($edge['node'])) {
                        $types[] = $edge['node'];
                    }
                }
            }
            
            $this->logError("Completed product types fetch", [
                'total_types' => count($types),
                'sample_types' => array_slice($types, 0, 5)
            ]);
            
            return $types;
        } catch (Exception $e) {
            $this->logError("Failed to fetch product types", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getProducts($since_id = 0) {
        try {
            $query = <<<'GRAPHQL'
            {
                products(first: 250, since_id: $since_id) {
                    edges {
                        node {
                            id
                            title
                            product_type
                            vendor
                            handle
                            status
                            tags
                        }
                    }
                }
            }
            GRAPHQL;

            $response = $this->makeGraphQLCall($query);
            
            if (!isset($response['products']['edges'])) {
                throw new Exception("No products found in GraphQL response");
            }

            $products = array_map(function($edge) {
                return $edge['node'];
            }, $response['products']['edges']);

            return array_filter($products); // Remove empty values
        } catch (Exception $e) {
            throw new Exception("Failed to fetch products: " . $e->getMessage());
        }
    }

    private function syncProduct($product) {
        try {
            $this->db->beginTransaction();

            // Insert/Update product
            $stmt = $this->db->prepare("
                INSERT INTO products 
                (shopify_product_id, title, product_type, vendor, handle, status, tags, last_sync_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                product_type = VALUES(product_type),
                vendor = VALUES(vendor),
                handle = VALUES(handle),
                status = VALUES(status),
                tags = VALUES(tags),
                last_sync_at = NOW()
            ");

            $stmt->execute([
                $product['id'],
                $product['title'],
                $product['product_type'],
                $product['vendor'],
                $product['handle'],
                $product['status'],
                implode(',', $product['tags'] ?? [])
            ]);

            $product_id = $this->db->lastInsertId();
            if (!$product_id) {
                $stmt = $this->db->prepare("SELECT id FROM products WHERE shopify_product_id = ?");
                $stmt->execute([$product['id']]);
                $product_id = $stmt->fetchColumn();
            }

            // Insert/Update variants
            foreach ($product['variants'] as $variant) {
                $stmt = $this->db->prepare("
                    INSERT INTO product_variants 
                    (product_id, shopify_variant_id, sku, title, price, compare_at_price, inventory_quantity)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    sku = VALUES(sku),
                    title = VALUES(title),
                    price = VALUES(price),
                    compare_at_price = VALUES(compare_at_price),
                    inventory_quantity = VALUES(inventory_quantity)
                ");

                $stmt->execute([
                    $product_id,
                    $variant['id'],
                    $variant['sku'],
                    $variant['title'],
                    $variant['price'],
                    $variant['compare_at_price'],
                    $variant['inventory_quantity']
                ]);
            }

            $this->db->commit();
            return $product_id;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function syncCustomerFromOrder($customer) {
        if (empty($customer['id'])) {
            return null;
        }

        $db = new Database();
        $conn = $db->getConnection();

        try {
            // Check if customer exists
            $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
            $stmt->execute([$customer['id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                // Get full customer data from Shopify
                $customer_endpoint = "customers/{$customer['id']}.json";
                try {
                    $customer_response = $this->makeApiCall($customer_endpoint, 'GET');
                    if (!empty($customer_response['customer'])) {
                        // Prepare customer data
                        $customer_data = [
                            'shopify_customer_id' => $customer_response['customer']['id'],
                            'email' => $customer_response['customer']['email'] ?? '',
                            'first_name' => $customer_response['customer']['first_name'] ?? '',
                            'last_name' => $customer_response['customer']['last_name'] ?? '',
                            'phone' => $customer_response['customer']['phone'] ?? '',
                            'total_spent' => $customer_response['customer']['total_spent'] ?? 0,
                            'orders_count' => $customer_response['customer']['orders_count'] ?? 0,
                            'verified_email' => $customer_response['customer']['verified_email'] ? 1 : 0,
                            'tax_exempt' => $customer_response['customer']['tax_exempt'] ? 1 : 0,
                            'tags' => $customer_response['customer']['tags'] ?? '',
                            'default_address' => json_encode($customer_response['customer']['default_address'] ?? null),
                            'addresses' => json_encode($customer_response['customer']['addresses'] ?? []),
                            'created_at' => $customer_response['customer']['created_at'],
                            'updated_at' => $customer_response['customer']['updated_at']
                        ];

                        // Insert new customer
                        $columns = implode(', ', array_keys($customer_data));
                        $values = ':' . implode(', :', array_keys($customer_data));
                        $stmt = $conn->prepare("
                            INSERT INTO customers ({$columns})
                            VALUES ({$values})
                        ");
                        $stmt->execute($customer_data);
                        
                        $customer_id = $conn->lastInsertId();
                        error_log("Synced new customer from order: ID {$customer_response['customer']['id']} ({$customer_response['customer']['email']})");
                        
                        return $customer_id;
                    }
                } catch (Exception $e) {
                    error_log("Error fetching customer data for order {$customer['id']}: " . $e->getMessage());
                }
            } else {
                // Get existing customer ID
                $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
                $stmt->execute([$customer['id']]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $customer_id = $existing['id'];
                }
            }

            return $customer_id;
        } catch (PDOException $e) {
            error_log("Error syncing customer from order {$customer['id']}: " . $e->getMessage());
            return null;
        }
    }

    public function syncOrders($start_date) {
        $db = new Database();
        $conn = $db->getConnection();
        $total_synced = 0;
        $page_size = 250;

        try {
            // Get all existing order IDs and numbers
            $stmt = $conn->query("SELECT shopify_order_id, order_number FROM orders");
            $existing_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $existing_shopify_ids = array_column($existing_orders, 'shopify_order_id');
            $existing_order_numbers = array_column($existing_orders, 'order_number');

            // Get all existing customers
            $stmt = $conn->query("SELECT shopify_customer_id, id FROM customers");
            $existing_customers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $endpoint = "orders.json?status=any&limit={$page_size}&created_at_min=" . urlencode($start_date);
            
            do {
                $this->logError("Fetching orders", ['endpoint' => $endpoint]);
                $response = $this->makeApiCall($endpoint);
                
                if (empty($response['orders'])) {
                    $this->logError("No more orders found");
                    break;
                }

                foreach ($response['orders'] as $order) {
                    try {
                        // Get the order number (with #)
                        $order_number = $order['name'] ?? ('#' . $order['order_number']);
                        
                        $this->logError("Processing order", [
                            'order_number' => $order_number,
                            'shopify_order_id' => $order['id']
                        ]);

                        // First, sync customer if present
                        $customer_id = null;
                        if (isset($order['customer']) && !empty($order['customer']['id'])) {
                            if (!isset($existing_customers[$order['customer']['id']])) {
                                // Get full customer data and sync
                                try {
                                    $shopify_customer = $this->getCustomerById($order['customer']['id']);
                                    if ($shopify_customer) {
                                        if ($this->syncCustomer($shopify_customer)) {
                                            // Get the new customer's ID
                                            $stmt = $conn->prepare("SELECT id FROM customers WHERE shopify_customer_id = ?");
                                            $stmt->execute([$shopify_customer['id']]);
                                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                            if ($result) {
                                                $customer_id = $result['id'];
                                                $existing_customers[$shopify_customer['id']] = $customer_id;
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    $this->logError("Error syncing customer for order", [
                                        'order_number' => $order_number,
                                        'customer_id' => $order['customer']['id'],
                                        'error' => $e->getMessage()
                                    ]);
                                }
                            } else {
                                $customer_id = $existing_customers[$order['customer']['id']];
                            }
                        }

                        // Check if order exists
                        if (!in_array($order['id'], $existing_shopify_ids) && !in_array($order_number, $existing_order_numbers)) {
                            // Prepare order data
                            $order_data = [
                                'shopify_order_id' => $order['id'],
                                'order_number' => $order_number,
                                'email' => $order['email'] ?? '',
                                'total_price' => $order['total_price'] ?? 0,
                                'subtotal_price' => $order['subtotal_price'] ?? 0,
                                'total_tax' => $order['total_tax'] ?? 0,
                                'total_shipping' => isset($order['shipping_lines'][0]) ? $order['shipping_lines'][0]['price'] : 0,
                                'currency' => $order['currency'],
                                'financial_status' => $order['financial_status'] ?? '',
                                'fulfillment_status' => $order['fulfillment_status'] ?? null,
                                'processed_at' => $order['processed_at'] ?? null,
                                'created_at' => $order['created_at'],
                                'updated_at' => $order['updated_at'],
                                'line_items' => json_encode($order['line_items']),
                                'shipping_address' => json_encode($order['shipping_address'] ?? null),
                                'billing_address' => json_encode($order['billing_address'] ?? null),
                                'discount_codes' => json_encode($order['discount_codes'] ?? []),
                                'discount_applications' => json_encode($order['discount_applications'] ?? [])
                            ];

                            // Add customer ID if we have it
                            if ($customer_id) {
                                $order_data['customer_id'] = $customer_id;
                            }

                            // Handle metafields
                            $metafields_endpoint = "orders/{$order['id']}/metafields.json";
                            try {
                                $metafields_response = $this->makeApiCall($metafields_endpoint);
                                $metafields_json = null;
                                
                                if (!empty($metafields_response['metafields'])) {
                                    $metafields = [];
                                    foreach ($metafields_response['metafields'] as $metafield) {
                                        // Check if the value is already JSON
                                        if ($this->isJson($metafield['value'])) {
                                            $metafields[$metafield['key']] = json_decode($metafield['value'], true);
                                        } else {
                                            $metafields[$metafield['key']] = $metafield['value'];
                                        }
                                    }
                                    
                                    // Special handling for customer_email in JSON format
                                    if (isset($metafields['customer_email'])) {
                                        if (is_string($metafields['customer_email']) && $this->isJson($metafields['customer_email'])) {
                                            $customer_email_data = json_decode($metafields['customer_email'], true);
                                            if (isset($customer_email_data['value'])) {
                                                $metafields['customer_email'] = $customer_email_data['value'];
                                            }
                                        }
                                    }
                                    
                                    $metafields_json = !empty($metafields) ? json_encode($metafields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                                }
                                
                                $order_data['metafields'] = $metafields_json;
                                
                                // Log metafields for debugging
                                $this->logError("Metafields processed for order", [
                                    'order_id' => $order['id'],
                                    'order_number' => $order_number,
                                    'metafields' => $metafields_json
                                ]);
                                
                            } catch (Exception $e) {
                                $this->logError("Error fetching metafields for order", [
                                    'order_id' => $order['id'],
                                    'order_number' => $order_number,
                                    'error' => $e->getMessage()
                                ]);
                                $order_data['metafields'] = null;
                            }
                            
                            // Insert order
                            $columns = implode(', ', array_keys($order_data));
                            $values = ':' . implode(', :', array_keys($order_data));
                            $update_values = [];
                            foreach ($order_data as $key => $value) {
                                if ($key !== 'shopify_order_id' && $key !== 'order_number') {
                                    $update_values[] = "{$key} = VALUES({$key})";
                                }
                            }
                            $update_clause = implode(', ', $update_values);

                            $sql = "
                                INSERT INTO orders ({$columns})
                                VALUES ({$values})
                                ON DUPLICATE KEY UPDATE
                                {$update_clause}
                            ";

                            $stmt = $conn->prepare($sql);
                            $stmt->execute($order_data);
                            
                            $existing_shopify_ids[] = $order['id'];
                            $existing_order_numbers[] = $order_number;
                            
                            $total_synced++;
                            $this->logError("Synced new order", [
                                'order_number' => $order_number,
                                'shopify_order_id' => $order['id'],
                                'customer_id' => $customer_id
                            ]);
                        }
                    } catch (Exception $e) {
                        $this->logError("Error processing order", [
                            'order_number' => $order_number ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }
                }

                // Get next page URL from Link header
                $next_url = null;
                $link_header = $this->getLastResponseHeader('Link');
                if ($link_header) {
                    foreach (explode(',', $link_header) as $link) {
                        if (strpos($link, 'rel="next"') !== false) {
                            preg_match('/<(.+?)>/', $link, $matches);
                            if (isset($matches[1])) {
                                $next_url = parse_url($matches[1], PHP_URL_QUERY);
                                break;
                            }
                        }
                    }
                }

                if (!$next_url) {
                    $this->logError("No more pages to fetch");
                    break;
                }

                $endpoint = "orders.json?" . $next_url;
                
            } while (true);

            return $total_synced;

        } catch (Exception $e) {
            $this->logError("Error in syncOrders", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function logSync($type, $status, $items_synced, $error_message = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_logs 
                (sync_type, status, items_synced, error_message, started_at, completed_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$type, $status, $items_synced, $error_message]);
        } catch (Exception $e) {
            error_log("Failed to log sync: " . $e->getMessage());
        }
    }

    public function getCustomerById($shopify_customer_id) {
        try {
            $this->logError("Fetching customer", ['shopify_customer_id' => $shopify_customer_id]);
            
            $endpoint = "customers/{$shopify_customer_id}.json";
            $response = $this->makeApiCall($endpoint);
            
            if (!empty($response['customer'])) {
                $this->logError("Found customer", [
                    'shopify_customer_id' => $shopify_customer_id,
                    'email' => $response['customer']['email'] ?? 'no email'
                ]);
                return $response['customer'];
            }
            
            $this->logError("Customer not found", ['shopify_customer_id' => $shopify_customer_id]);
            return null;
            
        } catch (Exception $e) {
            $this->logError("Error fetching customer by ID", [
                'shopify_customer_id' => $shopify_customer_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getProductById($product_id) {
        try {
            $this->logError("Starting product fetch", [
                'product_id' => $product_id,
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => 'getProductById'
            ]);

            $query = <<<GRAPHQL
            {
                product(id: "gid://shopify/Product/{$product_id}") {
                    id
                    title
                    productType
                    tags
                    status
                    vendor
                    createdAt
                    updatedAt
                }
            }
            GRAPHQL;

            $this->logError("Executing GraphQL query", [
                'query' => $query,
                'product_id' => $product_id
            ]);

            $result = $this->makeGraphQLCall($query);
            
            if (isset($result['data']['product'])) {
                $product = $result['data']['product'];
                $this->logError("Product fetch successful", [
                    'product_id' => $product_id,
                    'title' => $product['title'],
                    'type' => $product['productType'],
                    'status' => $product['status'],
                    'vendor' => $product['vendor'],
                    'tags_count' => count($product['tags']),
                    'created_at' => $product['createdAt'],
                    'updated_at' => $product['updatedAt']
                ]);

                return [
                    'product_type' => $product['productType'],
                    'tags' => $product['tags'],
                    'title' => $product['title'],
                    'status' => $product['status'],
                    'vendor' => $product['vendor']
                ];
            }
            
            if (isset($result['errors'])) {
                $this->logError("GraphQL returned errors", [
                    'product_id' => $product_id,
                    'errors' => $result['errors']
                ]);
                return null;
            }
            
            $this->logError("Product not found", [
                'product_id' => $product_id,
                'response' => $result
            ]);
            return null;

        } catch (Throwable $e) {
            $this->logError("Error fetching product", [
                'product_id' => $product_id,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return null;
        }
    }

    private function isJson($string) {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}
