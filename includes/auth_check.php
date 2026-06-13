<?php
/**
 * Authentication Middleware - Mini ERP System
 * Include this file at the top of every protected page.
 * Redirects to login if the user is not authenticated.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/permission_check.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_flash('error', 'Please log in to access this page.');
    redirect('/auth/login.php');
}

// Verify user still exists and is active in the database
$stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.email, u.status, u.role_id, r.role_name 
                         FROM tbl_users u 
                         LEFT JOIN tbl_roles r ON u.role_id = r.role_id 
                         WHERE u.user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentUser || $currentUser['status'] !== STATUS_ACTIVE) {
    // User has been deactivated or deleted since login
    session_unset();
    session_destroy();
    session_start();
    set_flash('error', 'Your account is no longer active. Please contact the administrator.');
    redirect('/auth/login.php');
}

// Update session with current data
$_SESSION['user_name'] = $currentUser['full_name'];
$_SESSION['user_email'] = $currentUser['email'];
$_SESSION['role_id'] = $currentUser['role_id'];
$_SESSION['role_name'] = $currentUser['role_name'];

// Load user permissions into session (refresh periodically)
if (!isset($_SESSION['permissions_loaded']) || (time() - ($_SESSION['permissions_loaded'] ?? 0)) > 300) {
    $_SESSION['permissions'] = load_user_permissions($conn, $currentUser['role_id']);
    $_SESSION['permissions_loaded'] = time();
}
?>
