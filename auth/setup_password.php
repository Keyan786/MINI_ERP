<?php
/**
 * Setup Password Page - Mini ERP System
 * Allows a user to set their password via an email invitation link.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';

$errors = [];
$token = $_GET['token'] ?? '';
$user = null;

if (empty($token)) {
    die("Invalid or missing setup token.");
}

// Find user by token
$stmt = $conn->prepare("SELECT user_id, email, full_name, token_expiry, is_password_set FROM tbl_users WHERE password_setup_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Invalid setup token. Please contact your administrator.");
}

if ($user['is_password_set'] == 1) {
    die("Password has already been set for this account. You can log in normally.");
}

if ($user['token_expiry'] && strtotime($user['token_expiry']) < time()) {
    die("This setup link has expired. Please ask your administrator to resend the invitation.");
}

// ─── Handle Password Setup POST ───────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Password must not exceed 72 characters.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $now = date('Y-m-d H:i:s');
            
            // Update user record
            $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, is_password_set = 1, password_setup_token = NULL, token_expiry = NULL, status = 'active', password_changed_at = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $passwordHash, $now, $user['user_id']);
            
            if ($stmt->execute()) {
                log_action($conn, 'Authentication', ACTION_PASSWORD_CREATED, 'User', $user['user_id']);
                log_action($conn, 'Authentication', ACTION_USER_ACTIVATED, 'User', $user['user_id']);
                
                set_flash('success', 'Your password has been successfully set. You can now log in.');
                redirect('/auth/login.php');
            } else {
                $errors[] = 'An error occurred while setting your password. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Set Up Password — Mini ERP">
    <title>Set Up Password — Mini ERP</title>
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
                <div class="auth-logo"><img src="<?= BASE_URL ?>/assets/images/logo.jpeg" alt="Logo"></div>
                <h1>Welcome, <?= e(explode(' ', $user['full_name'])[0]) ?>!</h1>
                <p>Please create a password for your account (<?= e($user['email']) ?>)</p>
            </div>

            <div class="auth-body">
                <?php if (!empty($errors)): ?>
                    <div style="background: var(--color-danger-bg); border: 1px solid rgba(239,68,68,0.2); border-radius: var(--border-radius-sm); padding: 12px 14px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--color-danger);">
                        <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i>
                        <ul style="list-style: none; margin: 0; padding: 0;">
                            <?php foreach ($errors as $error): ?>
                                <li style="padding: 2px 0;"> <?= e($error) ?> </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" data-validate id="setup-password-form">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="password">New Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" class="form-control" 
                                   placeholder="Minimum 6 characters" required minlength="6" maxlength="72" autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                                   placeholder="Re-enter your password" required autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="setup-btn" style="margin-top: 8px; padding: 12px;">
                        <i class="fa-solid fa-lock"></i>
                        Set Password & Log In
                    </button>
                </form>
            </div>
            
            <div class="auth-footer" style="min-height: 20px;"></div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
