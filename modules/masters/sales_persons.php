<?php
/**
 * Sales Person Master - Mini ERP System
 */

$pageTitle = 'Sales Person Master';
$currentModule = 'sales_persons';

require_once __DIR__ . '/../../includes/auth_check.php';
require_permission('master-management', 'view');

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'active';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$whereClause = "1=1";
$params = [];
$types = "";

if ($statusFilter !== 'all') {
    $whereClause .= " AND status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($search)) {
    $whereClause .= " AND (sales_person_name LIKE ? OR mobile_number LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

// Count total
$countQuery = "SELECT COUNT(id) as total FROM tbl_sales_persons WHERE $whereClause";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRecords = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPages = ceil($totalRecords / $limit);

// Fetch records
$query = "SELECT * FROM tbl_sales_persons WHERE $whereClause ORDER BY sales_person_name ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Sales Person Master</h1>
        <p class="page-header-desc">Manage sales persons for the ERP system.</p>
    </div>
    <?php if (has_permission('master-management', 'create')): ?>
        <div>
            <button type="button" class="btn btn-primary" onclick="openCustomModal('addModal')">
                <i class="fa-solid fa-plus"></i> Add Sales Person
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card animate-in">
    <div class="card-body">
        <form method="GET" action="" class="form-row" style="align-items: flex-end; margin-bottom:0;">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <div class="form-group col-md-8" style="margin-bottom:0;">
                <div class="search-box">
                    <i class="fa-solid fa-search search-icon"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search by name or mobile..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-4" style="margin-bottom:0;">
                <button type="submit" class="btn btn-secondary" style="width:100%;">Search</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Tabs -->
<div class="tabs animate-in">
    <?php 
    $tabs = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'all' => 'All Records'
    ];
    foreach ($tabs as $key => $label): 
        $activeClass = ($statusFilter === $key) ? 'active' : '';
        $url = "?status={$key}" . (!empty($search) ? "&search=" . urlencode($search) : "");
    ?>
        <a href="<?= $url ?>" class="tab <?= $activeClass ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sales Person Name</th>
                    <th>Mobile Number</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">
                            No records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($r['sales_person_name']) ?></td>
                            <td><?= e($r['mobile_number'] ?: '-') ?></td>
                            <td>
                                <?php if ($r['status'] === 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-error">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                            <td style="text-align: right;">
                                <?php if (has_permission('master-management', 'edit')): ?>
                                    <button class="btn btn-sm btn-outline" onclick="openCustomEditModal(<?= $r['id'] ?>, '<?= e(addslashes($r['sales_person_name'])) ?>', '<?= e(addslashes($r['mobile_number'])) ?>')">
                                        <i class="fa-regular fa-pen-to-square"></i> Edit
                                    </button>
                                    
                                    <?php if ($r['status'] === 'active'): ?>
                                        <form method="POST" action="action.php" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="master_type" value="sales_person">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="new_status" value="inactive">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <button type="submit" class="btn btn-sm" style="color:var(--color-danger); border:1px solid rgba(239,68,68,0.2); background:white;" onclick="return confirm('Deactivate this sales person?')">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="action.php" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="master_type" value="sales_person">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" style="color:var(--color-success); border-color:rgba(16,185,129,0.2);" onclick="return confirm('Activate this sales person?')">
                                                <i class="fa-regular fa-circle-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="font-size:0.875rem; color:var(--text-muted);">
            Showing <?= count($records) ?> of <?= $totalRecords ?> records
        </div>
        <div style="display:flex; gap:5px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): 
                $pageUrl = "?page=$i&status=" . urlencode($statusFilter) . (!empty($search) ? "&search=" . urlencode($search) : "");
                $btnClass = ($i === $page) ? 'btn-primary' : 'btn-outline';
            ?>
                <a href="<?= $pageUrl ?>" class="btn btn-sm <?= $btnClass ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Add Sales Person</h3>
            <span class="close" onclick="closeCustomModal('addModal')"><i class="fa-solid fa-xmark"></i></span>
        </div>
        <form method="POST" action="action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="master_type" value="sales_person">
            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sales Person Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Mobile Number</label>
                    <input type="text" name="mobile_number" class="form-control" maxlength="20">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCustomModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Edit Sales Person</h3>
            <span class="close" onclick="closeCustomModal('editModal')"><i class="fa-solid fa-xmark"></i></span>
        </div>
        <form method="POST" action="action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="master_type" value="sales_person">
            <input type="hidden" name="id" id="edit_id" value="">
            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sales Person Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="edit_name" class="form-control" required maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Mobile Number</label>
                    <input type="text" name="mobile_number" id="edit_mobile" class="form-control" maxlength="20">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCustomModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Move modals to body to prevent CSS containment issues
document.addEventListener('DOMContentLoaded', function() {
    document.body.appendChild(document.getElementById('addModal'));
    document.body.appendChild(document.getElementById('editModal'));
});

function openCustomModal(id) {
    document.getElementById(id).style.display = "flex";
}
function closeCustomModal(id) {
    document.getElementById(id).style.display = "none";
}
function openCustomEditModal(id, name, mobile) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_mobile').value = mobile;
    openCustomModal('editModal');
}
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = "none";
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
