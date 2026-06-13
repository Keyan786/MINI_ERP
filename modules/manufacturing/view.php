<?php
/**
 * View / Manage Manufacturing Order - Mini ERP System
 * Status-aware page: view details, confirm, produce, consume, cancel.
 * Lifecycle: Draft → Confirmed → Done | Cancelled
 */

$pageTitle = 'Manufacturing Order';
$currentModule = 'manufacturing';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$moId = intval($_GET['id'] ?? 0);
if ($moId <= 0) {
    set_flash('error', 'Invalid Manufacturing Order ID.');
    redirect('/modules/manufacturing/index.php');
}

$errors = [];

// ─── Fetch MO ───────────────────────────────────────────────────────────────
function fetchMO(mysqli $conn, int $moId): ?array {
    $stmt = $conn->prepare("
        SELECT mo.*,
               w.warehouse_name,
               p.product_name, p.product_code, p.uom as product_uom,
               b.bom_code, b.bom_name,
               au.full_name as assignee_name,
               cr.full_name as creator_name,
               cfm.full_name as confirmer_name,
               cnl.full_name as canceller_name
        FROM tbl_manufacturing_orders mo
        LEFT JOIN tbl_warehouses w ON mo.warehouse_id = w.warehouse_id
        LEFT JOIN tbl_products p ON mo.product_id = p.product_id
        LEFT JOIN tbl_bom b ON mo.bom_id = b.bom_id
        LEFT JOIN tbl_users au ON mo.assigned_user_id = au.user_id
        LEFT JOIN tbl_users cr ON mo.created_by = cr.user_id
        LEFT JOIN tbl_users cfm ON mo.confirmed_by = cfm.user_id
        LEFT JOIN tbl_users cnl ON mo.cancelled_by = cnl.user_id
        WHERE mo.mo_id = ?
    ");
    $stmt->bind_param("i", $moId);
    $stmt->execute();
    $mo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $mo;
}

function fetchComponents(mysqli $conn, int $moId, int $warehouseId): array {
    $stmt = $conn->prepare("
        SELECT mc.*, COALESCE(ws.on_hand_qty, 0) as on_hand_qty, COALESCE(ws.reserved_qty, 0) as reserved_qty
        FROM tbl_mo_components mc
        LEFT JOIN tbl_product_warehouse_stock ws ON mc.product_id = ws.product_id AND ws.warehouse_id = ?
        WHERE mc.mo_id = ?
        ORDER BY mc.mo_component_id ASC
    ");
    $stmt->bind_param("ii", $warehouseId, $moId);
    $stmt->execute();
    $comps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $comps;
}

function fetchWorkOrders(mysqli $conn, int $moId): array {
    $stmt = $conn->prepare("
        SELECT wo.*, u.full_name as assignee_name
        FROM tbl_mo_work_orders wo
        LEFT JOIN tbl_users u ON wo.assigned_user_id = u.user_id
        WHERE wo.mo_id = ?
        ORDER BY wo.sequence ASC, wo.wo_id ASC
    ");
    $stmt->bind_param("i", $moId);
    $stmt->execute();
    $wos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $wos;
}

$mo = fetchMO($conn, $moId);
if (!$mo) {
    set_flash('error', 'Manufacturing Order not found.');
    redirect('/modules/manufacturing/index.php');
}

$components = fetchComponents($conn, $moId, (int)$mo['warehouse_id']);
$workOrders = fetchWorkOrders($conn, $moId);

$status = $mo['status'];
$isDraft = ($status === 'draft');
$isConfirmed = ($status === 'confirmed');
$isDone = ($status === 'done');
$isCancelled = ($status === 'cancelled');
$isReadonly = ($isDone || $isCancelled);

// ─── Handle POST Actions ────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = $_SESSION['user_id'];
        $now = date('Y-m-d H:i:s');

        // ── CONFIRM (Draft → Confirmed) ─────────────────────────────────
        if ($action === 'confirm' && $isDraft) {
            if (empty($components)) {
                $errors[] = 'Cannot confirm an order with no components. Add components first.';
            } else {
                $conn->begin_transaction();
                try {
                    // Snapshot component costs and reserve stock
                    foreach ($components as $comp) {
                        // Get current cost price
                        $stmtP = $conn->prepare("SELECT cost_price FROM tbl_products WHERE product_id = ? FOR UPDATE");
                        $stmtP->bind_param("i", $comp['product_id']);
                        $stmtP->execute();
                        $prod = $stmtP->get_result()->fetch_assoc();
                        $stmtP->close();

                        $unitCost = (float)($prod['cost_price'] ?? 0);

                        // Update component with cost snapshot
                        $stmtU = $conn->prepare("UPDATE tbl_mo_components SET unit_cost = ? WHERE mo_component_id = ?");
                        $stmtU->bind_param("di", $unitCost, $comp['mo_component_id']);
                        $stmtU->execute();
                        $stmtU->close();

                        // Reserve stock via helper
                        reserve_stock($conn, $comp['product_id'], (int)$mo['warehouse_id'], $comp['required_qty']);
                    }

                    // Update MO status (actual_start NOT set here — Option B)
                    $stmt = $conn->prepare("UPDATE tbl_manufacturing_orders SET status = 'confirmed', confirmed_by = ?, confirmed_at = ? WHERE mo_id = ? AND status = 'draft'");
                    $stmt->bind_param("isi", $userId, $now, $moId);
                    $stmt->execute();
                    $stmt->close();

                    if ($conn->affected_rows > 0) {
                        log_action($conn, 'Manufacturing', ACTION_MO_CONFIRM, 'Manufacturing Order', $moId,
                            ['status' => 'draft'],
                            ['status' => 'confirmed', 'confirmed_by' => $_SESSION['user_name'], 'confirmed_at' => $now, 'components_reserved' => count($components)]
                        );
                        $conn->commit();
                        set_flash('success', 'Manufacturing Order ' . $mo['mo_number'] . ' confirmed. Component stock reserved.');
                        redirect('/modules/manufacturing/view.php?id=' . $moId);
                    } else {
                        throw new Exception('Could not confirm — order may have already been updated.');
                    }
                } catch (Exception $ex) {
                    $conn->rollback();
                    $errors[] = $ex->getMessage();
                }
            }
        }

        // ── CONSUME MATERIALS (Confirmed) ───────────────────────────────
        elseif ($action === 'consume' && $isConfirmed) {
            $conn->begin_transaction();
            try {
                $anyConsumed = false;
                $consumeLog = [];

                foreach ($components as $comp) {
                    $inputKey = 'consume_qty_' . $comp['mo_component_id'];
                    $consumeQty = floatval($_POST[$inputKey] ?? 0);

                    if ($consumeQty <= 0) continue;

                    $remaining = (float)$comp['required_qty'] - (float)$comp['consumed_qty'];
                    if ($consumeQty > $remaining + 0.001) {
                        throw new Exception('Cannot consume ' . fmt_qty($consumeQty) . ' of ' . $comp['product_name'] . '. Remaining: ' . fmt_qty($remaining));
                    }

                    $newConsumed = (float)$comp['consumed_qty'] + $consumeQty;

                    // Update consumed qty
                    $stmtU = $conn->prepare("UPDATE tbl_mo_components SET consumed_qty = ? WHERE mo_component_id = ?");
                    $stmtU->bind_param("di", $newConsumed, $comp['mo_component_id']);
                    $stmtU->execute();
                    $stmtU->close();

                    // Create stock movement (manufacturing_consume)
                    update_stock(
                        $conn,
                        $comp['product_id'],
                        (int)$mo['warehouse_id'],
                        -$consumeQty,
                        'manufacturing_consume',
                        'Manufacturing Order',
                        $moId,
                        'MO #' . $mo['mo_number'] . ' — Material Consumption',
                        $userId,
                        (float)$comp['unit_cost']
                    );

                    // Decrease reserved_qty via helper
                    release_stock($conn, $comp['product_id'], (int)$mo['warehouse_id'], $consumeQty);

                    $consumeLog[$comp['product_code']] = ['consumed' => $consumeQty, 'new_total' => $newConsumed];
                    $anyConsumed = true;
                }

                if (!$anyConsumed) {
                    throw new Exception('Please enter a quantity to consume for at least one component.');
                }

                // Set actual_start if NULL (Option B — first shop-floor activity)
                if (empty($mo['actual_start'])) {
                    $stmtAS = $conn->prepare("UPDATE tbl_manufacturing_orders SET actual_start = ? WHERE mo_id = ? AND actual_start IS NULL");
                    $stmtAS->bind_param("si", $now, $moId);
                    $stmtAS->execute();
                    $stmtAS->close();
                }

                log_action($conn, 'Manufacturing', ACTION_MO_CONSUME, 'Manufacturing Order', $moId, null, $consumeLog);

                $conn->commit();
                set_flash('success', 'Materials consumed successfully.');
                redirect('/modules/manufacturing/view.php?id=' . $moId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = $ex->getMessage();
            }
        }

        // ── PRODUCE (Confirmed → Done) ──────────────────────────────────
        elseif ($action === 'produce' && $isConfirmed) {
            $conn->begin_transaction();
            try {
                // Auto-consume all remaining component quantities
                $components = fetchComponents($conn, $moId, (int)$mo['warehouse_id']);
                foreach ($components as $comp) {
                    $remaining = (float)$comp['required_qty'] - (float)$comp['consumed_qty'];
                    if ($remaining > 0.001) {
                        // Update consumed qty to required
                        $stmtU = $conn->prepare("UPDATE tbl_mo_components SET consumed_qty = required_qty WHERE mo_component_id = ?");
                        $stmtU->bind_param("i", $comp['mo_component_id']);
                        $stmtU->execute();
                        $stmtU->close();

                        // Stock movement for remaining consumption
                        update_stock(
                            $conn,
                            $comp['product_id'],
                            (int)$mo['warehouse_id'],
                            -$remaining,
                            'manufacturing_consume',
                            'Manufacturing Order',
                            $moId,
                            'MO #' . $mo['mo_number'] . ' — Final Consumption (Produce)',
                            $userId,
                            (float)$comp['unit_cost']
                        );

                        // Release reserved qty via helper
                        release_stock($conn, $comp['product_id'], (int)$mo['warehouse_id'], $remaining);
                    }
                }

                // Create finished goods stock movement (manufacturing_in)
                $moQty = (float)$mo['quantity'];
                update_stock(
                    $conn,
                    $mo['product_id'],
                    (int)$mo['warehouse_id'],
                    $moQty,
                    'manufacturing_in',
                    'Manufacturing Order',
                    $moId,
                    'MO #' . $mo['mo_number'] . ' — Production Complete',
                    $userId
                );

                // Set actual_start if still NULL
                if (empty($mo['actual_start'])) {
                    $stmtAS = $conn->prepare("UPDATE tbl_manufacturing_orders SET actual_start = ? WHERE mo_id = ? AND actual_start IS NULL");
                    $stmtAS->bind_param("si", $now, $moId);
                    $stmtAS->execute();
                    $stmtAS->close();
                }

                // Update MO: produced_qty, actual_end, status = done
                $stmt = $conn->prepare("UPDATE tbl_manufacturing_orders SET produced_qty = quantity, actual_end = ?, status = 'done' WHERE mo_id = ? AND status = 'confirmed'");
                $stmt->bind_param("si", $now, $moId);
                $stmt->execute();
                $stmt->close();

                // Complete any pending work orders
                $conn->query("UPDATE tbl_mo_work_orders SET status = 'completed', completed_at = '$now' WHERE mo_id = $moId AND status IN ('pending','in_progress')");

                log_action($conn, 'Manufacturing', ACTION_MO_PRODUCE, 'Manufacturing Order', $moId,
                    ['status' => 'confirmed'],
                    ['status' => 'done', 'produced_qty' => $moQty, 'actual_end' => $now]
                );

                $conn->commit();
                set_flash('success', 'Production complete for ' . $mo['mo_number'] . '. Finished goods received into inventory.');
                redirect('/modules/manufacturing/view.php?id=' . $moId);

            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = $ex->getMessage();
            }
        }

        // ── CANCEL (Draft/Confirmed → Cancelled) ────────────────────────
        elseif ($action === 'cancel' && ($isDraft || $isConfirmed)) {
            $conn->begin_transaction();
            try {
                // If confirmed, release reserved quantities
                if ($isConfirmed) {
                    $components = fetchComponents($conn, $moId, (int)$mo['warehouse_id']);
                    foreach ($components as $comp) {
                        $unreserve = (float)$comp['required_qty'] - (float)$comp['consumed_qty'];
                        if ($unreserve > 0.001) {
                            release_stock($conn, $comp['product_id'], (int)$mo['warehouse_id'], $unreserve);
                        }
                    }
                }

                // Cancel work orders
                $conn->query("UPDATE tbl_mo_work_orders SET status = 'cancelled' WHERE mo_id = $moId AND status IN ('pending','in_progress')");

                $stmt = $conn->prepare("UPDATE tbl_manufacturing_orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = ? WHERE mo_id = ? AND status IN ('draft','confirmed')");
                $stmt->bind_param("isi", $userId, $now, $moId);
                $stmt->execute();
                $stmt->close();

                if ($conn->affected_rows > 0) {
                    log_action($conn, 'Manufacturing', ACTION_MO_CANCEL, 'Manufacturing Order', $moId,
                        ['status' => $status],
                        ['status' => 'cancelled', 'cancelled_by' => $_SESSION['user_name'], 'cancelled_at' => $now, 'was_confirmed' => $isConfirmed]
                    );
                    $conn->commit();
                    set_flash('info', 'Manufacturing Order ' . $mo['mo_number'] . ' has been cancelled.' . ($isConfirmed ? ' Reserved stock released.' : ''));
                    redirect('/modules/manufacturing/view.php?id=' . $moId);
                } else {
                    throw new Exception('Could not cancel — order may have already been updated.');
                }
            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = $ex->getMessage();
            }
        }

        // ── WORK ORDER STATUS UPDATE ────────────────────────────────────
        elseif ($action === 'wo_start' && $isConfirmed) {
            $woId = intval($_POST['wo_id'] ?? 0);
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE tbl_mo_work_orders SET status = 'in_progress', started_at = ? WHERE wo_id = ? AND mo_id = ? AND status = 'pending'");
                $stmt->bind_param("sii", $now, $woId, $moId);
                $stmt->execute();
                $stmt->close();

                // Set actual_start if NULL (Option B)
                if (empty($mo['actual_start'])) {
                    $stmtAS = $conn->prepare("UPDATE tbl_manufacturing_orders SET actual_start = ? WHERE mo_id = ? AND actual_start IS NULL");
                    $stmtAS->bind_param("si", $now, $moId);
                    $stmtAS->execute();
                    $stmtAS->close();
                }

                log_action($conn, 'Manufacturing', ACTION_MO_UPDATE, 'Work Order', $woId, ['status' => 'pending'], ['status' => 'in_progress']);
                $conn->commit();
                set_flash('success', 'Work order started.');
                redirect('/modules/manufacturing/view.php?id=' . $moId);
            } catch (Exception $ex) {
                $conn->rollback();
                $errors[] = $ex->getMessage();
            }
        }

        elseif ($action === 'wo_complete' && $isConfirmed) {
            $woId = intval($_POST['wo_id'] ?? 0);
            $realDuration = floatval($_POST['real_duration'] ?? 0);
            $stmt = $conn->prepare("UPDATE tbl_mo_work_orders SET status = 'completed', completed_at = ?, actual_duration_minutes = ? WHERE wo_id = ? AND mo_id = ? AND status IN ('pending','in_progress')");
            $stmt->bind_param("sdii", $now, $realDuration, $woId, $moId);
            $stmt->execute();
            $stmt->close();

            log_action($conn, 'Manufacturing', ACTION_MO_UPDATE, 'Work Order', $woId, null, ['status' => 'completed', 'actual_duration' => $realDuration]);
            set_flash('success', 'Work order completed.');
            redirect('/modules/manufacturing/view.php?id=' . $moId);
        }

        // ── SAVE WORK ORDER REAL DURATIONS ──────────────────────────────
        elseif ($action === 'save_durations' && $isConfirmed) {
            foreach ($workOrders as $wo) {
                $key = 'real_dur_' . $wo['wo_id'];
                $dur = floatval($_POST[$key] ?? $wo['actual_duration_minutes']);
                $stmtD = $conn->prepare("UPDATE tbl_mo_work_orders SET actual_duration_minutes = ? WHERE wo_id = ? AND mo_id = ?");
                $stmtD->bind_param("dii", $dur, $wo['wo_id'], $moId);
                $stmtD->execute();
                $stmtD->close();
            }
            set_flash('success', 'Work order durations saved.');
            redirect('/modules/manufacturing/view.php?id=' . $moId);
        }
    }

    // Re-fetch after action
    $mo = fetchMO($conn, $moId);
    $components = fetchComponents($conn, $moId, (int)$mo['warehouse_id']);
    $workOrders = fetchWorkOrders($conn, $moId);
    $status = $mo['status'];
    $isDraft = ($status === 'draft');
    $isConfirmed = ($status === 'confirmed');
    $isDone = ($status === 'done');
    $isCancelled = ($status === 'cancelled');
    $isReadonly = ($isDone || $isCancelled);
}

// Status badge helper
function mo_status_badge(string $status): string {
    $map = [
        'draft'     => ['Draft', 'badge-secondary'],
        'confirmed' => ['Confirmed', 'badge-info'],
        'done'      => ['Done', 'badge-success'],
        'cancelled' => ['Cancelled', 'badge-danger'],
    ];
    $d = $map[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $d[1] . '">' . $d[0] . '</span>';
}

function wo_status_badge(string $status): string {
    $map = [
        'pending'     => ['Pending', 'badge-secondary'],
        'in_progress' => ['In Progress', 'badge-warning'],
        'completed'   => ['Completed', 'badge-success'],
        'cancelled'   => ['Cancelled', 'badge-danger'],
    ];
    $d = $map[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $d[1] . '">' . $d[0] . '</span>';
}

$pageTitle = 'MO ' . $mo['mo_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1><?= e($mo['mo_number']) ?></h1>
        <p class="page-header-desc">
            <a href="<?= BASE_URL ?>/modules/manufacturing/index.php" style="color:var(--text-muted);">
                <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Back to Manufacturing Orders
            </a>
        </p>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
        <?= mo_status_badge($status) ?>

        <?php if ($isDraft): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm this Manufacturing Order? Component stock will be reserved and key fields will be locked.')">
                    <i class="fa-solid fa-check-double"></i> Confirm
                </button>
            </form>
        <?php endif; ?>

        <?php if ($isConfirmed): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="produce">
                <button type="submit" class="btn btn-primary" style="background:var(--color-success); border-color:var(--color-success);" onclick="return confirm('Mark as Produced? This will:\n• Auto-consume all remaining materials\n• Add finished goods to inventory\n• Mark the order as Done\n\nThis action cannot be undone.')">
                    <i class="fa-solid fa-check-circle"></i> Produce
                </button>
            </form>
        <?php endif; ?>

        <?php if ($isDraft || $isConfirmed): ?>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel this Manufacturing Order?<?= $isConfirmed ? ' Reserved stock will be released.' : '' ?>')">
                    <i class="fa-solid fa-ban"></i> Cancel
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
    <!-- Product Info -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-boxes-stacked" style="color:var(--accent-primary); margin-right:8px;"></i>Product Information</h3>
        </div>
        <div class="card-body">
            <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Finished Product</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);">
                        <?= e($mo['product_name'] ?? '—') ?>
                        <span style="font-family:'Fira Code',monospace; color:var(--text-muted); font-size:0.75rem; margin-left:6px;"><?= e($mo['product_code'] ?? '') ?></span>
                    </span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Bill of Materials</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);">
                        <?php if ($mo['bom_id']): ?>
                            <a href="<?= BASE_URL ?>/modules/bom/view.php?id=<?= $mo['bom_id'] ?>" style="color:var(--accent-primary);">
                                <?= e($mo['bom_code'] ?? '') ?> — <?= e($mo['bom_name'] ?? '') ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">Manual Components</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Planned Quantity</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);"><?= fmt_qty($mo['quantity']) ?> <?= e($mo['product_uom'] ?? '') ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Produced Quantity</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:<?= $mo['produced_qty'] > 0 ? 'var(--color-success)' : 'var(--text-muted)' ?>;"><?= fmt_qty($mo['produced_qty']) ?> <?= e($mo['product_uom'] ?? '') ?></span>
                </div>
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
                    <span style="font-size:0.8125rem; color:var(--text-muted);">MO Number</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace;"><?= e($mo['mo_number']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Production Warehouse</span>
                    <span style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);"><?= e($mo['warehouse_name'] ?? '—') ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Created</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= format_datetime($mo['created_at']) ?> by <?= e($mo['creator_name'] ?? 'System') ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Assigned To</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= $mo['assignee_name'] ? e($mo['assignee_name']) : '—' ?></span>
                </div>
                <?php if ($mo['planned_start'] || $mo['planned_end']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Planned Period</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);">
                        <?= $mo['planned_start'] ? date('M d, Y', strtotime($mo['planned_start'])) : '—' ?>
                        →
                        <?= $mo['planned_end'] ? date('M d, Y', strtotime($mo['planned_end'])) : '—' ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($mo['confirmed_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Confirmed</span>
                    <span style="font-size:0.8125rem; color:var(--color-success);"><?= format_datetime($mo['confirmed_at']) ?> by <?= e($mo['confirmer_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($mo['actual_start']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Actual Start</span>
                    <span style="font-size:0.8125rem; color:var(--accent-primary);"><?= format_datetime($mo['actual_start']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($mo['actual_end']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Actual End</span>
                    <span style="font-size:0.8125rem; color:var(--color-success);"><?= format_datetime($mo['actual_end']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($mo['cancelled_at']): ?>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                    <span style="font-size:0.8125rem; color:var(--text-muted);">Cancelled</span>
                    <span style="font-size:0.8125rem; color:var(--color-danger);"><?= format_datetime($mo['cancelled_at']) ?> by <?= e($mo['canceller_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($mo['notes']): ?>
                <div style="padding:8px 0;">
                    <span style="font-size:0.8125rem; color:var(--text-muted); display:block; margin-bottom:4px;">Notes</span>
                    <span style="font-size:0.8125rem; color:var(--text-primary);"><?= nl2br(e($mo['notes'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Components Section -->
<?php if ($isConfirmed): ?>
<form method="POST" id="consume-form" style="margin-top:20px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="consume">
<?php endif; ?>

<div class="card animate-in" style="margin-top:<?= $isConfirmed ? '0' : '20px' ?>;">
    <div class="card-header">
        <h3><i class="fa-solid fa-cubes" style="color:var(--accent-primary); margin-right:8px;"></i>Components</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product Code</th>
                    <th>Product Name</th>
                    <th>UOM</th>
                    <th style="text-align:right;">Required Qty</th>
                    <?php if (!$isDraft): /* Consumed Qty visible from Confirmed onwards */ ?>
                    <th style="text-align:right;">Consumed Qty</th>
                    <th style="text-align:right;">Remaining</th>
                    <?php endif; ?>
                    <?php if ($isConfirmed): ?>
                    <th style="text-align:right;">Consume Now</th>
                    <?php endif; ?>
                    <?php if (!$isReadonly): /* Availability visible in Draft and Confirmed */ ?>
                    <th>Availability</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($components)): ?>
                    <tr>
                        <td colspan="<?= $isDraft ? 5 : ($isConfirmed ? 8 : 6) ?>" style="text-align:center; color:var(--text-muted); padding:24px;">
                            No components defined.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($components as $comp):
                        $remaining = (float)$comp['required_qty'] - (float)$comp['consumed_qty'];
                        $onHand = (float)($comp['on_hand_qty'] ?? 0);
                        $reserved = (float)($comp['reserved_qty'] ?? 0);
                        $freeQty = get_free_qty($onHand, $reserved);
                        $isAvailable = ($freeQty >= (float)$comp['required_qty']);
                    ?>
                        <tr>
                            <td>
                                <span style="font-family:'Fira Code',monospace; font-size:0.8125rem; color:var(--accent-primary);"><?= e($comp['product_code']) ?></span>
                            </td>
                            <td><span style="font-weight:500; font-size:0.8125rem; color:var(--text-primary);"><?= e($comp['product_name']) ?></span></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($comp['uom']) ?></td>
                            <td style="text-align:right; font-weight:500;"><?= fmt_qty($comp['required_qty']) ?></td>

                            <?php if (!$isDraft): ?>
                            <td style="text-align:right; font-weight:500; color:var(--color-success);"><?= fmt_qty($comp['consumed_qty']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem; color:<?= $remaining > 0.001 ? 'var(--color-warning)' : 'var(--color-success)' ?>;">
                                <?= $remaining > 0.001 ? fmt_qty($remaining) : '✓' ?>
                            </td>
                            <?php endif; ?>

                            <?php if ($isConfirmed): ?>
                            <td style="text-align:right;">
                                <?php if ($remaining > 0.001): ?>
                                    <input type="number" name="consume_qty_<?= $comp['mo_component_id'] ?>"
                                           class="form-control" step="0.001" min="0" max="<?= $remaining ?>"
                                           value="" placeholder="0"
                                           style="width:100px; text-align:right; font-size:0.8125rem; display:inline-block;">
                                    <div style="font-size:0.6875rem; color:var(--text-muted); margin-top:2px;">max: <?= fmt_qty($remaining) ?></div>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size:0.6875rem;">Complete</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>

                            <?php if (!$isReadonly): ?>
                            <td>
                                <?php if ($isAvailable): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check" style="margin-right:4px;"></i>Available</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-xmark" style="margin-right:4px;"></i>Not Available</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isConfirmed): ?>
    <div class="card-footer">
        <div></div>
        <button type="submit" class="btn btn-primary" id="consume-btn" onclick="return confirm('Confirm material consumption? Inventory will be updated.')">
            <i class="fa-solid fa-arrow-down"></i> Consume Materials
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($isConfirmed): ?>
</form>
<script>
document.getElementById('consume-form').addEventListener('submit', function() {
    const btn = document.getElementById('consume-btn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
});
</script>
<?php endif; ?>

<!-- Work Orders Section -->
<div class="card animate-in" style="margin-top:20px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-list-ol" style="color:var(--color-warning); margin-right:8px;"></i>Work Orders</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Seq</th>
                    <th>Operation</th>
                    <th>Work Center</th>
                    <th style="text-align:right;">Expected (min)</th>
                    <?php if (!$isDraft): /* Real Duration hidden in Draft */ ?>
                    <th style="text-align:right;">Real Duration (min)</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <?php if ($isConfirmed): ?>
                    <th style="text-align:right;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($workOrders)): ?>
                    <tr>
                        <td colspan="<?= $isDraft ? 6 : ($isConfirmed ? 8 : 7) ?>" style="text-align:center; color:var(--text-muted); padding:24px;">
                            No work orders defined.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workOrders as $wo): ?>
                        <tr>
                            <td style="text-align:center; font-size:0.8125rem; color:var(--text-muted);"><?= $wo['sequence'] ?></td>
                            <td style="font-weight:500; font-size:0.8125rem; color:var(--text-primary);"><?= e($wo['operation_name']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= e($wo['work_center']) ?></td>
                            <td style="text-align:right; font-size:0.8125rem; font-family:'Fira Code',monospace;"><?= number_format($wo['expected_duration_minutes'], 1) ?></td>

                            <?php if (!$isDraft): ?>
                            <td style="text-align:right;">
                                <?php if ($isConfirmed && $wo['status'] !== 'cancelled'): ?>
                                    <input type="number" form="duration-form" name="real_dur_<?= $wo['wo_id'] ?>"
                                           class="form-control" step="0.01" min="0"
                                           value="<?= $wo['actual_duration_minutes'] ?>"
                                           style="width:100px; text-align:right; font-size:0.8125rem; display:inline-block;">
                                <?php else: ?>
                                    <span style="font-family:'Fira Code',monospace; font-size:0.8125rem;"><?= number_format($wo['actual_duration_minutes'], 1) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>

                            <td><?= wo_status_badge($wo['status']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $wo['assignee_name'] ? e($wo['assignee_name']) : '—' ?></td>

                            <?php if ($isConfirmed): ?>
                            <td style="text-align:right;">
                                <?php if ($wo['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="wo_start">
                                        <input type="hidden" name="wo_id" value="<?= $wo['wo_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary" title="Start">
                                            <i class="fa-solid fa-play"></i>
                                        </button>
                                    </form>
                                <?php elseif ($wo['status'] === 'in_progress'): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="wo_complete">
                                        <input type="hidden" name="wo_id" value="<?= $wo['wo_id'] ?>">
                                        <input type="hidden" name="real_duration" value="<?= $wo['actual_duration_minutes'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary" style="background:var(--color-success); border-color:var(--color-success);" title="Complete">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                    </form>
                                <?php elseif ($wo['status'] === 'completed'): ?>
                                    <span class="badge badge-success" style="font-size:0.6875rem;">Done</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isConfirmed && !empty($workOrders)): ?>
    <div class="card-footer">
        <div></div>
        <form method="POST" id="duration-form" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_durations">
            <button type="submit" class="btn btn-secondary">
                <i class="fa-solid fa-floppy-disk"></i> Save Durations
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
