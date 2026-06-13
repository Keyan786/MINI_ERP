<?php
/**
 * User Rights Management - Mini ERP System
 * Dynamic interface for managing granular user permissions.
 */

$pageTitle = 'User Rights Management';
$currentModule = 'user-rights';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// Fetch all users for dropdown
$stmt = $conn->prepare("SELECT user_id, full_name, email, status FROM tbl_users ORDER BY full_name ASC");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all active modules
$stmt = $conn->prepare("SELECT module_id, module_name, module_slug FROM tbl_modules WHERE is_active = 1 ORDER BY display_order ASC");
$stmt->execute();
$modules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$targetUserId = intval($_GET['target_user_id'] ?? 0);
$userPermissions = [];
$targetUserName = '';

if ($targetUserId > 0) {
    foreach ($users as $u) {
        if ($u['user_id'] == $targetUserId) {
            $targetUserName = $u['full_name'];
            break;
        }
    }
    
    // Load permissions
    $stmt = $conn->prepare("SELECT module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report FROM tbl_user_permissions WHERE user_id = ?");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $userPermissions[$row['module_id']] = $row;
    }
    $stmt->close();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>User Rights Management</h1>
        <p class="page-header-desc">Dynamically manage module and action-level permissions for any user.</p>
    </div>
</div>

<div class="card animate-in" style="margin-bottom: 20px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex; gap:15px; align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0; flex-grow:1; max-width: 400px;">
                <label class="form-label" for="target_user_id">Select User</label>
                <select name="target_user_id" id="target_user_id" class="form-control" required>
                    <option value="">— Select a User —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= $targetUserId === (int)$u['user_id'] ? 'selected' : '' ?>>
                            <?= e($u['full_name']) ?> (<?= e($u['email']) ?>) - <?= ucfirst($u['status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-download"></i> Load Permissions</button>
            </div>
        </form>
    </div>
</div>

<?php if ($targetUserId > 0): ?>
    <form method="POST" action="<?= BASE_URL ?>/admin/user_rights_actions.php" id="permissionsForm">
        <?= csrf_field() ?>
        <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
        
        <div class="card animate-in">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;"><i class="fa-solid fa-shield-halved"></i> Permissions for <?= e($targetUserName) ?></h3>
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="selectAll()"><i class="fa-solid fa-check-double"></i> Select All</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="clearAll()"><i class="fa-solid fa-eraser"></i> Clear All</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-save"></i> Save Permissions</button>
                </div>
            </div>
            
            <div class="table-wrapper" style="overflow-x:auto;">
                <table class="data-table" id="permissionsTable" style="min-width: 800px;">
                    <thead style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th style="width: 200px;">Module</th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('view')" title="Select all View permissions">View <i class="fa-solid fa-caret-down text-muted"></i></th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('create')" title="Select all Create permissions">Create <i class="fa-solid fa-caret-down text-muted"></i></th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('edit')" title="Select all Edit permissions">Edit <i class="fa-solid fa-caret-down text-muted"></i></th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('delete')" title="Select all Delete permissions">Delete <i class="fa-solid fa-caret-down text-muted"></i></th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('approve')" title="Select all Approve permissions">Approve <i class="fa-solid fa-caret-down text-muted"></i></th>
                            <th style="text-align:center; cursor:pointer;" onclick="selectColumn('report')" title="Select all Reports permissions">Reports <i class="fa-solid fa-caret-down text-muted"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $actions = ['view', 'create', 'edit', 'delete', 'approve', 'report'];
                        foreach ($modules as $m): 
                            $mId = $m['module_id'];
                            $perms = $userPermissions[$mId] ?? [];
                        ?>
                            <tr class="module-row" data-module-id="<?= $mId ?>">
                                <td style="font-weight:600; cursor:pointer;" onclick="selectRow(<?= $mId ?>)" title="Select all permissions for <?= e($m['module_name']) ?>">
                                    <?= e($m['module_name']) ?> <i class="fa-solid fa-caret-right text-muted" style="float:right; margin-top:4px;"></i>
                                    <input type="hidden" name="modules[<?= $mId ?>]" value="<?= $mId ?>">
                                </td>
                                <?php foreach ($actions as $act): 
                                    $isChecked = !empty($perms["can_{$act}"]) ? 'checked' : '';
                                ?>
                                    <td style="text-align:center;">
                                        <label class="perm-checkbox">
                                            <input type="checkbox" class="perm-chk act-<?= $act ?>" name="perms[<?= $mId ?>][can_<?= $act ?>]" value="1" <?= $isChecked ?> onchange="updateColors(this); updateSummary();">
                                            <span class="perm-box"></span>
                                        </label>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="background:var(--surface-hover); text-align:right; font-weight:600; color:var(--text-primary);">
                <span id="summaryText">Total Permissions Assigned: 0</span>
            </div>
        </div>
    </form>
<?php endif; ?>

<style>
.perm-checkbox {
    display: inline-block;
    position: relative;
    cursor: pointer;
    width: 24px;
    height: 24px;
}
.perm-checkbox input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}
.perm-box {
    position: absolute;
    top: 0;
    left: 0;
    height: 24px;
    width: 24px;
    background-color: #e5e7eb; /* Gray */
    border-radius: 4px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.perm-checkbox input:checked ~ .perm-box {
    background-color: var(--color-success); /* Green */
}
.perm-checkbox input:checked ~ .perm-box:after {
    content: '\f00c';
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    color: white;
    font-size: 14px;
}
</style>

<script>
function updateSummary() {
    const checkedBoxes = document.querySelectorAll('.perm-chk:checked').length;
    const summaryText = document.getElementById('summaryText');
    if (summaryText) {
        summaryText.innerText = `Total Permissions Assigned: ${checkedBoxes}`;
    }
}

function updateColors(el) {
    // Styling is handled purely by CSS sibling selector, but we could add classes here if needed.
}

function selectAll() {
    document.querySelectorAll('.perm-chk').forEach(chk => {
        chk.checked = true;
    });
    updateSummary();
}

function clearAll() {
    document.querySelectorAll('.perm-chk').forEach(chk => {
        chk.checked = false;
    });
    updateSummary();
}

function selectRow(moduleId) {
    const row = document.querySelector(`.module-row[data-module-id="${moduleId}"]`);
    if (row) {
        const checkboxes = row.querySelectorAll('.perm-chk');
        const allChecked = Array.from(checkboxes).every(chk => chk.checked);
        checkboxes.forEach(chk => {
            chk.checked = !allChecked;
        });
        updateSummary();
    }
}

function selectColumn(actionName) {
    const checkboxes = document.querySelectorAll(`.act-${actionName}`);
    const allChecked = Array.from(checkboxes).every(chk => chk.checked);
    checkboxes.forEach(chk => {
        chk.checked = !allChecked;
    });
    updateSummary();
}

// Initialize summary on load
document.addEventListener('DOMContentLoaded', () => {
    updateSummary();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
