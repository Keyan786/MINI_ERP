<?php
/**
 * Process Purchase Return - Mini ERP System
 */

$pageTitle = 'Process Purchase Return';
$currentModule = 'purchase';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$poId = intval($_GET['id'] ?? 0);
if ($poId <= 0) redirect('/modules/purchase/index.php');

// Fetch Header
$stmt = $conn->prepare("SELECT * FROM tbl_purchase_orders WHERE po_id = ?");
$stmt->bind_param("i", $poId);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po || !in_array($po['status'], ['partially_received', 'fully_received', 'cancelled'])) {
    set_flash('error', 'Only orders that have been received can have returns processed.');
    redirect("/modules/purchase/view.php?id=$poId");
}

// Fetch Lines
$stmt = $conn->prepare("SELECT l.*, p.product_name, p.product_code, p.uom FROM tbl_purchase_order_lines l JOIN tbl_products p ON l.product_id = p.product_id WHERE l.po_id = ? AND l.received_qty > 0");
$stmt->bind_param("i", $poId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($lines)) {
    set_flash('error', 'No received items to return.');
    redirect("/modules/purchase/view.php?id=$poId");
}

// Fetch Destination Warehouse Stock Data to prevent returning more than we have
$warehouseId = $po['destination_warehouse_id'];

$errors = [];

// Handle POST
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = "Invalid security token.";
    } else {
        $returnQtys = $_POST['return_qty'] ?? [];
        $notes = trim($_POST['notes'] ?? '');
        
        $totalReturnNow = 0;
        $updates = []; // To process after validation
        
        foreach ($lines as $l) {
            $lid = $l['line_id'];
            $rQty = floatval($returnQtys[$lid] ?? 0);
            $maxReturn = $l['received_qty'] - ($l['returned_qty'] ?? 0);
            
            if ($rQty < 0) {
                $errors[] = "Return quantity cannot be negative for {$l['product_code']}.";
            } elseif ($rQty > $maxReturn) {
                $errors[] = "Cannot return more than previously received (minus already returned) for {$l['product_code']}. Max allowed: $maxReturn.";
            } elseif ($rQty > 0) {
                // Check if we have enough stock free to use in the warehouse to return to vendor
                $stmtStock = $conn->prepare("SELECT on_hand_qty, reserved_qty FROM tbl_product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?");
                $stmtStock->bind_param("ii", $l['product_id'], $warehouseId);
                $stmtStock->execute();
                $stock = $stmtStock->get_result()->fetch_assoc();
                $stmtStock->close();
                
                $freeQty = $stock ? get_free_qty((float)$stock['on_hand_qty'], (float)$stock['reserved_qty']) : 0;
                
                if ($rQty > $freeQty) {
                    $errors[] = "Not enough available stock for {$l['product_code']}. Only {$freeQty} free to return.";
                } else {
                    $updates[] = [
                        'line_id' => $lid,
                        'product_id' => $l['product_id'],
                        'qty' => $rQty
                    ];
                    $totalReturnNow += $rQty;
                }
            }
        }
        
        if (empty($errors)) {
            if ($totalReturnNow <= 0) {
                $errors[] = "No return quantities were entered.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmtUpdateLine = $conn->prepare("UPDATE tbl_purchase_order_lines SET returned_qty = returned_qty + ? WHERE line_id = ?");
                    
                    foreach ($updates as $u) {
                        // Update Line
                        $stmtUpdateLine->bind_param("di", $u['qty'], $u['line_id']);
                        $stmtUpdateLine->execute();
                        
                        // Decrease On-Hand Qty and Record Stock Movement
                        update_stock($conn, $u['product_id'], $warehouseId, -$u['qty'], 'purchase_return_out', 'purchase_return', $poId, $notes, $_SESSION['user_id']);
                    }
                    $stmtUpdateLine->close();
                    
                    log_action($conn, 'Purchase', ACTION_PO_RECEIVE, 'PurchaseReturn', $poId, [], ['returned_qty' => $totalReturnNow]);
                    $conn->commit();
                    set_flash('success', "Return processed successfully.");
                    redirect("/modules/purchase/view.php?id=$poId");
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
        <a href="<?= BASE_URL ?>/modules/purchase/view.php?id=<?= $poId ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Purchase Order</a>
        <h1>Process Return to Vendor: <?= e($po['po_number']) ?></h1>
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
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="notes">Return Reason / Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Explain why goods are being returned to the vendor..."><?= e($_POST['notes'] ?? '') ?></textarea>
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
                            <th style="width: 20%;">Total Received</th>
                            <th style="width: 20%;">Already Returned</th>
                            <th style="width: 20%;">Return Qty Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): 
                            $returnedQty = $l['returned_qty'] ?? 0;
                            $maxReturn = $l['received_qty'] - $returnedQty;
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;">[<?= e($l['product_code']) ?>]</div>
                                    <div><?= e($l['product_name']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500; color:var(--color-success);"><?= (float)$l['received_qty'] ?> <span class="text-muted" style="font-size:0.85rem;"><?= e($l['uom']) ?></span></div>
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
            <a href="<?= BASE_URL ?>/modules/purchase/view.php?id=<?= $poId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-warning"><i class="fa-solid fa-rotate-left"></i> Process Return</button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
