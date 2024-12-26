<?php
session_start();
require_once '../config/database.php';
require_once '../config/tables.php';
require_once '../config/shopify_config.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_message'] = 'Please enter a valid email address.';
        header('Location: forgot-password.php');
        exit();
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM " . TABLE_USERS . " WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update user with reset token
        $update_stmt = $conn->prepare("
            UPDATE " . TABLE_USERS . " 
            SET reset_token = ?, reset_token_expiry = ? 
            WHERE email = ?
        ");
        $update_stmt->execute([$token, $token_expiry, $email]);
        
        // Load mail configuration
        $mail_config = require '../config/mail.php';
        
        // Send password reset email
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = $mail_config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mail_config['username'];
            $mail->Password = $mail_config['password'];
            $mail->SMTPSecure = $mail_config['encryption'];
            $mail->Port = $mail_config['port'];
            
            // Recipients
            $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
            $mail->addAddress($email);
            
            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = "Reset Your Password";
            
            $reset_link = BASE_URL . "/agent/reset-password.php?token=" . $token . "&email=" . urlencode($email);
            
            // HTML Message
            $htmlMessage = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2>Password Reset Request</h2>
                <p>Hello,</p>
                <p>We received a request to reset your password. Click the button below to reset it:</p>
                <p style='margin: 25px 0;'>
                    <a href='{$reset_link}' 
                       style='background-color: #007bff; 
                              color: white; 
                              padding: 10px 20px; 
                              text-decoration: none; 
                              border-radius: 5px;
                              display: inline-block;'>
                        Reset Password
                    </a>
                </p>
                <p>Or copy and paste this link in your browser:</p>
                <p>{$reset_link}</p>
                <p>This link will expire in 24 hours.</p>
                <p>If you didn't request this, please ignore this email.</p>
                <p>Best regards,<br>Agent Portal Team</p>
            </body>
            </html>";
            
            $mail->Body = $htmlMessage;
            $mail->AltBody = "Reset your password by clicking this link: {$reset_link}";
            
            $mail->send();
            
            $_SESSION['success_message'] = 'Password reset instructions have been sent to your email.';
            header('Location: login.php');
            exit();
            
        } catch (Exception $e) {
            error_log("Mail Error: " . $e->getMessage());
            $_SESSION['error_message'] = 'Failed to send password reset email. Please try again later.';
            header('Location: forgot-password.php');
            exit();
        }
    } else {
        // For security, show the same message even if user doesn't exist
        $_SESSION['success_message'] = 'If your email exists in our system, you will receive password reset instructions.';
        header('Location: login.php');
        exit();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Agent Portal</title>
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
            max-width: 200px;
            height: auto;
        }
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid #ced4da;
        }
        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(13,110,253,.15);
        }
        .btn-primary {
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 500;
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
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
                        <h2 class="text-center mb-4">Forgot Password</h2>

                        <?php if (isset($_SESSION['error_message'])): ?>
                            <div class="alert alert-danger">
                                <?php 
                                echo htmlspecialchars($_SESSION['error_message']);
                                unset($_SESSION['error_message']);
                                ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" required 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="Enter your registered email">
                                <div class="invalid-feedback">
                                    Please enter a valid email address.
                                </div>
                                <div class="form-text">
                                    Enter your registered email address to receive password reset instructions.
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="resetButton">
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    <span class="button-text">Send Reset Link</span>
                                </button>
                                <a href="login.php" class="btn btn-link">Back to Login</a>
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
                            const button = document.getElementById('resetButton')
                            const spinner = button.querySelector('.spinner-border')
                            const buttonText = button.querySelector('.button-text')
                            spinner.classList.remove('d-none')
                            buttonText.textContent = 'Sending...'
                            button.disabled = true
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
</body>
</html>
