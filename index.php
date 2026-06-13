<?php
/**
 * Entry Point - Mini ERP System
 * Redirects to login or dashboard based on auth state.
 */

require_once __DIR__ . '/config/app.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: " . BASE_URL . "/dashboard/index.php");
} else {
    header("Location: " . BASE_URL . "/auth/login.php");
}
exit;
?>
