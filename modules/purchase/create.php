<?php
/**
 * Create Purchase Order - Mini ERP System
 * Form with vendor selection, responsible person, and dynamic product line builder.
 */

$pageTitle = 'New Purchase Order';
$currentModule = 'purchase';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];

// Fetch vendors, users, products for dropdowns
$vendors = $conn->query("SELECT vendor_id, vendor_name, contact_person, email, phone, address, city, state, country FROM tbl_vendors WHERE is_active = 1 ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$users = $conn->query("SELECT user_id, full_name FROM tbl_users WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT product_id, product_code, product_name, uom, cost_price FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

// Build JSON for client-side auto-fill
$vendorJson = [];
foreach ($vendors as $v) {
    $addr = implode(', ', array_filter([$v['address'], $v['city'], $v['state'], $v['country']]));
    $vendorJson[$v['vendor_id']] = [
        'name' => $v['vendor_name'],
        'contact' => $v['contact_person'],
        'email' => $v['email'],
        'phone' => $v['phone'],
        'address' => $addr,
    ];
}

$productJson = [];
foreach ($products as $p) {
    $productJson[$p['product_id']] = [
        'code' => $p['product_code'],
        'name' => $p['product_name'],
        'uom' => $p['uom'],
        'cost_price' => (float)$p['cost_price'],
    ];
}

// ─── PO Number Generator ────────────────────────────────────────────────────
function generate_po_number(mysqli $conn): string {
    $year = date('Y');
    $prefix = "PO-$year-";
    $stmt = $conn->prepare("SELECT po_number FROM tbl_purchase_orders WHERE po_number LIKE ? ORDER BY po_id DESC LIMIT 1");
    $pattern = $prefix . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $lastNum = (int)substr($result['po_number'], strlen($prefix));
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
        $vendorId = intval($_POST['vendor_id'] ?? 0);
        $responsibleUserId = intval($_POST['responsible_user_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        // Line items
        $lineProductIds = $_POST['line_product_id'] ?? [];
        $lineQtys = $_POST['line_qty'] ?? [];
        $lineCosts = $_POST['line_cost'] ?? [];

        // Validate
        if ($vendorId <= 0) $errors[] = 'Please select a vendor.';
        if (empty($lineProductIds) || count($lineProductIds) === 0) {
            $errors[] = 'Add at least one product line.';
        }

        // Validate lines
        $validLines = [];
        if (empty($errors)) {
            foreach ($lineProductIds as $i => $pid) {
                $pid = intval($pid);
                $qty = floatval($lineQtys[$i] ?? 0);
                $cost = floatval($lineCosts[$i] ?? 0);
                if ($pid <= 0) continue;
                if ($qty <= 0) { $errors[] = 'All line quantities must be greater than zero.'; break; }
                if ($cost < 0) { $errors[] = 'Cost price cannot be negative.'; break; }
                $validLines[] = ['product_id' => $pid, 'qty' => $qty, 'cost' => $cost];
            }
            if (empty($validLines) && empty($errors)) {
                $errors[] = 'Add at least one valid product line.';
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                // Fetch vendor snapshot
                $stmt = $conn->prepare("SELECT vendor_name, contact_person, email, phone, address, city, state, country FROM tbl_vendors WHERE vendor_id = ?");
                $stmt->bind_param("i", $vendorId);
                $stmt->execute();
                $vendor = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$vendor) throw new Exception('Vendor not found.');

                $vendorAddr = implode(', ', array_filter([$vendor['address'], $vendor['city'], $vendor['state'], $vendor['country']]));

                // Generate PO number
                $poNumber = generate_po_number($conn);

                // Calculate ordered total
                $orderedTotal = 0;
                foreach ($validLines as $line) {
                    $orderedTotal += $line['qty'] * $line['cost'];
                }

                // Insert PO header
                $userId = $_SESSION['user_id'];
                $respId = $responsibleUserId > 0 ? $responsibleUserId : null;

                $stmt = $conn->prepare("INSERT INTO tbl_purchase_orders (po_number, vendor_id, vendor_name, vendor_contact_person, vendor_email, vendor_phone, vendor_address, responsible_user_id, notes, ordered_total, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisssssisdi",
                    $poNumber, $vendorId, $vendor['vendor_name'], $vendor['contact_person'],
                    $vendor['email'], $vendor['phone'], $vendorAddr,
                    $respId, $notes, $orderedTotal, $userId
                );
                $stmt->execute();
                $poId = $conn->insert_id;
                $stmt->close();

                // Insert line items with product snapshots
                $stmtLine = $conn->prepare("INSERT INTO tbl_purchase_order_lines (po_id, product_id, product_code, product_name, uom, ordered_qty, cost_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

                foreach ($validLines as $line) {
                    // Fetch product snapshot
                    $stmtP = $conn->prepare("SELECT product_code, product_name, uom FROM tbl_products WHERE product_id = ?");
                    $stmtP->bind_param("i", $line['product_id']);
                    $stmtP->execute();
                    $prod = $stmtP->get_result()->fetch_assoc();
                    $stmtP->close();

                    if (!$prod) throw new Exception('Product not found.');

                    $lineTotal = $line['qty'] * $line['cost'];
                    $stmtLine->bind_param("iisssddd",
                        $poId, $line['product_id'], $prod['product_code'], $prod['product_name'],
                        $prod['uom'], $line['qty'], $line['cost'], $lineTotal
                    );
                    $stmtLine->execute();
                }
                $stmtLine->close();

                // Audit log
                log_action($conn, 'Purchase', ACTION_CREATE, 'Purchase Order', $poId, null, [
                    'po_number' => $poNumber,
                    'vendor' => $vendor['vendor_name'],
                    'lines' => count($validLines),
                    'ordered_total' => $orderedTotal,
                ]);

                $conn->commit();
                set_flash('success', 'Purchase Order ' . $poNumber . ' created successfully.');
                redirect('/modules/purchase/view.php?id=' . $poId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = 'Failed to create purchase order: ' . $ex->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>New Purchase Order</h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/purchase/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Purchase Orders
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

<form method="POST" action="" id="po-form">
    <?= csrf_field() ?>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
        <!-- Vendor & Details -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-truck" style="color:var(--accent-primary); margin-right:8px;"></i>Order Details</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label class="form-label" for="vendor_id" style="margin-bottom:0;">Vendor <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('vendor-modal').classList.add('active')" style="font-size:0.75rem; padding:3px 10px;">
                            <i class="fa-solid fa-plus" style="font-size:0.625rem;"></i> Quick Add
                        </button>
                    </div>
                    <select name="vendor_id" id="vendor_id" class="form-control form-select" required onchange="fillVendorInfo(this.value)" style="margin-top:6px;">
                        <option value="">— Select Vendor —</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= $v['vendor_id'] ?>" <?= intval($_POST['vendor_id'] ?? 0) == $v['vendor_id'] ? 'selected' : '' ?>>
                                <?= e($v['vendor_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Vendor Info Preview -->
                <div id="vendor-info" style="display:none; background:var(--accent-ultra-light); border:1px solid rgba(37,99,235,0.1); border-radius:var(--border-radius-sm); padding:12px; margin-bottom:16px;">
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">Vendor Address (snapshotted)</div>
                    <div id="vendor-address" style="font-size:0.8125rem; color:var(--text-primary);"></div>
                    <div id="vendor-contact" style="font-size:0.75rem; color:var(--text-muted); margin-top:6px;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="responsible_user_id">Responsible Person</label>
                    <select name="responsible_user_id" id="responsible_user_id" class="form-control form-select">
                        <option value="">— Select Person —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= intval($_POST['responsible_user_id'] ?? 0) == $u['user_id'] ? 'selected' : '' ?>>
                                <?= e($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Optional notes..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Grand Total Summary -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa-solid fa-calculator" style="color:var(--color-success); margin-right:8px;"></i>Order Summary</h3>
            </div>
            <div class="card-body" style="display:flex; flex-direction:column; justify-content:center; align-items:center; min-height:200px;">
                <div style="font-size:0.8125rem; color:var(--text-muted); margin-bottom:8px;">Grand Total</div>
                <div id="grand-total" style="font-size:2.5rem; font-weight:700; color:var(--text-heading);">0.00</div>
                <div id="line-count" style="font-size:0.75rem; color:var(--text-muted); margin-top:8px;">0 line(s)</div>
            </div>
        </div>
    </div>

    <!-- Product Lines -->
    <div class="card animate-in" style="margin-top:20px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-list" style="color:var(--color-warning); margin-right:8px;"></i>Product Lines</h3>
            <button type="button" class="btn btn-sm btn-primary" onclick="addLine()">
                <i class="fa-solid fa-plus"></i> Add Product
            </button>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width:35%;">Product</th>
                            <th style="width:10%;">UOM</th>
                            <th style="width:15%; text-align:right;">Cost Price</th>
                            <th style="width:15%; text-align:right;">Quantity</th>
                            <th style="width:15%; text-align:right;">Line Total</th>
                            <th style="width:10%; text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                        <!-- Dynamic rows inserted by JS -->
                    </tbody>
                </table>
            </div>

            <div id="no-lines" style="text-align:center; padding:40px; color:var(--text-muted);">
                <i class="fa-solid fa-cart-plus" style="font-size:2rem; margin-bottom:12px; opacity:0.4; display:block;"></i>
                Click "Add Product" to add items to this purchase order.
            </div>
        </div>
        <div class="card-footer">
            <a href="<?= BASE_URL ?>/modules/purchase/index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" id="submit-btn">
                <i class="fa-solid fa-check"></i> Create Purchase Order
            </button>
        </div>
    </div>
</form>

<script>
const vendorData = <?= json_encode($vendorJson) ?>;
const productData = <?= json_encode($productJson) ?>;
let lineIndex = 0;

function fillVendorInfo(vendorId) {
    const info = document.getElementById('vendor-info');
    if (!vendorId || !vendorData[vendorId]) {
        info.style.display = 'none';
        return;
    }
    const v = vendorData[vendorId];
    info.style.display = 'block';
    document.getElementById('vendor-address').textContent = v.address || 'No address on file';
    const contactParts = [v.contact ? 'Contact: ' + v.contact : '', v.phone ? 'Phone: ' + v.phone : '', v.email ? 'Email: ' + v.email : ''].filter(Boolean);
    document.getElementById('vendor-contact').textContent = contactParts.join(' • ');
}

function addLine() {
    document.getElementById('no-lines').style.display = 'none';
    const tbody = document.getElementById('lines-body');
    const idx = lineIndex++;

    // Build product options
    let options = '<option value="">— Select —</option>';
    for (const [id, p] of Object.entries(productData)) {
        options += `<option value="${id}">${p.code} — ${p.name}</option>`;
    }

    const tr = document.createElement('tr');
    tr.id = 'line-' + idx;
    tr.innerHTML = `
        <td>
            <select name="line_product_id[]" class="form-control form-select" required onchange="onProductSelect(${idx}, this.value)" style="font-size:0.8125rem;">
                ${options}
            </select>
        </td>
        <td><span id="uom-${idx}" style="font-size:0.8125rem; color:var(--text-muted);">—</span></td>
        <td>
            <input type="number" name="line_cost[]" id="cost-${idx}" class="form-control" step="0.01" min="0" value="0" style="text-align:right; font-size:0.8125rem;" oninput="calcLine(${idx})" required>
        </td>
        <td>
            <input type="number" name="line_qty[]" id="qty-${idx}" class="form-control" step="0.001" min="0.001" value="" placeholder="0" style="text-align:right; font-size:0.8125rem;" oninput="calcLine(${idx})" required>
        </td>
        <td style="text-align:right; font-weight:600;">
            <span id="total-${idx}">0.00</span>
        </td>
        <td style="text-align:right;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeLine(${idx})" title="Remove">
                <i class="fa-solid fa-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    recalcGrand();
}

function onProductSelect(idx, productId) {
    if (!productId || !productData[productId]) {
        document.getElementById('uom-' + idx).textContent = '—';
        document.getElementById('cost-' + idx).value = '0';
        calcLine(idx);
        return;
    }
    const p = productData[productId];
    document.getElementById('uom-' + idx).textContent = p.uom;
    document.getElementById('cost-' + idx).value = p.cost_price.toFixed(2);
    calcLine(idx);
}

function calcLine(idx) {
    const cost = parseFloat(document.getElementById('cost-' + idx)?.value) || 0;
    const qty = parseFloat(document.getElementById('qty-' + idx)?.value) || 0;
    const total = cost * qty;
    const el = document.getElementById('total-' + idx);
    if (el) el.textContent = total.toFixed(2);
    recalcGrand();
}

function removeLine(idx) {
    const row = document.getElementById('line-' + idx);
    if (row) row.remove();
    recalcGrand();
    // Show placeholder if no lines left
    const tbody = document.getElementById('lines-body');
    if (tbody.children.length === 0) {
        document.getElementById('no-lines').style.display = 'block';
    }
}

function recalcGrand() {
    const tbody = document.getElementById('lines-body');
    let grand = 0;
    let count = 0;
    for (const row of tbody.children) {
        const totalSpan = row.querySelector('[id^="total-"]');
        if (totalSpan) {
            grand += parseFloat(totalSpan.textContent) || 0;
            count++;
        }
    }
    document.getElementById('grand-total').textContent = grand.toFixed(2);
    document.getElementById('line-count').textContent = count + ' line(s)';
}

// Disable double-submit
document.getElementById('po-form').addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';
});

// Init vendor info if POST value exists
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('vendor_id');
    if (sel.value) fillVendorInfo(sel.value);
});
</script>

<!-- Quick Add Vendor Modal -->
<div class="modal-overlay" id="vendor-modal">
    <div class="modal" style="max-width:550px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-truck" style="color:var(--accent-primary); margin-right:8px;"></i>Quick Add Vendor</h3>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                <input type="text" id="v_name" class="form-control" maxlength="150" placeholder="Company or person name">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="form-group"><label class="form-label">Contact Person</label><input type="text" id="v_contact" class="form-control" maxlength="100"></div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" id="v_phone" class="form-control" maxlength="20"></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" id="v_email" class="form-control" maxlength="150"></div>
            <div class="form-group"><label class="form-label">Address</label><textarea id="v_address" class="form-control" rows="2"></textarea></div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                <div class="form-group"><label class="form-label">City</label><input type="text" id="v_city" class="form-control" maxlength="100"></div>
                <div class="form-group"><label class="form-label">State</label><input type="text" id="v_state" class="form-control" maxlength="100"></div>
                <div class="form-group"><label class="form-label">Country</label><input type="text" id="v_country" class="form-control" maxlength="100"></div>
            </div>
            <div id="v_error" style="display:none; color:var(--color-danger); font-size:0.8125rem; margin-top:8px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
            <button type="button" class="btn btn-primary" id="v_submit" onclick="createVendorAjax()">
                <i class="fa-solid fa-check"></i> Create Vendor
            </button>
        </div>
    </div>
</div>

<script>
function createVendorAjax() {
    const name = document.getElementById('v_name').value.trim();
    if (!name) {
        document.getElementById('v_error').style.display = 'block';
        document.getElementById('v_error').textContent = 'Vendor name is required.';
        return;
    }

    const btn = document.getElementById('v_submit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';
    document.getElementById('v_error').style.display = 'none';

    const formData = new FormData();
    formData.append('vendor_name', name);
    formData.append('contact_person', document.getElementById('v_contact').value);
    formData.append('email', document.getElementById('v_email').value);
    formData.append('phone', document.getElementById('v_phone').value);
    formData.append('address', document.getElementById('v_address').value);
    formData.append('city', document.getElementById('v_city').value);
    formData.append('state', document.getElementById('v_state').value);
    formData.append('country', document.getElementById('v_country').value);

    fetch('<?= BASE_URL ?>/modules/purchase/ajax_create_vendor.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Add to dropdown and select it
            const sel = document.getElementById('vendor_id');
            const opt = document.createElement('option');
            opt.value = data.vendor.vendor_id;
            opt.textContent = data.vendor.vendor_name;
            opt.selected = true;
            sel.appendChild(opt);

            // Add to JS data for auto-fill
            vendorData[data.vendor.vendor_id] = {
                name: data.vendor.vendor_name,
                contact: data.vendor.contact_person,
                email: data.vendor.email,
                phone: data.vendor.phone,
                address: data.vendor.address
            };
            fillVendorInfo(data.vendor.vendor_id);

            // Close modal & reset
            document.getElementById('vendor-modal').classList.remove('active');
            document.getElementById('v_name').value = '';
            document.getElementById('v_contact').value = '';
            document.getElementById('v_email').value = '';
            document.getElementById('v_phone').value = '';
            document.getElementById('v_address').value = '';
            document.getElementById('v_city').value = '';
            document.getElementById('v_state').value = '';
            document.getElementById('v_country').value = '';
        } else {
            document.getElementById('v_error').style.display = 'block';
            document.getElementById('v_error').textContent = data.message || 'Failed to create vendor.';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Vendor';
    })
    .catch(err => {
        document.getElementById('v_error').style.display = 'block';
        document.getElementById('v_error').textContent = 'Network error. Please try again.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Create Vendor';
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
