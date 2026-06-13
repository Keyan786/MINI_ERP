<?php
/**
 * Permission Check Helpers - Mini ERP System
 * Functions to load and verify module-level permissions for the current user.
 */

/**
 * Load all permissions for a given user.
 * Returns an associative array: [module_slug => [can_view, can_create, can_edit, can_delete, can_approve, can_report]]
 */
function load_user_permissions(mysqli $conn, ?int $userId): array {
    if (!$userId) return [];

    $permissions = [];
    $stmt = $conn->prepare("
        SELECT m.module_slug, up.can_view, up.can_create, up.can_edit, up.can_delete, up.can_approve, up.can_report
        FROM tbl_user_permissions up
        JOIN tbl_modules m ON up.module_id = m.module_id
        WHERE up.user_id = ? AND m.is_active = 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $permissions[$row['module_slug']] = [
            'can_view'   => (bool)$row['can_view'],
            'can_create' => (bool)$row['can_create'],
            'can_edit'   => (bool)$row['can_edit'],
            'can_delete' => (bool)$row['can_delete'],
            'can_approve'=> (bool)$row['can_approve'],
            'can_report' => (bool)$row['can_report'],
        ];
    }
    $stmt->close();
    return $permissions;
}

/**
 * Check if the current user can perform an action on a module.
 * @param string $moduleSlug The module slug (e.g., 'sales', 'inventory')
 * @param string $action One of: 'view', 'create', 'edit', 'delete'
 */
function has_permission(string $moduleSlug, string $action = 'view'): bool {
    $permissions = $_SESSION['permissions'] ?? [];
    $key = 'can_' . $action;
    return isset($permissions[$moduleSlug][$key]) && $permissions[$moduleSlug][$key] === true;
}

/**
 * Require a specific permission, or redirect with error.
 */
function require_permission(string $moduleSlug, string $action = 'view'): void {
    if (!has_permission($moduleSlug, $action)) {
        set_flash('error', 'Access Denied. You do not have permission to perform this action.');
        redirect('/dashboard/index.php');
    }
}

/**
 * Check if current user is an Admin.
 */
function is_admin(): bool {
    return ($_SESSION['role_name'] ?? '') === 'Admin';
}

/**
 * Require admin role, or redirect with error.
 */
function require_admin(): void {
    if (!is_admin()) {
        set_flash('error', 'This area is restricted to administrators.');
        redirect('/dashboard/index.php');
    }
}

/**
 * Get all accessible modules for the current user (for sidebar).
 */
function get_accessible_modules(mysqli $conn): array {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return [];

    $stmt = $conn->prepare("
        SELECT m.module_name, m.module_slug, m.module_icon, m.display_order
        FROM tbl_user_permissions up
        JOIN tbl_modules m ON up.module_id = m.module_id
        WHERE up.user_id = ? AND up.can_view = 1 AND m.is_active = 1
        ORDER BY m.display_order ASC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $modules = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $modules;
}
?>
