<?php
if (headers_sent($filename, $linenum)) {
    write_log("Headers already sent in $filename on line $linenum", 'ERROR');
}

// Start output buffering
ob_start();
session_start();
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
    $date = date('Y-m-d H:i:s');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    write_log("Login attempt for email: " . $email);

    $db = new Database();
    $conn = $db->getConnection();

    try {
        // First check if user exists
        $query = "SELECT * FROM " . TABLE_USERS . " WHERE email = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // New user - create account
            write_log("Creating new user account for: " . $email);
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Insert new user with reset token
            $insert_query = "INSERT INTO " . TABLE_USERS . " 
                           (email, role, status, password_status, reset_token, reset_token_expiry, created_at) 
                           VALUES (?, 'agent', 'active', 'unset', ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->execute([$email, $token, $token_expiry]);
            
            $userId = $conn->lastInsertId();
            write_log("Created new user with ID: " . $userId);
            
            // Send password setup email
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
                $mail->Subject = "Set Up Your Agent Account Password";
                
                // HTML Message
                $reset_link = BASE_URL . "/agent/reset-password.php?token=" . $token . "&email=" . urlencode($email);
                $htmlMessage = "
                <html>
                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <h2>Welcome to our Agent Portal!</h2>
                    <p>Hello,</p>
                    <p>Please click the button below to set up your password:</p>
                    <p style='margin: 25px 0;'>
                        <a href='{$reset_link}' 
                           style='background-color: #007bff; 
                                  color: white; 
                                  padding: 10px 20px; 
                                  text-decoration: none; 
                                  border-radius: 5px;
                                  display: inline-block;'>
                            Set Up Password
                        </a>
                    </p>
                    <p>Or copy and paste this link in your browser:</p>
                    <p>{$reset_link}</p>
                    <p>This link will expire in 24 hours.</p>
                    <p>Best regards,<br>Agent Portal Team</p>
                </body>
                </html>";
                
                $mail->Body = $htmlMessage;
                $mail->send();
                
                write_log("Password setup email sent to: " . $email);
                $_SESSION['success_message'] = 'Please check your email to set your password.';
                header('Location: login.php');
                exit();
                
            } catch (Exception $e) {
                write_log("Failed to send password setup email: " . $e->getMessage(), 'ERROR');
                $_SESSION['error_message'] = 'Failed to send password setup email. Please try again later.';
                header('Location: login.php');
                exit();
            }
        } else {
            // Existing user - verify password if status is set
            if ($user['password_status'] === 'set') {
                write_log("Verifying password for user: " . $email);
                
                if (!password_verify($password, $user['password'])) {
                    write_log("Password verification failed for user: " . $email);
                    $_SESSION['error_message'] = 'Invalid email or password.';
                    header('Location: login.php');
                    exit();
                }
                
                // Password verified, proceed with login
                write_log("Login successful for user: " . $email);
                
                // Get customer details from customers table
                $customer_query = "SELECT * FROM " . TABLE_CUSTOMERS . " WHERE email = ? AND is_agent = 1";
                $customer_stmt = $conn->prepare($customer_query);
                $customer_stmt->execute([$email]);
                $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) {
                    // Customer record not found, create one
                    write_log("Creating customer record for agent: " . $email);
                    $insert_customer = "INSERT INTO " . TABLE_CUSTOMERS . " 
                                     (email, first_name, last_name, is_agent, status, created_at) 
                                     VALUES (?, ?, ?, 1, 'active', NOW())";
                    $insert_stmt = $conn->prepare($insert_customer);
                    $insert_stmt->execute([$email, '', '']); // Empty names for now, can be updated in profile
                    
                    // Get the newly created customer record
                    $customer_stmt->execute([$email]);
                    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                write_log("Customer data retrieved: " . print_r($customer, true));
                
                // Set up session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['customer_id'] = $customer['id'];
                
                write_log("Redirecting user to dashboard");
                
                // Debug information
                write_log("Current BASE_URL: " . BASE_URL);
                write_log("Session variables set: " . print_r($_SESSION, true));
                
                // Clear all output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                // Use explicit path for redirection
                $dashboard_path = dirname($_SERVER['PHP_SELF']) . '/dashboard.php';
                write_log("Redirecting to: " . $dashboard_path);
                
                header("Location: " . $dashboard_path, true, 302);
                exit();
            } else {
                // Password not set, send setup email
                write_log("Password not set for existing user: " . $email . ". Sending setup email.");
                
                // Generate new reset token
                $token = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                // Update user with new reset token
                $update_query = "UPDATE " . TABLE_USERS . " 
                               SET reset_token = ?, 
                                   reset_token_expiry = ? 
                               WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->execute([$token, $token_expiry, $user['id']]);
                
                // Send password setup email
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
                    $mail->Subject = "Set Up Your Agent Account Password";
                    
                    $reset_link = BASE_URL . "/agent/reset-password.php?token=" . $token . "&email=" . urlencode($email);
                    $htmlMessage = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <h2>Welcome to our Agent Portal!</h2>
                        <p>Hello,</p>
                        <p>Please click the button below to set up your password:</p>
                        <p style='margin: 25px 0;'>
                            <a href='{$reset_link}' 
                               style='background-color: #007bff; 
                                      color: white; 
                                      padding: 10px 20px; 
                                      text-decoration: none; 
                                      border-radius: 5px;
                                      display: inline-block;'>
                                Set Up Password
                            </a>
                        </p>
                        <p>Or copy and paste this link in your browser:</p>
                        <p>{$reset_link}</p>
                        <p>This link will expire in 24 hours.</p>
                        <p>Best regards,<br>Agent Portal Team</p>
                    </body>
                    </html>";
                    
                    $mail->Body = $htmlMessage;
                    $mail->send();
                    
                    write_log("Password setup email sent to: " . $email);
                    $_SESSION['success_message'] = 'Please check your email to set your password.';
                    header('Location: login.php');
                    exit();
                    
                } catch (Exception $e) {
                    write_log("Failed to send password setup email: " . $e->getMessage(), 'ERROR');
                    $_SESSION['error_message'] = 'Failed to send password setup email. Please try again later.';
                    header('Location: login.php');
                    exit();
                }
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        write_log("Login failed for agent {$email}: " . $error, 'ERROR');
        if (isset($response)) {
            write_log("Last response: " . $response, 'DEBUG');
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
                    <div class="card-body">
                        <div class="logo">
                            <img src="../assets/images/logo.png" alt="Logo">
                        </div>
                        <h2 class="text-center mb-4">Agent Login</h2>

                        <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger">
                            <?php 
                            echo htmlspecialchars($_SESSION['error_message']);
                            unset($_SESSION['error_message']);
                            ?>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success">
                            <?php 
                            echo htmlspecialchars($_SESSION['success_message']);
                            unset($_SESSION['success_message']);
                            ?>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="Enter your email">
                                <div class="invalid-feedback">
                                    Please enter a valid email address.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required 
                                       placeholder="Enter your password">
                                <div class="mt-2">
                                    <a href="forgot-password.php" class="text-decoration-none">Forgot Password?</a>
                                </div>
                                <div class="invalid-feedback">
                                    Please enter your password.
                                </div>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary" id="loginButton">
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    <span class="button-text">Login</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
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
                            const button = document.getElementById('loginButton')
                            const spinner = button.querySelector('.spinner-border')
                            const buttonText = button.querySelector('.button-text')
                            
                            spinner.classList.remove('d-none')
                            buttonText.textContent = 'Logging in...'
                            button.disabled = true
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            const icon = this.querySelector('i');
            icon.classList.toggle('bi-eye');
            icon.classList.toggle('bi-eye-slash');
        });

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
