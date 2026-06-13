<?php
/**
 * Create Manufacturing Order - Mini ERP System
 * Form with product → filtered BoM selection, auto-populated components, and work orders.
 */

$pageTitle = 'New Manufacturing Order';
$currentModule = 'manufacturing';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];

// Fetch data for dropdowns
$products = $conn->query("SELECT product_id, product_code, product_name, uom FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);
$users = $conn->query("SELECT user_id, full_name FROM tbl_users WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$warehouses = $conn->query("SELECT warehouse_id, warehouse_name FROM tbl_warehouses WHERE is_active = 1 ORDER BY warehouse_name")->fetch_all(MYSQLI_ASSOC);

// All products JSON for manual component addition
$allProducts = $conn->query("SELECT product_id, product_code, product_name, uom, cost_price FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);
$productJson = [];
foreach ($allProducts as $p) {
    $productJson[$p['product_id']] = [
        'code' => $p['product_code'],
        'name' => $p['product_name'],
        'uom'  => $p['uom'],
        'cost_price' => (float)$p['cost_price'],
    ];
}

$stockData = [];
$res = $conn->query("SELECT product_id, warehouse_id, on_hand_qty, reserved_qty FROM tbl_product_warehouse_stock");
while ($r = $res->fetch_assoc()) {
    $free = get_free_qty((float)$r['on_hand_qty'], (float)$r['reserved_qty']);
    $stockData[$r['warehouse_id']][$r['product_id']] = [
        'on_hand' => (float)$r['on_hand_qty'],
        'reserved' => (float)$r['reserved_qty'],
        'free_to_use' => $free
    ];
}
$stockDataJson = json_encode($stockData);

// ─── MO Number Generator ────────────────────────────────────────────────────
function generate_mo_number(mysqli $conn): string {
    $year = date('Y');
    $prefix = "MO-$year-";
    $stmt = $conn->prepare("SELECT mo_number FROM tbl_manufacturing_orders WHERE mo_number LIKE ? ORDER BY mo_id DESC LIMIT 1");
    $pattern = $prefix . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $lastNum = (int)substr($result['mo_number'], strlen($prefix));
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
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $bomId = intval($_POST['bom_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $assignedUserId = intval($_POST['assigned_user_id'] ?? 0);
        $plannedStart = trim($_POST['planned_start'] ?? '');
        $plannedEnd = trim($_POST['planned_end'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Component lines
        $compProductIds = $_POST['comp_product_id'] ?? [];
        $compQtys = $_POST['comp_qty'] ?? [];

        // Work order lines
        $woNames = $_POST['wo_name'] ?? [];
        $woCenters = $_POST['wo_center'] ?? [];
        $woSequences = $_POST['wo_sequence'] ?? [];
        $woExpected = $_POST['wo_expected'] ?? [];

        // Validate
        if ($productId <= 0) $errors[] = 'Please select a finished product.';
        if ($warehouseId <= 0) $errors[] = 'Please select a production warehouse.';
        if ($quantity <= 0) $errors[] = 'Production quantity must be greater than zero.';

        // Validate components
        $validComponents = [];
        if (empty($errors)) {
            foreach ($compProductIds as $i => $pid) {
                $pid = intval($pid);
                $qty = floatval($compQtys[$i] ?? 0);
                if ($pid <= 0) continue;
                if ($qty <= 0) { $errors[] = 'All component quantities must be greater than zero.'; break; }
                $validComponents[] = ['product_id' => $pid, 'qty' => $qty];
            }
        }

        // Validate work orders
        $validWorkOrders = [];
        if (empty($errors)) {
            foreach ($woNames as $i => $name) {
                $name = trim($name);
                $center = trim($woCenters[$i] ?? '');
                $seq = intval($woSequences[$i] ?? $i);
                $exp = floatval($woExpected[$i] ?? 0);
                if (empty($name)) continue;
                if (empty($center)) { $errors[] = 'Work Center is mandatory for all work orders.'; break; }
                $validWorkOrders[] = ['name' => $name, 'center' => $center, 'sequence' => $seq, 'expected' => $exp];
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $moNumber = generate_mo_number($conn);
                $userId = $_SESSION['user_id'];
                $assignId = $assignedUserId > 0 ? $assignedUserId : null;
                $bomIdVal = $bomId > 0 ? $bomId : null;
                $pStart = !empty($plannedStart) ? $plannedStart : null;
                $pEnd = !empty($plannedEnd) ? $plannedEnd : null;

                // Insert MO header
                $stmt = $conn->prepare("INSERT INTO tbl_manufacturing_orders (mo_number, product_id, warehouse_id, bom_id, quantity, assigned_user_id, planned_start, planned_end, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("siiidisssi", $moNumber, $productId, $warehouseId, $bomIdVal, $quantity, $assignId, $pStart, $pEnd, $notes, $userId);
                $stmt->execute();
                $moId = $conn->insert_id;
                $stmt->close();

                // Insert component snapshots (Draft — no reservation yet)
                if (!empty($validComponents)) {
                    $stmtComp = $conn->prepare("INSERT INTO tbl_mo_components (mo_id, product_id, product_code, product_name, uom, required_qty, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($validComponents as $comp) {
                        $stmtP = $conn->prepare("SELECT product_code, product_name, uom, cost_price FROM tbl_products WHERE product_id = ?");
                        $stmtP->bind_param("i", $comp['product_id']);
                        $stmtP->execute();
                        $prod = $stmtP->get_result()->fetch_assoc();
                        $stmtP->close();

                        if (!$prod) throw new Exception('Component product not found.');

                        $stmtComp->bind_param("iisssdd", $moId, $comp['product_id'], $prod['product_code'], $prod['product_name'], $prod['uom'], $comp['qty'], $prod['cost_price']);
                        $stmtComp->execute();
                    }
                    $stmtComp->close();
                }

                // Insert work orders
                if (!empty($validWorkOrders)) {
                    $stmtWO = $conn->prepare("INSERT INTO tbl_mo_work_orders (mo_id, operation_name, work_center, sequence, expected_duration_minutes) VALUES (?, ?, ?, ?, ?)");
                    foreach ($validWorkOrders as $wo) {
                        $stmtWO->bind_param("issid", $moId, $wo['name'], $wo['center'], $wo['sequence'], $wo['expected']);
                        $stmtWO->execute();
                    }
                    $stmtWO->close();
                }

                // Audit log
                log_action($conn, 'Manufacturing', ACTION_CREATE, 'Manufacturing Order', $moId, null, [
                    'mo_number' => $moNumber,
                    'product_id' => $productId,
                    'bom_id' => $bomIdVal,
                    'quantity' => $quantity,
                    'assigned_user_id' => $assignId,
                    'planned_start' => $pStart,
                    'planned_end' => $pEnd,
                    'components' => count($validComponents),
                    'work_orders' => count($validWorkOrders),
                ]);

                $conn->commit();
                set_flash('success', 'Manufacturing Order ' . $moNumber . ' created in Draft status.');
                redirect('/modules/manufacturing/view.php?id=' . $moId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = 'Failed to create Manufacturing Order: ' . $ex->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>New Manufacturing Order</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/manufacturing/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Manufacturing Orders
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

<form method="POST" action="" id="mo-form">
    <?= csrf_field() ?>

    <!-- Header Section -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-industry" style="color:var(--accent-primary); margin-right:8px;"></i>Production Details</h3>
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
                    <label class="form-label">Bill of Materials</label>
                    <select name="bom_id" id="bom_id" class="form-control">
                        <option value="">— Select BoM (optional) —</option>
                    </select>
                    <small style="color:var(--text-muted); font-size:0.75rem;">Filtered by selected product. Leave empty to add components manually.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Production Quantity <span style="color:var(--color-danger);">*</span></label>
                    <input type="number" name="quantity" id="mo_quantity" class="form-control" step="0.001" min="0.001"
                           value="<?= e($_POST['quantity'] ?? '') ?>" placeholder="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Production Warehouse <span style="color:var(--color-danger);">*</span></label>
                    <select name="warehouse_id" id="warehouse_id" class="form-control" required onchange="warehouseChanged()">
                        <option value="">— Select Warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['warehouse_id'] ?>" <?= (intval($_POST['warehouse_id'] ?? 0) === $w['warehouse_id']) ? 'selected' : '' ?>>
                                <?= e($w['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-calendar-days" style="color:var(--color-warning); margin-right:8px;"></i>Planning</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Assigned To</label>
                    <select name="assigned_user_id" class="form-control">
                        <option value="">— Not Assigned —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= (intval($_POST['assigned_user_id'] ?? 0) === $u['user_id']) ? 'selected' : '' ?>>
                                <?= e($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Planned Start</label>
                        <input type="date" name="planned_start" class="form-control" value="<?= e($_POST['planned_start'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Planned End</label>
                        <input type="date" name="planned_end" class="form-control" value="<?= e($_POST['planned_end'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Components Preview -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fa-solid fa-cubes" style="color:var(--accent-primary); margin-right:8px;"></i>Components</h3>
            <button type="button" class="btn btn-sm btn-secondary" id="add-comp-btn">
                <i class="fa-solid fa-plus"></i> Add Manual Component
            </button>
        </div>
        <div class="table-wrapper">
            <table class="data-table" id="comp-table">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>UOM</th>
                        <th style="text-align:right;">Required Qty</th>
                        <th style="text-align:right;">Available Qty</th>
                        <th>Availability</th>
                        <th style="text-align:center;">Remove</th>
                    </tr>
                </thead>
                <tbody id="comp-body">
                    <tr id="comp-empty">
                        <td colspan="7" style="text-align:center; color:var(--text-muted); padding:24px;">
                            Select a product and BoM to auto-populate components, or add them manually.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Work Orders -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fa-solid fa-list-ol" style="color:var(--color-warning); margin-right:8px;"></i>Work Orders</h3>
            <button type="button" class="btn btn-sm btn-secondary" id="add-wo-btn">
                <i class="fa-solid fa-plus"></i> Add Work Order
            </button>
        </div>
        <div class="table-wrapper">
            <table class="data-table" id="wo-table">
                <thead>
                    <tr>
                        <th style="width:5%;">Seq</th>
                        <th style="width:25%;">Operation Name</th>
                        <th style="width:20%;">Work Center <span style="color:var(--color-danger);">*</span></th>
                        <th style="width:18%; text-align:right;">Expected Duration (min)</th>
                        <th style="width:8%; text-align:center;">Remove</th>
                    </tr>
                </thead>
                <tbody id="wo-body">
                    <tr id="wo-empty">
                        <td colspan="5" style="text-align:center; color:var(--text-muted); padding:24px;">
                            Work orders will be generated from the BoM, or add them manually.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <div></div>
            <button type="submit" class="btn btn-primary" id="save-btn">
                <i class="fa-solid fa-floppy-disk"></i> Create Manufacturing Order
            </button>
        </div>
    </div>
</form>

<script>
const BASE = '<?= BASE_URL ?>';
const allProducts = <?= json_encode($productJson) ?>;
const stockData = <?= $stockDataJson ?>;
let compIndex = 0;
let woIndex = 0;

// ─── Product change → filter BoMs ──────────────────────────────────────────
document.getElementById('product_id').addEventListener('change', async function() {
    const productId = this.value;
    const bomSelect = document.getElementById('bom_id');
    bomSelect.innerHTML = '<option value="">— Select BoM (optional) —</option>';
    clearComponents();
    clearWorkOrders();

    if (!productId) return;

    try {
        const res = await fetch(`${BASE}/modules/manufacturing/ajax_bom_data.php?action=boms_for_product&product_id=${productId}`);
        const boms = await res.json();
        boms.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.bom_id;
            opt.textContent = `${b.bom_code} — ${b.bom_name} (Base: ${b.quantity}, Time: ${b.standard_time_minutes} min)`;
            bomSelect.appendChild(opt);
        });
    } catch (e) {
        console.error('Failed to load BoMs:', e);
    }
});

// ─── BoM change → load components ──────────────────────────────────────────
document.getElementById('bom_id').addEventListener('change', async function() {
    const bomId = this.value;
    const moQty = parseFloat(document.getElementById('mo_quantity').value) || 1;
    const warehouseId = document.getElementById('warehouse_id').value;
    clearComponents();
    clearWorkOrders();

    if (!bomId || !warehouseId) return;

    try {
        const res = await fetch(`${BASE}/modules/manufacturing/ajax_bom_data.php?action=bom_components&bom_id=${bomId}&mo_qty=${moQty}&warehouse_id=${warehouseId}`);
        const data = await res.json();

        data.components.forEach(comp => {
            addComponentRow(comp.product_id, comp.product_code, comp.product_name, comp.uom, comp.required_qty, comp.free_qty, comp.available, false);
        });

        // Auto-add a default work order based on standard time
        if (data.standard_time > 0) {
            const scaledTime = data.standard_time * (moQty / (data.bom_base_qty || 1));
            addWorkOrderRow('Production', 'Main', 1, scaledTime.toFixed(2));
        }
    } catch (e) {
        console.error('Failed to load BoM components:', e);
    }
});

// ─── Quantity change → re-scale components ─────────────────────────────────
document.getElementById('mo_quantity').addEventListener('change', function() {
    const bomId = document.getElementById('bom_id').value;
    if (bomId) {
        document.getElementById('bom_id').dispatchEvent(new Event('change'));
    }
});

function warehouseChanged() {
    const bomId = document.getElementById('bom_id').value;
    if (bomId) {
        document.getElementById('bom_id').dispatchEvent(new Event('change'));
    }
}

// ─── Component helpers ─────────────────────────────────────────────────────
function clearComponents() {
    document.getElementById('comp-body').innerHTML = '';
    const emptyRow = document.createElement('tr');
    emptyRow.id = 'comp-empty';
    emptyRow.innerHTML = '<td colspan="7" style="text-align:center; color:var(--text-muted); padding:24px;">Select a product and BoM to auto-populate components, or add them manually.</td>';
    document.getElementById('comp-body').appendChild(emptyRow);
    compIndex = 0;
}

function addComponentRow(pid, code, name, uom, reqQty, freeQty, available, isManual) {
    const empty = document.getElementById('comp-empty');
    if (empty) empty.remove();

    const tbody = document.getElementById('comp-body');
    const row = document.createElement('tr');
    row.id = 'comp-' + compIndex;

    const availBadge = available
        ? '<span class="badge badge-success"><i class="fa-solid fa-check" style="margin-right:4px;"></i>Available</span>'
        : '<span class="badge badge-danger"><i class="fa-solid fa-xmark" style="margin-right:4px;"></i>Not Available</span>';

    if (isManual) {
        let productOptions = '<option value="">— Select —</option>';
        for (const [id, p] of Object.entries(allProducts)) {
            const sel = (id == pid) ? 'selected' : '';
            productOptions += `<option value="${id}" ${sel}>${p.code} — ${p.name}</option>`;
        }

        row.innerHTML = `
            <td colspan="2">
                <select name="comp_product_id[]" class="form-control manual-comp-select" data-idx="${compIndex}" style="font-size:0.8125rem;">
                    ${productOptions}
                </select>
            </td>
            <td><span class="comp-uom" style="font-size:0.8125rem; color:var(--text-muted);">${uom || '—'}</span></td>
            <td><input type="number" name="comp_qty[]" class="form-control" step="0.001" min="0.001" value="${reqQty}" style="text-align:right; font-size:0.8125rem;"></td>
            <td style="text-align:right; font-size:0.8125rem;" class="comp-free">${freeQty !== undefined ? freeQty.toFixed(3) : '—'}</td>
            <td class="comp-avail">${availBadge}</td>
            <td style="text-align:center;"><button type="button" class="btn btn-sm btn-danger" onclick="removeComp(${compIndex})"><i class="fa-solid fa-trash-can"></i></button></td>
        `;

        // Attach change handler for manual select
        tbody.appendChild(row);
        row.querySelector('.manual-comp-select').addEventListener('change', function() {
            const p = allProducts[this.value];
            const warehouseId = document.getElementById('warehouse_id').value;
            let freeQty = 0;
            if (warehouseId && stockData[warehouseId] && stockData[warehouseId][this.value]) {
                freeQty = stockData[warehouseId][this.value].free_to_use;
            }

            const tr = this.closest('tr');
            tr.querySelector('.comp-uom').textContent = p ? p.uom : '—';
            tr.querySelector('.comp-free').textContent = p ? freeQty.toFixed(3) : '—';
            const qtyInput = tr.querySelector('input[name="comp_qty[]"]');
            const reqQ = parseFloat(qtyInput.value) || 0;
            const avail = freeQty >= reqQ;
            tr.querySelector('.comp-avail').innerHTML = avail
                ? '<span class="badge badge-success"><i class="fa-solid fa-check" style="margin-right:4px;"></i>Available</span>'
                : '<span class="badge badge-danger"><i class="fa-solid fa-xmark" style="margin-right:4px;"></i>Not Available</span>';
        });
    } else {
        row.innerHTML = `
            <td>
                <input type="hidden" name="comp_product_id[]" value="${pid}">
                <span style="font-family:'Fira Code',monospace; font-size:0.8125rem; color:var(--accent-primary);">${code}</span>
            </td>
            <td><span style="font-weight:500; font-size:0.8125rem; color:var(--text-primary);">${name}</span></td>
            <td style="font-size:0.8125rem; color:var(--text-muted);">${uom}</td>
            <td><input type="number" name="comp_qty[]" class="form-control" step="0.001" min="0.001" value="${reqQty}" style="text-align:right; font-size:0.8125rem;"></td>
            <td style="text-align:right; font-size:0.8125rem;">${freeQty !== undefined ? freeQty.toFixed(3) : '—'}</td>
            <td>${availBadge}</td>
            <td style="text-align:center;"><button type="button" class="btn btn-sm btn-danger" onclick="removeComp(${compIndex})"><i class="fa-solid fa-trash-can"></i></button></td>
        `;
        tbody.appendChild(row);
    }

    compIndex++;
}

function removeComp(idx) {
    const row = document.getElementById('comp-' + idx);
    if (row) row.remove();
    if (document.querySelectorAll('#comp-body tr').length === 0) {
        clearComponents();
    }
}

document.getElementById('add-comp-btn').addEventListener('click', () => {
    addComponentRow('', '', '', '', '', 0, false, true);
});

// ─── Work Order helpers ────────────────────────────────────────────────────
function clearWorkOrders() {
    document.getElementById('wo-body').innerHTML = '';
    const emptyRow = document.createElement('tr');
    emptyRow.id = 'wo-empty';
    emptyRow.innerHTML = '<td colspan="5" style="text-align:center; color:var(--text-muted); padding:24px;">Work orders will be generated from the BoM, or add them manually.</td>';
    document.getElementById('wo-body').appendChild(emptyRow);
    woIndex = 0;
}

function addWorkOrderRow(name = '', center = '', seq = '', expected = '') {
    const empty = document.getElementById('wo-empty');
    if (empty) empty.remove();

    const tbody = document.getElementById('wo-body');
    const row = document.createElement('tr');
    row.id = 'wo-' + woIndex;

    if (!seq) seq = document.querySelectorAll('#wo-body tr').length + 1;

    row.innerHTML = `
        <td><input type="number" name="wo_sequence[]" class="form-control" value="${seq}" min="1" style="width:60px; text-align:center; font-size:0.8125rem;"></td>
        <td><input type="text" name="wo_name[]" class="form-control" value="${name}" placeholder="e.g., Cutting, Assembly..." style="font-size:0.8125rem;" required></td>
        <td><input type="text" name="wo_center[]" class="form-control" value="${center}" placeholder="e.g., Workshop A" style="font-size:0.8125rem;" required></td>
        <td><input type="number" name="wo_expected[]" class="form-control" step="0.01" min="0" value="${expected}" style="text-align:right; font-size:0.8125rem;"></td>
        <td style="text-align:center;"><button type="button" class="btn btn-sm btn-danger" onclick="removeWO(${woIndex})"><i class="fa-solid fa-trash-can"></i></button></td>
    `;
    tbody.appendChild(row);
    woIndex++;
}

function removeWO(idx) {
    const row = document.getElementById('wo-' + idx);
    if (row) row.remove();
    if (document.querySelectorAll('#wo-body tr').length === 0) {
        clearWorkOrders();
    }
}

document.getElementById('add-wo-btn').addEventListener('click', () => addWorkOrderRow());

// Double-submit protection
document.getElementById('mo-form').addEventListener('submit', function() {
    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';
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
