<?php
/**
 * Role Management - Mini ERP System
 * View and manage system roles.
 */

$pageTitle = 'Role Management';
$currentModule = 'user-management';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Fetch Roles with User Count ────────────────────────────────────────────
$roles = $conn->query("
    SELECT r.*, 
           (SELECT COUNT(*) FROM tbl_users u WHERE u.role_id = r.role_id AND u.status = 'active') AS user_count
    FROM tbl_roles r
    ORDER BY r.role_id ASC
")->fetch_all(MYSQLI_ASSOC);

// ─── Handle Edit Role POST ─────────────────────────────────────────────────
if (is_post() && isset($_POST['edit_role'])) {
    if (!csrf_validate()) {
        set_flash('error', 'Invalid security token.');
        redirect('/admin/roles.php');
    }

    $roleId = intval($_POST['role_id'] ?? 0);
    $roleName = sanitize_input($_POST['role_name'] ?? '');
    $roleDesc = sanitize_input($_POST['role_description'] ?? '');

    if ($roleId > 0 && !empty($roleName)) {
        // Get old values
        $stmt = $conn->prepare("SELECT role_name, role_description FROM tbl_roles WHERE role_id = ?");
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $oldRole = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE tbl_roles SET role_name = ?, role_description = ? WHERE role_id = ?");
        $stmt->bind_param("ssi", $roleName, $roleDesc, $roleId);
        $stmt->execute();
        $stmt->close();

        log_action($conn, 'User Management', ACTION_UPDATE, 'Role', $roleId,
            ['role_name' => $oldRole['role_name'], 'role_description' => $oldRole['role_description']],
            ['role_name' => $roleName, 'role_description' => $roleDesc]
        );

        set_flash('success', 'Role updated successfully.');
    }
    redirect('/admin/roles.php');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Role Management</h1>
        <p class="page-header-desc">View and manage system roles</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/permissions.php" class="btn btn-primary">
        <i class="fa-solid fa-shield-halved"></i> Manage Permissions
    </a>
</div>

<div class="card animate-in">
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table" id="roles-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Role Name</th>
                        <th>Description</th>
                        <th>Active Users</th>
                        <th>System Role</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:0.8125rem;">#<?= $role['role_id'] ?></td>
                            <td style="font-weight:600; color:var(--text-primary);"><?= e($role['role_name']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-secondary); max-width:300px;"><?= e($role['role_description'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-primary"><?= $role['user_count'] ?> users</span>
                            </td>
                            <td>
                                <?php if ($role['is_system_role']): ?>
                                    <span class="badge badge-warning"><i class="fa-solid fa-lock" style="margin-right:3px;"></i> System</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8125rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= format_datetime($role['created_at']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-secondary" data-modal-target="edit-role-<?= $role['role_id'] ?>">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Role Modals -->
<?php foreach ($roles as $role): ?>
<div class="modal-overlay" id="edit-role-<?= $role['role_id'] ?>">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Role: <?= e($role['role_name']) ?></h3>
            <button class="modal-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="edit_role" value="1">
            <input type="hidden" name="role_id" value="<?= $role['role_id'] ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="role_name_<?= $role['role_id'] ?>">Role Name</label>
                    <input type="text" name="role_name" id="role_name_<?= $role['role_id'] ?>" class="form-control"
                           value="<?= e($role['role_name']) ?>" required maxlength="50"
                           <?= $role['is_system_role'] ? 'readonly' : '' ?>>
                    <?php if ($role['is_system_role']): ?>
                        <div class="form-hint">System role names cannot be changed.</div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="role_desc_<?= $role['role_id'] ?>">Description</label>
                    <textarea name="role_description" id="role_desc_<?= $role['role_id'] ?>" class="form-control" rows="3"><?= e($role['role_description'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
