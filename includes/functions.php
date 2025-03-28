<?php
// Security Functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generate_password_hash($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Session Management
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_agent() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'agent';
}

// Format Functions
function format_currency($amount, $currency = null) {
    // Get currency from order if provided, otherwise use store default
    $currency = $currency ?? 'INR';
    
    // Format based on currency
    switch ($currency) {
        case 'INR':
            return '₹' . number_format($amount, 2, '.', ',');
        case 'USD':
            return '$' . number_format($amount, 2, '.', ',');
        case 'EUR':
            return '€' . number_format($amount, 2, '.', ',');
        case 'GBP':
            return '£' . number_format($amount, 2, '.', ',');
        case 'MYR':
            return 'RM ' . number_format($amount, 2, '.', ',');
        default:
            return number_format($amount, 2, '.', ',') . ' ' . $currency;
    }
}

function format_date($date) {
    return date('Y-m-d H:i:s', strtotime($date));
}

// Validation Functions
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Response Functions
function json_response($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Notification Functions
function send_email_notification($to, $subject, $message) {
    // Add your email sending logic here
    $headers = 'From: ' . ADMIN_EMAIL . "\r\n" .
        'Reply-To: ' . ADMIN_EMAIL . "\r\n" .
        'X-Mailer: PHP/' . phpversion();
    
    return mail($to, $subject, $message, $headers);
}

// Status Color Mapping Functions
function get_financial_status_color($status) {
    switch (strtolower($status)) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'refunded':
            return 'info';
        case 'voided':
            return 'secondary';
        default:
            return 'light';
    }
}

function get_fulfillment_status_color($status) {
    switch (strtolower($status)) {
        case 'fulfilled':
            return 'success';
        case 'partial':
            return 'info';
        case 'unfulfilled':
            return 'danger'; 
        case 'restocked':
            return 'primary'; 
        case 'cancelled':
            return 'secondary'; 
        default:
            return 'light';
    }
}

function get_status_color($status) {
    // If status is null or empty, return a default color
    if (empty($status)) {
        return 'primary';
    }
    
    switch (strtolower($status)) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'refunded':
            return 'danger';
        case 'cancelled':
            return 'danger';
        case 'fulfilled':
            return 'success';
        case 'unfulfilled':
            return 'warning';
        default:
            return 'primary';
    }
}

// Database and Log Management Functions
function clear_database() {
    try {
        $db = new Database();
        $pdo = $db->getConnection();
        
        // Temporarily disable foreign key checks
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        
        // List of tables to clear (excluding users table)
        $tables = [
            'invoices',          // Clear child tables first
            'order_items',
            'product_metadata',
            'product_tags',
            'product_variants',
            'commissions',
            'commission_rules',
            'customers',
            'email_logs',
            'orders',
            'products',
            'product_types',
            'sync_logs'
        ];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("TRUNCATE TABLE $table");
                $stmt->execute();
            } catch (PDOException $e) {
                // Log the error but continue with other tables
                error_log("Error truncating table $table: " . $e->getMessage());
            }
        }
        
        // Re-enable foreign key checks
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        
        return ['success' => true, 'message' => 'Database cleared successfully'];
    } catch (PDOException $e) {
        // Re-enable foreign key checks in case of error
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (PDOException $ex) {
            // Ignore any errors while re-enabling foreign key checks
        }
        return ['success' => false, 'message' => 'Error clearing database: ' . $e->getMessage()];
    }
}

function clear_logs() {
    try {
        $log_dir = __DIR__ . '/../logs/';
        $log_files = glob($log_dir . '*.log');
        $deleted_count = 0;
        
        foreach ($log_files as $file) {
            if (is_file($file)) {
                // Instead of deleting, we'll clear the content
                file_put_contents($file, '');
                $deleted_count++;
            }
        }
        
        return ['success' => true, 'message' => "Cleared $deleted_count log files successfully"];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error clearing logs: ' . $e->getMessage()];
    }
}
?>
