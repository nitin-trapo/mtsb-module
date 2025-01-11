<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php-error.log');

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Initialize database connection
$db = new Database();
$pdo = $db->getConnection();

// Fetch current user details
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: login.php');
        exit();
    }
} catch (PDOException $e) {
    $error_message = "Error fetching user details";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $error_message = "Name and email are required";
        } else {
            try {
                // Check if email already exists for other users
                $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check_stmt->execute([$email, $user_id]);
                if ($check_stmt->rowCount() > 0) {
                    $error_message = "Email already exists";
                } else {
                    $update_stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $update_stmt->execute([$name, $email, $user_id]);
                    $success_message = "Profile updated successfully";
                    
                    // Update session data
                    $_SESSION['name'] = $name;
                    
                    // Refresh user details
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                }
            } catch (PDOException $e) {
                $error_message = "An error occurred while updating profile";
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All password fields are required";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match";
        } else {
            try {
                if (verify_password($current_password, $user['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->execute([$hashed_password, $user_id]);
                    $success_message = "Password updated successfully";
                } else {
                    $error_message = "Current password is incorrect";
                }
            } catch (PDOException $e) {
                $error_message = "An error occurred while updating password";
            }
        }
    } elseif (isset($_POST['clear_database'])) {
        $result = clear_database();
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    } elseif (isset($_POST['clear_logs'])) {
        $result = clear_logs();
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    }
}

// Set page title
$page_title = 'Admin Profile';

// Include header
include 'includes/header.php';
?>

<!-- Main content -->
<div class="main-content">
    
    <div class="content">
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Profile Settings</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($success_message): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                            <?php endif; ?>
                            
                            <?php if ($error_message): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h6 class="mb-0">Edit Profile</h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST" action="">
                                                <div class="mb-3">
                                                    <label for="name" class="form-label">Name</label>
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="email" class="form-label">Email</label>
                                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                                </div>
                                                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Change Password</h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST" action="">
                                                <div class="mb-3">
                                                    <label for="current_password" class="form-label">Current Password</label>
                                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="new_password" class="form-label">New Password</label>
                                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                </div>
                                                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Settings Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-subtitle mb-3">Database Management</h6>
                                            <p class="text-muted">Clear all data except user accounts. This action cannot be undone.</p>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to clear the database? This action cannot be undone.');">
                                                <button type="submit" name="clear_database" class="btn btn-danger">
                                                    <i class="fas fa-database me-2"></i>Clear Database
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-subtitle mb-3">Log Management</h6>
                                            <p class="text-muted">Clear all log files. This action cannot be undone.</p>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to clear all log files? This action cannot be undone.');">
                                                <button type="submit" name="clear_logs" class="btn btn-warning">
                                                    <i class="fas fa-file-alt me-2"></i>Clear Logs
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
