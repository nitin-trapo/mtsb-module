<?php
/**
 * Mail Configuration Example File
 * Copy this file to mail.php and update with your actual settings
 */

// Email Server Configuration
define('MAIL_MAILER', 'smtp');                      // Options: smtp, sendmail, mail
define('MAIL_HOST', 'smtp.example.com');            // SMTP Host
define('MAIL_PORT', 587);                           // SMTP Port (587 for TLS, 465 for SSL)
define('MAIL_USERNAME', 'your-email@example.com');  // SMTP Username
define('MAIL_PASSWORD', 'your-smtp-password');      // SMTP Password
define('MAIL_ENCRYPTION', 'tls');                   // Options: tls, ssl, null
define('MAIL_FROM_ADDRESS', 'noreply@example.com'); // Default from address
define('MAIL_FROM_NAME', 'Your Company Name');      // Default from name

// Email Templates Configuration
define('MAIL_TEMPLATE_PATH', __DIR__ . '/../templates/emails/');

// Email Settings
define('MAIL_DEBUG', false);                        // Enable debug mode for troubleshooting
define('MAIL_CHARSET', 'UTF-8');                    // Email character set
define('MAIL_TIMEOUT', 30);                         // Connection timeout in seconds

// Notification Settings
define('MAIL_ADMIN_ADDRESS', 'admin@example.com');  // Admin notification email
define('MAIL_ERROR_ADDRESS', 'errors@example.com'); // Error notification email

// Rate Limiting
define('MAIL_RATE_LIMIT', 100);                     // Maximum emails per hour
define('MAIL_BATCH_SIZE', 50);                      // Maximum emails per batch

// Custom Headers
define('MAIL_CUSTOM_HEADERS', [
    'X-Application-Name' => 'Shopify Agent Module',
    'X-Auto-Response-Suppress' => 'All'
]);
