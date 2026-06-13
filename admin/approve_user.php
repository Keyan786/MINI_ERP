<?php
/**
 * Approve/Reject User - Mini ERP System
 * Admin reviews an approval request and approves (with role) or rejects (with reason).
 */

$pageTitle = 'Review Approval Request';
$currentModule = 'user-management';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

$requestId = intval($_GET['id'] ?? 0);
if ($requestId <= 0) {
    set_flash('error', 'Invalid request ID.');
    redirect('/admin/users.php');
}

// ─── Fetch Request + User ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT ar.*, u.full_name, u.email, u.phone, u.status AS user_status, u.created_at AS user_created_at,
           u.role_id AS current_user_role_id
    FROM tbl_user_approval_requests ar
    JOIN tbl_users u ON ar.user_id = u.user_id
    WHERE ar.request_id = ?
");
$stmt->bind_param("i", $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    set_flash('error', 'Approval request not found.');
    redirect('/admin/users.php');
}

// Fetch available roles (exclude Admin for non-registration)
$roles = $conn->query("SELECT role_id, role_name, role_description FROM tbl_roles WHERE role_name != 'Admin' ORDER BY role_name")->fetch_all(MYSQLI_ASSOC);

// Fetch reviewer info if already reviewed
$reviewer = null;
if ($request['reviewed_by']) {
    $stmt = $conn->prepare("SELECT full_name FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $request['reviewed_by']);
    $stmt->execute();
    $reviewer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$errors = [];

// ─── Handle Approve/Reject POST ────────────────────────────────────────────
if (is_post() && $request['status'] === 'pending') {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $decision = $_POST['decision'] ?? '';
        $assignedRoleId = intval($_POST['assigned_role_id'] ?? 0);
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($decision === 'approve') {
            if ($assignedRoleId <= 0) {
                $errors[] = 'Please select a role to assign.';
            } else {
                $conn->begin_transaction();
                try {
                    $now = date('Y-m-d H:i:s');

                    // Update approval request
                    $stmt = $conn->prepare("UPDATE tbl_user_approval_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?, assigned_role_id = ?, remarks = ? WHERE request_id = ?");
                    $remarksVal = !empty($remarks) ? $remarks : null;
                    $stmt->bind_param("isisi", $_SESSION['user_id'], $now, $assignedRoleId, $remarksVal, $requestId);
                    $stmt->execute();
                    $stmt->close();

                    // Update user
                    $stmt = $conn->prepare("UPDATE tbl_users SET status = 'active', role_id = ?, approved_by = ?, approved_at = ? WHERE user_id = ?");
                    $stmt->bind_param("iisi", $assignedRoleId, $_SESSION['user_id'], $now, $request['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    // Get role name for audit
                    $stmt = $conn->prepare("SELECT role_name FROM tbl_roles WHERE role_id = ?");
                    $stmt->bind_param("i", $assignedRoleId);
                    $stmt->execute();
                    $roleName = $stmt->get_result()->fetch_assoc()['role_name'];
                    $stmt->close();

                    // Audit log
                    log_action($conn, 'User Management', ACTION_APPROVE, 'User', $request['user_id'],
                        ['status' => 'pending', 'role' => null],
                        ['status' => 'active', 'role' => $roleName, 'approved_by' => $_SESSION['user_name']]
                    );

                    $conn->commit();
                    set_flash('success', $request['full_name'] . ' has been approved and assigned the ' . $roleName . ' role.');
                    redirect('/admin/users.php');

                } catch (Exception $ex) {
                    $conn->rollback();
                    $errors[] = 'An error occurred. Please try again.';
                }
            }
        } elseif ($decision === 'reject') {
            if (empty($rejectionReason)) {
                $errors[] = 'Please provide a reason for rejection.';
            } else {
                $conn->begin_transaction();
                try {
                    $now = date('Y-m-d H:i:s');

                    // Update approval request
                    $stmt = $conn->prepare("UPDATE tbl_user_approval_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, rejection_reason = ?, remarks = ? WHERE request_id = ?");
                    $remarksVal = !empty($remarks) ? $remarks : null;
                    $stmt->bind_param("isssi", $_SESSION['user_id'], $now, $rejectionReason, $remarksVal, $requestId);
                    $stmt->execute();
                    $stmt->close();

                    // Update user
                    $stmt = $conn->prepare("UPDATE tbl_users SET status = 'rejected', approved_by = ?, approved_at = ?, rejection_reason = ? WHERE user_id = ?");
                    $stmt->bind_param("issi", $_SESSION['user_id'], $now, $rejectionReason, $request['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    // Audit log
                    log_action($conn, 'User Management', ACTION_REJECT, 'User', $request['user_id'],
                        ['status' => 'pending'],
                        ['status' => 'rejected', 'rejection_reason' => $rejectionReason, 'rejected_by' => $_SESSION['user_name']]
                    );

                    $conn->commit();
                    set_flash('info', $request['full_name'] . '\'s account has been rejected.');
                    redirect('/admin/users.php');

                } catch (Exception $ex) {
                    $conn->rollback();
                    $errors[] = 'An error occurred. Please try again.';
                }
            }
        } else {
            $errors[] = 'Invalid action.';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Review Approval Request</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/admin/users.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Users
            </a>
        </p>
    </div>
    <?= status_badge($request['status']) ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background: var(--color-danger-bg); border: 1px solid rgba(239,68,68,0.2); border-radius: var(--border-radius-sm); padding: 14px 16px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i>
        <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;" class="animate-in">
    <!-- User Details -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-user" style="color:var(--accent-primary); margin-right:8px;"></i> User Details</h3>
        </div>
        <div class="card-body">
            <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
                <div style="width:56px; height:56px; border-radius:var(--border-radius-full); background:var(--accent-gradient); display:flex; align-items:center; justify-content:center; color:white; font-size:1.25rem; font-weight:600;">
                    <?= strtoupper(substr($request['full_name'], 0, 2)) ?>
                </div>
                <div>
                    <div style="font-size:1.125rem; font-weight:600; color:var(--text-primary);"><?= e($request['full_name']) ?></div>
                    <div style="font-size:0.8125rem; color:var(--text-muted);"><?= e($request['email']) ?></div>
                </div>
            </div>

            <div style="display:grid; gap:16px;">
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Phone</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary); font-weight:500;"><?= $request['phone'] ? e($request['phone']) : '—' ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Request Type</span>
                    <span class="badge badge-info"><?= e(ucfirst(str_replace('_', ' ', $request['request_type']))) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Account Status</span>
                    <?= status_badge($request['user_status']) ?>
                </div>
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Registered</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($request['user_created_at']) ?></span>
                </div>
                <?php if ($reviewer): ?>
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Reviewed By</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary); font-weight:500;"><?= e($reviewer['full_name']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Reviewed At</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($request['reviewed_at']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($request['rejection_reason']): ?>
                <div style="padding:10px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted); display:block; margin-bottom:6px;">Rejection Reason</span>
                    <span style="font-size:0.8125rem; color:var(--color-danger);"><?= e($request['rejection_reason']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Decision Form -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-gavel" style="color:var(--color-warning); margin-right:8px;"></i> Decision</h3>
        </div>
        <div class="card-body">
            <?php if ($request['status'] !== 'pending'): ?>
                <div class="empty-state" style="padding:30px;">
                    <div class="empty-state-icon">
                        <?php if ($request['status'] === 'approved'): ?>
                            <i class="fa-solid fa-circle-check" style="color:var(--color-success);"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-circle-xmark" style="color:var(--color-danger);"></i>
                        <?php endif; ?>
                    </div>
                    <h3>Request <?= ucfirst($request['status']) ?></h3>
                    <p>This request has already been <?= $request['status'] ?>.</p>
                </div>
            <?php else: ?>
                <form method="POST" action="" id="approval-form">
                    <?= csrf_field() ?>

                    <!-- Approve Section -->
                    <div style="background:var(--color-success-bg); border:1px solid rgba(34,197,94,0.15); border-radius:var(--border-radius-sm); padding:16px; margin-bottom:16px;">
                        <h4 style="font-size:0.875rem; color:var(--color-success); margin-bottom:12px;">
                            <i class="fa-solid fa-check-circle" style="margin-right:6px;"></i>Approve User
                        </h4>

                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label" for="assigned_role_id">Assign Role <span class="text-danger">*</span></label>
                            <select name="assigned_role_id" id="assigned_role_id" class="form-control form-select">
                                <option value="">Select a role...</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['role_id'] ?>"><?= e($role['role_name']) ?> — <?= e($role['role_description'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" name="decision" value="approve" class="btn btn-success w-100"
                                onclick="return confirm('Approve this user and assign the selected role?')">
                            <i class="fa-solid fa-check"></i> Approve & Assign Role
                        </button>
                    </div>

                    <!-- Reject Section -->
                    <div style="background:var(--color-danger-bg); border:1px solid rgba(239,68,68,0.15); border-radius:var(--border-radius-sm); padding:16px; margin-bottom:16px;">
                        <h4 style="font-size:0.875rem; color:var(--color-danger); margin-bottom:12px;">
                            <i class="fa-solid fa-xmark-circle" style="margin-right:6px;"></i>Reject User
                        </h4>

                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label" for="rejection_reason">Rejection Reason <span class="text-danger">*</span></label>
                            <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3"
                                      placeholder="Provide a reason for rejecting this request..."></textarea>
                        </div>

                        <button type="submit" name="decision" value="reject" class="btn btn-danger w-100"
                                onclick="return confirm('Are you sure you want to reject this request?')">
                            <i class="fa-solid fa-xmark"></i> Reject Request
                        </button>
                    </div>

                    <!-- Remarks (optional) -->
                    <div class="form-group">
                        <label class="form-label" for="remarks">Admin Remarks (optional)</label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="2"
                                  placeholder="Internal notes about this decision..."></textarea>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
