<?php
/**
 * Sidebar Include - Mini ERP System
 * Role-aware navigation sidebar.
 */

$accessibleModules = get_accessible_modules($conn);



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
    'user-rights'       => '/admin/user_rights.php',
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
        <div class="logo-icon"><img src="<?= BASE_URL ?>/assets/images/logo.jpeg" alt="Logo"></div>
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
                
                if ($slug === 'master-management'):
                    $isActive = in_array($currentModule, ['assignees', 'sales_persons', 'responsible_persons']) ? 'active' : '';
            ?>
                <div class="sidebar-dropdown">
                    <a href="javascript:void(0)" class="sidebar-link <?= $isActive ?>" id="nav-<?= e($slug) ?>" onclick="this.nextElementSibling.classList.toggle('show')">
                        <i class="<?= e($module['module_icon']) ?>"></i>
                        <span><?= e($module['module_name']) ?></span>
                        <i class="fa-solid fa-caret-down" style="margin-left: auto;"></i>
                    </a>
                    <div class="sidebar-submenu <?= $isActive ? 'show' : '' ?>" style="display: <?= $isActive ? 'block' : 'none' ?>; padding-left: 20px;">
                        <a href="<?= BASE_URL ?>/modules/masters/assignees.php" class="sidebar-link <?= $currentModule === 'assignees' ? 'active' : '' ?>" style="padding: 8px 15px; font-size: 0.85rem;"><i class="fa-solid fa-angle-right" style="font-size: 0.7rem; margin-right: 8px;"></i> Assignee Master</a>
                        <a href="<?= BASE_URL ?>/modules/masters/sales_persons.php" class="sidebar-link <?= $currentModule === 'sales_persons' ? 'active' : '' ?>" style="padding: 8px 15px; font-size: 0.85rem;"><i class="fa-solid fa-angle-right" style="font-size: 0.7rem; margin-right: 8px;"></i> Sales Person Master</a>
                        <a href="<?= BASE_URL ?>/modules/masters/responsible_persons.php" class="sidebar-link <?= $currentModule === 'responsible_persons' ? 'active' : '' ?>" style="padding: 8px 15px; font-size: 0.85rem;"><i class="fa-solid fa-angle-right" style="font-size: 0.7rem; margin-right: 8px;"></i> Responsible Person Master</a>
                    </div>
                </div>
                <script>
                    // Simple inline script to toggle display
                    document.getElementById('nav-<?= e($slug) ?>').addEventListener('click', function() {
                        const submenu = this.nextElementSibling;
                        if (submenu.style.display === 'none' || submenu.style.display === '') {
                            submenu.style.display = 'block';
                        } else {
                            submenu.style.display = 'none';
                        }
                    });
                </script>
            <?php else: ?>
                <?php
                    $url = BASE_URL . ($moduleUrls[$slug] ?? '/dashboard/index.php');
                    $isActive = ($currentModule === $slug) ? 'active' : '';
                ?>
                <a href="<?= $url ?>" class="sidebar-link <?= $isActive ?>" id="nav-<?= e($slug) ?>">
                    <i class="<?= e($module['module_icon']) ?>"></i>
                    <span><?= e($module['module_name']) ?></span>
                </a>
            <?php endif; ?>
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
