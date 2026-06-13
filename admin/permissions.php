<?php
/**
 * Permission Management - Mini ERP System
 * Interactive permission matrix: role × module grid with checkboxes.
 */

$pageTitle = 'Permission Management';
$currentModule = 'user-management';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Fetch Roles & Modules ─────────────────────────────────────────────────
$roles = $conn->query("SELECT role_id, role_name, is_system_role FROM tbl_roles ORDER BY role_id")->fetch_all(MYSQLI_ASSOC);
$modules = $conn->query("SELECT module_id, module_name, module_slug, module_icon FROM tbl_modules WHERE is_active = 1 ORDER BY display_order")->fetch_all(MYSQLI_ASSOC);

// Fetch current permissions
$permMap = [];
$permResult = $conn->query("SELECT role_id, module_id, can_view, can_create, can_edit, can_delete FROM tbl_role_permissions");
while ($row = $permResult->fetch_assoc()) {
    $permMap[$row['role_id']][$row['module_id']] = [
        'can_view' => $row['can_view'],
        'can_create' => $row['can_create'],
        'can_edit' => $row['can_edit'],
        'can_delete' => $row['can_delete'],
    ];
}

// ─── Handle Permission Update POST ─────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        set_flash('error', 'Invalid security token.');
        redirect('/admin/permissions.php');
    }

    $conn->begin_transaction();
    try {
        // Delete existing non-admin permissions and re-insert
        foreach ($roles as $role) {
            if ($role['role_name'] === 'Admin') continue; // Skip admin — always full

            $roleId = $role['role_id'];
            $conn->query("DELETE FROM tbl_role_permissions WHERE role_id = $roleId");

            foreach ($modules as $mod) {
                $modId = $mod['module_id'];
                $view   = isset($_POST["perm_{$roleId}_{$modId}_view"]) ? 1 : 0;
                $create = isset($_POST["perm_{$roleId}_{$modId}_create"]) ? 1 : 0;
                $edit   = isset($_POST["perm_{$roleId}_{$modId}_edit"]) ? 1 : 0;
                $delete = isset($_POST["perm_{$roleId}_{$modId}_delete"]) ? 1 : 0;

                if ($view || $create || $edit || $delete) {
                    $stmt = $conn->prepare("INSERT INTO tbl_role_permissions (role_id, module_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiiii", $roleId, $modId, $view, $create, $edit, $delete);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        log_action($conn, 'User Management', ACTION_UPDATE, 'Permissions', null, null, ['updated' => 'All role permissions']);

        $conn->commit();
        set_flash('success', 'Permissions updated successfully.');

        // Clear cached permissions for all sessions
        $_SESSION['permissions_loaded'] = 0;

    } catch (Exception $ex) {
        $conn->rollback();
        set_flash('error', 'Failed to update permissions.');
    }
    redirect('/admin/permissions.php');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Permission Management</h1>
        <p class="page-header-desc">Configure module access for each role</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/roles.php" class="btn btn-secondary">
        <i class="fa-solid fa-users-gear"></i> Manage Roles
    </a>
</div>

<form method="POST" action="" id="permissions-form">
    <?= csrf_field() ?>

    <?php foreach ($roles as $role): ?>
        <?php $isAdmin = ($role['role_name'] === 'Admin'); ?>
        <div class="card animate-in" style="margin-bottom: 20px;">
            <div class="card-header">
                <h3>
                    <i class="fa-solid fa-shield-halved" style="color:var(--accent-primary); margin-right:8px;"></i>
                    <?= e($role['role_name']) ?>
                    <?php if ($isAdmin): ?>
                        <span class="badge badge-warning" style="margin-left:8px; font-size:0.65rem;">Full Access — Cannot Modify</span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="permission-grid">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:30%;">Module</th>
                                <th>View</th>
                                <th>Create</th>
                                <th>Edit</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modules as $mod): ?>
                                <?php
                                    $rId = $role['role_id'];
                                    $mId = $mod['module_id'];
                                    $perms = $permMap[$rId][$mId] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
                                ?>
                                <tr>
                                    <td>
                                        <i class="<?= e($mod['module_icon']) ?>" style="width:20px; text-align:center; color:var(--text-muted); margin-right:8px;"></i>
                                        <?= e($mod['module_name']) ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="permission-toggle"
                                               name="perm_<?= $rId ?>_<?= $mId ?>_view"
                                               <?= $perms['can_view'] ? 'checked' : '' ?>
                                               <?= $isAdmin ? 'disabled checked' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="permission-toggle"
                                               name="perm_<?= $rId ?>_<?= $mId ?>_create"
                                               <?= $perms['can_create'] ? 'checked' : '' ?>
                                               <?= $isAdmin ? 'disabled checked' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="permission-toggle"
                                               name="perm_<?= $rId ?>_<?= $mId ?>_edit"
                                               <?= $perms['can_edit'] ? 'checked' : '' ?>
                                               <?= $isAdmin ? 'disabled checked' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="permission-toggle"
                                               name="perm_<?= $rId ?>_<?= $mId ?>_delete"
                                               <?= $perms['can_delete'] ? 'checked' : '' ?>
                                               <?= $isAdmin ? 'disabled checked' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;" class="animate-in">
        <a href="<?= BASE_URL ?>/admin/permissions.php" class="btn btn-secondary">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </a>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Save all permission changes?')">
            <i class="fa-solid fa-floppy-disk"></i> Save All Permissions
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
