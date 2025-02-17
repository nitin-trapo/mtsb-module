<?php
session_start();
require_once '../includes/functions.php';

// Check if user is logged in and is agent
if (is_logged_in() && is_agent()) {
    // User is logged in and is an agent, redirect to dashboard
    header('Location: dashboard.php');
    exit;
} else {
    // User is not logged in or is not an agent, redirect to login
    header('Location: login.php');
    exit;
}
