<?php
/**
 * Aqua-Vision Logout
 * Location: logout.php
 */

session_start();

// Log logout activity if user was logged in
if (isset($_SESSION['user_id'])) {
    require_once 'database/config.php';
    log_activity('logout', "User {$_SESSION['username']} logged out");
    $conn->close();
}

// Destroy session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
header('Location: login.php');
exit();
?>
