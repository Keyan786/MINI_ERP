<?php
/**
 * Logout Handler - Mini ERP System
 * Destroys session, deactivates session record, and logs the action.
 */

ob_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';

if (isset($_SESSION['user_id'])) {
    $userId   = $_SESSION['user_id'];

    // Deactivate session record in database
    $sessionId = session_id();
    $stmt = $conn->prepare("UPDATE tbl_user_sessions SET is_active = 0 WHERE session_id = ?");
    $stmt->bind_param("s", $sessionId);
    $stmt->execute();
    $stmt->close();

    // Audit log
    log_action($conn, 'Authentication', ACTION_LOGOUT, 'User', $userId);
}

// Expire the session cookie in the browser so it is immediately removed
$params = session_get_cookie_params();
setcookie(
    session_name(),
    '',
    time() - 42000,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
);

// Clear session data and destroy the session file
session_unset();
session_destroy();

// Start a fresh session under the correct name for the flash message
session_name(SESSION_NAME);
session_start();
set_flash('info', 'You have been logged out successfully.');

ob_end_clean();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
?>
