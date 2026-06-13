<?php
/**
 * Permission Check Helpers - Mini ERP System
 * Functions to load and verify module-level permissions for the current user.
 */

/**
 * Load all permissions for a given role.
 * Returns an associative array: [module_slug => [can_view, can_create, can_edit, can_delete]]
 */
function load_user_permissions(mysqli $conn, ?int $roleId): array {
    if (!$roleId) return [];

    $permissions = [];
    $stmt = $conn->prepare("
        SELECT m.module_slug, rp.can_view, rp.can_create, rp.can_edit, rp.can_delete
        FROM tbl_role_permissions rp
        JOIN tbl_modules m ON rp.module_id = m.module_id
        WHERE rp.role_id = ? AND m.is_active = 1
    ");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $permissions[$row['module_slug']] = [
            'can_view'   => (bool)$row['can_view'],
            'can_create' => (bool)$row['can_create'],
            'can_edit'   => (bool)$row['can_edit'],
            'can_delete' => (bool)$row['can_delete'],
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
        set_flash('error', 'You do not have permission to access this resource.');
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
    $roleId = $_SESSION['role_id'] ?? null;
    if (!$roleId) return [];

    $stmt = $conn->prepare("
        SELECT m.module_name, m.module_slug, m.module_icon, m.display_order
        FROM tbl_role_permissions rp
        JOIN tbl_modules m ON rp.module_id = m.module_id
        WHERE rp.role_id = ? AND rp.can_view = 1 AND m.is_active = 1
        ORDER BY m.display_order ASC
    ");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $modules = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $modules;
}
?>
