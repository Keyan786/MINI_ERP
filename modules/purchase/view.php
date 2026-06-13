<?php
/**
 * View / Manage Purchase Order - Mini ERP System
 * Status-aware page: view details, confirm, receive goods, or cancel.
 */

$pageTitle = 'Purchase Order';
$currentModule = 'purchase';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$poId = intval($_GET['id'] ?? 0);
if ($poId <= 0) {
    set_flash('error', 'Invalid purchase order ID.');
    redirect('/modules/purchase/index.php');
}

$errors = [];

// ─── Fetch PO ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT po.*, 
           resp.full_name as responsible_name,
           cr.full_name as creator_name,
           cfm.full_name as confirmer_name,
           rcv.full_name as receiver_name,
           cnl.full_name as canceller_name
    FROM tbl_purchase_orders po
    LEFT JOIN tbl_users resp ON po.responsible_user_id = resp.user_id
    LEFT JOIN tbl_users cr   ON po.created_by = cr.user_id
    LEFT JOIN tbl_users cfm  ON po.confirmed_by = cfm.user_id
    LEFT JOIN tbl_users rcv  ON po.received_by = rcv.user_id
    LEFT JOIN tbl_users cnl  ON po.cancelled_by = cnl.user_id
    WHERE po.po_id = ?
");
$stmt->bind_param("i", $poId);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    set_flash('error', 'Purchase order not found.');
    redirect('/modules/purchase/index.php');
}

// Fetch lines
$stmt = $conn->prepare("SELECT * FROM tbl_purchase_order_lines WHERE po_id = ? ORDER BY line_id ASC");
$stmt->bind_param("i", $poId);
$stmt->execute();
$lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status = $po['status'];
$isDraft = ($status === 'draft');
$isConfirmed = ($status === 'confirmed');
$isPartial = ($status === 'partially_received');
$isFullyReceived = ($status === 'fully_received');
$isCancelled = ($status === 'cancelled');
$canReceive = ($isConfirmed || $isPartial);
$canCancel = ($isDraft || $isConfirmed || $isPartial);

