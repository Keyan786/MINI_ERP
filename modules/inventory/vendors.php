<?php
/**
 * Vendor Management - Mini ERP System
 * CRUD for vendor/supplier records with modal forms.
 */

$pageTitle = 'Vendor Management';
$currentModule = 'inventory';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];

// ─── Handle POST ────────────────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $vendorId = intval($_POST['vendor_id'] ?? 0);
        $vendorName = trim($_POST['vendor_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($vendorName)) $errors[] = 'Vendor name is required.';

        if (empty($errors)) {
            $userId = $_SESSION['user_id'];
            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO tbl_vendors (vendor_name, contact_person, email, phone, address, city, state, country, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssii", $vendorName, $contactPerson, $email, $phone, $address, $city, $state, $country, $isActive, $userId);
                if ($stmt->execute()) {
                    log_action($conn, 'Inventory', ACTION_CREATE, 'Vendor', $stmt->insert_id, null, ['vendor_name' => $vendorName]);
                    set_flash('success', 'Vendor "' . $vendorName . '" created.');
                    redirect('/modules/inventory/vendors.php');
                } else {
                    $errors[] = 'Failed to create vendor.';
                }
                $stmt->close();
            } elseif ($action === 'edit' && $vendorId > 0) {
                $stmt = $conn->prepare("SELECT vendor_name FROM tbl_vendors WHERE vendor_id = ?");
                $stmt->bind_param("i", $vendorId);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE tbl_vendors SET vendor_name=?, contact_person=?, email=?, phone=?, address=?, city=?, state=?, country=?, is_active=?, updated_by=? WHERE vendor_id=?");
                $stmt->bind_param("ssssssssiiii", $vendorName, $contactPerson, $email, $phone, $address, $city, $state, $country, $isActive, $userId, $vendorId);
                if ($stmt->execute()) {
                    log_action($conn, 'Inventory', ACTION_UPDATE, 'Vendor', $vendorId, $old, ['vendor_name' => $vendorName]);
                    set_flash('success', 'Vendor updated.');
                    redirect('/modules/inventory/vendors.php');
                } else {
                    $errors[] = 'Failed to update vendor.';
                }
                $stmt->close();
            }
        }
    }
}

// ─── Handle Delete ─────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_products WHERE default_vendor_id = ?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($cnt > 0) {
        set_flash('error', 'Cannot delete vendor — ' . $cnt . ' product(s) use it as default vendor.');
    } else {
        $stmt = $conn->prepare("SELECT vendor_name FROM tbl_vendors WHERE vendor_id = ?");
        $stmt->bind_param("i", $delId);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM tbl_vendors WHERE vendor_id = ?");
        $stmt->bind_param("i", $delId);
        if ($stmt->execute()) {
            log_action($conn, 'Inventory', ACTION_DELETE, 'Vendor', $delId, $v, null);
            set_flash('success', 'Vendor deleted.');
        }
        $stmt->close();
    }
    redirect('/modules/inventory/vendors.php');
}

// ─── Fetch Vendors ──────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where = "1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $where .= " AND (v.vendor_name LIKE ? OR v.email LIKE ? OR v.city LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s];
    $types = "sss";
}

$sql = "SELECT v.*, (SELECT COUNT(*) FROM tbl_products p WHERE p.default_vendor_id = v.vendor_id) as product_count
        FROM tbl_vendors v WHERE $where ORDER BY v.vendor_name";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$editVendor = null;
if (isset($_GET['edit'])) {
    $eId = intval($_GET['edit']);
    foreach ($vendors as $v) {
        if ($v['vendor_id'] == $eId) { $editVendor = $v; break; }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Vendor Management</h1>
        <p class="page-header-desc">Manage suppliers and procurement vendors</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('vendor-modal').classList.add('active')">
        <i class="fa-solid fa-plus"></i> New Vendor
    </button>
</div>

<!-- Search -->
<div class="filter-bar animate-in">
    <div class="search-input" style="max-width:400px;">
        <i class="fa-solid fa-search"></i>
        <form method="GET"><input type="text" name="search" class="form-control" placeholder="Search vendors..." value="<?= e($search) ?>"></form>
    </div>
    <span style="font-size:0.8125rem; color:var(--text-muted);"><?= count($vendors) ?> vendor(s)</span>
</div>

<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vendor Name</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Location</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                    <tr><td colspan="8"><div class="empty-state" style="padding:40px;"><div class="empty-state-icon"><i class="fa-solid fa-truck"></i></div><h3>No Vendors</h3><p>Add your first vendor.</p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($vendors as $v): ?>
                        <tr>
                            <td style="font-weight:600; color:var(--text-primary);"><?= e($v['vendor_name']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $v['contact_person'] ? e($v['contact_person']) : '—' ?></td>
                            <td style="font-size:0.8125rem;"><?= $v['email'] ? e($v['email']) : '—' ?></td>
                            <td style="font-size:0.8125rem;"><?= $v['phone'] ? e($v['phone']) : '—' ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $v['city'] ? e($v['city']) . ($v['country'] ? ', ' . e($v['country']) : '') : '—' ?></td>
                            <td><span class="badge badge-primary"><?= $v['product_count'] ?></span></td>
                            <td><?= $v['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
                            <td style="text-align:right;">
                                <div class="btn-group">
                                    <a href="?edit=<?= $v['vendor_id'] ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-pen-to-square"></i></a>
                                    <?php if ($v['product_count'] == 0): ?>
                                        <a href="?delete=<?= $v['vendor_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this vendor?')"><i class="fa-solid fa-trash"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="vendor-modal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-plus" style="color:var(--accent-primary); margin-right:8px;"></i>New Vendor</h3>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                    <input type="text" name="vendor_name" class="form-control" required maxlength="150" placeholder="Company or person name">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" maxlength="100"></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" maxlength="20"></div>
                </div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="150"></div>
                <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                    <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" maxlength="100"></div>
                    <div class="form-group"><label class="form-label">State</label><input type="text" name="state" class="form-control" maxlength="100"></div>
                    <div class="form-group"><label class="form-label">Country</label><input type="text" name="country" class="form-control" maxlength="100"></div>
                </div>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="is_active" checked style="accent-color:var(--accent-primary);width:16px;height:16px;">
                    <span class="form-label" style="margin-bottom:0;">Active</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<?php if ($editVendor): ?>
<div class="modal-overlay active" id="edit-vendor-modal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color:var(--color-warning); margin-right:8px;"></i>Edit Vendor</h3>
            <a href="<?= BASE_URL ?>/modules/inventory/vendors.php" class="modal-close">&times;</a>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="vendor_id" value="<?= $editVendor['vendor_id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                    <input type="text" name="vendor_name" class="form-control" required maxlength="150" value="<?= e($editVendor['vendor_name']) ?>">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= e($editVendor['contact_person'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($editVendor['phone'] ?? '') ?>"></div>
                </div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($editVendor['email'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($editVendor['address'] ?? '') ?></textarea></div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                    <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= e($editVendor['city'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">State</label><input type="text" name="state" class="form-control" value="<?= e($editVendor['state'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Country</label><input type="text" name="country" class="form-control" value="<?= e($editVendor['country'] ?? '') ?>"></div>
                </div>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="is_active" <?= $editVendor['is_active'] ? 'checked' : '' ?> style="accent-color:var(--accent-primary);width:16px;height:16px;">
                    <span class="form-label" style="margin-bottom:0;">Active</span>
                </label>
            </div>
            <div class="modal-footer">
                <a href="<?= BASE_URL ?>/modules/inventory/vendors.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
