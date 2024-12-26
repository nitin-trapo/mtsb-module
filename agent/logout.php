<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Clear any other cookies that might be set
$cookies = array_keys($_COOKIE);
$path = dirname($_SERVER['PHP_SELF']);
foreach ($cookies as $cookie) {
    setcookie($cookie, '', time() - 3600, $path);
}

// Destroy the session
session_destroy();

// Set a logout message in a temporary cookie
setcookie('logout_message', 'You have been successfully logged out.', time() + 5, '/');

// Redirect to login page
header('Location: login.php');
exit();
?>
