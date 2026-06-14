<?php
/**
 * View & Manage Sales Order - Mini ERP System
 */

$pageTitle = 'Sales Order Details';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/audit_log.php';

$soId = intval($_GET['id'] ?? 0);
if ($soId <= 0) redirect('/modules/sales/index.php');

// Fetch Header
$stmt = $conn->prepare("SELECT * FROM tbl_sales_orders WHERE so_id = ?");
$stmt->bind_param("i", $soId);
$stmt->execute();
$so = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$so) redirect('/modules/sales/index.php');

// Fetch Lines
$stmt = $conn->prepare("SELECT l.*, p.product_name, p.product_code, p.on_hand_qty, p.reserved_qty FROM tbl_so_lines l JOIN tbl_products p ON l.product_id = p.product_id WHERE l.so_id = ?");
$stmt->bind_param("i", $soId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Reference Data for Dropdowns (only needed if Draft)
$customers = []; $users = []; $products = [];
if ($so['status'] === 'draft') {
    $customers = $conn->query("SELECT * FROM tbl_customers WHERE is_active = 1 ORDER BY customer_name ASC")->fetch_all(MYSQLI_ASSOC);
    $users = $conn->query("SELECT user_id, full_name FROM tbl_users WHERE status = 'active' ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);
    
    $productsResult = $conn->query("SELECT product_id, product_code, product_name, sales_price, uom, on_hand_qty, reserved_qty FROM tbl_products WHERE is_active = 1 ORDER BY product_name ASC");
    while ($row = $productsResult->fetch_assoc()) {
        $row['free_qty'] = max(0, $row['on_hand_qty'] - $row['reserved_qty']);
        $products[] = $row;
    }
}

// ─── ACTIONS ────────────────────────────────────────────────────────────────
if (is_post() && csrf_validate()) {
    $action = $_POST['action'] ?? '';
    
    // 1. UPDATE DRAFT
    if ($action === 'update_draft' && $so['status'] === 'draft') {
        $conn->begin_transaction();
        try {
            // Update Header
            $customerId = intval($_POST['customer_id'] ?? 0);
            $salesPersonId = intval($_POST['sales_person_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT customer_name, address, city FROM tbl_customers WHERE customer_id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $cust = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $cName = $cust ? $cust['customer_name'] : 'Unknown';
            $cAddress = $cust ? trim($cust['address'] . "\n" . $cust['city']) : '';
            
            $stmt = $conn->prepare("UPDATE tbl_sales_orders SET customer_id = ?, customer_name_snapshot = ?, customer_address_snapshot = ?, sales_person_id = ? WHERE so_id = ?");
            $stmt->bind_param("isssi", $customerId, $cName, $cAddress, $salesPersonId, $soId);
            $stmt->execute();
            $stmt->close();
            
            // Delete old lines
            $conn->query("DELETE FROM tbl_so_lines WHERE so_id = $soId");
            
            // Insert new lines
            $productIds = $_POST['product_id'] ?? [];
            $orderedQtys = $_POST['ordered_qty'] ?? [];
            
            $stmt = $conn->prepare("INSERT INTO tbl_so_lines (so_id, product_id, ordered_qty, uom, unit_price) VALUES (?, ?, ?, ?, ?)");
            foreach ($productIds as $index => $pid) {
                $pid = intval($pid);
                $qty = floatval($orderedQtys[$index] ?? 0);
                if ($pid > 0 && $qty > 0) {
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
            
            log_action($conn, 'Sales', ACTION_SO_UPDATE_LINE, 'SalesOrder', $soId);
            $conn->commit();
            set_flash('success', 'Draft updated successfully.');
            redirect("/modules/sales/view.php?id=$soId");
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', 'Failed to update draft: ' . $e->getMessage());
        }
    }
    
    // 2. CONFIRM ORDER
    if ($action === 'confirm' && $so['status'] === 'draft') {
        // Validation: has customer, has lines, qty > 0, price > 0
        $hasErrors = false;
        if (empty($so['customer_id'])) {
            set_flash('error', 'Cannot confirm: Customer is missing.');
            $hasErrors = true;
        }
        if (empty($lines)) {
            set_flash('error', 'Cannot confirm: No product lines exist.');
            $hasErrors = true;
        }
        foreach ($lines as $l) {
            if ($l['ordered_qty'] <= 0) {
                set_flash('error', 'Cannot confirm: Line quantity must be greater than zero.');
                $hasErrors = true;
            }
        }
        
        if (!$hasErrors) {
            $conn->begin_transaction();
            try {
                // Change status
                $conn->query("UPDATE tbl_sales_orders SET status = 'confirmed' WHERE so_id = $soId");
                
                // Reserve stock in the assigned warehouse
                foreach ($lines as $l) {
                    reserve_stock($conn, $l['product_id'], $so['warehouse_id'], $l['ordered_qty']);
                }
                
                log_action($conn, 'Sales', ACTION_SO_CONFIRM, 'SalesOrder', $soId);
                $conn->commit();
                set_flash('success', 'Order confirmed. Stock has been reserved.');
                redirect("/modules/sales/view.php?id=$soId");
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Failed to confirm order: ' . $e->getMessage());
            }
        }
    }
    
    // 3. DELIVER
    if ($action === 'deliver' && in_array($so['status'], ['confirmed', 'partially_delivered'])) {
        $conn->begin_transaction();
        try {
            $deliverQtys = $_POST['deliver_qty'] ?? [];
            $totalRemaining = 0; // Check if order will be fully delivered after this
            $totalDeliveredNow = 0;
            
            $stmtUpdateLine = $conn->prepare("UPDATE tbl_so_lines SET delivered_qty = delivered_qty + ? WHERE line_id = ?");
            
            foreach ($lines as $l) {
                $lid = $l['line_id'];
                $newDeliverQty = floatval($deliverQtys[$lid] ?? 0);
                $remainingQty = $l['ordered_qty'] - $l['delivered_qty'];
                
                if ($newDeliverQty > 0) {
                    if ($newDeliverQty > $remainingQty) {
                        throw new Exception("Cannot deliver more than ordered for product " . $l['product_code']);
                    }
                    
                    // Update Line
                    $stmtUpdateLine->bind_param("di", $newDeliverQty, $lid);
                    $stmtUpdateLine->execute();
                    
                    // Decrease Reserved Qty in Warehouse
                    reserve_stock($conn, $l['product_id'], $so['warehouse_id'], -$newDeliverQty);

                    // Decrease On-Hand Qty and Record Stock Movement
                    update_stock($conn, $l['product_id'], $so['warehouse_id'], -$newDeliverQty, 'sales_out', 'sales_order', $soId, null, $_SESSION['user_id']);
                    
                    $totalDeliveredNow += $newDeliverQty;
                    $remainingQty -= $newDeliverQty;
                }
                $totalRemaining += $remainingQty;
            }
            
            if ($totalDeliveredNow > 0) {
                // Update SO Status
                $newStatus = ($totalRemaining <= 0.001) ? 'fully_delivered' : 'partially_delivered';
                $conn->query("UPDATE tbl_sales_orders SET status = '$newStatus' WHERE so_id = $soId");
                
                log_action($conn, 'Sales', ACTION_SO_DELIVER, 'SalesOrder', $soId, ['prev_status' => $so['status']], ['new_status' => $newStatus]);
                $conn->commit();
                set_flash('success', "Delivery recorded successfully. Status is now " . ucwords(str_replace('_', ' ', $newStatus)) . ".");
            } else {
                $conn->rollback();
                set_flash('error', 'No delivery quantities were entered.');
            }
            
            redirect("/modules/sales/view.php?id=$soId");
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', 'Delivery failed: ' . $e->getMessage());
        }
    }
    
    // 4. CANCEL
    if ($action === 'cancel' && in_array($so['status'], ['draft', 'confirmed', 'partially_delivered'])) {
        $conn->begin_transaction();
        try {
            // Release undelivered reserved stock
            if (in_array($so['status'], ['confirmed', 'partially_delivered'])) {
                foreach ($lines as $l) {
                    $undelivered = $l['ordered_qty'] - $l['delivered_qty'];
                    if ($undelivered > 0) {
                        reserve_stock($conn, $l['product_id'], $so['warehouse_id'], -$undelivered);
                    }
                }
            }
            
            // Change status
            $conn->query("UPDATE tbl_sales_orders SET status = 'cancelled' WHERE so_id = $soId");
            
            log_action($conn, 'Sales', ACTION_SO_CANCEL, 'SalesOrder', $soId);
            $conn->commit();
            set_flash('success', 'Order has been cancelled. Undelivered stock reservations released.');
            redirect("/modules/sales/view.php?id=$soId");
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', 'Failed to cancel order: ' . $e->getMessage());
        }
    }
}

// Calculate Document Totals Dynamically
$orderTotal = 0;
foreach ($lines as $l) {
    if (in_array($so['status'], ['draft', 'confirmed'])) {
        $orderTotal += ($l['ordered_qty'] * $l['unit_price']);
    } else {
        $orderTotal += ($l['delivered_qty'] * $l['unit_price']);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sales Orders</a>
        <h1>Sales Order: <?= e($so['so_number']) ?></h1>
    </div>
    <div style="display:flex; gap:10px;">
        <?php if ($so['status'] === 'draft'): ?>
            <form method="POST" action="" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm order? Stock will be reserved and fields locked.')">
                    <i class="fa-solid fa-check"></i> Confirm Order
                </button>
            </form>
            <button class="btn btn-primary" onclick="document.getElementById('so-form').submit();">
                <i class="fa-solid fa-save"></i> Save Draft
            </button>
        <?php endif; ?>
        
        <?php if (in_array($so['status'], ['confirmed', 'partially_delivered'])): ?>
            <button class="btn btn-success" onclick="document.getElementById('deliveryModal').style.display='flex'">
                <i class="fa-solid fa-truck"></i> Deliver
            </button>
        <?php endif; ?>
        
        <?php if (in_array($so['status'], ['draft', 'confirmed', 'partially_delivered'])): ?>
            <form method="POST" action="" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel this order? This action cannot be fully undone.')">
                    <i class="fa-solid fa-ban"></i> Cancel
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php 
// Status banner
$bColor = 'var(--text-muted)'; $bg = 'var(--surface-hover)';
if ($so['status'] === 'confirmed') { $bColor = '#fff'; $bg = 'var(--color-primary)'; }
if ($so['status'] === 'partially_delivered') { $bColor = '#000'; $bg = 'var(--color-warning)'; }
if ($so['status'] === 'fully_delivered') { $bColor = '#fff'; $bg = 'var(--color-success)'; }
if ($so['status'] === 'cancelled') { $bColor = '#fff'; $bg = 'var(--color-danger)'; }
?>
<div class="card animate-in" style="background: <?= $bg ?>; color: <?= $bColor ?>; border: none; margin-bottom: 20px;">
    <div class="card-body" style="padding: 15px 20px; font-weight: 600; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px;">
        STATUS: <?= str_replace('_', ' ', $so['status']) ?>
    </div>
</div>

<form method="POST" action="" id="so-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_draft">
    
    <div class="card animate-in">
        <div class="card-header">
            <h3><i class="fa-solid fa-file-invoice-dollar" style="color:var(--primary-color); margin-right:8px;"></i> Order Details</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label">SO Number</label>
                    <input type="text" class="form-control" value="<?= e($so['so_number']) ?>" readonly style="background:var(--surface-hover);">
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label">Creation Date</label>
                    <input type="text" class="form-control" value="<?= date('Y-m-d H:i', strtotime($so['created_at'])) ?>" readonly style="background:var(--surface-hover);">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label">Customer</label>
                    <?php if ($so['status'] === 'draft'): ?>
                        <select name="customer_id" class="form-control" required>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['customer_id'] ?>" <?= $so['customer_id'] == $c['customer_id'] ? 'selected' : '' ?>>
                                    <?= e($c['customer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($so['customer_name_snapshot']) ?>" readonly style="background:var(--surface-hover);">
                    <?php endif; ?>
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label">Sales Person</label>
                    <?php if ($so['status'] === 'draft'): ?>
                        <select name="sales_person_id" class="form-control" required>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>" <?= $so['sales_person_id'] == $u['user_id'] ? 'selected' : '' ?>>
                                    <?= e($u['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php 
                            $spName = 'Unknown';
                            $spStmt = $conn->query("SELECT full_name FROM tbl_users WHERE user_id = " . intval($so['sales_person_id']));
                            if ($sp = $spStmt->fetch_assoc()) $spName = $sp['full_name'];
                        ?>
                        <input type="text" class="form-control" value="<?= e($spName) ?>" readonly style="background:var(--surface-hover);">
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Address Snapshot</label>
                <?php if ($so['status'] === 'draft'): ?>
                    <textarea class="form-control" readonly style="background:var(--surface-hover);" rows="3"><?= e($so['customer_address_snapshot']) ?></textarea>
                    <small class="text-muted">Will be updated dynamically when confirmed.</small>
                <?php else: ?>
                    <textarea class="form-control" readonly style="background:var(--surface-hover);" rows="3"><?= e($so['customer_address_snapshot']) ?></textarea>
                <?php endif; ?>
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
                            <th style="width: 30%;">Product</th>
                            <?php if ($so['status'] === 'draft'): ?>
                                <th style="width: 15%;">Availability</th>
                            <?php endif; ?>
                            <th style="width: 15%;">Unit Price</th>
                            <th style="width: 15%;">Ordered Qty</th>
                            <th style="width: 15%;">Delivered Qty</th>
                            <th style="width: 10%;">Line Total</th>
                            <?php if ($so['status'] === 'draft'): ?>
                                <th style="width: 5%;"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                        <?php foreach ($lines as $l): 
                            $lineTotal = in_array($so['status'], ['draft', 'confirmed']) 
                                ? ($l['ordered_qty'] * $l['unit_price']) 
                                : ($l['delivered_qty'] * $l['unit_price']);
                        ?>
                            <tr class="so-line" data-price="<?= $l['unit_price'] ?>">
                                <td>
                                    <?php if ($so['status'] === 'draft'): ?>
                                        <select name="product_id[]" class="form-control product-select" required onchange="updateLine(this)">
                                            <?php foreach ($products as $p): ?>
                                                <option value="<?= $p['product_id'] ?>" <?= $p['product_id'] == $l['product_id'] ? 'selected' : '' ?>>
                                                    [<?= e($p['product_code']) ?>] <?= e($p['product_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <div style="font-weight:600;">[<?= e($l['product_code']) ?>]</div>
                                        <div><?= e($l['product_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <?php if ($so['status'] === 'draft'): ?>
                                    <td>
                                        <?php 
                                            $free = max(0, $l['on_hand_qty'] - $l['reserved_qty']);
                                            $color = $free > 0 ? 'var(--color-success)' : 'var(--color-danger)';
                                        ?>
                                        <div class="availability-display" style="font-size:0.85rem; padding-top:8px;">
                                            <span style="color:<?= $color ?>; font-weight:500;"><?= number_format($free, 2) ?></span> <?= e($l['uom']) ?> free
                                        </div>
                                    </td>
                                <?php endif; ?>
                                
                                <td>
                                    <div class="price-display" style="padding-top:8px;">₹<?= number_format($l['unit_price'], 2) ?></div>
                                </td>
                                
                                <td>
                                    <?php if ($so['status'] === 'draft'): ?>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <input type="number" name="ordered_qty[]" class="form-control qty-input" value="<?= (float)$l['ordered_qty'] ?>" min="0.001" step="0.001" required oninput="calculateTotals()" style="width:100px;">
                                            <span class="uom-display text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div style="padding-top:8px; font-weight:500;"><?= (float)$l['ordered_qty'] ?> <span class="text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div style="padding-top:8px; font-weight:500; color:var(--color-success);"><?= (float)$l['delivered_qty'] ?> <span class="text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span></div>
                                </td>
                                
                                <td>
                                    <div class="total-display" style="font-weight:600; padding-top:8px;">₹<?= number_format($lineTotal, 2) ?></div>
                                </td>
                                
                                <?php if ($so['status'] === 'draft'): ?>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline text-danger" onclick="this.closest('tr').remove(); calculateTotals();" style="border:none; padding:8px;">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?= $so['status'] === 'draft' ? '5' : '4' ?>" style="text-align: right; font-weight: bold; padding-right: 15px;">
                                <?php if (in_array($so['status'], ['draft', 'confirmed'])): ?>
                                    Order Total (Ordered):
                                <?php else: ?>
                                    Order Total (Delivered):
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: bold; font-size: 1.1rem; color: var(--primary-color);">₹<span id="order-total"><?= number_format($orderTotal, 2) ?></span></td>
                            <?php if ($so['status'] === 'draft'): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <?php if ($so['status'] === 'draft'): ?>
                <div style="padding: 15px;">
                    <button type="button" class="btn btn-outline" onclick="addLine()"><i class="fa-solid fa-plus"></i> Add Line</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (in_array($so['status'], ['confirmed', 'partially_delivered'])): ?>
<!-- Delivery Modal -->
<div id="deliveryModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div class="card animate-in" style="width: 700px; max-width: 90%; background:#fff; padding: 20px;">
        <h3 style="margin-top:0;"><i class="fa-solid fa-truck" style="color:var(--color-success);"></i> Process Delivery</h3>
        <p style="font-size: 0.85rem; color: var(--text-secondary);">Enter the <strong>newly delivered quantity</strong> for each line.</p>
        
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deliver">
            
            <div class="table-wrapper" style="margin-bottom: 20px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Ordered</th>
                            <th>Already Delivered</th>
                            <th>Remaining</th>
                            <th>New Delivery Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): 
                            $remaining = $l['ordered_qty'] - $l['delivered_qty'];
                        ?>
                            <tr>
                                <td style="font-size:0.85rem;"><?= e($l['product_name']) ?></td>
                                <td><?= (float)$l['ordered_qty'] ?></td>
                                <td style="color:var(--color-success); font-weight:600;"><?= (float)$l['delivered_qty'] ?></td>
                                <td style="color:var(--color-warning); font-weight:600;"><?= (float)$remaining ?></td>
                                <td>
                                    <?php if ($remaining > 0): ?>
                                        <input type="number" name="deliver_qty[<?= $l['line_id'] ?>]" class="form-control" value="0" min="0" max="<?= $remaining ?>" step="0.001" required style="width:120px;">
                                    <?php else: ?>
                                        <span class="badge badge-success">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('deliveryModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-success">Confirm Delivery</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($so['status'] === 'draft'): ?>
<script>
const products = <?= json_encode($products) ?>;

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
            <div class="price-display" style="padding-top:8px;">₹0.00</div>
        </td>
        <td>
            <div style="display:flex; align-items:center; gap:5px;">
                <input type="number" name="ordered_qty[]" class="form-control qty-input" value="1" min="0.001" step="0.001" required oninput="calculateTotals()" style="width:100px;">
                <span class="uom-display text-muted" style="font-size:0.85rem;"></span>
            </div>
        </td>
        <td>
            <div class="total-display" style="font-weight:600; padding-top:8px;">₹0.00</div>
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
        priceDisplay.innerText = '₹0.00';
        uomDisplay.innerText = '';
        calculateTotals();
        return;
    }
    
    const p = products.find(x => x.product_id == pid);
    if (p) {
        let availColor = p.free_qty > 0 ? 'var(--color-success)' : 'var(--color-danger)';
        availDisplay.innerHTML = `<span style="color:${availColor}; font-weight:500;">${parseFloat(p.free_qty).toFixed(2)}</span> ${p.uom} free`;
        
        priceDisplay.innerText = '₹' + parseFloat(p.sales_price).toFixed(2);
        uomDisplay.innerText = p.uom;
        
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
        
        tr.querySelector('.total-display').innerText = '₹' + lineTotal.toFixed(2);
        orderTotal += lineTotal;
    });
    
    document.getElementById('order-total').innerText = orderTotal.toFixed(2);
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
