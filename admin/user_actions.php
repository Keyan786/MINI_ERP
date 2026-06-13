<?php
/**
 * User Actions Controller - Mini ERP System
 * Handles POST requests for administrative user management actions.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/mail_helpers.php';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

if (!is_post()) {
    redirect('/admin/users.php');
}

if (!csrf_validate()) {
    set_flash('error', 'Invalid security token.');
    redirect('/admin/users.php');
}

$action = $_POST['action'] ?? '';
$targetUserId = intval($_POST['target_user_id'] ?? 0);

if ($targetUserId <= 0) {
    set_flash('error', 'Invalid user ID.');
    redirect('/admin/users.php');
}

// Fetch user
$stmt = $conn->prepare("SELECT user_id, full_name, email, status, is_password_set, force_password_change FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $targetUserId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('/admin/users.php');
}

// Cannot modify self through these actions (except maybe force password, but generally avoided)
if ($user['user_id'] === $_SESSION['user_id'] && $action !== 'force_password_change') {
    set_flash('error', 'You cannot perform this action on your own account.');
    redirect('/admin/users.php');
}

function send_auth_email($email, $name, $token, $isReset = false) {
    $script = $isReset ? 'setup_password.php' : 'setup_password.php';
    $url = "http://" . $_SERVER['HTTP_HOST'] . BASE_URL . "/auth/" . $script . "?token=" . $token;
    
    $subject = $isReset ? "Mini ERP - Password Reset Request" : "Welcome to Mini ERP - Set up your account";
    $message = "Hello " . $name . ",\n\n";
    
    if ($isReset) {
        $message .= "An administrator has requested a password reset for your account.\n\n";
        $message .= "Please click the link below to set up a new password:\n";
    } else {
        $message .= "An account has been created for you on Mini ERP.\n\n";
        $message .= "Please click the link below to set up your password and access your account:\n";
    }
    
    $message .= $url . "\n\n";
    $message .= "This link will expire in 24 hours.\n\n";
    $message .= "Best regards,\nMini ERP Administrator";

    send_mail([$email => $name], $subject, $message);
}

// ─── Route Actions ─────────────────────────────────────────────────────────

switch ($action) {
    case 'resend_invite':
        if ($user['status'] !== 'pending_setup') {
            set_flash('error', 'Invitations can only be resent to users pending password setup.');
            break;
        }
        $token = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', time() + 86400);

        $stmt = $conn->prepare("UPDATE tbl_users SET password_setup_token = ?, token_expiry = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $token, $tokenExpiry, $user['user_id']);
        if ($stmt->execute()) {
            send_auth_email($user['email'], $user['full_name'], $token, false);
            log_action($conn, 'User Management', ACTION_INVITE_RESENT, 'User', $user['user_id']);
            set_flash('success', 'Invitation email has been resent to ' . e($user['full_name']) . '.');
        }
        $stmt->close();
        break;

    case 'reset_password':
        $token = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', time() + 86400);

        $stmt = $conn->prepare("UPDATE tbl_users SET password_setup_token = ?, token_expiry = ?, is_password_set = 0 WHERE user_id = ?");
        $stmt->bind_param("ssi", $token, $tokenExpiry, $user['user_id']);
        if ($stmt->execute()) {
            send_auth_email($user['email'], $user['full_name'], $token, true);
            log_action($conn, 'User Management', ACTION_PASSWORD_RESET_REQ, 'User', $user['user_id']);
            set_flash('success', 'Password reset email sent to ' . e($user['full_name']) . '.');
        }
        $stmt->close();
        break;

    case 'admin_change_password':
        $newPassword = $_POST['new_password'] ?? '';
        if (empty($newPassword) || strlen($newPassword) < 6 || strlen($newPassword) > 72) {
            set_flash('error', 'Password must be between 6 and 72 characters.');
            break;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $now = date('Y-m-d H:i:s');
        $newStatus = ($user['status'] === 'pending_setup') ? 'active' : $user['status'];

        $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, is_password_set = 1, force_password_change = 0, password_changed_at = ?, password_setup_token = NULL, token_expiry = NULL, status = ? WHERE user_id = ?");
        $stmt->bind_param("sssi", $passwordHash, $now, $newStatus, $user['user_id']);
        
        if ($stmt->execute()) {
            log_action($conn, 'User Management', ACTION_PASSWORD_CHANGED, 'Admin', $_SESSION['user_id'], null, ['target_user' => $user['user_id']]);
            if ($user['status'] === 'pending_setup') {
                log_action($conn, 'User Management', ACTION_USER_ACTIVATED, 'Admin', $_SESSION['user_id'], null, ['target_user' => $user['user_id']]);
            }
            set_flash('success', 'Password successfully updated for ' . e($user['full_name']) . '.');
        }
        $stmt->close();
        break;

    case 'change_status':
        $newStatus = $_POST['new_status'] ?? '';
        $validStatuses = ['active', 'inactive', 'locked'];
        
        if (!in_array($newStatus, $validStatuses)) {
            set_flash('error', 'Invalid status provided.');
            break;
        }
        
        if ($user['status'] === $newStatus) {
            set_flash('info', 'User is already in this status.');
            break;
        }

        $stmt = $conn->prepare("UPDATE tbl_users SET status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $newStatus, $user['user_id']);
        if ($stmt->execute()) {
            $auditAction = ACTION_UPDATE;
            if ($newStatus === 'active') $auditAction = ACTION_USER_ACTIVATED;
            if ($newStatus === 'inactive') $auditAction = ACTION_USER_DEACTIVATED;
            if ($newStatus === 'locked') $auditAction = ACTION_USER_LOCKED;
            
            log_action($conn, 'User Management', $auditAction, 'User', $user['user_id'], ['status' => $user['status']], ['status' => $newStatus]);
            set_flash('success', 'Status for ' . e($user['full_name']) . ' updated to ' . ucfirst($newStatus) . '.');
        }
        $stmt->close();
        break;

    default:
        set_flash('error', 'Unknown action.');
        break;
}

// Redirect back with existing status filter if present
$redirectUrl = '/admin/users.php';
if (!empty($_POST['return_status'])) {
    $redirectUrl .= '?status=' . urlencode($_POST['return_status']);
}
redirect($redirectUrl);
