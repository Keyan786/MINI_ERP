<?php
/**
 * Inventory Overview - Mini ERP System
 * Dashboard with stock level stats and product stock table.
 */

$pageTitle = 'Inventory';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// ─── Stat counts ────────────────────────────────────────────────────────────
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN (on_hand_qty - reserved_qty) > min_stock_level AND is_active = 1 THEN 1 ELSE 0 END) as in_stock,
        SUM(CASE WHEN (on_hand_qty - reserved_qty) > 0 AND (on_hand_qty - reserved_qty) <= min_stock_level AND is_active = 1 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN (on_hand_qty - reserved_qty) <= 0 AND is_active = 1 THEN 1 ELSE 0 END) as out_of_stock
    FROM tbl_products WHERE is_active = 1
")->fetch_assoc();

// ─── Product stock table ────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$stockFilter = $_GET['stock'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["p.is_active = 1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s;
    $types .= "ss";
}

if ($stockFilter === 'in_stock') {
    $where[] = "(pws.on_hand_qty - pws.reserved_qty) > p.min_stock_level";
} elseif ($stockFilter === 'low_stock') {
    $where[] = "(pws.on_hand_qty - pws.reserved_qty) > 0 AND (pws.on_hand_qty - pws.reserved_qty) <= p.min_stock_level";
} elseif ($stockFilter === 'out_of_stock') {
    $where[] = "(pws.on_hand_qty - pws.reserved_qty) <= 0";
}

$whereClause = implode(" AND ", $where);

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_product_warehouse_stock pws JOIN tbl_products p ON pws.product_id = p.product_id WHERE $whereClause");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalItems = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalItems / $perPage));

$params[] = $perPage; $params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare("
    SELECT pws.*, p.product_name, p.product_code, p.uom, p.min_stock_level, c.category_name, w.warehouse_name 
    FROM tbl_product_warehouse_stock pws
    JOIN tbl_products p ON pws.product_id = p.product_id
    JOIN tbl_warehouses w ON pws.warehouse_id = w.warehouse_id
    LEFT JOIN tbl_product_categories c ON p.category_id = c.category_id 
    WHERE $whereClause 
    ORDER BY (pws.on_hand_qty - pws.reserved_qty) ASC, p.product_name ASC, w.warehouse_name ASC 
    LIMIT ? OFFSET ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Inventory Overview</h1>
        <p class="page-header-desc">Monitor stock levels and inventory health</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/inventory/warehouses.php" class="btn btn-secondary">
            <i class="fa-solid fa-warehouse"></i> Warehouses
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/transfers.php" class="btn btn-secondary">
            <i class="fa-solid fa-truck-moving"></i> Transfers
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/adjust.php" class="btn btn-primary">
            <i class="fa-solid fa-sliders"></i> Adjust Stock
        </a>
        <a href="<?= BASE_URL ?>/modules/inventory/movements.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-right-arrow-left"></i> Movements
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid animate-in">
    <div class="stat-card">
        <div class="stat-card-icon blue"><i class="fa-solid fa-cubes"></i></div>
        <div class="stat-card-value"><?= $stats['total'] ?></div>
        <div class="stat-card-label">Total Products</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon green"><i class="fa-solid fa-check-circle"></i></div>
        <div class="stat-card-value"><?= $stats['in_stock'] ?></div>
        <div class="stat-card-label">In Stock</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon amber"><i class="fa-solid fa-exclamation-triangle"></i></div>
        <div class="stat-card-value"><?= $stats['low_stock'] ?></div>
        <div class="stat-card-label">Low Stock</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon red"><i class="fa-solid fa-times-circle"></i></div>
        <div class="stat-card-value"><?= $stats['out_of_stock'] ?></div>
        <div class="stat-card-label">Out of Stock</div>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'all', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'all' ? 'active' : '' ?>">All</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'in_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'in_stock' ? 'active' : '' ?>">In Stock</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'low_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'low_stock' ? 'active' : '' ?>">Low Stock</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'out_of_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'out_of_stock' ? 'active' : '' ?>">Out of Stock</a>
    </div>
    <div class="search-input">
        <i class="fa-solid fa-search"></i>
        <form method="GET">
            <?php if ($stockFilter !== 'all'): ?><input type="hidden" name="stock" value="<?= e($stockFilter) ?>"><?php endif; ?>
            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= e($search) ?>">
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Warehouse</th>
                    <th>Category</th>
                    <th style="text-align:right;">On-Hand</th>
                    <th style="text-align:right;">Reserved</th>
                    <th style="text-align:right;">Free-to-Use</th>
                    <th style="text-align:right;">Min Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="7"><div class="empty-state" style="padding:40px;"><p style="color:var(--text-muted);">No products match the filter.</p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php $free = get_free_qty($p['on_hand_qty'], $p['reserved_qty']); ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $p['product_id'] ?>" style="font-weight:600; color:var(--text-primary);">
                                    <?= e($p['product_name']) ?>
                                </a>
                                <div style="font-size:0.75rem; font-family:'Fira Code',monospace; color:var(--accent-primary);"><?= e($p['product_code']) ?></div>
                            </td>
                            <td style="font-size:0.85rem; font-weight:500;"><span class="badge badge-secondary"><?= e($p['warehouse_name']) ?></span></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $p['category_name'] ? e($p['category_name']) : '—' ?></td>
                            <td style="text-align:right; font-weight:500;"><?= fmt_qty($p['on_hand_qty']) ?> <span style="font-size:0.75rem;color:var(--text-muted);"><?= e($p['uom']) ?></span></td>
                            <td style="text-align:right; color:var(--color-warning); font-weight:500;"><?= fmt_qty($p['reserved_qty']) ?></td>
                            <td style="text-align:right; font-weight:600; color:var(--color-<?= $free > 0 ? 'success' : 'danger' ?>);"><?= fmt_qty($free) ?></td>
                            <td style="text-align:right; color:var(--text-muted);"><?= fmt_qty($p['min_stock_level']) ?></td>
                            <td><?= stock_status_badge($free, $p['min_stock_level']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
