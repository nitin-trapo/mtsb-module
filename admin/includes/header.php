<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

// Get current page name
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Check page permission for the current user
$stmt = $conn->prepare("SELECT has_access FROM user_permissions WHERE user_id = ? AND page_name = ?");
$stmt->execute([$_SESSION['user_id'], $current_page]);
$permission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$permission || !$permission['has_access']) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Get all user permissions for menu display
$menu_stmt = $conn->prepare("SELECT page_name, has_access FROM user_permissions WHERE user_id = ?");
$menu_stmt->execute([$_SESSION['user_id']]);
$user_permissions = [];
while ($row = $menu_stmt->fetch(PDO::FETCH_ASSOC)) {
    $user_permissions[$row['page_name']] = $row['has_access'];
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/shopify-agent-module');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Shopify Commission Module</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <!-- Custom CSS -->
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Custom JS -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    
    <!-- Toastr CSS and JS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/css/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/js/toastr.min.js"></script>
    <script>
        toastr.options = {
            "closeButton": true,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "timeOut": "5000"
        };
    </script>
</head>
<body>

<!-- Main wrapper -->
<div class="wrapper">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>/admin/dashboard.php">
                TRAPO
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/dashboard.php">Dashboard</a>
                    </li>
                    
                    <?php if (isset($user_permissions['agents']) && $user_permissions['agents']): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'agents' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/agents.php">Agents</a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (isset($user_permissions['orders']) && $user_permissions['orders']): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'orders' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/orders.php">Orders</a>
                    </li>
                    <?php endif; ?>

                    <?php if (isset($user_permissions['sales_report']) && $user_permissions['sales_report']): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'sales_report' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/sales_report.php">
                           Sales Report
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (isset($user_permissions['commissions']) && $user_permissions['commissions']): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['commissions', 'bulk_commissions', 'rules']) ? 'active' : ''; ?>" 
                           href="#" 
                           id="commissionsDropdown" 
                           role="button" 
                           data-bs-toggle="dropdown" 
                           aria-expanded="false">
                            Commissions
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="commissionsDropdown">
                            <?php if (isset($user_permissions['commissions']) && $user_permissions['commissions']): ?>
                            <li>
                                <a class="dropdown-item <?php echo $current_page === 'commissions' ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>/admin/commissions.php">
                                    Commissions List
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (isset($user_permissions['bulk_commissions']) && $user_permissions['bulk_commissions']): ?>
                            <li>
                                <a class="dropdown-item <?php echo $current_page === 'bulk_commissions' ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>/admin/bulk_commissions.php">
                                    Bulk Commissions
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (isset($user_permissions['rules']) && $user_permissions['rules']): ?>
                            <li>
                                <a class="dropdown-item <?php echo $current_page === 'rules' ? 'active' : ''; ?>" 
                                   href="<?php echo BASE_URL; ?>/admin/rules.php">
                                    Commission Rules
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (isset($user_permissions['users']) && $user_permissions['users']): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'users' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/users.php">Users</a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (isset($user_permissions['sync']) && $user_permissions['sync']): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'sync' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/admin/sync.php">Sync Data</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <?php echo isset($_SESSION['name']) ? $_SESSION['name'] : 'User'; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/profile.php">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Content wrapper -->
    <div class="content-wrapper">
        <!-- Main content -->
        <div class="main-content">
