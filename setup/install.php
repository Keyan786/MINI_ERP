<?php
/**
 * Database Installation Script - Mini ERP System
 * Creates all tables, indexes, and seeds initial data.
 * Run this script once during system setup.
 *
 * Usage: php setup/install.php
 * Or visit: http://localhost/MiniERP/setup/install.php
 */

// Database connection without database name (to create it if needed)
$db_host = "localhost";
$db_user = "root";
$db_pass = "@MEH2004meh";
$db_name = "ERP";

$conn = new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$isCli = (php_sapi_name() === 'cli');
$output = [];

function out($msg, $type = 'info') {
    global $isCli, $output;
    if ($isCli) {
        $prefix = match($type) {
            'success' => "\033[32m✓\033[0m",
            'error'   => "\033[31m✗\033[0m",
            'warning' => "\033[33m⚠\033[0m",
            default   => "\033[34mℹ\033[0m"
        };
        echo "$prefix $msg\n";
    } else {
        $output[] = ['type' => $type, 'msg' => $msg];
    }
}

// ─── Create Database ────────────────────────────────────────────────────────
out("Creating database '$db_name' if not exists...");
$conn->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($db_name);
out("Database '$db_name' ready.", 'success');

// ─── Drop existing tables (in correct FK order) ────────────────────────────
out("Dropping existing tables if any...");
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$tables = [
    'tbl_password_resets', 'tbl_audit_log', 'tbl_user_sessions',
    'tbl_user_approval_requests', 'tbl_role_permissions',
    'tbl_users', 'tbl_modules', 'tbl_roles'
];
foreach ($tables as $table) {
    $conn->query("DROP TABLE IF EXISTS `$table`");
}
$conn->query("SET FOREIGN_KEY_CHECKS = 1");
out("Existing tables dropped.", 'success');

// ═══════════════════════════════════════════════════════════════════════════
// TABLE CREATION
// ═══════════════════════════════════════════════════════════════════════════

