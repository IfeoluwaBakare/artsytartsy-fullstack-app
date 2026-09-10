<?php
// Include this at the top of every protected page.
// Usage: require_once '../auth_check.php'; require_role('staff');   // or 'customer'

function require_role($role) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== $role) {
        header('Location: /login.php');
        exit;
    }
}
