<?php
/**
 * Report Damage / Scrap - Mini ERP System
 * Write off damaged or scrapped inventory.
 */

$pageTitle = 'Report Damage / Scrap';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];
$old = $_POST;

// Fetch products for selector
$products = $conn->query("SELECT product_id, product_code, product_name, uom FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

// Fetch warehouses
$warehouses = $conn->query("SELECT warehouse_id, warehouse_name FROM tbl_warehouses WHERE is_active = 1 ORDER BY warehouse_name")->fetch_all(MYSQLI_ASSOC);

// Build JSON data for client-side display per warehouse
$stockData = [];
$res = $conn->query("SELECT product_id, warehouse_id, on_hand_qty, reserved_qty FROM tbl_product_warehouse_stock");
while ($r = $res->fetch_assoc()) {
    $free = get_free_qty((float)$r['on_hand_qty'], (float)$r['reserved_qty']);
    $stockData[$r['warehouse_id']][$r['product_id']] = [
        'on_hand' => (float)$r['on_hand_qty'],
        'reserved' => (float)$r['reserved_qty'],
        'free_to_use' => $free,
    ];
}

$productUomMap = [];
foreach ($products as $p) {
    $productUomMap[$p['product_id']] = $p['uom'];
}

// ─── Handle POST ────────────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $productId = intval($_POST['product_id'] ?? 0);
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if ($productId <= 0) $errors[] = 'Please select a product.';
        if ($warehouseId <= 0) $errors[] = 'Please select a warehouse.';
        if ($quantity <= 0) $errors[] = 'Quantity must be greater than zero.';
        if (empty($notes)) $errors[] = 'Reason/notes is required for stock adjustments.';

        if (empty($errors)) {
            // Fetch product for validation
            $stmt = $conn->prepare("SELECT product_name, product_code, on_hand_qty, reserved_qty FROM tbl_products WHERE product_id = ? AND is_active = 1");
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                $errors[] = 'Product not found or inactive.';
            } else {
                // Fetch warehouse stock
                $stmt = $conn->prepare("SELECT on_hand_qty, reserved_qty FROM tbl_product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?");
                $stmt->bind_param("ii", $productId, $warehouseId);
                $stmt->execute();
                $ws = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $wsOnHand = $ws ? (float)$ws['on_hand_qty'] : 0.000;
                $wsReserved = $ws ? (float)$ws['reserved_qty'] : 0.000;

                // ─── Negative Stock Prevention ──────────────────────────────
                $freeToUse = get_free_qty($wsOnHand, $wsReserved);
                if ($quantity > $freeToUse) {
                    $errors[] = 'Cannot write off ' . fmt_qty($quantity) . ' units. Only ' 
                              . fmt_qty($freeToUse) . ' units are available (Free-to-Use) in this warehouse. '
                              . fmt_qty($wsReserved) . ' units are reserved by active orders.';
                }
                // ─── End Negative Stock Prevention ──────────────────────────

                if (empty($errors)) {
                    $actualQty = -$quantity;
                    $userId = $_SESSION['user_id'];

                    $conn->begin_transaction();
                    try {
                        update_stock($conn, $productId, $warehouseId, $actualQty, 'damage_out', 'Manual', null, $notes, $userId);

                        // Audit log
                        log_action($conn, 'Inventory', ACTION_STOCK_ADJUST, 'Product', $productId, 
                            ['on_hand_qty' => $wsOnHand],
                            [
                                'warehouse_id' => $warehouseId,
                                'adjustment_type' => 'damage_out',
                                'quantity' => $quantity,
                                'on_hand_qty' => $wsOnHand + $actualQty,
                                'reason' => $notes,
                            ]
                        );

                        $conn->commit();
                        set_flash('success', 'Successfully wrote off ' . fmt_qty($quantity) . ' units of ' . $product['product_code'] . ' — ' . $product['product_name']);
                        redirect('/modules/inventory/damage.php');

                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = 'Failed to process adjustment: ' . $ex->getMessage();
                    }
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Report Damage / Scrap</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/inventory/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Inventory
            </a>
        </p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background:var(--color-danger-bg); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); padding:14px 16px; margin-bottom:20px; font-size:0.8125rem; color:var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div style="max-width:700px;">
    <form method="POST" action="" id="adjust-form">
        <?= csrf_field() ?>

        <div class="card animate-in">
            <div class="card-header">
                <h3><i class="fa-solid fa-sliders" style="color:var(--accent-primary); margin-right:8px;"></i>Adjustment Details</h3>
            </div>
            <div class="card-body">
                <!-- Warehouse Selector -->
                <div class="form-group">
                    <label class="form-label" for="warehouse_id">Target Warehouse <span class="text-danger">*</span></label>
                    <select name="warehouse_id" id="warehouse_id" class="form-control form-select" required onchange="updateProductInfo()">
                        <option value="">— Select Warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['warehouse_id'] ?>" <?= intval($old['warehouse_id'] ?? 0) == $w['warehouse_id'] ? 'selected' : '' ?>>
                                <?= e($w['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product Selector -->
                <div class="form-group">
                    <label class="form-label" for="product_id">Product <span class="text-danger">*</span></label>
                    <select name="product_id" id="product_id" class="form-control form-select" required onchange="updateProductInfo()">
                        <option value="">— Select Product —</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['product_id'] ?>" <?= intval($old['product_id'] ?? 0) == $p['product_id'] ? 'selected' : '' ?>>
                                <?= e($p['product_code']) ?> — <?= e($p['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product Stock Info (dynamic) -->
                <div id="product-info" style="display:none; background:var(--accent-ultra-light); border:1px solid rgba(37,99,235,0.1); border-radius:var(--border-radius-sm); padding:16px; margin-bottom:16px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; text-align:center;">
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">On-Hand</div>
                            <div id="info-on-hand" style="font-size:1.25rem; font-weight:700; color:var(--text-heading);">—</div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Reserved</div>
                            <div id="info-reserved" style="font-size:1.25rem; font-weight:700; color:var(--color-warning);">—</div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Free-to-Use</div>
                            <div id="info-free" style="font-size:1.25rem; font-weight:700; color:var(--color-success);">—</div>
                        </div>
                    </div>
                    <div id="info-uom" style="font-size:0.75rem; color:var(--text-muted); text-align:center; margin-top:6px;">—</div>
                </div>

                <!-- Adjustment Type -->
                <div class="form-group">
                    <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                    <div style="display:flex; gap:12px;">
                        <label style="display:flex; align-items:center; gap:8px; padding:12px 20px; border:2px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; flex:1; transition:all 0.2s;" class="adjust-type-label">
                            <input type="radio" name="adjust_type" value="add" <?= ($old['adjust_type'] ?? '') === 'add' ? 'checked' : '' ?> style="accent-color:var(--color-success);width:16px;height:16px;" onchange="updateValidation()">
                            <div>
                                <div style="font-weight:600; color:var(--color-success);"><i class="fa-solid fa-plus-circle"></i> Add Stock</div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Increase inventory</div>
                            </div>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px 20px; border:2px solid var(--border-color); border-radius:var(--border-radius-sm); cursor:pointer; flex:1; transition:all 0.2s;" class="adjust-type-label">
                            <input type="radio" name="adjust_type" value="remove" <?= ($old['adjust_type'] ?? '') === 'remove' ? 'checked' : '' ?> style="accent-color:var(--color-danger);width:16px;height:16px;" onchange="updateValidation()">
                            <div>
                                <div style="font-weight:600; color:var(--color-danger);"><i class="fa-solid fa-minus-circle"></i> Remove Stock</div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Decrease inventory</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Quantity -->
                <div class="form-group">
                    <label class="form-label" for="quantity">Damage/Scrap Quantity <span class="text-danger">*</span></label>
                    <input type="number" step="0.001" min="0.001" name="quantity" id="quantity" class="form-control" required value="<?= e($old['quantity'] ?? '') ?>" placeholder="e.g. 5.00" oninput="updateValidation()">
                    <div id="qty-warning" style="display:none; margin-top:6px; padding:8px 12px; background:rgba(220,38,38,0.06); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); font-size:0.8125rem; color:var(--color-danger);">
                        <i class="fa-solid fa-exclamation-triangle" style="margin-right:4px;"></i>
                        <span id="qty-warning-text"></span>
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="notes">Reason / Notes <span class="text-danger">*</span></label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" required placeholder="Describe why this is being written off..."><?= e($old['notes'] ?? '') ?></textarea>
                    <span class="form-hint">Required for audit trail purposes.</span>
                </div>
            </div>
            <div class="card-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" id="submit-btn" class="btn btn-danger"><i class="fa-solid fa-trash-can"></i> Write Off Stock</button>
            </div>
        </div>
    </form>
</div>

<script>
const stockData = <?= json_encode($stockData) ?>;
const productUomMap = <?= json_encode($productUomMap) ?>;

function updateProductInfo() {
    const warehouseId = document.getElementById('warehouse_id').value;
    const productId = document.getElementById('product_id').value;
    const info = document.getElementById('product-info');
    
    if (!warehouseId || !productId) {
        info.style.display = 'none';
        updateValidation();
        return;
    }
    
    const pStock = (stockData[warehouseId] && stockData[warehouseId][productId]) || {on_hand: 0, reserved: 0, free_to_use: 0};
    const uom = productUomMap[productId] || 'Unit';

    info.style.display = 'block';
    document.getElementById('info-on-hand').textContent = pStock.on_hand.toFixed(3).replace(/\.?0+$/, '');
    document.getElementById('info-reserved').textContent = pStock.reserved.toFixed(3).replace(/\.?0+$/, '');
    document.getElementById('info-free').textContent = pStock.free_to_use.toFixed(3).replace(/\.?0+$/, '');
    document.getElementById('info-uom').textContent = 'Unit: ' + uom;
    updateValidation();
}

function updateValidation() {
    const warehouseId = document.getElementById('warehouse_id').value;
    const productId = document.getElementById('product_id').value;
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const warning = document.getElementById('qty-warning');
    const submitBtn = document.getElementById('submit-btn');
    
    if (warehouseId && productId) {
        const pStock = (stockData[warehouseId] && stockData[warehouseId][productId]) || {on_hand: 0, reserved: 0, free_to_use: 0};
        const freeToUse = pStock.free_to_use;
        const reserved = pStock.reserved;
        if (quantity > freeToUse) {
            warning.style.display = 'block';
            document.getElementById('qty-warning-text').textContent = 
                'Cannot remove ' + quantity + ' units. Only ' + freeToUse.toFixed(3).replace(/\.?0+$/, '') + 
                ' units are available (Free-to-Use) in this warehouse. ' + reserved.toFixed(3).replace(/\.?0+$/, '') + 
                ' units are reserved.';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            return;
        }
    }
    warning.style.display = 'none';
    submitBtn.disabled = false;
    submitBtn.style.opacity = '1';
}

// Init on page load
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('product_id');
    if (sel.value) updateProductInfo(sel.value);
});
</script>

<style>
    .adjust-type-label:has(input:checked) {
        border-color: var(--accent-primary) !important;
        background: var(--accent-ultra-light);
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