// ─── Handle POST Actions ────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = $_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');

        // ── CONFIRM ─────────────────────────────────────────────────────
        if ($action === 'confirm' && $isDraft) {
            if (empty($lines)) {
                $errors[] = 'Cannot confirm an empty purchase order.';
            } else {
                $stmt = $conn->prepare("UPDATE tbl_purchase_orders SET status = 'confirmed', confirmed_by = ?, confirmed_at = ? WHERE po_id = ? AND status = 'draft'");
                $stmt->bind_param("isi", $userId, $now, $poId);
                $stmt->execute();
                $stmt->close();

                if ($conn->affected_rows > 0) {
                    log_action($conn, 'Purchase', ACTION_PO_CONFIRM, 'Purchase Order', $poId,
                        ['status' => 'draft'],
                        ['status' => 'confirmed', 'confirmed_by' => $_SESSION['user_name'], 'confirmed_at' => $now]
                    );
                    set_flash('success', 'Purchase Order ' . $po['po_number'] . ' confirmed.');
                    redirect('/modules/purchase/view.php?id=' . $poId);
                } else {
                    $errors[] = 'Could not confirm — order may have already been updated.';
                }
            }
        }

        // ── RECEIVE ─────────────────────────────────────────────────────
        elseif ($action === 'receive' && $canReceive) {
            $conn->begin_transaction();
            try {
                $anyReceived = false;
                $oldValues = [];
                $newValues = [];

                foreach ($lines as $line) {
                    $inputKey = 'receive_qty_' . $line['line_id'];
                    $receiveQty = floatval($_POST[$inputKey] ?? 0);

                    if ($receiveQty <= 0) continue;

                    // Idempotency: check remaining
                    $remaining = $line['ordered_qty'] - $line['received_qty'];
                    if ($receiveQty > $remaining) {
                        throw new Exception('Cannot receive ' . fmt_qty($receiveQty) . ' for ' . $line['product_name'] . '. Remaining: ' . fmt_qty($remaining));
                    }

                    $newReceivedQty = $line['received_qty'] + $receiveQty;

                    // Update line
                    $stmtU = $conn->prepare("UPDATE tbl_purchase_order_lines SET received_qty = ? WHERE line_id = ? AND po_id = ?");
                    $stmtU->bind_param("dii", $newReceivedQty, $line['line_id'], $poId);
                    $stmtU->execute();
                    $stmtU->close();

                    // Update inventory via helper (locks row, updates on_hand, creates movement)
                    update_stock(
                        $conn,
                        $line['product_id'],
                        $receiveQty,
                        'purchase_in',
                        'Purchase Order',
                        $poId,
                        'PO #' . $po['po_number'] . ' — Goods Receipt',
                        $userId,
                        (float)$line['cost_price']
                    );

                    $oldValues[$line['product_code']] = ['received_qty' => (float)$line['received_qty']];
                    $newValues[$line['product_code']] = ['received_qty' => $newReceivedQty, 'this_receipt' => $receiveQty];
                    $anyReceived = true;
                }

                if (!$anyReceived) {
                    throw new Exception('Please enter a quantity to receive for at least one line.');
                }

                // Recalculate received_total
                $stmtRT = $conn->prepare("SELECT SUM(received_qty * cost_price) as rt FROM tbl_purchase_order_lines WHERE po_id = ?");
                $stmtRT->bind_param("i", $poId);
                $stmtRT->execute();
                $receivedTotal = (float)($stmtRT->get_result()->fetch_assoc()['rt'] ?? 0);
                $stmtRT->close();

                // Determine new status
                $stmtCheck = $conn->prepare("SELECT SUM(ordered_qty) as total_ord, SUM(received_qty) as total_rcv FROM tbl_purchase_order_lines WHERE po_id = ?");
                $stmtCheck->bind_param("i", $poId);
                $stmtCheck->execute();
                $totals = $stmtCheck->get_result()->fetch_assoc();
                $stmtCheck->close();

                $newStatus = ((float)$totals['total_rcv'] >= (float)$totals['total_ord']) ? 'fully_received' : 'partially_received';

                // Update PO header
                $stmtPO = $conn->prepare("UPDATE tbl_purchase_orders SET status = ?, received_total = ?, received_by = ?, received_at = ? WHERE po_id = ?");
                $stmtPO->bind_param("sdisi", $newStatus, $receivedTotal, $userId, $now, $poId);
                $stmtPO->execute();
                $stmtPO->close();

                // Audit
                log_action($conn, 'Purchase', ACTION_PO_RECEIVE, 'Purchase Order', $poId, $oldValues, $newValues);

                $conn->commit();

                $flashMsg = ($newStatus === 'fully_received')
                    ? 'All goods received for ' . $po['po_number'] . '. Purchase Order complete.'
                    : 'Partial goods received for ' . $po['po_number'] . '.';
                set_flash('success', $flashMsg);
                redirect('/modules/purchase/view.php?id=' . $poId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = $ex->getMessage();
            }
        }

        // ── CANCEL ──────────────────────────────────────────────────────
        elseif ($action === 'cancel' && $canCancel) {
            $stmt = $conn->prepare("UPDATE tbl_purchase_orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = ? WHERE po_id = ? AND status IN ('draft','confirmed','partially_received')");
            $stmt->bind_param("isi", $userId, $now, $poId);
            $stmt->execute();
            $stmt->close();

            if ($conn->affected_rows > 0) {
                log_action($conn, 'Purchase', ACTION_PO_CANCEL, 'Purchase Order', $poId,
                    ['status' => $status],
                    ['status' => 'cancelled', 'cancelled_by' => $_SESSION['user_name'], 'cancelled_at' => $now]
                );
                set_flash('info', 'Purchase Order ' . $po['po_number'] . ' has been cancelled.');
                redirect('/modules/purchase/view.php?id=' . $poId);
            } else {
                $errors[] = 'Could not cancel — order may have already been updated.';
            }
        }
    }

    // Re-fetch data after action
    $stmt = $conn->prepare("SELECT po.*, resp.full_name as responsible_name, cr.full_name as creator_name, cfm.full_name as confirmer_name, rcv.full_name as receiver_name, cnl.full_name as canceller_name FROM tbl_purchase_orders po LEFT JOIN tbl_users resp ON po.responsible_user_id = resp.user_id LEFT JOIN tbl_users cr ON po.created_by = cr.user_id LEFT JOIN tbl_users cfm ON po.confirmed_by = cfm.user_id LEFT JOIN tbl_users rcv ON po.received_by = rcv.user_id LEFT JOIN tbl_users cnl ON po.cancelled_by = cnl.user_id WHERE po.po_id = ?");
    $stmt->bind_param("i", $poId);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM tbl_purchase_order_lines WHERE po_id = ? ORDER BY line_id ASC");
    $stmt->bind_param("i", $poId);
    $stmt->execute();
    $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $status = $po['status'];
    $isDraft = ($status === 'draft');
    $isConfirmed = ($status === 'confirmed');
    $isPartial = ($status === 'partially_received');
    $isFullyReceived = ($status === 'fully_received');
    $isCancelled = ($status === 'cancelled');
    $canReceive = ($isConfirmed || $isPartial);
    $canCancel = ($isDraft || $isConfirmed || $isPartial);
}

