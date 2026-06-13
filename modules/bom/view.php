<?php
/**
 * View Bill of Materials - Mini ERP System
 * Read-only detail page with component list and linked MOs.
 */

$pageTitle = 'Bill of Materials';
$currentModule = 'bom';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$bomId = intval($_GET['id'] ?? 0);
if ($bomId <= 0) {
    set_flash('error', 'Invalid BoM ID.');
    redirect('/modules/bom/index.php');
}

// Fetch BoM header
$stmt = $conn->prepare("
    SELECT b.*, p.product_name, p.product_code, p.default_bom_id,
           cu.full_name as creator_name, uu.full_name as updater_name
    FROM tbl_bom b
    LEFT JOIN tbl_products p ON b.product_id = p.product_id
    LEFT JOIN tbl_users cu ON b.created_by = cu.user_id
    LEFT JOIN tbl_users uu ON b.updated_by = uu.user_id
    WHERE b.bom_id = ?
");
$stmt->bind_param("i", $bomId);
$stmt->execute();
$bom = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bom) {
    set_flash('error', 'Bill of Materials not found.');
    redirect('/modules/bom/index.php');
}

// Fetch component lines
$stmt = $conn->prepare("
    SELECT bl.*, p.product_name, p.product_code, p.on_hand_qty, p.reserved_qty, p.cost_price
    FROM tbl_bom_lines bl
    LEFT JOIN tbl_products p ON bl.product_id = p.product_id
    WHERE bl.bom_id = ?
    ORDER BY bl.sort_order ASC, bl.bom_line_id ASC
");
$stmt->bind_param("i", $bomId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch linked Manufacturing Orders
$stmt = $conn->prepare("
    SELECT mo.mo_id, mo.mo_number, mo.quantity, mo.produced_qty, mo.status, mo.created_at
    FROM tbl_manufacturing_orders mo
    WHERE mo.bom_id = ?
    ORDER BY mo.created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $bomId);
$stmt->execute();
$linkedMOs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$isDefault = ($bom['default_bom_id'] == $bomId);

$pageTitle = 'BoM ' . $bom['bom_code'];
include __DIR__ . '/../../includes/header.php';

function mo_status_badge_mini(string $status): string {
    $map = [
        'draft'     => ['Draft', 'badge-secondary'],
        'confirmed' => ['Confirmed', 'badge-info'],
        'done'      => ['Done', 'badge-success'],
        'cancelled' => ['Cancelled', 'badge-danger'],
    ];
    $d = $map[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $d[1] . '">' . $d[0] . '</span>';
}
?>

<div class="page-header animate-in">
    <div>
        <h1><?= e($bom['bom_code']) ?></h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/bom/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to BoM List
            </a>
        </p>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
        <?php if ($bom['is_active']): ?>
            <span class="badge badge-success">Active</span>
        <?php else: ?>
            <span class="badge badge-secondary">Inactive</span>
        <?php endif; ?>
        <?php if ($isDefault): ?>
            <span class="badge badge-info"><i class="fa-solid fa-star" style="margin-right:4px;"></i>Default BoM</span>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/bom/edit.php?id=<?= $bomId ?>" class="btn btn-primary">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
    </div>
</div>

<!-- Header Info Grid -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
    <!-- Product Info -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-boxes-stacked" style="color:var(--accent-primary); margin-right:8px;"></i>Product & Recipe</h3>
        </div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">BoM Code</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace;"><?= e($bom['bom_code']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">BoM Name</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);"><?= e($bom['bom_name']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Finished Product</span>
                    <span style="font-size:0.8125rem; font-weight:500; color:var(--text-primary);">
                        <?= e($bom['product_name'] ?? '—') ?>
                        <span style="font-family:'Fira Code',monospace; color:var(--text-muted); font-size:0.75rem; margin-left:6px;"><?= e($bom['product_code'] ?? '') ?></span>
                    </span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Base Quantity</span>
                    <span style="font-size:0.8125rem; font-weight:500; color:var(--text-primary);"><?= fmt_qty($bom['quantity']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Standard Time</span>
                    <span style="font-size:0.8125rem; font-weight:500; color:var(--text-primary);"><?= number_format($bom['standard_time_minutes'], 1) ?> min</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Details -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-circle-info" style="color:var(--color-warning); margin-right:8px;"></i>Details</h3>
        </div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Created</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($bom['created_at']) ?> by <?= e($bom['creator_name'] ?? 'System') ?></span>
                </div>
                <?php if ($bom['updated_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Last Updated</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($bom['updated_at']) ?> by <?= e($bom['updater_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Components</span>
                    <span class="badge badge-info"><?= count($lines) ?></span>
                </div>
                <?php if ($bom['notes']): ?>
                <div style="padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted); display:block; margin-bottom:4px;">Notes</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= nl2br(e($bom['notes'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Component Lines -->
<div class="card animate-in" style="margin-top:20px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-cubes" style="color:var(--accent-primary); margin-right:8px;"></i>Components</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>UOM</th>
                    <th style="text-align:right;">Qty per Base</th>
                    <th style="text-align:right;">Unit Cost</th>
                    <th style="text-align:right;">On Hand</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lines)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; color:var(--text-muted); padding:24px;">No components defined.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lines as $i => $line): ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:0.8125rem;"><?= $i + 1 ?></td>
                            <td>
                                <span style="font-weight:600; color:var(--text-primary);"><?= e($line['product_name'] ?? '—') ?></span>
                                <div style="font-size:0.75rem; font-family:'Fira Code',monospace; color:var(--accent-primary);"><?= e($line['product_code'] ?? '') ?></div>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($line['uom']) ?></td>
                            <td style="text-align:right; font-weight:500;"><?= fmt_qty($line['quantity']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem;"><?= fmt_price($line['cost_price'] ?? 0) ?></td>
                            <td style="text-align:right; font-size:0.8125rem;">
                                <?= fmt_qty($line['on_hand_qty'] ?? 0) ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($line['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Linked Manufacturing Orders -->
<?php if (!empty($linkedMOs)): ?>
<div class="card animate-in" style="margin-top:20px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-industry" style="color:var(--color-warning); margin-right:8px;"></i>Linked Manufacturing Orders</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>MO Number</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Produced</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linkedMOs as $mo): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/manufacturing/view.php?id=<?= $mo['mo_id'] ?>"
                               style="font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace; font-size:0.8125rem;">
                                <?= e($mo['mo_number']) ?>
                            </a>
                        </td>
                        <td style="text-align:right; font-weight:500;"><?= fmt_qty($mo['quantity']) ?></td>
                        <td style="text-align:right; font-weight:500; color:var(--color-success);"><?= fmt_qty($mo['produced_qty']) ?></td>
                        <td><?= mo_status_badge_mini($mo['status']) ?></td>
                        <td style="font-size:0.8125rem; color:var(--text-muted);"><?= format_datetime($mo['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
