<?php
/**
 * Application Configuration - Mini ERP System
 * Central configuration file for constants, session settings, and app-wide settings.
 */

// ─── Application Settings ───────────────────────────────────────────────────
define('APP_NAME', 'Mini ERP');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/MiniERP');

// ─── Session Configuration ──────────────────────────────────────────────────
define('SESSION_LIFETIME', 1800);       // 30 minutes in seconds
define('SESSION_NAME', 'MINIERP_SID');

// ─── Security Settings ─────────────────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);        // 15 minutes in seconds
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', 'csrf_token');

// ─── User Status Constants ──────────────────────────────────────────────────
define('STATUS_PENDING', 'pending');
define('STATUS_ACTIVE', 'active');
define('STATUS_REJECTED', 'rejected');
define('STATUS_SUSPENDED', 'suspended');

// ─── Approval Request Types ─────────────────────────────────────────────────
define('REQUEST_REGISTRATION', 'registration');
define('REQUEST_ROLE_CHANGE', 'role_change');
define('REQUEST_REACTIVATION', 'reactivation');

// ─── Approval Request Status ────────────────────────────────────────────────
define('APPROVAL_PENDING', 'pending');
define('APPROVAL_APPROVED', 'approved');
define('APPROVAL_REJECTED', 'rejected');

// ─── Audit Log Actions ─────────────────────────────────────────────────────
define('ACTION_LOGIN', 'LOGIN');
define('ACTION_LOGOUT', 'LOGOUT');
define('ACTION_LOGIN_FAILED', 'LOGIN_FAILED');
define('ACTION_REGISTER', 'REGISTER');
define('ACTION_APPROVE', 'APPROVE');
define('ACTION_REJECT', 'REJECT');
define('ACTION_CREATE', 'CREATE');
define('ACTION_UPDATE', 'UPDATE');
define('ACTION_DELETE', 'DELETE');
define('ACTION_LOCKED', 'ACCOUNT_LOCKED');
define('ACTION_STOCK_ADJUST', 'STOCK_ADJUST');
define('ACTION_DEACTIVATE', 'DEACTIVATE');

// ─── Session Initialization ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_name(SESSION_NAME);
    session_start();
}

// ─── Session Timeout Check ─────────────────────────────────────────────────
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
    }
}
$_SESSION['last_activity'] = time();
?>
