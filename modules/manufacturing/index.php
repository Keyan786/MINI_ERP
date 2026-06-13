<?php
/**
 * Manufacturing Orders — List Page
 * Filterable, searchable MO listing with status tabs and pagination.
 */

$pageTitle = 'Manufacturing Orders';
$currentModule = 'manufacturing';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// ─── Filters ────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ["1=1"];
$params = [];
$types = "";

if ($statusFilter !== 'all') {
    $where[] = "mo.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($search)) {
    $where[] = "(mo.mo_number LIKE ? OR p.product_name LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

if (!empty($dateFrom)) {
    $where[] = "mo.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= "s";
}

if (!empty($dateTo)) {
    $where[] = "mo.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Count
$countSql = "SELECT COUNT(*) as cnt FROM tbl_manufacturing_orders mo LEFT JOIN tbl_products p ON mo.product_id = p.product_id WHERE $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalItems = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalItems / $perPage));

// Fetch MOs
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$sql = "SELECT mo.*, p.product_name, p.product_code,
               cu.full_name as creator_name
        FROM tbl_manufacturing_orders mo
        LEFT JOIN tbl_products p ON mo.product_id = p.product_id
        LEFT JOIN tbl_users cu ON mo.created_by = cu.user_id
        WHERE $whereClause
        ORDER BY mo.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status counts
$statusCounts = $conn->query("SELECT status, COUNT(*) as cnt FROM tbl_manufacturing_orders GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$counts = ['all' => 0];
foreach ($statusCounts as $sc) {
    $counts[$sc['status']] = $sc['cnt'];
    $counts['all'] += $sc['cnt'];
}

include __DIR__ . '/../../includes/header.php';

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
?>

<div class="page-header animate-in">
    <div>
        <h1>Manufacturing Orders</h1>
        <p class="page-header-desc">Manage production orders and track manufacturing progress</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/manufacturing/create.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New Manufacturing Order
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
            'done' => 'Done',
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
    <form method="GET" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-left: auto;">
        <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>

        <div class="search-input" style="margin-bottom:0; width: 220px; position: relative;">
            <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;"></i>
            <input type="text" name="search" class="form-control" placeholder="MO# or product..." value="<?= e($search) ?>" style="padding-left: 32px; height: 38px;">
        </div>

        <div style="display:flex; align-items:center; gap:6px;">
            <label class="form-label" style="margin-bottom:0; font-size:0.75rem; color:var(--text-muted);">From</label>
            <input type="date" name="date_from" class="form-control" style="padding:6px 10px; font-size:0.8125rem; width:130px; height:38px;" value="<?= e($dateFrom) ?>">
        </div>

        <div style="display:flex; align-items:center; gap:6px;">
            <label class="form-label" style="margin-bottom:0; font-size:0.75rem; color:var(--text-muted);">To</label>
            <input type="date" name="date_to" class="form-control" style="padding:6px 10px; font-size:0.8125rem; width:130px; height:38px;" value="<?= e($dateTo) ?>">
        </div>

        <button type="submit" class="btn btn-primary btn-sm" style="height:38px; padding:0 12px;"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if (!empty($search) || !empty($dateFrom) || !empty($dateTo)): ?>
            <a href="?status=<?= e($statusFilter) ?>" class="btn btn-secondary btn-sm" style="height:38px; padding:0 12px; display:inline-flex; align-items:center; justify-content:center;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- MO Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table" id="mo-table">
            <thead>
                <tr>
                    <th>MO Number</th>
                    <th>Product</th>
                    <th style="text-align:right;">Planned Qty</th>
                    <th style="text-align:right;">Produced</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Planned Start</th>
                    <th>Created</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state" style="padding:40px;">
                                <div class="empty-state-icon"><i class="fa-solid fa-industry"></i></div>
                                <h3>No Manufacturing Orders</h3>
                                <p>Create your first manufacturing order to start production.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $mo): ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/manufacturing/view.php?id=<?= $mo['mo_id'] ?>"
                                   style="font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace; font-size:0.8125rem;">
                                    <?= e($mo['mo_number']) ?>
                                </a>
                            </td>
                            <td>
                                <span style="font-weight:500; color:var(--text-primary);"><?= e($mo['product_name'] ?? '—') ?></span>
                                <div style="font-size:0.75rem; font-family:'Fira Code',monospace; color:var(--text-muted);"><?= e($mo['product_code'] ?? '') ?></div>
                            </td>
                            <td style="text-align:right; font-weight:500;"><?= fmt_qty($mo['planned_qty']) ?></td>
                            <td style="text-align:right; font-weight:500; color:var(--color-success);">
                                <?= $mo['produced_qty'] > 0 ? fmt_qty($mo['produced_qty']) : '—' ?>
                            </td>
                            <td><?= mo_status_badge($mo['status']) ?></td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= $mo['creator_name'] ? e($mo['creator_name']) : '—' ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= $mo['planned_start'] ? date('M d, Y', strtotime($mo['planned_start'])) : '—' ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= format_datetime($mo['created_at']) ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= BASE_URL ?>/modules/manufacturing/view.php?id=<?= $mo['mo_id'] ?>"
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
