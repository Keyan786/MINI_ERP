<?php
/**
 * Create User Page - Mini ERP System
 * Admins create users here and system sends an email invitation.
 */

$pageTitle = 'Create User';
$currentModule = 'user-management';

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/mail_helpers.php';
require_admin();

$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => '', 'role_id' => '', 'department' => ''];

// Fetch Roles
$stmt = $conn->query("SELECT role_id, role_name FROM tbl_roles ORDER BY role_name ASC");
$roles = $stmt->fetch_all(MYSQLI_ASSOC);

// ─── Handle Creation POST ──────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $old['full_name'] = trim($_POST['full_name'] ?? '');
        $old['email'] = trim($_POST['email'] ?? '');
        $old['phone'] = trim($_POST['phone'] ?? '');
        $old['role_id'] = trim($_POST['role_id'] ?? '');
        $old['department'] = trim($_POST['department'] ?? '');

        if (empty($old['full_name'])) {
            $errors[] = 'Full name is required.';
        }
        if (empty($old['email']) || !validate_email($old['email'])) {
            $errors[] = 'A valid email address is required.';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
            $stmt->bind_param("s", $old['email']);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $errors[] = 'A user with this email address already exists.';
            }
            $stmt->close();
        }
        if (empty($old['role_id'])) {
            $errors[] = 'Please select a role.';
        }

        if (empty($errors)) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', time() + 86400); // 24 hours

            $conn->begin_transaction();
            try {
                // Insert User (Note: no password_hash required for pending_setup)
                $stmt = $conn->prepare("INSERT INTO tbl_users (full_name, email, password_hash, phone, role_id, department, status, password_setup_token, token_expiry) VALUES (?, ?, '', ?, ?, ?, 'pending_setup', ?, ?)");
                $stmt->bind_param("sssssss", $old['full_name'], $old['email'], $old['phone'], $old['role_id'], $old['department'], $token, $tokenExpiry);
                $stmt->execute();
                $newUserId = $conn->insert_id;
                $stmt->close();

                // Seed tbl_user_permissions with role defaults
                $rolePermsStmt = $conn->prepare("SELECT module_id, can_view, can_create, can_edit, can_delete FROM tbl_role_permissions WHERE role_id = ?");
                $rolePermsStmt->bind_param("i", $old['role_id']);
                $rolePermsStmt->execute();
                $rolePermsResult = $rolePermsStmt->get_result();
                
                $insertPermsStmt = $conn->prepare("INSERT INTO tbl_user_permissions (user_id, module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                
                $cA = ($old['role_id'] == 1) ? 1 : 0; // Admin gets approve
                $cR = ($old['role_id'] == 1) ? 1 : 0; // Admin gets report
                
                while ($rp = $rolePermsResult->fetch_assoc()) {
                    $insertPermsStmt->bind_param("iiiiiiii", $newUserId, $rp['module_id'], $rp['can_view'], $rp['can_create'], $rp['can_edit'], $rp['can_delete'], $cA, $cR);
                    $insertPermsStmt->execute();
                }
                $rolePermsStmt->close();
                $insertPermsStmt->close();

                // Send Email Invitation
                $setupUrl = "http://" . $_SERVER['HTTP_HOST'] . BASE_URL . "/auth/setup_password.php?token=" . $token;
                
                $subject = "Welcome to Mini ERP - Set up your account";
                $message = "Hello " . $old['full_name'] . ",\n\n";
                $message .= "An account has been created for you on Mini ERP.\n\n";
                $message .= "Please click the link below to set up your password and access your account:\n";
                $message .= $setupUrl . "\n\n";
                $message .= "This link will expire in 24 hours.\n\n";
                $message .= "Best regards,\nMini ERP Administrator";

                // Send mail using PHPMailer helper
                if (!send_mail([$old['email'] => $old['full_name']], $subject, $message)) {
                    // Log the error but don't fail the transaction, they can resend the invite later
                    error_log("Failed to send welcome email to " . $old['email']);
                }

                log_action($conn, 'User Management', ACTION_USER_CREATED, 'User', $newUserId, null, ['email' => $old['email'], 'role_id' => $old['role_id']]);
                log_action($conn, 'User Management', ACTION_INVITE_SENT, 'User', $newUserId, null, ['email' => $old['email']]);

                $conn->commit();

                set_flash('success', "User '{$old['full_name']}' created successfully. An invitation email has been sent.");
                redirect('/admin/users.php');

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Failed to create user. Please try again. ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <a href="<?= BASE_URL ?>/admin/users.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Users</a>
        <h1>Create New User</h1>
        <p class="page-header-desc">Provision a new account and send an email invitation.</p>
    </div>
</div>

<div class="card animate-in" style="max-width: 800px;">
    <div class="card-body">
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

        <form method="POST" action="">
            <?= csrf_field() ?>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label" for="full_name">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" id="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required maxlength="100">
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" id="email" class="form-control" value="<?= e($old['email']) ?>" required maxlength="150">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label" for="phone">Mobile Number</label>
                    <input type="text" name="phone" id="phone" class="form-control" value="<?= e($old['phone']) ?>" maxlength="20">
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label" for="role_id">Role <span class="text-danger">*</span></label>
                    <select name="role_id" id="role_id" class="form-control" required>
                        <option value="">— Select Role —</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['role_id'] ?>" <?= $old['role_id'] == $role['role_id'] ? 'selected' : '' ?>>
                                <?= e($role['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="department">Department</label>
                <input type="text" name="department" id="department" class="form-control" value="<?= e($old['department']) ?>" maxlength="100" placeholder="e.g., Sales, HR, Production">
            </div>

            <div class="form-group" style="background: var(--surface-hover); padding: 16px; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); margin-top: 20px;">
                <h4 style="margin: 0 0 10px 0; font-size: 0.9rem; color: var(--text-primary);"><i class="fa-regular fa-envelope"></i> Email Invitation</h4>
                <p style="margin: 0; font-size: 0.8125rem; color: var(--text-secondary);">An email will be sent to the provided address with a secure link. The user will use this link to set their password. They will not be able to log in until the password is set.</p>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Create User & Send Invite</button>
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline" style="margin-left: 10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
