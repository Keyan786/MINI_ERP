<?php
/**
 * Edit Product - Mini ERP System
 * Edit product with field-level audit logging for all critical fields.
 */

$pageTitle = 'Edit Product';
$currentModule = 'products';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$productId = intval($_GET['id'] ?? 0);
if ($productId <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect('/modules/products/index.php');
}

// Fetch product
$stmt = $conn->prepare("SELECT p.*, c.category_name FROM tbl_products p LEFT JOIN tbl_product_categories c ON p.category_id = c.category_id WHERE p.product_id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/modules/products/index.php');
}

$errors = [];

// Fetch categories & vendors
$categories = $conn->query("SELECT category_id, category_name FROM tbl_product_categories WHERE is_active = 1 ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM tbl_vendors WHERE is_active = 1 ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

// ─── Handle POST ────────────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $productName = trim($_POST['product_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 0) ?: null;
        $uom = trim($_POST['uom'] ?? 'Pcs');
        $salesPrice = floatval($_POST['sales_price'] ?? 0);
        $costPrice = floatval($_POST['cost_price'] ?? 0);
        $minStockLevel = floatval($_POST['min_stock_level'] ?? 0);
        $procureOnDemand = isset($_POST['procure_on_demand']) ? 1 : 0;
        $procurementType = !empty($_POST['procurement_type']) ? $_POST['procurement_type'] : null;
        $defaultVendorId = intval($_POST['default_vendor_id'] ?? 0) ?: null;

        if (empty($productName)) $errors[] = 'Product name is required.';
        if ($salesPrice < 0) $errors[] = 'Sales price cannot be negative.';
        if ($costPrice < 0) $errors[] = 'Cost price cannot be negative.';
        if ($procureOnDemand && empty($procurementType)) $errors[] = 'Procurement type is required when Procure on Demand is enabled.';
        if ($procurementType === 'purchase' && !$defaultVendorId) $errors[] = 'Default vendor is required for Purchase procurement type.';

        if (!$procureOnDemand) {
            $procurementType = null;
            $defaultVendorId = null;
        }

        if (empty($errors)) {
            // Prepare new data for audit comparison
            $newData = [
                'product_name' => $productName,
                'sales_price' => $salesPrice,
                'cost_price' => $costPrice,
                'category_id' => $categoryId,
                'uom' => $uom,
                'min_stock_level' => $minStockLevel,
                'procure_on_demand' => $procureOnDemand,
                'procurement_type' => $procurementType,
                'default_vendor_id' => $defaultVendorId,
                'default_bom_id' => $product['default_bom_id'],
            ];

            // Field-level audit diff
            $auditDiff = audit_product_changes($product, $newData, get_product_tracked_fields());

            $userId = $_SESSION['user_id'];
            $stmt = $conn->prepare("UPDATE tbl_products SET product_name = ?, description = ?, category_id = ?, uom = ?, sales_price = ?, cost_price = ?, min_stock_level = ?, procure_on_demand = ?, procurement_type = ?, default_vendor_id = ?, updated_by = ? WHERE product_id = ?");
            $stmt->bind_param("ssisddissiiii",
                $productName, $description, $categoryId, $uom,
                $salesPrice, $costPrice, $minStockLevel,
                $procureOnDemand, $procurementType, $defaultVendorId,
                $userId, $productId
            );

            if ($stmt->execute()) {
                // Log with field-level diff
                if ($auditDiff) {
                    log_action($conn, 'Product Management', ACTION_UPDATE, 'Product', $productId,
                        $auditDiff['old_values'], $auditDiff['new_values']);
                }

                set_flash('success', 'Product updated successfully.');
                redirect('/modules/products/view.php?id=' . $productId);
            } else {
                $errors[] = 'Failed to update product.';
            }
            $stmt->close();
        }
    }
}

