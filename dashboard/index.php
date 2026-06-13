<?php
/**
 * Role-Based Dashboard - Mini ERP System
 * Routes to appropriate dashboard based on user role.
 * Non-admin users see a generic dashboard with their accessible modules.
 */

$pageTitle = 'Dashboard';
$currentModule = 'dashboard';

require_once __DIR__ . '/../includes/auth_check.php';

// Admin gets redirected to admin dashboard
if (is_admin()) {
    header("Location: " . BASE_URL . "/admin/dashboard.php");
    exit;
}

// Get accessible modules for the current user
$accessibleModules = get_accessible_modules($conn);

// Module URL map
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

// Color map for module cards
$moduleColors = [
    'dashboard'       => 'blue',
    'products'        => 'purple',
    'sales'           => 'green',
    'purchase'        => 'amber',
    'manufacturing'   => 'cyan',
    'bom'             => 'red',
    'inventory'       => 'green',
    'user-management' => 'blue',
    'audit-log'       => 'purple',
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Welcome, <?= e($_SESSION['user_name'] ?? 'User') ?></h1>
        <p class="page-header-desc">
            <span class="badge badge-primary" style="margin-right:6px;"><?= e($_SESSION['role_name'] ?? 'User') ?></span>
            Here's your dashboard overview
        </p>
    </div>
</div>

<!-- Quick Access Modules -->
<div class="stat-grid animate-in">
    <?php foreach ($accessibleModules as $module): ?>
        <?php if ($module['module_slug'] === 'dashboard') continue; // Skip dashboard itself ?>
        <?php
            $url = BASE_URL . ($moduleUrls[$module['module_slug']] ?? '#');
            $color = $moduleColors[$module['module_slug']] ?? 'blue';
        ?>
        <a href="<?= $url ?>" class="stat-card" style="text-decoration:none; cursor:pointer;">
            <div class="stat-card-icon <?= $color ?>">
                <i class="<?= e($module['module_icon']) ?>"></i>
            </div>
            <div class="stat-card-label" style="font-size:0.9375rem; font-weight:600; color:var(--text-primary); margin-top:8px;">
                <?= e($module['module_name']) ?>
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                Click to open <i class="fa-solid fa-arrow-right" style="margin-left:4px; font-size:0.625rem;"></i>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- Info Card -->
<div class="card animate-in">
    <div class="card-body" style="text-align:center; padding:40px;">
        <div style="font-size:2.5rem; margin-bottom:16px; opacity:0.5;">
            <i class="fa-solid fa-cubes"></i>
        </div>
        <h3 style="margin-bottom:8px;">Mini ERP System</h3>
        <p style="color:var(--text-muted); font-size:0.875rem; max-width:500px; margin:0 auto;">
            Use the sidebar or quick access cards above to navigate to your assigned modules.
            Module content will be available as features are implemented.
        </p>
        <div style="margin-top:20px; display:flex; gap:16px; justify-content:center; flex-wrap:wrap;">
            <div style="font-size:0.75rem; color:var(--text-muted);">
                <i class="fa-solid fa-user" style="margin-right:4px;"></i>
                <?= e($_SESSION['user_name'] ?? '') ?>
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted);">
                <i class="fa-solid fa-envelope" style="margin-right:4px;"></i>
                <?= e($_SESSION['user_email'] ?? '') ?>
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted);">
                <i class="fa-solid fa-shield-halved" style="margin-right:4px;"></i>
                <?= e($_SESSION['role_name'] ?? '') ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
