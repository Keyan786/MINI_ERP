<?php
/**
 * Change Password Page - Mini ERP System
 * Mandatory password change screen for users with force_password_change = 1
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Must be logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    redirect('/auth/login.php');
}

// Check if they actually need to change it
$stmt = $conn->prepare("SELECT force_password_change FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['force_password_change'] != 1) {
    if (isset($_SESSION['force_password_change'])) {
        unset($_SESSION['force_password_change']);
    }
    redirect('/dashboard/index.php');
}

$errors = [];

// ─── Handle Password Change POST ──────────────────────────────────────────
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
            $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, force_password_change = 0, password_changed_at = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $passwordHash, $now, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                log_action($conn, 'Authentication', ACTION_PASSWORD_CHANGED, 'User', $_SESSION['user_id']);
                
                unset($_SESSION['force_password_change']);
                set_flash('success', 'Your password has been successfully updated.');
                redirect('/dashboard/index.php');
            } else {
                $errors[] = 'An error occurred while updating your password. Please try again.';
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
    <meta name="description" content="Change Password — Mini ERP">
    <title>Change Password — Mini ERP</title>
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
                <h1>Update Password</h1>
                <p>Your administrator requires you to change your password before continuing.</p>
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

                <form method="POST" action="" data-validate id="change-password-form">
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

                    <button type="submit" class="btn btn-primary w-100" id="change-btn" style="margin-top: 8px; padding: 12px;">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Update Password & Continue
                    </button>
                    
                    <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline w-100" style="margin-top: 10px; padding: 12px; text-align:center;">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        Cancel & Logout
                    </a>
                </form>
            </div>
            
            <div class="auth-footer" style="min-height: 20px;"></div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
