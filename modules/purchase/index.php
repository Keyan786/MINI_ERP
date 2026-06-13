<?php
/**
 * Purchase Order List - Mini ERP System
 * Filterable, searchable PO listing with status tabs and pagination.
 */

$pageTitle = 'Purchase Orders';
$currentModule = 'purchase';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// ─── Filters ────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];
$types = "";

if ($statusFilter !== 'all') {
    $where[] = "po.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($search)) {
    $where[] = "(po.po_number LIKE ? OR po.vendor_name LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

$whereClause = implode(" AND ", $where);

// Count
$countSql = "SELECT COUNT(*) as cnt FROM tbl_purchase_orders po WHERE $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalItems = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalItems / $perPage));

// Fetch POs
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$sql = "SELECT po.*, u.full_name as responsible_name, cu.full_name as creator_name
        FROM tbl_purchase_orders po
        LEFT JOIN tbl_users u ON po.responsible_user_id = u.user_id
        LEFT JOIN tbl_users cu ON po.created_by = cu.user_id
        WHERE $whereClause
        ORDER BY po.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status counts for tabs
$statusCounts = $conn->query("
    SELECT status, COUNT(*) as cnt FROM tbl_purchase_orders GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$counts = ['all' => 0];
foreach ($statusCounts as $sc) {
    $counts[$sc['status']] = $sc['cnt'];
    $counts['all'] += $sc['cnt'];
}

include __DIR__ . '/../../includes/header.php';

// PO status badge helper
function po_status_badge(string $status): string {
    $map = [
        'draft'               => ['Draft', 'badge-secondary'],
        'confirmed'           => ['Confirmed', 'badge-info'],
        'partially_received'  => ['Partial', 'badge-warning'],
        'fully_received'      => ['Received', 'badge-success'],
        'cancelled'           => ['Cancelled', 'badge-danger'],
    ];
    $d = $map[$status] ?? ['Unknown', 'badge-secondary'];
    return '<span class="badge ' . $d[1] . '">' . $d[0] . '</span>';
}
?>

<div class="page-header animate-in">
    <div>
        <h1>Purchase Orders</h1>
        <p class="page-header-desc">Manage vendor procurement and goods receipt</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/purchase/create.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New Purchase Order
    </a>
</div>

<!-- Status Tabs -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <?php
        $tabs = [
            'all' => 'All',
            'draft' => 'Draft',
            'confirmed' => 'Confirmed',
            'partially_received' => 'Partial',
            'fully_received' => 'Received',
            'cancelled' => 'Cancelled',
        ];
        foreach ($tabs as $key => $label):
            $cnt = $counts[$key] ?? 0;
            $active = $statusFilter === $key ? 'active' : '';
        ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['status' => $key, 'page' => 1])) ?>"
               class="filter-tab <?= $active ?>">
                <?= $label ?> <span style="opacity:0.6; font-size:0.75rem;">(<?= $cnt ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="search-input">
        <i class="fa-solid fa-search"></i>
        <form method="GET">
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
            <input type="text" name="search" class="form-control" placeholder="Search PO# or vendor..." value="<?= e($search) ?>">
        </form>
    </div>
</div>

<!-- PO Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table" id="po-table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Vendor</th>
                    <th>Responsible</th>
                    <th style="text-align:right;">Ordered Total</th>
                    <th style="text-align:right;">Received Total</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state" style="padding:40px;">
                                <div class="empty-state-icon"><i class="fa-solid fa-truck-field"></i></div>
                                <h3>No Purchase Orders</h3>
                                <p>Create your first purchase order to get started.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $po): ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/purchase/view.php?id=<?= $po['po_id'] ?>"
                                   style="font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace; font-size:0.8125rem;">
                                    <?= e($po['po_number']) ?>
                                </a>
                            </td>
                            <td>
                                <span style="font-weight:500; color:var(--text-primary);"><?= e($po['vendor_name']) ?></span>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= $po['responsible_name'] ? e($po['responsible_name']) : '—' ?>
                            </td>
                            <td style="text-align:right; font-weight:500;">
                                <?= fmt_price($po['ordered_total']) ?>
                            </td>
                            <td style="text-align:right; font-weight:500; color:var(--color-success);">
                                <?= $po['received_total'] > 0 ? fmt_price($po['received_total']) : '—' ?>
                            </td>
                            <td><?= po_status_badge($po['status']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= format_datetime($po['created_at']) ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= BASE_URL ?>/modules/purchase/view.php?id=<?= $po['po_id'] ?>"
                                   class="btn btn-sm btn-secondary" title="View">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                   class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
