<?php
/**
 * Create Bill of Materials - Mini ERP System
 * Form with product selection, component line builder, and standard time.
 */

$pageTitle = 'New Bill of Materials';
$currentModule = 'bom';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];

// Fetch products for dropdowns
$products = $conn->query("SELECT product_id, product_code, product_name, uom, cost_price FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

$productJson = [];
foreach ($products as $p) {
    $productJson[$p['product_id']] = [
        'code' => $p['product_code'],
        'name' => $p['product_name'],
        'uom'  => $p['uom'],
        'cost_price' => (float)$p['cost_price'],
    ];
}

// ─── BoM Number Generator ───────────────────────────────────────────────────
function generate_bom_code(mysqli $conn): string {
    $year = date('Y');
    $prefix = "BOM-$year-";
    $stmt = $conn->prepare("SELECT bom_code FROM tbl_bom WHERE bom_code LIKE ? ORDER BY bom_id DESC LIMIT 1");
    $pattern = $prefix . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $lastNum = (int)substr($result['bom_code'], strlen($prefix));
        $nextNum = $lastNum + 1;
    } else {
        $nextNum = 1;
    }
    return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

// ─── Handle POST ────────────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $productId = intval($_POST['product_id'] ?? 0);
        $bomName = trim($_POST['bom_name'] ?? '');
        $baseQty = floatval($_POST['base_qty'] ?? 1);
        $standardTime = floatval($_POST['standard_time'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $setAsDefault = isset($_POST['set_default']);

        // Component lines
        $lineProductIds = $_POST['line_product_id'] ?? [];
        $lineQtys = $_POST['line_qty'] ?? [];
        $lineNotes = $_POST['line_notes'] ?? [];

        // Validate
        if ($productId <= 0) $errors[] = 'Please select a finished product.';
        if (empty($bomName)) $errors[] = 'BoM name is required.';
        if ($baseQty <= 0) $errors[] = 'Base quantity must be greater than zero.';
        if (empty($lineProductIds)) $errors[] = 'Add at least one component line.';

        // Validate lines
        $validLines = [];
        if (empty($errors)) {
            foreach ($lineProductIds as $i => $pid) {
                $pid = intval($pid);
                $qty = floatval($lineQtys[$i] ?? 0);
                $note = trim($lineNotes[$i] ?? '');
                if ($pid <= 0) continue;
                if ($pid === $productId) { $errors[] = 'A product cannot be a component of itself.'; break; }
                if ($qty <= 0) { $errors[] = 'All component quantities must be greater than zero.'; break; }
                $validLines[] = ['product_id' => $pid, 'qty' => $qty, 'notes' => $note, 'sort' => count($validLines)];
            }
            if (empty($validLines) && empty($errors)) {
                $errors[] = 'Add at least one valid component line.';
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $bomCode = generate_bom_code($conn);
                $userId = $_SESSION['user_id'];

                // Insert BoM header
                $stmt = $conn->prepare("INSERT INTO tbl_bom (bom_code, bom_name, product_id, quantity, standard_time_minutes, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssiddsi", $bomCode, $bomName, $productId, $baseQty, $standardTime, $notes, $userId);
                $stmt->execute();
                $bomId = $conn->insert_id;
                $stmt->close();

                // Insert component lines
                $stmtLine = $conn->prepare("INSERT INTO tbl_bom_lines (bom_id, product_id, quantity, uom, notes, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($validLines as $line) {
                    // Get product UOM
                    $stmtP = $conn->prepare("SELECT uom FROM tbl_products WHERE product_id = ?");
                    $stmtP->bind_param("i", $line['product_id']);
                    $stmtP->execute();
                    $prod = $stmtP->get_result()->fetch_assoc();
                    $stmtP->close();

                    $uom = $prod['uom'] ?? 'Pcs';
                    $stmtLine->bind_param("iidssi", $bomId, $line['product_id'], $line['qty'], $uom, $line['notes'], $line['sort']);
                    $stmtLine->execute();
                }
                $stmtLine->close();

                // Set as default BoM
                if ($setAsDefault) {
                    $stmtDef = $conn->prepare("UPDATE tbl_products SET default_bom_id = ? WHERE product_id = ?");
                    $stmtDef->bind_param("ii", $bomId, $productId);
                    $stmtDef->execute();
                    $stmtDef->close();
                }

                // Audit log
                log_action($conn, 'Bill of Materials', ACTION_BOM_CREATE, 'BoM', $bomId, null, [
                    'bom_code' => $bomCode,
                    'bom_name' => $bomName,
                    'product_id' => $productId,
                    'base_qty' => $baseQty,
                    'standard_time' => $standardTime,
                    'components' => count($validLines),
                    'set_as_default' => $setAsDefault,
                ]);

                $conn->commit();
                set_flash('success', 'Bill of Materials ' . $bomCode . ' created successfully.');
                redirect('/modules/bom/view.php?id=' . $bomId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = 'Failed to create BoM: ' . $ex->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>New Bill of Materials</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/bom/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to BoM List
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

<form method="POST" action="" id="bom-form">
    <?= csrf_field() ?>

    <!-- Header Section -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-boxes-stacked" style="color:var(--accent-primary); margin-right:8px;"></i>Product & Recipe</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Finished Product <span style="color:var(--color-danger);">*</span></label>
                    <select name="product_id" id="product_id" class="form-control" required>
                        <option value="">— Select Product —</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['product_id'] ?>" <?= (intval($_POST['product_id'] ?? 0) === $p['product_id']) ? 'selected' : '' ?>>
                                <?= e($p['product_code']) ?> — <?= e($p['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">BoM Name <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="bom_name" class="form-control" required
                           value="<?= e($_POST['bom_name'] ?? '') ?>" placeholder="e.g., Standard Recipe v1">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Base Quantity</label>
                        <input type="number" name="base_qty" class="form-control" step="0.001" min="0.001"
                               value="<?= e($_POST['base_qty'] ?? '1') ?>" placeholder="1">
                        <small style="color:var(--text-muted); font-size:0.75rem;">How many finished items this recipe makes</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Standard Time (minutes)</label>
                        <input type="number" name="standard_time" class="form-control" step="0.01" min="0"
                               value="<?= e($_POST['standard_time'] ?? '0') ?>" placeholder="0">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-gear" style="color:var(--color-warning); margin-right:8px;"></i>Settings</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="4" placeholder="Optional notes about this BoM..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
                <div class="form-group" style="margin-top:16px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="set_default" value="1" <?= isset($_POST['set_default']) ? 'checked' : '' ?>
                               style="width:18px; height:18px; accent-color:var(--accent-primary);">
                        <span style="font-size:0.875rem; color:var(--text-primary);">Set as default BoM for this product</span>
                    </label>
                    <small style="color:var(--text-muted); font-size:0.75rem; display:block; margin-top:4px;">
                        Updates the product's <code>default_bom_id</code> field
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Component Lines -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fa-solid fa-cubes" style="color:var(--accent-secondary, var(--accent-primary)); margin-right:8px;"></i>Components</h3>
            <button type="button" class="btn btn-sm btn-primary" id="add-line-btn">
                <i class="fa-solid fa-plus"></i> Add Component
            </button>
        </div>
        <div class="table-wrapper">
            <table class="data-table" id="lines-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:30%;">Component Product</th>
                        <th style="width:12%;">Code</th>
                        <th style="width:8%;">UOM</th>
                        <th style="width:12%; text-align:right;">Quantity</th>
                        <th style="width:25%;">Notes</th>
                        <th style="width:8%; text-align:center;">Remove</th>
                    </tr>
                </thead>
                <tbody id="lines-body">
                    <!-- Dynamic rows added via JS -->
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <div></div>
            <button type="submit" class="btn btn-primary" id="save-btn">
                <i class="fa-solid fa-floppy-disk"></i> Save Bill of Materials
            </button>
        </div>
    </div>
</form>

<script>
const products = <?= json_encode($productJson) ?>;
let lineIndex = 0;

function addLine(pid = '', qty = '', notes = '') {
    const tbody = document.getElementById('lines-body');
    const row = document.createElement('tr');
    row.id = 'line-' + lineIndex;

    let productOptions = '<option value="">— Select Component —</option>';
    for (const [id, p] of Object.entries(products)) {
        const sel = (id == pid) ? 'selected' : '';
        productOptions += `<option value="${id}" ${sel}>${p.code} — ${p.name}</option>`;
    }

    row.innerHTML = `
        <td style="text-align:center; color:var(--text-muted); font-size:0.8125rem;" class="line-num"></td>
        <td>
            <select name="line_product_id[]" class="form-control line-product" data-idx="${lineIndex}" style="font-size:0.8125rem;" required>
                ${productOptions}
            </select>
        </td>
        <td><span class="line-code" style="font-size:0.8125rem; font-family:'Fira Code',monospace; color:var(--text-muted);">—</span></td>
        <td><span class="line-uom" style="font-size:0.8125rem; color:var(--text-muted);">—</span></td>
        <td><input type="number" name="line_qty[]" class="form-control" step="0.001" min="0.001" value="${qty}" placeholder="0" required style="text-align:right; font-size:0.8125rem;"></td>
        <td><input type="text" name="line_notes[]" class="form-control" value="${notes}" placeholder="Optional" style="font-size:0.8125rem;"></td>
        <td style="text-align:center;">
            <button type="button" class="btn btn-sm btn-danger remove-line" onclick="removeLine(${lineIndex})" title="Remove">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);

    // Attach change handler
    row.querySelector('.line-product').addEventListener('change', function() {
        const p = products[this.value];
        const tr = this.closest('tr');
        tr.querySelector('.line-code').textContent = p ? p.code : '—';
        tr.querySelector('.line-uom').textContent = p ? p.uom : '—';
    });

    // Trigger initial fill if pre-populated
    if (pid && products[pid]) {
        row.querySelector('.line-code').textContent = products[pid].code;
        row.querySelector('.line-uom').textContent = products[pid].uom;
    }

    lineIndex++;
    renumberLines();
}

function removeLine(idx) {
    const row = document.getElementById('line-' + idx);
    if (row) row.remove();
    renumberLines();
}

function renumberLines() {
    const rows = document.querySelectorAll('#lines-body tr');
    rows.forEach((row, i) => {
        row.querySelector('.line-num').textContent = i + 1;
    });
}

document.getElementById('add-line-btn').addEventListener('click', () => addLine());

// Start with one empty line
addLine();

// Double-submit protection
document.getElementById('bom-form').addEventListener('submit', function() {
    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
});
</script>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
