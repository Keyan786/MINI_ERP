<?php
/**
 * Warehouse Master - Mini ERP System
 */

$pageTitle = 'Warehouse Management';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// Handle Add/Edit
if (is_post() && csrf_validate()) {
    $action = $_POST['action'] ?? '';
    $warehouseCode = trim($_POST['warehouse_code'] ?? '');
    $warehouseName = trim($_POST['warehouse_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = intval($_POST['is_active'] ?? 1);

    if ($action === 'add') {
        $stmt = $conn->prepare("INSERT INTO tbl_warehouses (warehouse_code, warehouse_name, location, is_active) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $warehouseCode, $warehouseName, $location, $status);
        if ($stmt->execute()) {
            set_flash('success', 'Warehouse created successfully.');
        } else {
            set_flash('error', 'Failed to create warehouse.');
        }
        $stmt->close();
    } elseif ($action === 'edit') {
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE tbl_warehouses SET warehouse_code = ?, warehouse_name = ?, location = ?, is_active = ? WHERE warehouse_id = ?");
        $stmt->bind_param("sssii", $warehouseCode, $warehouseName, $location, $status, $warehouseId);
        if ($stmt->execute()) {
            set_flash('success', 'Warehouse updated successfully.');
        } else {
            set_flash('error', 'Failed to update warehouse.');
        }
        $stmt->close();
    }
    
    redirect('/modules/inventory/warehouses.php');
}

$warehouses = $conn->query("SELECT * FROM tbl_warehouses ORDER BY warehouse_id ASC")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1><i class="fa-solid fa-warehouse"></i> Warehouse Management</h1>
        <p class="text-muted">Manage inventory locations across the system.</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="openCustomModal('addModal')">
            <i class="fa-solid fa-plus"></i> Add Warehouse
        </button>
    </div>
</div>

<div class="card animate-in" style="animation-delay: 0.1s;">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($warehouses as $w): ?>
                    <tr>
                        <td><span class="badge badge-secondary"><?= e($w['warehouse_code']) ?></span></td>
                        <td style="font-weight: 500;"><?= e($w['warehouse_name']) ?></td>
                        <td><?= e($w['location']) ?: '-' ?></td>
                        <td>
                            <?php if ($w['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline" 
                                onclick="editWarehouse(<?= htmlspecialchars(json_encode($w)) ?>)">
                                <i class="fa-solid fa-edit"></i> Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($warehouses)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 20px;">No warehouses found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content animate-in" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Add Warehouse</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('addModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label class="form-label">Warehouse Code*</label>
                    <input type="text" name="warehouse_code" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Warehouse Name*</label>
                    <input type="text" name="warehouse_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Location Details</label>
                    <input type="text" name="location" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-control" required>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Warehouse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content animate-in" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Edit Warehouse</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('editModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="warehouse_id" id="edit_warehouse_id">
                
                <div class="form-group">
                    <label class="form-label">Warehouse Code*</label>
                    <input type="text" name="warehouse_code" id="edit_warehouse_code" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Warehouse Name*</label>
                    <input type="text" name="warehouse_name" id="edit_warehouse_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Location Details</label>
                    <input type="text" name="location" id="edit_location" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" id="edit_is_active" class="form-control" required>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Warehouse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.appendChild(document.getElementById('addModal'));
    document.body.appendChild(document.getElementById('editModal'));
});

function openCustomModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function editWarehouse(w) {
    document.getElementById('edit_warehouse_id').value = w.warehouse_id;
    document.getElementById('edit_warehouse_code').value = w.warehouse_code;
    document.getElementById('edit_warehouse_name').value = w.warehouse_name;
    document.getElementById('edit_location').value = w.location;
    document.getElementById('edit_is_active').value = w.is_active;
    
    openCustomModal('editModal');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
