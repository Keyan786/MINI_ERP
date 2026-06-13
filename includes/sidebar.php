<?php
/**
 * Sidebar Include - Mini ERP System
 * Role-aware navigation sidebar.
 */

$accessibleModules = get_accessible_modules($conn);

// Count pending approval requests (for admin badge)
$pendingCount = 0;
if (is_admin()) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM tbl_user_approval_requests WHERE status = 'pending'");
    $pendingCount = $result->fetch_assoc()['cnt'] ?? 0;
}

// Map module slugs to their URLs
$moduleUrls = [
    'dashboard'         => '/dashboard/index.php',
    'products'          => '/modules/products/index.php',
    'sales'             => '/modules/sales/index.php',
    'purchase'          => '/modules/purchase/index.php',
    'manufacturing'     => '/modules/manufacturing/index.php',
    'bom'               => '/modules/bom/index.php',
    'inventory'         => '/modules/inventory/index.php',
    'user-management'   => '/admin/users.php',
    'audit-log'         => '/admin/audit_log.php',
];

// Get user initials for avatar
$userName = $_SESSION['user_name'] ?? 'User';
$initials = '';
$nameParts = explode(' ', $userName);
foreach ($nameParts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);
?>

<aside class="sidebar" id="main-sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="logo-icon"><i class="fa-solid fa-cubes"></i></div>
        <div>
            <div class="brand-text"><?= APP_NAME ?></div>
            <div class="brand-version">v<?= APP_VERSION ?></div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="sidebar-nav-label">Main Menu</div>

        <?php foreach ($accessibleModules as $module): ?>
            <?php
                $slug = $module['module_slug'];
                $url = BASE_URL . ($moduleUrls[$slug] ?? '/dashboard/index.php');
                $isActive = ($currentModule === $slug) ? 'active' : '';
            ?>
            <a href="<?= $url ?>" class="sidebar-link <?= $isActive ?>" id="nav-<?= e($slug) ?>">
                <i class="<?= e($module['module_icon']) ?>"></i>
                <span><?= e($module['module_name']) ?></span>
                <?php if ($slug === 'user-management' && $pendingCount > 0): ?>
                    <span class="badge-count"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- User Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= $initials ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= e($userName) ?></div>
                <div class="sidebar-user-role"><?= e($_SESSION['role_name'] ?? 'User') ?></div>
            </div>
            <a href="<?= BASE_URL ?>/auth/logout.php" title="Logout" style="color: var(--text-muted); font-size: 0.875rem;" onclick="return confirm('Log out?')">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