// PO status badge helper
function po_status_badge(string $status): string {
    $map = [
        'draft'              => ['Draft', 'badge-secondary'],
        'confirmed'          => ['Confirmed', 'badge-info'],
        'partially_received' => ['Partially Received', 'badge-warning'],
        'fully_received'     => ['Fully Received', 'badge-success'],
        'cancelled'          => ['Cancelled', 'badge-danger'],
    ];
    $d = $map[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $d[1] . '">' . $d[0] . '</span>';
}

$pageTitle = 'PO ' . $po['po_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1><?= e($po['po_number']) ?></h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/purchase/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Purchase Orders
            </a>
        </p>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
        <?= po_status_badge($status) ?>
        <?php if ($isDraft): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm this Purchase Order? Fields will become read-only.')">
                    <i class="fa-solid fa-check-double"></i> Confirm
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel this Purchase Order? This action cannot be undone.')">
                    <i class="fa-solid fa-ban"></i> Cancel PO
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background:var(--color-danger-bg); border:1px solid rgba(220,38,38,0.15); border-radius:var(--border-radius-sm); padding:14px 16px; margin-bottom:20px; font-size:0.8125rem; color:var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Header Info Grid -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;" class="animate-in">
    <!-- Vendor Info -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-truck" style="color:var(--accent-primary); margin-right:8px;"></i>Vendor Information</h3>
        </div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Vendor</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);"><?= e($po['vendor_name']) ?></span>
                </div>
                <?php if ($po['vendor_contact_person']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Contact</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= e($po['vendor_contact_person']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['vendor_phone']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Phone</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= e($po['vendor_phone']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['vendor_email']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Email</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= e($po['vendor_email']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['vendor_address']): ?>
                <div style="padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted); display:block; margin-bottom:4px;">Address</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= e($po['vendor_address']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Order Details -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-file-invoice" style="color:var(--color-warning); margin-right:8px;"></i>Order Details</h3>
        </div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">PO Number</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace;"><?= e($po['po_number']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Created</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($po['created_at']) ?> by <?= e($po['creator_name'] ?? 'System') ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Responsible</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= $po['responsible_name'] ? e($po['responsible_name']) : '—' ?></span>
                </div>
                <?php if ($po['confirmed_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Confirmed</span>
                    <span style="font-size:0.8125rem; color:var(--color-success);"><?= format_datetime($po['confirmed_at']) ?> by <?= e($po['confirmer_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['received_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Last Received</span>
                    <span style="font-size:0.8125rem; color:var(--color-success);"><?= format_datetime($po['received_at']) ?> by <?= e($po['receiver_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['cancelled_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Cancelled</span>
                    <span style="font-size:0.8125rem; color:var(--color-danger);"><?= format_datetime($po['cancelled_at']) ?> by <?= e($po['canceller_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po['notes']): ?>
                <div style="padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted); display:block; margin-bottom:4px;">Notes</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= nl2br(e($po['notes'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Totals -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;" class="animate-in">
    <div class="card" style="text-align:center;">
        <div class="card-body" style="padding:24px;">
            <div style="font-size:0.8125rem; color:var(--text-muted); margin-bottom:6px;">Ordered Total</div>
            <div style="font-size:1.75rem; font-weight:700; color:var(--text-heading);"><?= fmt_price($po['ordered_total']) ?></div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body" style="padding:24px;">
            <div style="font-size:0.8125rem; color:var(--text-muted); margin-bottom:6px;">Received Total</div>
            <div style="font-size:1.75rem; font-weight:700; color:var(--color-success);"><?= fmt_price($po['received_total']) ?></div>
            <?php if ($po['ordered_total'] > 0): ?>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                    <?= round(($po['received_total'] / $po['ordered_total']) * 100, 1) ?>% received
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Line Items -->
<?php if ($canReceive): ?>
<form method="POST" action="" id="receive-form" style="margin-top:20px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="receive">
<?php endif; ?>

<div class="card animate-in" style="margin-top:<?= $canReceive ? '0' : '20px' ?>;">
    <div class="card-header">
        <h3><i class="fa-solid fa-list" style="color:var(--color-warning); margin-right:8px;"></i>Line Items</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>UOM</th>
                    <th style="text-align:right;">Cost Price</th>
                    <th style="text-align:right;">Ordered Qty</th>
                    <th style="text-align:right;">Received Qty</th>
                    <?php if ($canReceive): ?><th style="text-align:right;">Receive Now</th><?php endif; ?>
                    <th style="text-align:right;">Line Total</th>
                    <th>Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line):
                    $remaining = $line['ordered_qty'] - $line['received_qty'];
                    $pct = ($line['ordered_qty'] > 0) ? round(($line['received_qty'] / $line['ordered_qty']) * 100) : 0;
                ?>
                    <tr>
                        <td>
                            <span style="font-weight:600; color:var(--text-primary);"><?= e($line['product_name']) ?></span>
                            <div style="font-size:0.75rem; font-family:'Fira Code',monospace; color:var(--accent-primary);"><?= e($line['product_code']) ?></div>
                        </td>
                        <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($line['uom']) ?></td>
                        <td style="text-align:right; font-size:0.8125rem;"><?= fmt_price($line['cost_price']) ?></td>
                        <td style="text-align:right; font-weight:500;"><?= fmt_qty($line['ordered_qty']) ?></td>
                        <td style="text-align:right; font-weight:500; color:var(--color-success);"><?= fmt_qty($line['received_qty']) ?></td>
                        <?php if ($canReceive): ?>
                        <td style="text-align:right;">
                            <?php if ($remaining > 0): ?>
                                <input type="number" name="receive_qty_<?= $line['line_id'] ?>"
                                       class="form-control" step="0.001" min="0" max="<?= $remaining ?>"
                                       value="" placeholder="0"
                                       style="width:100px; text-align:right; font-size:0.8125rem; display:inline-block;">
                                <div style="font-size:0.6875rem; color:var(--text-muted); margin-top:2px;">max: <?= fmt_qty($remaining) ?></div>
                            <?php else: ?>
                                <span class="badge badge-success" style="font-size:0.6875rem;">Complete</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td style="text-align:right; font-weight:500;"><?= fmt_price($line['line_total']) ?></td>
                        <td>
                            <div style="width:80px; height:6px; background:var(--border-color); border-radius:3px; overflow:hidden;">
                                <div style="width:<?= $pct ?>%; height:100%; background:<?= $pct >= 100 ? 'var(--color-success)' : 'var(--accent-primary)' ?>; border-radius:3px; transition:width 0.3s;"></div>
                            </div>
                            <div style="font-size:0.6875rem; color:var(--text-muted); margin-top:2px;"><?= $pct ?>%</div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canReceive): ?>
    <div class="card-footer">
        <div></div>
        <button type="submit" class="btn btn-primary" id="receive-btn" onclick="return confirm('Confirm goods receipt? Inventory will be updated.')">
            <i class="fa-solid fa-truck-ramp-box"></i> Receive Goods
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($canReceive): ?>
</form>

<script>
// Disable double-submit on receive
document.getElementById('receive-form').addEventListener('submit', function() {
    const btn = document.getElementById('receive-btn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
});
</script>
<?php endif; ?>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
