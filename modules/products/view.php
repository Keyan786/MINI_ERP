<?php
/**
 * Product Detail View - Mini ERP System
 * Shows product info, stock levels, and movement history.
 */

$pageTitle = 'Product Detail';
$currentModule = 'products';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$productId = intval($_GET['id'] ?? 0);
if ($productId <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect('/modules/products/index.php');
}

// Fetch product with joins
$stmt = $conn->prepare("
    SELECT p.*, c.category_name, v.vendor_name as default_vendor_name,
           uc.full_name as created_by_name, uu.full_name as updated_by_name
    FROM tbl_products p
    LEFT JOIN tbl_product_categories c ON p.category_id = c.category_id
    LEFT JOIN tbl_vendors v ON p.default_vendor_id = v.vendor_id
    LEFT JOIN tbl_users uc ON p.created_by = uc.user_id
    LEFT JOIN tbl_users uu ON p.updated_by = uu.user_id
    WHERE p.product_id = ?
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/modules/products/index.php');
}

$freeQty = get_free_qty($product['on_hand_qty'], $product['reserved_qty']);
$stockStatus = get_stock_status($freeQty, $product['min_stock_level']);

// Fetch recent stock movements
$movePage = max(1, intval($_GET['mpage'] ?? 1));
$movePerPage = 15;
$moveOffset = ($movePage - 1) * $movePerPage;

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_stock_movements WHERE product_id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$totalMoves = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalMovePages = max(1, ceil($totalMoves / $movePerPage));

$stmt = $conn->prepare("
    SELECT sm.*, u.full_name as user_name 
    FROM tbl_stock_movements sm 
    LEFT JOIN tbl_users u ON sm.created_by = u.user_id 
    WHERE sm.product_id = ? 
    ORDER BY sm.created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $productId, $movePerPage, $moveOffset);
