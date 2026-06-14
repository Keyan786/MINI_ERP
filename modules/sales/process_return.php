<?php
/**
 * Process Sales Return - Mini ERP System
 */

$pageTitle = 'Process Sales Return';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$soId = intval($_GET['id'] ?? 0);
if ($soId <= 0) redirect('/modules/sales/index.php');

// Fetch Header
$stmt = $conn->prepare("SELECT * FROM tbl_sales_orders WHERE so_id = ?");
$stmt->bind_param("i", $soId);
$stmt->execute();
$so = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$so || !in_array($so['status'], ['partially_delivered', 'fully_delivered', 'cancelled'])) {
    set_flash('error', 'Only orders that have been delivered can have returns processed.');
    redirect("/modules/sales/view.php?id=$soId");
}

// Fetch Lines
$stmt = $conn->prepare("SELECT l.*, p.product_name, p.product_code, p.uom FROM tbl_so_lines l JOIN tbl_products p ON l.product_id = p.product_id WHERE l.so_id = ? AND l.delivered_qty > 0");
$stmt->bind_param("i", $soId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($lines)) {
    set_flash('error', 'No delivered items to return.');
    redirect("/modules/sales/view.php?id=$soId");
}

// Fetch Warehouses
$warehouses = $conn->query("SELECT warehouse_id, warehouse_name FROM tbl_warehouses WHERE is_active = 1 ORDER BY warehouse_name ASC")->fetch_all(MYSQLI_ASSOC);

$errors = [];

// Handle POST
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = "Invalid security token.";
    } else {
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $returnQtys = $_POST['return_qty'] ?? [];
        $notes = trim($_POST['notes'] ?? '');
        
        if ($warehouseId <= 0) $errors[] = "Please select a target warehouse for the returned items.";
        
        $totalReturnNow = 0;
        $updates = []; // To process after validation
        
        foreach ($lines as $l) {
            $lid = $l['line_id'];
            $rQty = floatval($returnQtys[$lid] ?? 0);
            $maxReturn = $l['delivered_qty'] - ($l['returned_qty'] ?? 0);
            
            if ($rQty < 0) {
                $errors[] = "Return quantity cannot be negative for {$l['product_code']}.";
            } elseif ($rQty > $maxReturn) {
                $errors[] = "Cannot return more than previously delivered (minus already returned) for {$l['product_code']}. Max allowed: $maxReturn.";
            } elseif ($rQty > 0) {
                $updates[] = [
                    'line_id' => $lid,
                    'product_id' => $l['product_id'],
                    'qty' => $rQty
                ];
                $totalReturnNow += $rQty;
            }
        }
        
        if (empty($errors)) {
            if ($totalReturnNow <= 0) {
                $errors[] = "No return quantities were entered.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmtUpdateLine = $conn->prepare("UPDATE tbl_so_lines SET returned_qty = returned_qty + ? WHERE line_id = ?");
                    
                    foreach ($updates as $u) {
                        // Update Line
                        $stmtUpdateLine->bind_param("di", $u['qty'], $u['line_id']);
                        $stmtUpdateLine->execute();
                        
                        // Increase On-Hand Qty and Record Stock Movement
                        update_stock($conn, $u['product_id'], $warehouseId, $u['qty'], 'sales_return_in', 'sales_return', $soId, $notes, $_SESSION['user_id']);
                    }
                    $stmtUpdateLine->close();
                    
                    log_action($conn, 'Sales', ACTION_SO_UPDATE_LINE, 'SalesReturn', $soId, [], ['returned_qty' => $totalReturnNow]);
                    $conn->commit();
                    set_flash('success', "Return processed successfully.");
                    redirect("/modules/sales/view.php?id=$soId");
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Return processing failed: " . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $soId ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sales Order</a>
        <h1>Process Return: <?= e($so['so_number']) ?></h1>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background:var(--color-danger-bg); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); padding:14px 16px; margin-bottom:20px; font-size:0.8125rem; color:var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <?= csrf_field() ?>
    
    <div class="card animate-in">
        <div class="card-header">
            <h3><i class="fa-solid fa-rotate-left" style="color:var(--color-warning); margin-right:8px;"></i> Return Details</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label" for="warehouse_id">Target Warehouse <span class="text-danger">*</span></label>
                    <select name="warehouse_id" id="warehouse_id" class="form-control form-select" required>
                        <option value="">— Select Warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                            <option value="<?= $w['warehouse_id'] ?>" <?= ($so['warehouse_id'] ?? 0) == $w['warehouse_id'] ? 'selected' : '' ?>>
                                <?= e($w['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Where will the returned goods be stored?</small>
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="notes">Return Reason / Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Explain why the customer is returning items..."><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <div class="card animate-in" style="animation-delay: 0.1s;">
        <div class="card-header">
            <h3><i class="fa-solid fa-list-check" style="color:var(--primary-color); margin-right:8px;"></i> Items to Return</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Product</th>
                            <th style="width: 20%;">Total Delivered</th>
                            <th style="width: 20%;">Already Returned</th>
                            <th style="width: 20%;">Return Qty Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): 
                            $returnedQty = $l['returned_qty'] ?? 0;
                            $maxReturn = $l['delivered_qty'] - $returnedQty;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;">[<?= e($l['product_code']) ?>]</div>
                                    <div><?= e($l['product_name']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500; color:var(--color-success);"><?= (float)$l['delivered_qty'] ?> <span class="text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span></div>
                                </td>
                                <td>
                                    <?php if ($returnedQty > 0): ?>
                                        <div style="font-weight:500; color:var(--color-danger);"><?= (float)$returnedQty ?> <span class="text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span></div>
                                    <?php else: ?>
                                        <div style="font-weight:500; color:var(--text-muted);">-</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($maxReturn > 0): ?>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <input type="number" name="return_qty[<?= $l['line_id'] ?>]" class="form-control" value="0" min="0" max="<?= $maxReturn ?>" step="0.001" style="width:120px;">
                                            <span class="text-muted" style="font-size:0.85rem;">/ <?= (float)$maxReturn ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Fully Returned</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer" style="display:flex; justify-content:flex-end; gap:10px;">
            <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $soId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-rotate-left"></i> Process Return</button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
