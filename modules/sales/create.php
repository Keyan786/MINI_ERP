<?php
/**
 * Create Sales Order - Mini ERP System
 */

$pageTitle = 'Create Sales Order';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';

// Generate SO Number
$year = date('Y');
$stmt = $conn->query("SELECT so_number FROM tbl_sales_orders WHERE so_number LIKE 'SO-$year-%' ORDER BY so_number DESC LIMIT 1");
$lastSO = $stmt->fetch_assoc();
$nextNum = 1;
if ($lastSO) {
    $parts = explode('-', $lastSO['so_number']);
    $nextNum = intval(end($parts)) + 1;
}
$soNumber = "SO-$year-" . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

// Fetch Customers
$customers = $conn->query("SELECT * FROM tbl_customers WHERE is_active = 1 ORDER BY customer_name ASC")->fetch_all(MYSQLI_ASSOC);

// Fetch Sales Persons (Users)
$users = $conn->query("SELECT user_id, full_name FROM tbl_users WHERE status = 'active' ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// Fetch Products
$productsResult = $conn->query("SELECT product_id, product_code, product_name, sales_price, uom, on_hand_qty, reserved_qty FROM tbl_products WHERE is_active = 1 ORDER BY product_name ASC");
$products = [];
while ($row = $productsResult->fetch_assoc()) {
    $row['free_qty'] = max(0, $row['on_hand_qty'] - $row['reserved_qty']);
    $products[] = $row;
}
$productsJson = json_encode($products);

// Handle POST
if (is_post()) {
    if (csrf_validate()) {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesPersonId = intval($_POST['sales_person_id'] ?? 0);
        $customerAddress = trim($_POST['customer_address'] ?? '');
        $productIds = $_POST['product_id'] ?? [];
        $orderedQtys = $_POST['ordered_qty'] ?? [];
        
        if ($customerId <= 0) {
            set_flash('error', 'Please select a customer.');
        } elseif (empty($productIds)) {
            set_flash('error', 'Please add at least one product line.');
        } else {
            // Get customer name for snapshot
            $stmt = $conn->prepare("SELECT customer_name FROM tbl_customers WHERE customer_id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $cName = $stmt->get_result()->fetch_assoc()['customer_name'] ?? 'Unknown';
            $stmt->close();
            
            $conn->begin_transaction();
            try {
                // Insert Header
                $stmt = $conn->prepare("INSERT INTO tbl_sales_orders (so_number, customer_id, customer_name_snapshot, customer_address_snapshot, sales_person_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisssi", $soNumber, $customerId, $cName, $customerAddress, $salesPersonId, $_SESSION['user_id']);
                $stmt->execute();
                $soId = $conn->insert_id;
                $stmt->close();
                
                // Insert Lines
                $stmt = $conn->prepare("INSERT INTO tbl_so_lines (so_id, product_id, ordered_qty, uom, unit_price) VALUES (?, ?, ?, ?, ?)");
                foreach ($productIds as $index => $pid) {
                    $pid = intval($pid);
                    $qty = floatval($orderedQtys[$index] ?? 0);
                    if ($pid > 0 && $qty > 0) {
                        // Find product price and uom
                        $pPrice = 0; $pUom = 'Pcs';
                        foreach ($products as $p) {
                            if ($p['product_id'] == $pid) {
                                $pPrice = $p['sales_price'];
                                $pUom = $p['uom'];
                                break;
                            }
                        }
                        $stmt->bind_param("iidid", $soId, $pid, $qty, $pUom, $pPrice);
                        $stmt->execute();
                    }
                }
                $stmt->close();
                
                // Audit
                log_action($conn, 'Sales', ACTION_SO_CREATE, 'SalesOrder', $soId);
                
                $conn->commit();
                set_flash('success', "Sales Order $soNumber created successfully in Draft status.");
                redirect('/modules/sales/view.php?id=' . $soId);
                
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Failed to create Sales Order. ' . $e->getMessage());
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sales Orders</a>
        <h1>Create Sales Order</h1>
        <p class="page-header-desc">Draft a new sales order.</p>
    </div>
</div>

<form method="POST" action="" id="so-form">
    <?= csrf_field() ?>
    
    <div class="card animate-in">
        <div class="card-header">
            <h3><i class="fa-solid fa-file-invoice-dollar" style="color:var(--primary-color); margin-right:8px;"></i> Order Details</h3>
            <span class="badge badge-secondary">Draft</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label">SO Number</label>
                    <input type="text" class="form-control" value="<?= $soNumber ?>" readonly style="background:var(--surface-hover);">
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label">Creation Date</label>
                    <input type="text" class="form-control" value="<?= date('Y-m-d H:i') ?>" readonly style="background:var(--surface-hover);">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label">Customer <span class="text-danger">*</span></label>
                    <select name="customer_id" id="customer_id" class="form-control" required onchange="populateAddress()">
                        <option value="">— Select Customer —</option>
                        <?php foreach ($customers as $c): ?>
                            <?php 
                                $fullAddr = trim($c['address'] . "\n" . $c['city']); 
                            ?>
                            <option value="<?= $c['customer_id'] ?>" data-address="<?= e($fullAddr) ?>">
                                <?= e($c['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label">Sales Person <span class="text-danger">*</span></label>
                    <select name="sales_person_id" class="form-control" required>
                        <option value="">— Select Sales Person —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $u['user_id'] == $_SESSION['user_id'] ? 'selected' : '' ?>>
                                <?= e($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Address</label>
                <textarea name="customer_address" id="customer_address" class="form-control" rows="3" placeholder="Will auto-fill from customer selection..."></textarea>
                <small class="text-muted">This address will be snapshotted when the order is created.</small>
            </div>
        </div>
    </div>
    
    <div class="card animate-in" style="animation-delay: 0.1s;">
        <div class="card-header">
            <h3><i class="fa-solid fa-list-check" style="color:var(--primary-color); margin-right:8px;"></i> Product Lines</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Product <span class="text-danger">*</span></th>
                            <th style="width: 15%;">Availability</th>
                            <th style="width: 15%;">Unit Price</th>
                            <th style="width: 15%;">Ordered Qty <span class="text-danger">*</span></th>
                            <th style="width: 15%;">Total</th>
                            <th style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                        <!-- Lines will be added here -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right; font-weight: bold; padding-right: 15px;">Order Total:</td>
                            <td style="font-weight: bold; font-size: 1.1rem; color: var(--primary-color);">$<span id="order-total">0.00</span></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div style="padding: 15px;">
                <button type="button" class="btn btn-outline" onclick="addLine()"><i class="fa-solid fa-plus"></i> Add Line</button>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 24px; margin-bottom: 50px;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save as Draft</button>
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="btn btn-outline" style="margin-left: 10px;">Cancel</a>
    </div>
</form>

<script>
const products = <?= $productsJson ?>;

function populateAddress() {
    const sel = document.getElementById('customer_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('customer_address').value = opt.getAttribute('data-address');
    } else {
        document.getElementById('customer_address').value = '';
    }
}

function getProductOptions() {
    let html = '<option value="">— Select Product —</option>';
    products.forEach(p => {
        html += `<option value="${p.product_id}">[${p.product_code}] ${p.product_name}</option>`;
    });
    return html;
}

function addLine() {
    const tbody = document.getElementById('lines-body');
    const tr = document.createElement('tr');
    tr.className = 'so-line';
    
    tr.innerHTML = `
        <td>
            <select name="product_id[]" class="form-control product-select" required onchange="updateLine(this)">
                ${getProductOptions()}
            </select>
        </td>
        <td>
            <div class="availability-display" style="font-size:0.85rem; color:var(--text-muted); padding-top:8px;">-</div>
        </td>
        <td>
            <div class="price-display" style="padding-top:8px;">$0.00</div>
        </td>
        <td>
            <div style="display:flex; align-items:center; gap:5px;">
                <input type="number" name="ordered_qty[]" class="form-control qty-input" value="1" min="0.001" step="0.001" required oninput="calculateTotals()" style="width:100px;">
                <span class="uom-display text-muted" style="font-size:0.85rem;"></span>
            </div>
        </td>
        <td>
            <div class="total-display" style="font-weight:600; padding-top:8px;">$0.00</div>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline text-danger" onclick="this.closest('tr').remove(); calculateTotals();" style="border:none; padding:8px;">
                <i class="fa-solid fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
}

function updateLine(selectElem) {
    const tr = selectElem.closest('tr');
    const pid = selectElem.value;
    const availDisplay = tr.querySelector('.availability-display');
    const priceDisplay = tr.querySelector('.price-display');
    const uomDisplay = tr.querySelector('.uom-display');
    
    if (!pid) {
        availDisplay.innerHTML = '-';
        priceDisplay.innerText = '$0.00';
        uomDisplay.innerText = '';
        calculateTotals();
        return;
    }
    
    const p = products.find(x => x.product_id == pid);
    if (p) {
        // Availability formatting
        let availColor = p.free_qty > 0 ? 'var(--color-success)' : 'var(--color-danger)';
        availDisplay.innerHTML = `<span style="color:${availColor}; font-weight:500;">${parseFloat(p.free_qty).toFixed(2)}</span> ${p.uom} free`;
        
        // Price & UOM
        priceDisplay.innerText = '$' + parseFloat(p.sales_price).toFixed(2);
        uomDisplay.innerText = p.uom;
        
        // Save price in a data attribute on the row for easy calculation
        tr.setAttribute('data-price', p.sales_price);
    }
    
    calculateTotals();
}

function calculateTotals() {
    let orderTotal = 0;
    document.querySelectorAll('.so-line').forEach(tr => {
        const price = parseFloat(tr.getAttribute('data-price')) || 0;
        const qty = parseFloat(tr.querySelector('.qty-input').value) || 0;
        const lineTotal = price * qty;
        
        tr.querySelector('.total-display').innerText = '$' + lineTotal.toFixed(2);
        orderTotal += lineTotal;
    });
    
    document.getElementById('order-total').innerText = orderTotal.toFixed(2);
}

// Add an initial line
document.addEventListener('DOMContentLoaded', () => {
    addLine();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
