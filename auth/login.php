<?php
/**
 * Login Page - Mini ERP System
 * Handles user authentication with brute-force protection.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    redirect('/dashboard/index.php');
}

$errors = [];
$email = '';

// ─── Handle Login POST ────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errors[] = 'Please enter both email and password.';
        } else {
            // Find user by email
            $stmt = $conn->prepare("SELECT user_id, full_name, email, password_hash, status, role_id, failed_login_attempts, locked_until FROM tbl_users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $errors[] = 'Invalid email or password.';
            } else {
                // Check if account is locked
                if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                    $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
                    $errors[] = "Account is locked. Try again in $remaining minute(s).";
                    log_action($conn, 'Authentication', ACTION_LOGIN_FAILED, 'User', $user['user_id'], null, ['reason' => 'Account locked', 'email' => $email]);
                } elseif ($user['status'] === STATUS_PENDING) {
                    $errors[] = 'Your account is pending admin approval. Please wait for approval.';
                } elseif ($user['status'] === STATUS_REJECTED) {
                    $errors[] = 'Your account has been rejected. Please contact the administrator.';
                } elseif ($user['status'] === STATUS_SUSPENDED) {
                    $errors[] = 'Your account has been suspended. Please contact the administrator.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    // Wrong password — increment failed attempts
                    $attempts = $user['failed_login_attempts'] + 1;
                    $lockUntil = null;

                    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                        $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
                        $stmt = $conn->prepare("UPDATE tbl_users SET failed_login_attempts = ?, locked_until = ? WHERE user_id = ?");
                        $stmt->bind_param("isi", $attempts, $lockUntil, $user['user_id']);
                        $stmt->execute();
                        $stmt->close();

                        log_action($conn, 'Authentication', ACTION_LOCKED, 'User', $user['user_id'], null, ['attempts' => $attempts, 'locked_until' => $lockUntil]);
                        $errors[] = 'Too many failed attempts. Account locked for 15 minutes.';
                    } else {
                        $stmt = $conn->prepare("UPDATE tbl_users SET failed_login_attempts = ? WHERE user_id = ?");
                        $stmt->bind_param("ii", $attempts, $user['user_id']);
                        $stmt->execute();
                        $stmt->close();

                        $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
                        $errors[] = "Invalid email or password. $remaining attempt(s) remaining.";
                    }

                    log_action($conn, 'Authentication', ACTION_LOGIN_FAILED, 'User', $user['user_id'], null, ['email' => $email, 'attempts' => $attempts]);
                } else {
                    // Successful login
                    // Reset failed attempts and update login info
                    $ip = get_client_ip();
                    $now = date('Y-m-d H:i:s');
                    $stmt = $conn->prepare("UPDATE tbl_users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ? WHERE user_id = ?");
                    $stmt->bind_param("ssi", $now, $ip, $user['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    // Regenerate session ID for security
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();

                    // Get role name
                    $stmt = $conn->prepare("SELECT role_name FROM tbl_roles WHERE role_id = ?");
                    $stmt->bind_param("i", $user['role_id']);
                    $stmt->execute();
                    $role = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $_SESSION['role_name'] = $role['role_name'] ?? 'Unknown';

                    // Create session record
                    $sessionId = session_id();
                    $userAgent = get_user_agent();
                    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                    $stmt = $conn->prepare("INSERT INTO tbl_user_sessions (session_id, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sisss", $sessionId, $user['user_id'], $ip, $userAgent, $expiresAt);
                    $stmt->execute();
                    $stmt->close();

                    // Audit log
                    log_action($conn, 'Authentication', ACTION_LOGIN, 'User', $user['user_id'], null, ['ip' => $ip]);

                    set_flash('success', 'Welcome back, ' . $user['full_name'] . '!');
                    redirect('/dashboard/index.php');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Log in to Mini ERP — Manufacturing Business Management System">
    <title>Log In — Mini ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <?= render_flash_messages() ?>

    <div class="auth-wrapper">
        <div class="auth-card animate-in">
            <div class="auth-header">
                <div class="auth-logo"><i class="fa-solid fa-cubes"></i></div>
                <h1>Welcome Back</h1>
                <p>Sign in to your Mini ERP account</p>
            </div>

            <div class="auth-body">
                <?php if (!empty($errors)): ?>
                    <div style="background: var(--color-danger-bg); border: 1px solid rgba(239,68,68,0.2); border-radius: var(--border-radius-sm); padding: 12px 14px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--color-danger);">
                        <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i>
                        <?= e($errors[0]) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" data-validate id="login-form">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control" 
                               placeholder="Enter your email" value="<?= e($email) ?>" required autocomplete="email">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" class="form-control" 
                                   placeholder="Enter your password" required autocomplete="current-password">
                            <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="login-btn" style="margin-top: 8px; padding: 12px;">
                        <i class="fa-solid fa-right-to-bracket"></i>
                        Sign In
                    </button>
                </form>
            </div>

            <div class="auth-footer">
                Don't have an account? <a href="<?= BASE_URL ?>/auth/signup.php">Create one</a>
            </div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
