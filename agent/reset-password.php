<?php
session_start();
require_once '../config/database.php';
require_once '../config/shopify_config.php';
require_once '../config/tables.php';
require_once '../includes/functions.php';

// Set up error logging
$log_file = __DIR__ . '/../logs/password_reset.log';
if (!file_exists(dirname($log_file))) {
    mkdir(dirname($log_file), 0777, true);
}

function write_log($message, $type = 'INFO') {
    global $log_file;
    $date = date('Y-m-d H:i:s');
    $log_message = "[{$date}] [{$type}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

$error = '';
$success = '';

// Get token and email from URL for GET request
$token = sanitize_input($_GET['token'] ?? '');
$email = sanitize_input($_GET['email'] ?? '');

// Verify token validity on page load
if ($token && $email) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $query = "SELECT * FROM " . TABLE_USERS . " WHERE email = ? AND reset_token = ? AND reset_token_expiry > NOW()";
        $stmt = $conn->prepare($query);
        $stmt->execute([$email, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            write_log("Invalid or expired token for email: " . $email, 'ERROR');
            $error = "Invalid or expired reset token. Please request a new password reset.";
            // Redirect to login page after 3 seconds
            header("refresh:3;url=login.php");
        }
    } catch (PDOException $e) {
        write_log("Database error: " . $e->getMessage(), 'ERROR');
        $error = "An error occurred. Please try again later.";
        header("refresh:3;url=login.php");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = sanitize_input($_POST['token']);
        $email = sanitize_input($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        write_log("Password reset attempt for email: " . $email);

        if (empty($token) || empty($email) || empty($password) || empty($confirm_password)) {
            throw new Exception('All fields are required.');
        }

        if ($password !== $confirm_password) {
            throw new Exception('Passwords do not match.');
        }

        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long.');
        }

        $db = new Database();
        $conn = $db->getConnection();

        // Verify token again
        $query = "SELECT * FROM " . TABLE_USERS . " WHERE email = ? AND reset_token = ? AND reset_token_expiry > NOW()";
        $stmt = $conn->prepare($query);
        $stmt->execute([$email, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('Invalid or expired reset token. Please request a new password reset.');
        }

        // Update password and clear reset token
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $update_query = "UPDATE " . TABLE_USERS . " 
                       SET password = ?, 
                           reset_token = NULL, 
                           reset_token_expiry = NULL, 
                           password_status = 'set',
                           updated_at = NOW() 
                       WHERE email = ? AND reset_token = ?";
        
        $update_stmt = $conn->prepare($update_query);
        
        if ($update_stmt->execute([$hashed_password, $email, $token])) {
            write_log("Password reset successful for user: " . $email);
            $success = "Your password has been successfully set. You can now login with your new password.";
            // Redirect to login page after 3 seconds
            header("refresh:3;url=login.php");
        } else {
            throw new Exception('Failed to reset password. Please try again.');
        }

    } catch (PDOException $e) {
        write_log("Database error: " . $e->getMessage(), 'ERROR');
        $error = "An error occurred. Please try again later.";
    } catch (Exception $e) {
        $error = $e->getMessage();
        write_log("Password reset failed: " . $error, 'ERROR');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password - Agent Portal</title>
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
        .back-to-login {
            text-align: center;
            margin-top: 20px;
        }
        .back-to-login a {
            color: #0d6efd;
            text-decoration: none;
            font-weight: 500;
        }
        .back-to-login a:hover {
            text-decoration: underline;
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
                        <h2>Set Password</h2>
                        
                        <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($success); ?>
                            <div class="text-center mt-3">
                                <a href="login.php" class="btn btn-primary">Go to Login</a>
                            </div>
                        </div>
                        <?php else: ?>
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required 
                                           minlength="8" placeholder="Enter your new password">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">
                                    Password must be at least 8 characters long.
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           required placeholder="Confirm your new password">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">
                                    Please confirm your password.
                                </div>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary">Set Password</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if (!$success): ?>
                        <div class="back-to-login">
                            <a href="login.php">Back to Login</a>
                        </div>
                        <?php endif; ?>
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
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

        // Password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            if (this.value !== document.getElementById('password').value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Toggle password visibility
        function togglePasswordVisibility(inputId, buttonId) {
            const input = document.getElementById(inputId);
            const button = document.getElementById(buttonId);
            
            button.addEventListener('click', function() {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                const icon = button.querySelector('i');
                icon.classList.toggle('bi-eye');
                icon.classList.toggle('bi-eye-slash');
            });
        }

        togglePasswordVisibility('password', 'togglePassword');
        togglePasswordVisibility('confirm_password', 'toggleConfirmPassword');
    </script>
</body>
</html>
