<?php
/**
 * User Rights Actions - Mini ERP System
 * Processes the saving of user permissions.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_admin();

if (!is_post()) {
    redirect('/admin/user_rights.php');
}

if (!csrf_validate()) {
    set_flash('error', 'Invalid security token.');
    redirect('/admin/user_rights.php');
}

$targetUserId = intval($_POST['target_user_id'] ?? 0);

if ($targetUserId <= 0) {
    set_flash('error', 'Invalid user selected.');
    redirect('/admin/user_rights.php');
}

// Fetch user
$stmt = $conn->prepare("SELECT full_name FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $targetUserId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('/admin/user_rights.php');
}

$modulesParam = $_POST['modules'] ?? [];
$permsParam = $_POST['perms'] ?? [];

$conn->begin_transaction();

try {
    // 1. Delete all existing permissions for this user
    $delStmt = $conn->prepare("DELETE FROM tbl_user_permissions WHERE user_id = ?");
    $delStmt->bind_param("i", $targetUserId);
    $delStmt->execute();
    $delStmt->close();

    // 2. Insert new permissions
    $insertStmt = $conn->prepare("INSERT INTO tbl_user_permissions (user_id, module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($modulesParam as $mId) {
        $mId = intval($mId);
        if ($mId > 0) {
            $p = $permsParam[$mId] ?? [];
            $cV = !empty($p['can_view']) ? 1 : 0;
            $cC = !empty($p['can_create']) ? 1 : 0;
            $cE = !empty($p['can_edit']) ? 1 : 0;
            $cD = !empty($p['can_delete']) ? 1 : 0;
            $cA = !empty($p['can_approve']) ? 1 : 0;
            $cR = !empty($p['can_report']) ? 1 : 0;

            // Only insert if they have at least one permission
            if ($cV || $cC || $cE || $cD || $cA || $cR) {
                $insertStmt->bind_param("iiiiiiii", $targetUserId, $mId, $cV, $cC, $cE, $cD, $cA, $cR);
                $insertStmt->execute();
            }
        }
    }
    $insertStmt->close();

    // 3. Update the permissions_updated_at to invalidate their session cache immediately
    $updStmt = $conn->prepare("UPDATE tbl_users SET permissions_updated_at = NOW() WHERE user_id = ?");
    $updStmt->bind_param("i", $targetUserId);
    $updStmt->execute();
    $updStmt->close();

    // 4. Log Action
    // Create an ACTION_PERMISSION_UPDATED constant if it doesn't exist, else use raw string.
    $actionName = defined('ACTION_PERMISSION_UPDATED') ? ACTION_PERMISSION_UPDATED : 'Permission Updated';
    log_action($conn, 'User Rights Management', $actionName, 'User', $targetUserId);

    $conn->commit();
    set_flash('success', 'Permissions successfully updated for ' . e($user['full_name']) . '.');

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Failed to update permissions: ' . $e->getMessage());
}

redirect('/admin/user_rights.php?target_user_id=' . $targetUserId);
