<?php
// Include this at the top of every protected page.
// Usage: require_once '../auth_check.php'; require_role('staff');   // or 'customer'

define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes of inactivity

function require_role($role) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Log out and clear the session if it's been idle too long
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== $role) {
        header('Location: /login.php');
        exit;
    }
}
