<?php
/**
 * Sign Up Page - Mini ERP System
 * User registration with pending approval workflow.
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
$old = ['full_name' => '', 'email' => '', 'phone' => ''];

// ─── Handle Registration POST ──────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $old['full_name'] = trim($_POST['full_name'] ?? '');
        $old['email']     = trim($_POST['email'] ?? '');
        $old['phone']     = trim($_POST['phone'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirmPassword   = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($old['full_name'])) {
            $errors[] = 'Full name is required.';
        } elseif (strlen($old['full_name']) < 2 || strlen($old['full_name']) > 100) {
            $errors[] = 'Full name must be between 2 and 100 characters.';
        }

        if (empty($old['email'])) {
            $errors[] = 'Email address is required.';
        } elseif (!validate_email($old['email'])) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT user_id, status FROM tbl_users WHERE email = ?");
            $stmt->bind_param("s", $old['email']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                if ($existing['status'] === STATUS_PENDING) {
                    $errors[] = 'An account with this email is already pending approval.';
                } elseif ($existing['status'] === STATUS_REJECTED) {
                    $errors[] = 'An account with this email was previously rejected. Please contact the administrator.';
                } else {
                    $errors[] = 'An account with this email already exists.';
                }
            }
        }

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

        if (!empty($old['phone']) && !preg_match('/^[\d\s\-\+\(\)]{7,20}$/', $old['phone'])) {
            $errors[] = 'Please enter a valid phone number.';
        }

        // Create user if no errors
        if (empty($errors)) {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

            $conn->begin_transaction();
            try {
                // Insert user with pending status
                $stmt = $conn->prepare("INSERT INTO tbl_users (full_name, email, password_hash, phone, status) VALUES (?, ?, ?, ?, 'pending')");
                $phone = !empty($old['phone']) ? $old['phone'] : null;
                $stmt->bind_param("ssss", $old['full_name'], $old['email'], $passwordHash, $phone);
                $stmt->execute();
                $newUserId = $conn->insert_id;
                $stmt->close();

                // Create approval request
                $stmt = $conn->prepare("INSERT INTO tbl_user_approval_requests (user_id, request_type, status) VALUES (?, 'registration', 'pending')");
                $stmt->bind_param("i", $newUserId);
                $stmt->execute();
                $stmt->close();

                // Audit log (no session user yet, log as system)
                $ip = get_client_ip();
                $userAgent = get_user_agent();
                $stmtLog = $conn->prepare("INSERT INTO tbl_audit_log (user_id, user_name, module, action, record_type, record_id, new_values, ip_address, user_agent) VALUES (?, ?, 'Authentication', 'REGISTER', 'User', ?, ?, ?, ?)");
                $newValues = json_encode(['full_name' => $old['full_name'], 'email' => $old['email'], 'status' => 'pending']);
                $stmtLog->bind_param("isisss", $newUserId, $old['full_name'], $newUserId, $newValues, $ip, $userAgent);
                $stmtLog->execute();
                $stmtLog->close();

                $conn->commit();

                set_flash('success', 'Registration successful! Your account is pending admin approval. You will be able to log in once approved.');
                redirect('/auth/login.php');

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = 'An error occurred during registration. Please try again.';
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
    <meta name="description" content="Create an account on Mini ERP — Manufacturing Business Management System">
    <title>Sign Up — Mini ERP</title>
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
                <h1>Create Account</h1>
                <p>Register for access to Mini ERP</p>
            </div>

            <div class="auth-body">
                <?php if (!empty($errors)): ?>
                    <div style="background: var(--color-danger-bg); border: 1px solid rgba(239,68,68,0.2); border-radius: var(--border-radius-sm); padding: 12px 14px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--color-danger);">
                        <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i>
                        <ul style="list-style: none; margin: 0; padding: 0;">
                            <?php foreach ($errors as $error): ?>
                                <li style="padding: 2px 0;"><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" data-validate id="signup-form">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="full_name">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="full_name" class="form-control" 
                               placeholder="Enter your full name" value="<?= e($old['full_name']) ?>" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="email" class="form-control" 
                               placeholder="Enter your email" value="<?= e($old['email']) ?>" required maxlength="150" autocomplete="email">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Phone Number</label>
                        <input type="tel" name="phone" id="phone" class="form-control" 
                               placeholder="Enter your phone number (optional)" value="<?= e($old['phone']) ?>" maxlength="20">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
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

                    <button type="submit" class="btn btn-primary w-100" id="signup-btn" style="margin-top: 8px; padding: 12px;">
                        <i class="fa-solid fa-user-plus"></i>
                        Create Account
                    </button>
                </form>
            </div>

            <div class="auth-footer">
                Already have an account? <a href="<?= BASE_URL ?>/auth/login.php">Sign in</a>
            </div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