$freeQty = get_free_qty($product['on_hand_qty'], $product['reserved_qty']);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Edit Product</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $productId ?>" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to <?= e($product['product_name']) ?>
            </a>
        </p>
    </div>
    <span style="font-family:'Fira Code',monospace; font-size:0.875rem; color:var(--accent-primary); font-weight:500;"><?= e($product['product_code']) ?></span>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background:var(--color-danger-bg); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); padding:14px 16px; margin-bottom:20px; font-size:0.8125rem; color:var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" action="" id="edit-product-form">
    <?= csrf_field() ?>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
        <!-- Basic Info -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-box" style="color:var(--accent-primary); margin-right:8px;"></i>Basic Information</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Product Code</label>
                    <input type="text" class="form-control" value="<?= e($product['product_code']) ?>" disabled style="opacity:0.6;">
                    <span class="form-hint">Product code cannot be changed.</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="product_name">Product Name <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" id="product_name" class="form-control" required maxlength="200"
                           value="<?= e($product['product_name']) ?>">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="category_id">Category</label>
                        <select name="category_id" id="category_id" class="form-control form-select">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>" <?= $product['category_id'] == $cat['category_id'] ? 'selected' : '' ?>><?= e($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="uom">Unit of Measure</label>
                        <select name="uom" id="uom" class="form-control form-select">
                            <?php foreach (get_uom_list() as $u): ?>
                                <option value="<?= $u ?>" <?= $product['uom'] === $u ? 'selected' : '' ?>><?= $u ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3"><?= e($product['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Pricing & Stock -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-dollar-sign" style="color:var(--color-success); margin-right:8px;"></i>Pricing & Stock</h3>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="sales_price">Sales Price</label>
                        <input type="number" name="sales_price" id="sales_price" class="form-control" step="0.01" min="0"
                               value="<?= $product['sales_price'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cost_price">Cost Price</label>
                        <input type="number" name="cost_price" id="cost_price" class="form-control" step="0.01" min="0"
                               value="<?= $product['cost_price'] ?>">
                    </div>
                </div>

                <!-- Read-only stock info -->
                <div style="background:var(--accent-ultra-light); border:1px solid rgba(37,99,235,0.1); border-radius:var(--border-radius-sm); padding:16px; margin-bottom:16px;">
                    <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px;">Current Stock Levels</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">On-Hand</div>
                            <div style="font-size:1.125rem; font-weight:600; color:var(--text-heading);"><?= fmt_qty($product['on_hand_qty']) ?></div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Reserved</div>
                            <div style="font-size:1.125rem; font-weight:600; color:var(--color-warning);"><?= fmt_qty($product['reserved_qty']) ?></div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">Free-to-Use</div>
                            <div style="font-size:1.125rem; font-weight:600; color:var(--color-success);"><?= fmt_qty($freeQty) ?></div>
                        </div>
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:8px;">
                        <i class="fa-solid fa-info-circle" style="margin-right:4px;"></i>
                        Stock levels are managed via stock adjustments and order processing.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="min_stock_level">Min Stock Level</label>
                    <input type="number" name="min_stock_level" id="min_stock_level" class="form-control" step="0.001" min="0"
                           value="<?= $product['min_stock_level'] ?>">
                    <span class="form-hint">Reorder point. Status turns "Low Stock" when free-to-use drops to this level.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Procurement Config -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-truck-field" style="color:var(--color-info); margin-right:8px;"></i>Procurement Configuration</h3>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem;">
                <input type="checkbox" name="procure_on_demand" id="procure_on_demand" <?= $product['procure_on_demand'] ? 'checked' : '' ?>
                       onchange="document.getElementById('procurement-fields').style.display = this.checked ? 'block' : 'none';"
                       style="accent-color:var(--accent-primary); width:16px; height:16px;">
                <span>Enable Procure on Demand</span>
            </label>
        </div>
        <div id="procurement-fields" style="display:<?= $product['procure_on_demand'] ? 'block' : 'none' ?>;">
            <div class="card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="procurement_type">Procurement Type</label>
                        <select name="procurement_type" id="procurement_type" class="form-control form-select"
                                onchange="document.getElementById('vendor-field').style.display = this.value === 'purchase' ? 'block' : 'none'; document.getElementById('bom-field').style.display = this.value === 'manufacturing' ? 'block' : 'none';">
                            <option value="">— Select Type —</option>
                            <option value="purchase" <?= $product['procurement_type'] === 'purchase' ? 'selected' : '' ?>>Purchase (Buy from Vendor)</option>
                            <option value="manufacturing" <?= $product['procurement_type'] === 'manufacturing' ? 'selected' : '' ?>>Manufacturing (Produce in-house)</option>
                        </select>
                    </div>
                    <div class="form-group" id="vendor-field" style="display:<?= $product['procurement_type'] === 'purchase' ? 'block' : 'none' ?>;">
                        <label class="form-label" for="default_vendor_id">Default Vendor</label>
                        <select name="default_vendor_id" id="default_vendor_id" class="form-control form-select">
                            <option value="">— Select Vendor —</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['vendor_id'] ?>" <?= $product['default_vendor_id'] == $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="bom-field" style="display:<?= $product['procurement_type'] === 'manufacturing' ? 'block' : 'none' ?>;">
                        <label class="form-label">Default Bill of Materials</label>
                        <div style="padding:10px 14px; background:var(--bg-card-hover); border:1px solid var(--border-color); border-radius:var(--border-radius-sm); font-size:0.8125rem; color:var(--text-muted);">
                            <i class="fa-solid fa-info-circle" style="margin-right:6px;"></i>
                            BoM module not yet available.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;" class="animate-in">
        <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $productId ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Changes</button>
    </div>
</form>

<style>
    @media (max-width: 1024px) {
        #edit-product-form > div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
