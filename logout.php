<?php
session_start();

// Clear all session variables
$_SESSION = [];

// Delete the session cookie itself (if your app uses cookie-based sessions)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session data on the server
session_destroy();

// Redirect to login (adjust path if login.php lives elsewhere)
header("Location: login.php");
exit;