<?php
/**
 * Stock Transfers - Mini ERP System
 */

$pageTitle = 'Stock Transfers';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// Handle Transfer Creation
if (is_post() && csrf_validate() && isset($_POST['action']) && $_POST['action'] === 'transfer') {
    $sourceWhId = intval($_POST['source_warehouse_id'] ?? 0);
    $destWhId = intval($_POST['destination_warehouse_id'] ?? 0);
    $productId = intval($_POST['product_id'] ?? 0);
    $qty = floatval($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($sourceWhId === $destWhId) {
        set_flash('error', 'Source and destination warehouses must be different.');
        redirect('/modules/inventory/transfers.php');
    }

    if ($qty <= 0) {
        set_flash('error', 'Transfer quantity must be greater than zero.');
        redirect('/modules/inventory/transfers.php');
    }

    // Check Source Availability
    $stmt = $conn->prepare("SELECT on_hand_qty, reserved_qty FROM tbl_product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?");
    $stmt->bind_param("ii", $productId, $sourceWhId);
    $stmt->execute();
    $srcStock = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $freeToUse = $srcStock ? get_free_qty((float)$srcStock['on_hand_qty'], (float)$srcStock['reserved_qty']) : 0;

    if ($qty > $freeToUse) {
        set_flash('error', 'Insufficient free stock in source warehouse.');
        redirect('/modules/inventory/transfers.php');
    }

    $conn->begin_transaction();
    try {
        // Create transfer record
        $transferNo = 'TRF-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT); // simple generator
        $stmt = $conn->prepare("INSERT INTO tbl_stock_transfers (transfer_number, source_warehouse_id, destination_warehouse_id, status, notes, created_by, completed_by, completed_at) VALUES (?, ?, ?, 'completed', ?, ?, ?, NOW())");
        $stmt->bind_param("siisii", $transferNo, $sourceWhId, $destWhId, $notes, $_SESSION['user_id'], $_SESSION['user_id']);
        $stmt->execute();
        $transferId = $stmt->insert_id;
        $stmt->close();

        // Create line
        $stmt = $conn->prepare("INSERT INTO tbl_stock_transfer_lines (transfer_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->bind_param("iid", $transferId, $productId, $qty);
        $stmt->execute();
        $stmt->close();

        // Update Stock & Record Movements (Out from Source)
        update_stock($conn, $productId, $sourceWhId, -$qty, 'adjustment', 'transfer_out', $transferId, $notes, $_SESSION['user_id']);

        // Update Stock & Record Movements (In to Destination)
        update_stock($conn, $productId, $destWhId, $qty, 'adjustment', 'transfer_in', $transferId, $notes, $_SESSION['user_id']);

        $conn->commit();
        set_flash('success', 'Stock transferred successfully.');
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('error', 'Failed to transfer stock: ' . $e->getMessage());
    }

    redirect('/modules/inventory/transfers.php');
}

// Fetch Active Warehouses
$warehouses = $conn->query("SELECT warehouse_id, warehouse_name FROM tbl_warehouses WHERE is_active = 1 ORDER BY warehouse_name")->fetch_all(MYSQLI_ASSOC);

// Fetch Transfer History
$transfers = $conn->query("
    SELECT t.*, w1.warehouse_name as source_name, w2.warehouse_name as dest_name,
           p.product_code, p.product_name, p.uom, l.quantity
    FROM tbl_stock_transfers t
    JOIN tbl_warehouses w1 ON t.source_warehouse_id = w1.warehouse_id
    JOIN tbl_warehouses w2 ON t.destination_warehouse_id = w2.warehouse_id
    JOIN tbl_stock_transfer_lines l ON t.transfer_id = l.transfer_id
    JOIN tbl_products p ON l.product_id = p.product_id
    ORDER BY t.created_at DESC LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

// Fetch products for dropdown
$products = $conn->query("SELECT product_id, product_code, product_name, uom FROM tbl_products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Inventory</a>
        <h1><i class="fa-solid fa-truck-moving"></i> Stock Transfers</h1>
        <p class="text-muted">Move stock between warehouses.</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="openCustomModal('transferModal')">
            <i class="fa-solid fa-plus"></i> New Transfer
        </button>
    </div>
</div>

<div class="card animate-in" style="animation-delay: 0.1s;">
    <div class="card-header">
        <h3>Transfer History</h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transfer #</th>
                    <th>Product</th>
                    <th>Source</th>
                    <th>Destination</th>
                    <th style="text-align:right;">Quantity</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transfers as $t): ?>
                    <tr>
                        <td style="font-size:0.85rem; color:var(--text-muted);"><?= date('Y-m-d H:i', strtotime($t['created_at'])) ?></td>
                        <td><span class="badge badge-secondary"><?= e($t['transfer_number']) ?></span></td>
                        <td>
                            <div style="font-weight:500;"><?= e($t['product_name']) ?></div>
                            <div style="font-size:0.75rem; color:var(--accent-primary);"><?= e($t['product_code']) ?></div>
                        </td>
                        <td><i class="fa-solid fa-sign-out-alt" style="color:var(--color-danger); margin-right:5px;"></i> <?= e($t['source_name']) ?></td>
                        <td><i class="fa-solid fa-sign-in-alt" style="color:var(--color-success); margin-right:5px;"></i> <?= e($t['dest_name']) ?></td>
                        <td style="text-align:right; font-weight:600;"><?= fmt_qty($t['quantity']) ?> <span class="text-muted" style="font-size:0.75rem;"><?= e($t['uom']) ?></span></td>
                        <td>
                            <span class="badge badge-success">Completed</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($transfers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px;">No transfers found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Transfer Modal -->
<div id="transferModal" class="modal">
    <div class="modal-content animate-in" style="max-width: 600px;">
        <div class="modal-header">
            <h3>New Stock Transfer</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('transferModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="transfer">
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="form-label">Source Warehouse*</label>
                        <select name="source_warehouse_id" class="form-control" required>
                            <option value="">-- Select Source --</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['warehouse_id'] ?>"><?= e($w['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="form-label">Destination Warehouse*</label>
                        <select name="destination_warehouse_id" class="form-control" required>
                            <option value="">-- Select Destination --</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['warehouse_id'] ?>"><?= e($w['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Product*</label>
                    <select name="product_id" class="form-control" required>
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['product_id'] ?>">
                                [<?= e($p['product_code']) ?>] <?= e($p['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quantity to Transfer*</label>
                    <input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('transferModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm this transfer? Stock will be moved immediately.')">Complete Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.appendChild(document.getElementById('transferModal'));
});

function openCustomModal(id) {
    document.getElementById(id).style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