$stmt->execute();
$movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = $product['product_name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1><?= e($product['product_name']) ?></h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/products/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Products
            </a>
            <span style="margin:0 8px; color:var(--border-color);">|</span>
            <span style="font-family:'Fira Code',monospace; color:var(--accent-primary); font-weight:500;"><?= e($product['product_code']) ?></span>
            <?php if (!$product['is_active']): ?>
                <span class="badge badge-secondary" style="margin-left:4px;">Inactive</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $productId ?>" class="btn btn-primary">
            <i class="fa-solid fa-pen-to-square"></i> Edit
        </a>
        <?php if ($product['is_active']): ?>
            <form method="POST" action="<?= BASE_URL ?>/modules/products/delete.php" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= $productId ?>">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Deactivate this product?')">
                    <i class="fa-solid fa-ban"></i> Deactivate
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid animate-in">
    <div class="stat-card">
        <div class="stat-card-icon blue"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="stat-card-value"><?= fmt_qty($product['on_hand_qty']) ?></div>
        <div class="stat-card-label">On-Hand (<?= e($product['uom']) ?>)</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon amber"><i class="fa-solid fa-lock"></i></div>
        <div class="stat-card-value"><?= fmt_qty($product['reserved_qty']) ?></div>
        <div class="stat-card-label">Reserved</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon green"><i class="fa-solid fa-box-open"></i></div>
        <div class="stat-card-value"><?= fmt_qty($freeQty) ?></div>
        <div class="stat-card-label">Free-to-Use</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon <?= $stockStatus === 'in_stock' ? 'green' : ($stockStatus === 'low_stock' ? 'amber' : 'red') ?>">
            <i class="fa-solid fa-<?= $stockStatus === 'in_stock' ? 'check-circle' : ($stockStatus === 'low_stock' ? 'exclamation-triangle' : 'times-circle') ?>"></i>
        </div>
        <div class="stat-card-value" style="font-size:1.125rem;"><?= stock_status_badge($freeQty, $product['min_stock_level']) ?></div>
        <div class="stat-card-label">Min Level: <?= fmt_qty($product['min_stock_level']) ?></div>
    </div>
</div>

<!-- Product Details -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
    <div class="card">
        <div class="card-header"><h3><i class="fa-solid fa-info-circle" style="color:var(--accent-primary); margin-right:8px;"></i>Product Information</h3></div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <?php
                $details = [
                    ['Category', $product['category_name'] ?? '—'],
                    ['Unit of Measure', $product['uom']],
                    ['Sales Price', fmt_price($product['sales_price'])],
                    ['Cost Price', fmt_price($product['cost_price'])],
                    ['Margin', $product['sales_price'] > 0 ? round((($product['sales_price'] - $product['cost_price']) / $product['sales_price']) * 100, 1) . '%' : '—'],
                    ['Description', $product['description'] ?: '—'],
                ];
                foreach ($details as $d): ?>
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                        <span style="font-size:0.8125rem; color:var(--text-muted);"><?= $d[0] ?></span>
                        <span style="font-size:0.8125rem; color:var(--text-primary); font-weight:500; text-align:right; max-width:60%;"><?= e($d[1]) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fa-solid fa-truck-field" style="color:var(--color-info); margin-right:8px;"></i>Procurement & Meta</h3></div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <?php
                $procDetails = [
                    ['Procure on Demand', $product['procure_on_demand'] ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-secondary">Disabled</span>'],
                    ['Procurement Type', $product['procurement_type'] ? ucfirst($product['procurement_type']) : '—'],
                    ['Default Vendor', $product['default_vendor_name'] ?? '—'],
                    ['Created By', $product['created_by_name'] ?? '—'],
                    ['Created At', format_datetime($product['created_at'])],
                    ['Last Modified By', $product['updated_by_name'] ?? '—'],
                    ['Last Modified At', $product['updated_at'] ? format_datetime($product['updated_at']) : '—'],
                ];
                foreach ($procDetails as $d): ?>
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                        <span style="font-size:0.8125rem; color:var(--text-muted);"><?= $d[0] ?></span>
                        <span style="font-size:0.8125rem; color:var(--text-primary); font-weight:500;"><?= $d[1] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Stock Movement History -->
<div class="card animate-in" style="margin-top:20px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-arrow-right-arrow-left" style="color:var(--color-warning); margin-right:8px;"></i>Stock Movement History</h3>
        <span class="badge badge-primary"><?= $totalMoves ?> total</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Before</th>
                    <th style="text-align:right;">After</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="8"><div class="empty-state" style="padding:30px;"><p style="color:var(--text-muted);">No stock movements recorded yet.</p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $mv): ?>
                        <tr>
                            <td style="font-size:0.8125rem; white-space:nowrap;"><?= format_datetime($mv['created_at']) ?></td>
                            <td><span class="badge badge-<?= in_array($mv['movement_type'], ['purchase_in','manufacturing_in','initial']) ? 'success' : (in_array($mv['movement_type'], ['sales_out','manufacturing_consume']) ? 'danger' : 'info') ?>"><?= e(str_replace('_', ' ', ucfirst($mv['movement_type']))) ?></span></td>
                            <td style="text-align:right; font-weight:600; color:var(--color-<?= $mv['quantity'] >= 0 ? 'success' : 'danger' ?>);">
                                <?= $mv['quantity'] >= 0 ? '+' : '' ?><?= fmt_qty($mv['quantity']) ?>
                            </td>
                            <td style="text-align:right; font-size:0.8125rem; color:var(--text-muted);"><?= fmt_qty($mv['qty_before']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem; font-weight:500;"><?= fmt_qty($mv['qty_after']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $mv['reference_type'] ? e($mv['reference_type']) . ($mv['reference_id'] ? ' #' . $mv['reference_id'] : '') : '—' ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= $mv['notes'] ? e($mv['notes']) : '—' ?></td>
                            <td style="font-size:0.8125rem;"><?= $mv['user_name'] ? e($mv['user_name']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalMovePages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalMovePages; $i++): ?>
                <a href="?id=<?= $productId ?>&mpage=<?= $i ?>" class="<?= $i === $movePage ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
