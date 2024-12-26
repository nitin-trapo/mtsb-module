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
?>