// ─── 1. tbl_roles ───────────────────────────────────────────────────────────
out("Creating table: tbl_roles...");
$conn->query("
    CREATE TABLE `tbl_roles` (
        `role_id` INT NOT NULL AUTO_INCREMENT,
        `role_name` VARCHAR(50) NOT NULL,
        `role_description` TEXT NULL,
        `is_system_role` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`role_id`),
        UNIQUE KEY `uk_role_name` (`role_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_roles created.", 'success');

// ─── 2. tbl_modules ────────────────────────────────────────────────────────
out("Creating table: tbl_modules...");
$conn->query("
    CREATE TABLE `tbl_modules` (
        `module_id` INT NOT NULL AUTO_INCREMENT,
        `module_name` VARCHAR(100) NOT NULL,
        `module_slug` VARCHAR(50) NOT NULL,
        `module_icon` VARCHAR(50) NULL,
        `display_order` INT NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`module_id`),
        UNIQUE KEY `uk_module_slug` (`module_slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_modules created.", 'success');

// ─── 3. tbl_role_permissions ────────────────────────────────────────────────
out("Creating table: tbl_role_permissions...");
$conn->query("
    CREATE TABLE `tbl_role_permissions` (
        `permission_id` INT NOT NULL AUTO_INCREMENT,
        `role_id` INT NOT NULL,
        `module_id` INT NOT NULL,
        `can_view` TINYINT(1) NOT NULL DEFAULT 0,
        `can_create` TINYINT(1) NOT NULL DEFAULT 0,
        `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
        `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`permission_id`),
        UNIQUE KEY `uk_role_module` (`role_id`, `module_id`),
        CONSTRAINT `fk_perm_role` FOREIGN KEY (`role_id`) REFERENCES `tbl_roles`(`role_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_perm_module` FOREIGN KEY (`module_id`) REFERENCES `tbl_modules`(`module_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_role_permissions created.", 'success');

// ─── 4. tbl_users ──────────────────────────────────────────────────────────
out("Creating table: tbl_users...");
$conn->query("
    CREATE TABLE `tbl_users` (
        `user_id` INT NOT NULL AUTO_INCREMENT,
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(150) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `phone` VARCHAR(20) NULL,
        `role_id` INT NULL,
        `status` ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending',
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `rejection_reason` TEXT NULL,
        `last_login_at` DATETIME NULL,
        `last_login_ip` VARCHAR(45) NULL,
        `failed_login_attempts` INT NOT NULL DEFAULT 0,
        `locked_until` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`),
        UNIQUE KEY `uk_user_email` (`email`),
        KEY `idx_user_status` (`status`),
        KEY `idx_user_email_status` (`email`, `status`),
        CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `tbl_roles`(`role_id`) ON DELETE SET NULL,
        CONSTRAINT `fk_user_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_users created.", 'success');

// ─── 5. tbl_user_approval_requests ─────────────────────────────────────────
out("Creating table: tbl_user_approval_requests...");
$conn->query("
    CREATE TABLE `tbl_user_approval_requests` (
        `request_id` INT NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `request_type` ENUM('registration','role_change','reactivation') NOT NULL DEFAULT 'registration',
        `current_role_id` INT NULL,
        `requested_role_id` INT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `reviewed_by` INT NULL,
        `reviewed_at` DATETIME NULL,
        `assigned_role_id` INT NULL,
        `rejection_reason` TEXT NULL,
        `remarks` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`request_id`),
        KEY `idx_approval_user` (`user_id`),
        KEY `idx_approval_status` (`status`),
        KEY `idx_approval_type` (`request_type`),
        CONSTRAINT `fk_approval_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users`(`user_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_approval_current_role` FOREIGN KEY (`current_role_id`) REFERENCES `tbl_roles`(`role_id`) ON DELETE SET NULL,
        CONSTRAINT `fk_approval_requested_role` FOREIGN KEY (`requested_role_id`) REFERENCES `tbl_roles`(`role_id`) ON DELETE SET NULL,
        CONSTRAINT `fk_approval_assigned_role` FOREIGN KEY (`assigned_role_id`) REFERENCES `tbl_roles`(`role_id`) ON DELETE SET NULL,
        CONSTRAINT `fk_approval_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_user_approval_requests created.", 'success');

// ─── 6. tbl_user_sessions ──────────────────────────────────────────────────
out("Creating table: tbl_user_sessions...");
$conn->query("
    CREATE TABLE `tbl_user_sessions` (
        `session_id` VARCHAR(128) NOT NULL,
        `user_id` INT NOT NULL,
        `ip_address` VARCHAR(45) NOT NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`session_id`),
        KEY `idx_session_user` (`user_id`),
        KEY `idx_session_active` (`is_active`),
        CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_user_sessions created.", 'success');

// ─── 7. tbl_audit_log ──────────────────────────────────────────────────────
out("Creating table: tbl_audit_log...");
$conn->query("
    CREATE TABLE `tbl_audit_log` (
        `log_id` BIGINT NOT NULL AUTO_INCREMENT,
        `user_id` INT NULL,
        `user_name` VARCHAR(100) NOT NULL,
        `module` VARCHAR(100) NOT NULL,
        `action` VARCHAR(50) NOT NULL,
        `record_type` VARCHAR(50) NULL,
        `record_id` INT NULL,
        `old_values` JSON NULL,
        `new_values` JSON NULL,
        `ip_address` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `idx_audit_user` (`user_id`),
        KEY `idx_audit_module` (`module`),
        KEY `idx_audit_action` (`action`),
        KEY `idx_audit_created` (`created_at`),
        KEY `idx_audit_module_action` (`module`, `action`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_audit_log created.", 'success');

// ─── 8. tbl_password_resets ────────────────────────────────────────────────
out("Creating table: tbl_password_resets...");
$conn->query("
    CREATE TABLE `tbl_password_resets` (
        `reset_id` INT NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `token_hash` VARCHAR(255) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`reset_id`),
        KEY `idx_reset_user` (`user_id`),
        KEY `idx_reset_expires` (`expires_at`),
        CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
out("Table tbl_password_resets created.", 'success');

// ═══════════════════════════════════════════════════════════════════════════
// SEED DATA
// ═══════════════════════════════════════════════════════════════════════════

// ─── Seed Roles ─────────────────────────────────────────────────────────────
out("Seeding roles...");
$roles = [
    ['Admin', 'Full system access. Manages users, roles, permissions, and all modules.', 1],
    ['Purchase User', 'Manages purchase orders, supplier interactions, and procurement workflows.', 0],
    ['Sales User', 'Manages sales orders, customer interactions, and order fulfillment.', 0],
    ['Manufacturing User', 'Manages production orders, work orders, and manufacturing processes.', 0],
    ['Inventory Manager', 'Manages stock levels, inventory movements, and warehouse operations.', 0],
    ['Business Owner', 'Read-only access to dashboards, reports, and business analytics across all modules.', 0],
];

$stmt = $conn->prepare("INSERT INTO `tbl_roles` (`role_name`, `role_description`, `is_system_role`) VALUES (?, ?, ?)");
foreach ($roles as $role) {
    $stmt->bind_param("ssi", $role[0], $role[1], $role[2]);
    $stmt->execute();
}
$stmt->close();
out("6 roles seeded.", 'success');

// ─── Seed Modules ───────────────────────────────────────────────────────────
out("Seeding modules...");
$modules = [
    ['Dashboard',           'dashboard',            'fa-solid fa-gauge-high',       1],
    ['Product Management',  'products',             'fa-solid fa-boxes-stacked',    2],
    ['Sales',               'sales',                'fa-solid fa-cart-shopping',    3],
    ['Purchase',            'purchase',             'fa-solid fa-truck-field',      4],
    ['Manufacturing',       'manufacturing',        'fa-solid fa-industry',         5],
    ['Bill of Materials',   'bom',                  'fa-solid fa-list-check',       6],
    ['Inventory',           'inventory',            'fa-solid fa-warehouse',        7],
    ['User Management',     'user-management',      'fa-solid fa-users-gear',       8],
    ['Audit Log',           'audit-log',            'fa-solid fa-clipboard-list',   9],
];

$stmt = $conn->prepare("INSERT INTO `tbl_modules` (`module_name`, `module_slug`, `module_icon`, `display_order`) VALUES (?, ?, ?, ?)");
foreach ($modules as $mod) {
    $stmt->bind_param("sssi", $mod[0], $mod[1], $mod[2], $mod[3]);
    $stmt->execute();
}
$stmt->close();
out("9 modules seeded.", 'success');

// ─── Seed Admin Permissions (full access to all modules) ────────────────────
out("Seeding admin permissions...");
$adminRoleId = 1; // Admin is always ID 1
$moduleResult = $conn->query("SELECT `module_id` FROM `tbl_modules`");
$stmt = $conn->prepare("INSERT INTO `tbl_role_permissions` (`role_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`) VALUES (?, ?, 1, 1, 1, 1)");
while ($mod = $moduleResult->fetch_assoc()) {
    $stmt->bind_param("ii", $adminRoleId, $mod['module_id']);
    $stmt->execute();
}
$stmt->close();
out("Admin permissions set for all modules.", 'success');

// ─── Seed Default Role Permissions ──────────────────────────────────────────
out("Seeding default role permissions...");

// Role permission map: [role_name => [module_slug => [view, create, edit, delete]]]
$rolePermissions = [
    'Purchase User' => [
        'dashboard'  => [1, 0, 0, 0],
        'products'   => [1, 0, 0, 0],
        'purchase'   => [1, 1, 1, 1],
        'inventory'  => [1, 0, 0, 0],
    ],
    'Sales User' => [
        'dashboard'  => [1, 0, 0, 0],
        'products'   => [1, 0, 0, 0],
        'sales'      => [1, 1, 1, 1],
        'inventory'  => [1, 0, 0, 0],
    ],
    'Manufacturing User' => [
        'dashboard'      => [1, 0, 0, 0],
        'products'       => [1, 0, 0, 0],
        'manufacturing'  => [1, 1, 1, 1],
        'bom'            => [1, 1, 1, 1],
        'inventory'      => [1, 1, 1, 0],
    ],
    'Inventory Manager' => [
        'dashboard'  => [1, 0, 0, 0],
        'products'   => [1, 1, 1, 0],
        'inventory'  => [1, 1, 1, 1],
        'purchase'   => [1, 0, 0, 0],
    ],
    'Business Owner' => [
        'dashboard'      => [1, 0, 0, 0],
        'products'       => [1, 0, 0, 0],
        'sales'          => [1, 0, 0, 0],
        'purchase'       => [1, 0, 0, 0],
        'manufacturing'  => [1, 0, 0, 0],
        'bom'            => [1, 0, 0, 0],
        'inventory'      => [1, 0, 0, 0],
    ],
];

foreach ($rolePermissions as $roleName => $modules_perms) {
    // Get role_id
    $stmt_role = $conn->prepare("SELECT `role_id` FROM `tbl_roles` WHERE `role_name` = ?");
    $stmt_role->bind_param("s", $roleName);
    $stmt_role->execute();
    $roleId = $stmt_role->get_result()->fetch_assoc()['role_id'];
    $stmt_role->close();

    foreach ($modules_perms as $slug => $perms) {
        // Get module_id
        $stmt_mod = $conn->prepare("SELECT `module_id` FROM `tbl_modules` WHERE `module_slug` = ?");
        $stmt_mod->bind_param("s", $slug);
        $stmt_mod->execute();
        $modId = $stmt_mod->get_result()->fetch_assoc()['module_id'];
        $stmt_mod->close();

        $stmt_perm = $conn->prepare("INSERT INTO `tbl_role_permissions` (`role_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_perm->bind_param("iiiiii", $roleId, $modId, $perms[0], $perms[1], $perms[2], $perms[3]);
        $stmt_perm->execute();
        $stmt_perm->close();
    }
}
out("Default role permissions seeded.", 'success');

// ─── Seed Admin User ────────────────────────────────────────────────────────
out("Creating admin user...");
$adminEmail = 'admin@gmail.com';
$adminPassword = password_hash('123456', PASSWORD_BCRYPT, ['cost' => 12]);
$adminName = 'System Administrator';

$stmt = $conn->prepare("INSERT INTO `tbl_users` (`full_name`, `email`, `password_hash`, `role_id`, `status`, `approved_by`, `approved_at`) VALUES (?, ?, ?, 1, 'active', NULL, NOW())");
$stmt->bind_param("sss", $adminName, $adminEmail, $adminPassword);
$stmt->execute();
$adminUserId = $conn->insert_id;
$stmt->close();

// Self-reference: admin approved themselves
$conn->query("UPDATE `tbl_users` SET `approved_by` = $adminUserId WHERE `user_id` = $adminUserId");

out("Admin user created (admin@gmail.com).", 'success');

// ─── Seed Admin Audit Log Entry ─────────────────────────────────────────────
out("Creating initial audit log entry...");
$stmt = $conn->prepare("INSERT INTO `tbl_audit_log` (`user_id`, `user_name`, `module`, `action`, `record_type`, `record_id`, `new_values`, `ip_address`) VALUES (?, 'System', 'System', 'INSTALL', 'System', NULL, ?, ?)");
$installInfo = json_encode(['version' => '1.0.0', 'tables_created' => 8, 'roles_seeded' => 6, 'modules_seeded' => 9]);
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$stmt->bind_param("iss", $adminUserId, $installInfo, $ip);
$stmt->execute();
$stmt->close();
out("Audit log initialized.", 'success');

// ─── Summary ────────────────────────────────────────────────────────────────
out("");
out("═══════════════════════════════════════════════════");
out("  Mini ERP Installation Complete!", 'success');
out("═══════════════════════════════════════════════════");
out("  Tables created: 8");
out("  Roles seeded: 6");
out("  Modules seeded: 9");
out("  Admin email: admin@gmail.com");
out("  Admin password: 123456");
out("═══════════════════════════════════════════════════");
out("");

$conn->close();

// ─── HTML Output (for browser) ──────────────────────────────────────────────
if (!$isCli && !empty($output)) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Mini ERP - Installation</title>';
    echo '<style>body{background:#0f172a;color:#e2e8f0;font-family:monospace;padding:40px;font-size:14px}';
    echo '.line{padding:4px 0}.success{color:#22c55e}.error{color:#ef4444}.warning{color:#f59e0b}.info{color:#3b82f6}</style></head><body>';
    echo '<h1 style="color:#3b82f6">Mini ERP — Installation</h1><hr style="border-color:#1e293b">';
    foreach ($output as $line) {
        $icon = match($line['type']) {
            'success' => '✓',
            'error' => '✗',
            'warning' => '⚠',
            default => 'ℹ'
        };
        echo '<div class="line ' . $line['type'] . '">' . $icon . ' ' . htmlspecialchars($line['msg']) . '</div>';
    }
    echo '</body></html>';
}
?>
