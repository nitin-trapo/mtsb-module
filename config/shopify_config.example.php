<?php
// Shopify API Configuration
define('SHOPIFY_SHOP_DOMAIN', 'your-store-name.myshopify.com');
define('SHOPIFY_ACCESS_TOKEN', 'shpat_xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SHOPIFY_API_VERSION', '2025-01');

// Shopify Multipass secret from your Shopify admin
define('SHOPIFY_MULTIPASS_SECRET', 'your-multipass-secret-key');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'shopify_commission');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Email Configuration
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', '587');
define('SMTP_USERNAME', 'your-smtp-username');
define('SMTP_PASSWORD', 'your-smtp-password');
define('SMTP_FROM_EMAIL', 'noreply@yourdomain.com');
define('SMTP_FROM_NAME', 'Your Company Name');

// Application Settings
define('APP_URL', 'http://localhost/shopify-agent-module');
define('ADMIN_EMAIL', 'admin@yourdomain.com');

// Debug Mode (Set to false in production)
define('DEBUG_MODE', true);

// Timezone Setting
date_default_timezone_set('Asia/Kolkata');
