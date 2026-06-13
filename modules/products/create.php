<?php
/**
 * Create Product - Mini ERP System
 * Form to add a new product with pricing, stock, and procurement config.
 */

$pageTitle = 'Create Product';
$currentModule = 'products';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];
$old = $_POST;

// Fetch categories & vendors
$categories = $conn->query("SELECT category_id, category_name FROM tbl_product_categories WHERE is_active = 1 ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM tbl_vendors WHERE is_active = 1 ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

// Auto-suggest product code
$suggestedCode = generate_product_code($conn);

// ─── Handle POST ────────────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $productCode = trim($_POST['product_code'] ?? '');
        $productName = trim($_POST['product_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 0) ?: null;
        $uom = trim($_POST['uom'] ?? 'Pcs');
        $salesPrice = floatval($_POST['sales_price'] ?? 0);
        $costPrice = floatval($_POST['cost_price'] ?? 0);
        $initialQty = floatval($_POST['initial_qty'] ?? 0);
        $minStockLevel = floatval($_POST['min_stock_level'] ?? 0);
        $procureOnDemand = isset($_POST['procure_on_demand']) ? 1 : 0;
        $procurementType = !empty($_POST['procurement_type']) ? $_POST['procurement_type'] : null;
        $defaultVendorId = intval($_POST['default_vendor_id'] ?? 0) ?: null;
        $defaultBomId = null; // Future

        // Clear procurement fields if procure_on_demand is disabled
        if (!$procureOnDemand) {
            $procurementType = null;
            $defaultVendorId = null;
        }

        // Validation
        if (empty($productCode)) $errors[] = 'Product code is required.';
        if (empty($productName)) $errors[] = 'Product name is required.';
        if ($salesPrice < 0) $errors[] = 'Sales price cannot be negative.';
        if ($costPrice < 0) $errors[] = 'Cost price cannot be negative.';
        if ($initialQty < 0) $errors[] = 'Initial quantity cannot be negative.';
        if ($procureOnDemand && empty($procurementType)) $errors[] = 'Procurement type is required when Procure on Demand is enabled.';
        if ($procurementType === 'purchase' && !$defaultVendorId) $errors[] = 'Default vendor is required for Purchase procurement type.';

        if (empty($errors)) {
            // Get category name for code generation
            if ($categoryId) {
                $stmt = $conn->prepare("SELECT category_name FROM tbl_product_categories WHERE category_id = ?");
                $stmt->bind_param("i", $categoryId);
                $stmt->execute();
                $catRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            $conn->begin_transaction();
            try {
                $userId = $_SESSION['user_id'];
                $zeroQty = 0.0;
                $stmt = $conn->prepare("INSERT INTO tbl_products (product_code, product_name, description, category_id, uom, sales_price, cost_price, on_hand_qty, min_stock_level, procure_on_demand, procurement_type, default_vendor_id, default_bom_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisdddisisii",
                    $productCode, $productName, $description, $categoryId,
                    $uom, $salesPrice, $costPrice, $zeroQty, $minStockLevel,
                    $procureOnDemand, $procurementType, $defaultVendorId, $defaultBomId, $userId
                );
                $stmt->execute();
                $newProductId = $stmt->insert_id;
                $stmt->close();

                // Create initial stock movement if qty > 0
                if ($initialQty > 0) {
                    update_stock($conn, $newProductId, $initialQty, 'initial', 'Manual', null, 'Initial stock on product creation', $userId);
                }

                // Audit log
                log_action($conn, 'Product Management', ACTION_CREATE, 'Product', $newProductId, null, [
                    'product_code' => $productCode,
                    'product_name' => $productName,
                    'sales_price' => $salesPrice,
                    'cost_price' => $costPrice,
                    'initial_qty' => $initialQty,
                    'category_id' => $categoryId,
                ]);

                $conn->commit();
                set_flash('success', 'Product "' . $productName . '" created successfully.');
                redirect('/modules/products/index.php');

            } catch (Exception $ex) {
                $conn->rollback();
                if ($conn->errno === 1062) {
                    $errors[] = 'Product code "' . $productCode . '" already exists.';
                } else {
                    $errors[] = 'Failed to create product: ' . $ex->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Create Product</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/products/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Products
            </a>
        </p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background:var(--color-danger-bg); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); padding:14px 16px; margin-bottom:20px; font-size:0.8125rem; color:var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
        <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" action="" id="create-product-form">
    <?= csrf_field() ?>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
        <!-- Basic Info -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-box" style="color:var(--accent-primary); margin-right:8px;"></i>Basic Information</h3>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="product_code">Product Code <span class="text-danger">*</span></label>
                        <input type="text" name="product_code" id="product_code" class="form-control" required maxlength="50"
                               value="<?= e($old['product_code'] ?? $suggestedCode) ?>" placeholder="e.g. RM-0001">
                        <span class="form-hint">Auto-suggested. You can customize it.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="uom">Unit of Measure <span class="text-danger">*</span></label>
                        <select name="uom" id="uom" class="form-control form-select">
                            <?php foreach (get_uom_list() as $u): ?>
                                <option value="<?= $u ?>" <?= ($old['uom'] ?? 'Pcs') === $u ? 'selected' : '' ?>><?= $u ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="product_name">Product Name <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" id="product_name" class="form-control" required maxlength="200"
                           value="<?= e($old['product_name'] ?? '') ?>" placeholder="Enter product name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="category_id">Category</label>
                    <select name="category_id" id="category_id" class="form-control form-select">
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= intval($old['category_id'] ?? 0) === $cat['category_id'] ? 'selected' : '' ?>><?= e($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" placeholder="Product description..."><?= e($old['description'] ?? '') ?></textarea>
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
                        <label class="form-label" for="sales_price">Sales Price <span class="text-danger">*</span></label>
                        <input type="number" name="sales_price" id="sales_price" class="form-control" step="0.01" min="0"
                               value="<?= e($old['sales_price'] ?? '0.00') ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cost_price">Cost Price <span class="text-danger">*</span></label>
                        <input type="number" name="cost_price" id="cost_price" class="form-control" step="0.01" min="0"
                               value="<?= e($old['cost_price'] ?? '0.00') ?>" placeholder="0.00">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="initial_qty">Initial On-Hand Qty</label>
                        <input type="number" name="initial_qty" id="initial_qty" class="form-control" step="0.001" min="0"
                               value="<?= e($old['initial_qty'] ?? '0') ?>" placeholder="0">
                        <span class="form-hint">Creates an initial stock movement.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="min_stock_level">Min Stock Level</label>
                        <input type="number" name="min_stock_level" id="min_stock_level" class="form-control" step="0.001" min="0"
                               value="<?= e($old['min_stock_level'] ?? '0') ?>" placeholder="0">
                        <span class="form-hint">Reorder point threshold.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Procurement Config -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-truck-field" style="color:var(--color-info); margin-right:8px;"></i>Procurement Configuration</h3>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem;">
                <input type="checkbox" name="procure_on_demand" id="procure_on_demand" <?= !empty($old['procure_on_demand']) ? 'checked' : '' ?>
                       onchange="document.getElementById('procurement-fields').style.display = this.checked ? 'block' : 'none';"
                       style="accent-color:var(--accent-primary); width:16px; height:16px;">
                <span>Enable Procure on Demand</span>
            </label>
        </div>
        <div id="procurement-fields" style="display:<?= !empty($old['procure_on_demand']) ? 'block' : 'none' ?>;">
            <div class="card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label" for="procurement_type">Procurement Type</label>
                        <select name="procurement_type" id="procurement_type" class="form-control form-select"
                                onchange="document.getElementById('vendor-field').style.display = this.value === 'purchase' ? 'block' : 'none'; document.getElementById('bom-field').style.display = this.value === 'manufacturing' ? 'block' : 'none';">
                            <option value="">— Select Type —</option>
                            <option value="purchase" <?= ($old['procurement_type'] ?? '') === 'purchase' ? 'selected' : '' ?>>Purchase (Buy from Vendor)</option>
                            <option value="manufacturing" <?= ($old['procurement_type'] ?? '') === 'manufacturing' ? 'selected' : '' ?>>Manufacturing (Produce in-house)</option>
                        </select>
                    </div>
                    <div class="form-group" id="vendor-field" style="display:<?= ($old['procurement_type'] ?? '') === 'purchase' ? 'block' : 'none' ?>;">
                        <label class="form-label" for="default_vendor_id">Default Vendor</label>
                        <select name="default_vendor_id" id="default_vendor_id" class="form-control form-select">
                            <option value="">— Select Vendor —</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['vendor_id'] ?>" <?= intval($old['default_vendor_id'] ?? 0) === $v['vendor_id'] ? 'selected' : '' ?>><?= e($v['vendor_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="bom-field" style="display:<?= ($old['procurement_type'] ?? '') === 'manufacturing' ? 'block' : 'none' ?>;">
                        <label class="form-label">Default Bill of Materials</label>
                        <div style="padding:10px 14px; background:var(--bg-card-hover); border:1px solid var(--border-color); border-radius:var(--border-radius-sm); font-size:0.8125rem; color:var(--text-muted);">
                            <i class="fa-solid fa-info-circle" style="margin-right:6px;"></i>
                            BoM module not yet available. This will be configurable once the Bill of Materials module is built.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Submit -->
    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;" class="animate-in">
        <a href="<?= BASE_URL ?>/modules/products/index.php" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> Create Product
        </button>
    </div>
</form>

<style>
    @media (max-width: 1024px) {
        #create-product-form > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
