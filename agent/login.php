<?php
if (headers_sent($filename, $linenum)) {
    write_log("Headers already sent in $filename on line $linenum", 'ERROR');
}

// Start output buffering
ob_start();
session_start();

// Set timezone to India
date_default_timezone_set('Asia/Kolkata');

// Reset session if requested
if (isset($_GET['reset']) || isset($_POST['reset'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';
require_once '../includes/multipass.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Set up error logging
$log_file = __DIR__ . '/../logs/agent_login.log';
if (!file_exists(dirname($log_file))) {
    mkdir(dirname($log_file), 0777, true);
}

function write_log($message, $type = 'INFO') {
    global $log_file;
    $date = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    $log_message = "[{$date}] [{$type}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Load mail configuration
$mail_config = include '../config/mail.php';

if (!$mail_config || !is_array($mail_config)) {
    write_log("Failed to load mail configuration", 'ERROR');
    die("Error: Mail configuration not found");
}

// Redirect if already logged in
if (is_logged_in() && is_agent()) {
    write_log("Already logged in user redirected to dashboard. User ID: " . $_SESSION['user_id']);
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        $error = "Database connection failed";
        write_log("Database connection failed", 'ERROR');
    } else {
        if (isset($_POST['resend_otp']) && isset($_SESSION['login_email'])) {
            $email = $_SESSION['login_email'];
            write_log("Resend OTP request for email: " . $email);
            
            try {
                // Check if user exists and is active agent
                $query = "SELECT * FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND status = 'active' AND is_agent = 1";
                $stmt = $conn->prepare($query);
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Generate new OTP
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    
                    // Set expiry time 5 minutes from now
                    $current_time = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                    $expiry_time = clone $current_time;
                    $expiry_time->modify('+5 minutes');
                    $otp_expiry = $expiry_time->format('Y-m-d H:i:s');
                    
                    write_log("Generated new OTP at: " . $current_time->format('Y-m-d H:i:s'));
                    write_log("OTP will expire at: " . $expiry_time->format('Y-m-d H:i:s'));
                    
                    // First clear any existing OTP
                    $clear_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                  SET otp = NULL, otp_expiry = NULL 
                                  WHERE id = ?";
                    $clear_stmt = $conn->prepare($clear_query);
                    $clear_stmt->execute([$user['id']]);
                    
                    // Hash OTP and store in database
                    $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);
                    $update_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                   SET otp = ?, otp_expiry = ? 
                                   WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->execute([$hashed_otp, $otp_expiry, $user['id']]);
                    
                    // Send new OTP via email
                    $mail = new PHPMailer(true);
                    
                    try {
                        $mail->isSMTP();
                        $mail->Host = $mail_config['host'];
                        $mail->SMTPAuth = true;
                        $mail->Username = $mail_config['username'];
                        $mail->Password = $mail_config['password'];
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = $mail_config['port'];
                        
                        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
                        $mail->addAddress($email);
                        
                        $mail->isHTML(true);
                        $mail->Subject = 'New Login OTP for Agent Portal';
                        $mail->Body = "
                            <html>
                            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                <h2>Agent Portal Login OTP</h2>
                                <p>Hello {$user['first_name']},</p>
                                <p>Your new OTP for login is: <b style='font-size: 24px; color: #007bff;'>{$otp}</b></p>
                                <p>This OTP will expire in 5 minutes.</p>
                                <p>If you didn't request this OTP, please ignore this email.</p>
                                <p>Best regards,<br>Agent Portal Team</p>
                            </body>
                            </html>";
                        
                        $mail->send();
                        $success = "New OTP has been sent to your email address.";
                        write_log("New OTP sent to: " . $email);
                        
                    } catch (Exception $e) {
                        $error = "Failed to send new OTP. Please try again.";
                        write_log("Failed to send new OTP to {$email}: " . $mail->ErrorInfo, 'ERROR');
                    }
                } else {
                    $error = "Invalid email or you don't have agent access.";
                    write_log("Resend OTP failed - Invalid email or not an agent: " . $email, 'ERROR');
                    unset($_SESSION['login_email']);
                }
            } catch (Exception $e) {
                $error = "An error occurred. Please try again.";
                write_log("Resend OTP error for {$email}: " . $e->getMessage(), 'ERROR');
            }
        } elseif (isset($_POST['email'])) {
            $email = filter_var(sanitize_input($_POST['email']), FILTER_VALIDATE_EMAIL);
            if (!$email) {
                $error = "Invalid email format";
                write_log("Invalid email format attempt: " . $_POST['email'], 'WARNING');
            } else {
                write_log("Login attempt for email: " . $email);

                try {
                    // Check if email exists in customers table and verify status and is_agent
                    $query = "SELECT * FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND status = 'active' AND is_agent = 1";
                    $stmt = $conn->prepare($query);
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        // Generate OTP
                        $otp = sprintf("%06d", mt_rand(100000, 999999));
                        
                        // Set expiry time 5 minutes from now
                        $current_time = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                        $expiry_time = clone $current_time;
                        $expiry_time->modify('+5 minutes');
                        $otp_expiry = $expiry_time->format('Y-m-d H:i:s');
                        
                        write_log("Generated new OTP at: " . $current_time->format('Y-m-d H:i:s'));
                        write_log("OTP will expire at: " . $expiry_time->format('Y-m-d H:i:s'));
                        
                        // First clear any existing OTP
                        $clear_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                      SET otp = NULL, otp_expiry = NULL 
                                      WHERE id = ?";
                        $clear_stmt = $conn->prepare($clear_query);
                        $clear_stmt->execute([$user['id']]);
                        
                        // Hash OTP and store in database
                        $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);
                        $update_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                       SET otp = ?, otp_expiry = ? 
                                       WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->execute([$hashed_otp, $otp_expiry, $user['id']]);
                        
                        // Store email in session for verification
                        $_SESSION['login_email'] = $email;
                        
                        // Send OTP via email
                        $mail = new PHPMailer(true);
                        
                        try {
                            // Server settings
                            $mail->isSMTP();
                            $mail->Host = $mail_config['host'];
                            $mail->SMTPAuth = true;
                            $mail->Username = $mail_config['username'];
                            $mail->Password = $mail_config['password'];
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = $mail_config['port'];
                            
                            // Recipients
                            $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
                            $mail->addAddress($email);
                            
                            // Content
                            $mail->isHTML(true);
                            $mail->Subject = 'Login OTP for Agent Portal';
                            $mail->Body = "
                                <html>
                                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                    <h2>Agent Portal Login OTP</h2>
                                    <p>Hello {$user['first_name']},</p>
                                    <p>Your OTP for login is: <b style='font-size: 24px; color: #007bff;'>{$otp}</b></p>
                                    <p>This OTP will expire in 5 minutes.</p>
                                    <p>If you didn't request this OTP, please ignore this email.</p>
                                    <p>Best regards,<br>Agent Portal Team</p>
                                </body>
                                </html>";
                            
                            $mail->send();
                            $success = "OTP has been sent to your email address.";
                            write_log("OTP sent to: " . $email);
                            
                        } catch (Exception $e) {
                            $error = "Failed to send OTP. Please try again.";
                            write_log("Failed to send OTP to {$email}: " . $mail->ErrorInfo, 'ERROR');
                        }
                        
                    } else {
                        $error = "Invalid email or you don't have agent access.";
                        write_log("Login failed - Invalid email or not an agent: " . $email, 'ERROR');
                        sleep(1); // Add delay to prevent brute force
                    }
                    
                } catch (Exception $e) {
                    $error = "An error occurred. Please try again.";
                    write_log("Login error for {$email}: " . $e->getMessage(), 'ERROR');
                }
            }
        } elseif (isset($_POST['otp'])) {
            if (isset($_SESSION['login_email'])) {
                $input_otp = sanitize_input($_POST['otp']);
                $email = $_SESSION['login_email'];

                try {
                    // Get user data with OTP
                    $current_time = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                    $current_timestamp = $current_time->format('Y-m-d H:i:s');
                    write_log("Verifying OTP at time: " . $current_timestamp);
                    
                    // First get the user details
                    $query = "SELECT * FROM " . TABLE_CUSTOMERS . " 
                             WHERE email = ? AND status = 'active' AND is_agent = 1";
                    $stmt = $conn->prepare($query);
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        write_log("Found user: " . $user['email']);
                        
                        if ($user['otp'] === null || $user['otp_expiry'] === null) {
                            $error = "No active OTP found. Please request a new one.";
                            write_log("No active OTP for user: " . $email);
                            unset($_SESSION['login_email']);
                        } else {
                            // Convert expiry time to DateTime object
                            $expiry_time = new DateTime($user['otp_expiry'], new DateTimeZone('Asia/Kolkata'));
                            write_log("OTP expiry time: " . $expiry_time->format('Y-m-d H:i:s'));
                            write_log("Current time: " . $current_time->format('Y-m-d H:i:s'));
                            
                            if ($current_time < $expiry_time) {
                                if (password_verify($input_otp, $user['otp'])) {
                                    // OTP verified, clear it from database
                                    $clear_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                                  SET otp = NULL, otp_expiry = NULL 
                                                  WHERE id = ?";
                                    $clear_stmt = $conn->prepare($clear_query);
                                    $clear_stmt->execute([$user['id']]);
                                    
                                    // Set login session
                                    $_SESSION['user_id'] = $user['id'];
                                    $_SESSION['role'] = 'agent';  // Changed from is_agent to role
                                    $_SESSION['user_email'] = $user['email'];
                                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                                    
                                    // Clear email from session
                                    unset($_SESSION['login_email']);
                                    
                                    write_log("Successful login for: " . $user['email'] . " with role: " . $_SESSION['role']);
                                    
                                    // Clean output buffer
                                    ob_clean();
                                    
                                    // Redirect with absolute path
                                    $dashboard_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/dashboard.php";
                                    write_log("Redirecting to: " . $dashboard_url);
                                    
                                    header("Location: " . $dashboard_url);
                                    exit();
                                } else {
                                    $error = "Invalid OTP. Please try again.";
                                    write_log("Invalid OTP attempt for: " . $email . ". Input OTP: " . $input_otp);
                                }
                            } else {
                                $error = "OTP has expired. Click 'Request New OTP' to get a new code.";
                                write_log("OTP expired. Expiry: " . $expiry_time->format('Y-m-d H:i:s') . 
                                        ", Current: " . $current_time->format('Y-m-d H:i:s'));
                                
                                // Clear expired OTP
                                $clear_query = "UPDATE " . TABLE_CUSTOMERS . " 
                                              SET otp = NULL, otp_expiry = NULL 
                                              WHERE id = ?";
                                $clear_stmt = $conn->prepare($clear_query);
                                $clear_stmt->execute([$user['id']]);
                            }
                        }
                    } else {
                        $error = "Invalid email or you don't have agent access.";
                        write_log("User not found or inactive: " . $email);
                        unset($_SESSION['login_email']);
                    }
                } catch (Exception $e) {
                    $error = "An error occurred. Please try again.";
                    write_log("OTP verification error for {$email}: " . $e->getMessage(), 'ERROR');
                }
            } else {
                $error = "Invalid session. Please start over.";
                write_log("Invalid session during OTP verification", 'ERROR');
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Agent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin: 20px 0;
        }
        .card-body {
            padding: 40px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo img {
            max-width: 180px;
            height: auto;
        }
        h2 {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-label {
            font-weight: 500;
            color: #555;
            margin-bottom: 8px;
        }
        .form-control {
            height: 48px;
            padding: 10px 16px;
            font-size: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            background-color: #fff;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25);
        }
        .input-group .btn {
            padding: 10px 16px;
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
            border: 1px solid #ddd;
            border-left: none;
        }
        .input-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .btn-primary {
            height: 48px;
            padding: 10px 20px;
            font-size: 16px;
            font-weight: 500;
            background-color: #0d6efd;
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            transform: translateY(-1px);
        }
        .alert {
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 25px;
            font-size: 15px;
            border: none;
        }
        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
        }
        .invalid-feedback {
            font-size: 13px;
            color: #dc3545;
            margin-top: 6px;
        }
        .mb-3 {
            margin-bottom: 20px;
        }
        .mb-4 {
            margin-bottom: 25px;
        }
        @media (max-width: 576px) {
            .card-body {
                padding: 25px;
            }
            .col-md-4 {
                padding: 0 10px;
            }
        }
        .spinner-border-sm {
            margin-right: 8px;
            width: 1rem;
            height: 1rem;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h4 class="card-title text-center mb-4">Agent Login</h4>
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                            <?php if (isset($_SESSION['login_email'])): ?>
                            <form method="post">
                                <div class="d-grid">
                                    <button type="submit" name="reset" class="btn btn-link">Back to Email Entry</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['login_email'])): ?>
                            <!-- OTP verification form -->
                            <form method="post" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label for="otp" class="form-label">Enter OTP</label>
                                    <input type="text" class="form-control" id="otp" name="otp" required pattern="[0-9]{6}" maxlength="6" autocomplete="off">
                                    <div class="invalid-feedback">Please enter the 6-digit OTP.</div>
                                    <small class="form-text text-muted">OTP has been sent to <?php echo htmlspecialchars($_SESSION['login_email']); ?></small>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Verify OTP</button>
                                    <button type="submit" name="resend_otp" class="btn btn-outline-primary">Request New OTP</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Email form -->
                            <form method="post" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="email" name="email" required autocomplete="email">
                                    <div class="invalid-feedback">Please enter a valid email address.</div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">Send OTP</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (isset($_SESSION['login_email'])): ?>
                <div class="mt-3 text-center">
                    <form method="post">
                        <button type="submit" name="reset" class="btn btn-link">Use Different Email</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        } else {
                            // Show loading state
                            const button = document.querySelector('button[type="submit"]');
                            const spinner = button.querySelector('.spinner-border');
                            const buttonText = button.querySelector('.button-text');
                            
                            spinner.classList.remove('d-none');
                            buttonText.textContent = 'Logging in...';
                            button.disabled = true;
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });

        // Prevent double form submission
        const form = document.querySelector('form');
        form.addEventListener('submit', function() {
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
        });
    </script>
</body>
</html>
