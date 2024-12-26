<?php
session_start();
require_once 'config/database.php';
require_once 'config/shopify_config.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (is_logged_in()) {
    if (is_admin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: agent/dashboard.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Commission Module</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body">
                        <h2 class="text-center mb-4">Shopify Commission Module</h2>
                        <div class="d-grid gap-3">
                            <a href="admin/login.php" class="btn btn-primary btn-lg">
                                Admin Login
                            </a>
                            <a href="agent/login.php" class="btn btn-secondary btn-lg">
                                Agent Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Custom JS -->
    <script src="assets/js/main.js"></script>
</body>
</html>
