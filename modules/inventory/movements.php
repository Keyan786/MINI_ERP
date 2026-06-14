<?php
/**
 * Stock Movement Log - Mini ERP System
 * Filterable log of all inventory movements.
 */

$pageTitle = 'Stock Movements';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// ─── Filters ────────────────────────────────────────────────────────────────
$productFilter = intval($_GET['product'] ?? 0);
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];
$types = "";

if ($productFilter > 0) {
    $where[] = "sm.product_id = ?";
    $params[] = $productFilter;
    $types .= "i";
}
if (!empty($typeFilter)) {
    $where[] = "sm.movement_type = ?";
    $params[] = $typeFilter;
    $types .= "s";
}
if (!empty($dateFrom)) {
    $where[] = "sm.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= "s";
}
if (!empty($dateTo)) {
    $where[] = "sm.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_stock_movements sm WHERE $whereClause");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($total / $perPage));

$params[] = $perPage; $params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare("
    SELECT sm.*, p.product_name, p.product_code, p.uom, u.full_name as user_name, w.warehouse_name
    FROM tbl_stock_movements sm
    JOIN tbl_products p ON sm.product_id = p.product_id
    LEFT JOIN tbl_users u ON sm.created_by = u.user_id
    LEFT JOIN tbl_warehouses w ON sm.warehouse_id = w.warehouse_id
    WHERE $whereClause
    ORDER BY sm.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch products for filter dropdown
$allProducts = $conn->query("SELECT product_id, product_name, product_code FROM tbl_products ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

$moveTypes = ['initial', 'purchase_in', 'manufacturing_in', 'sales_out', 'manufacturing_consume', 'adjustment'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Stock Movements</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/inventory/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Inventory
            </a>
        </p>
    </div>
    <span class="badge badge-primary" style="font-size:0.875rem;"><?= $total ?> movements</span>
</div>

<!-- Filters -->
<div class="card animate-in" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:1; min-width:180px;">
                <label class="form-label" style="font-size:0.75rem;">Product</label>
                <select name="product" class="form-control form-select" style="padding:8px 12px;">
                    <option value="0">All Products</option>
                    <?php foreach ($allProducts as $ap): ?>
                        <option value="<?= $ap['product_id'] ?>" <?= $productFilter == $ap['product_id'] ? 'selected' : '' ?>><?= e($ap['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:150px;">
                <label class="form-label" style="font-size:0.75rem;">Type</label>
                <select name="type" class="form-control form-select" style="padding:8px 12px;">
                    <option value="">All Types</option>
                    <?php foreach ($moveTypes as $mt): ?>
                        <option value="<?= $mt ?>" <?= $typeFilter === $mt ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $mt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:140px;">
                <label class="form-label" style="font-size:0.75rem;">From</label>
                <input type="date" name="date_from" class="form-control" style="padding:8px 12px;" value="<?= e($dateFrom) ?>">
            </div>
            <div style="min-width:140px;">
                <label class="form-label" style="font-size:0.75rem;">To</label>
                <input type="date" name="date_to" class="form-control" style="padding:8px 12px;" value="<?= e($dateTo) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="<?= BASE_URL ?>/modules/inventory/movements.php" class="btn btn-secondary btn-sm">Clear</a>
        </form>
    </div>
</div>

<!-- Movement Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Movement Type</th>
                    <th>Warehouse</th>
                    <th>Direction</th>
                    <th>Reference Number</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Qty Before</th>
                    <th style="text-align:right;">Qty After</th>
                    <th style="text-align:right;">Unit Cost</th>
                    <th style="text-align:right;">Movement Value</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="12"><div class="empty-state" style="padding:40px;"><div class="empty-state-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div><h3>No Movements</h3><p>No stock movements match the current filters.</p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $mv): ?>
                        <tr>
                            <td style="font-size:0.8125rem; white-space:nowrap;"><?= format_datetime($mv['created_at']) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $mv['product_id'] ?>" style="font-weight:500; color:var(--text-primary);"><?= e($mv['product_name']) ?></a>
                                <div style="font-size:0.7rem; font-family:'Fira Code',monospace; color:var(--accent-primary);"><?= e($mv['product_code']) ?></div>
                            </td>
                            <td><span class="badge badge-primary"><?= e(str_replace('_', ' ', ucfirst($mv['movement_type']))) ?></span></td>
                            <td style="font-size:0.8125rem; font-weight:500; color:var(--text-secondary);"><?= $mv['warehouse_name'] ? e($mv['warehouse_name']) : 'Global' ?></td>
                            <td>
                                <?php if ($mv['quantity'] > 0): ?>
                                    <span class="badge badge-success" style="font-size: 0.6875rem;"><i class="fa-solid fa-arrow-down"></i> IN</span>
                                <?php elseif ($mv['quantity'] < 0): ?>
                                    <span class="badge badge-danger" style="font-size: 0.6875rem;"><i class="fa-solid fa-arrow-up"></i> OUT</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="font-size: 0.6875rem;">NO CHANGE</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted); font-weight: 500;">
                                <?= $mv['reference_type'] ? e($mv['reference_type']) . ($mv['reference_id'] ? ' #' . $mv['reference_id'] : '') : 'Manual' ?>
                            </td>
                            <td style="text-align:right; font-weight:600; font-size:0.9375rem; color:var(--color-<?= $mv['quantity'] >= 0 ? 'success' : 'danger' ?>);">
                                <?= $mv['quantity'] >= 0 ? '+' : '' ?><?= fmt_qty($mv['quantity']) ?>
                                <span style="font-size:0.7rem; color:var(--text-muted); font-weight:400;"><?= e($mv['uom']) ?></span>
                            </td>
                            <td style="text-align:right; font-size:0.8125rem; color:var(--text-muted);"><?= fmt_qty($mv['qty_before']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem; font-weight:500;"><?= fmt_qty($mv['qty_after']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem; font-weight:500;"><?= $mv['unit_cost'] !== null ? fmt_price($mv['unit_cost']) : '—' ?></td>
                            <td style="text-align:right; font-size:0.8125rem; font-weight:600; color:var(--text-primary);"><?= $mv['movement_value'] !== null ? fmt_price($mv['movement_value']) : '—' ?></td>
                            <td style="font-size:0.8125rem;"><?= $mv['user_name'] ? e($mv['user_name']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
