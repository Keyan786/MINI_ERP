<?php
/**
 * Bill of Materials — List Page
 * Searchable, filterable BoM listing with status tabs and pagination.
 */

$pageTitle = 'Bill of Materials';
$currentModule = 'bom';

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

if ($statusFilter === 'active') {
    $where[] = "b.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $where[] = "b.is_active = 0";
}

if (!empty($search)) {
    $where[] = "(b.bom_code LIKE ? OR b.bom_name LIKE ? OR p.product_name LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= "sss";
}

if (!empty($dateFrom)) {
    $where[] = "b.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= "s";
}

if (!empty($dateTo)) {
    $where[] = "b.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= "s";
}

$whereClause = implode(" AND ", $where);

// Count
$countSql = "SELECT COUNT(*) as cnt FROM tbl_bom b LEFT JOIN tbl_products p ON b.product_id = p.product_id WHERE $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalItems = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalItems / $perPage));

// Fetch BoMs
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$sql = "SELECT b.*, p.product_name, p.product_code,
               (SELECT COUNT(*) FROM tbl_bom_lines bl WHERE bl.bom_id = b.bom_id) as component_count,
               cu.full_name as creator_name
        FROM tbl_bom b
        LEFT JOIN tbl_products p ON b.product_id = p.product_id
        LEFT JOIN tbl_users cu ON b.created_by = cu.user_id
        WHERE $whereClause
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$boms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status counts
$allCount = $conn->query("SELECT COUNT(*) as cnt FROM tbl_bom")->fetch_assoc()['cnt'];
$activeCount = $conn->query("SELECT COUNT(*) as cnt FROM tbl_bom WHERE is_active = 1")->fetch_assoc()['cnt'];
$inactiveCount = $allCount - $activeCount;

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Bill of Materials</h1>
        <p class="page-header-desc">Manage production recipes and component lists</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/bom/create.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New BoM
    </a>
</div>

<!-- Status Tabs -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <?php
        $tabs = ['all' => ['All', $allCount], 'active' => ['Active', $activeCount], 'inactive' => ['Inactive', $inactiveCount]];
        foreach ($tabs as $key => $tab):
            $active = $statusFilter === $key ? 'active' : '';
        ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['status' => $key, 'page' => 1])) ?>"
               class="filter-tab <?= $active ?>">
                <?= $tab[0] ?> <span style="opacity:0.6; font-size:0.75rem;">(<?= $tab[1] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-left: auto;">
        <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>

        <div class="search-input" style="margin-bottom:0; width: 220px; position: relative;">
            <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;"></i>
            <input type="text" name="search" class="form-control" placeholder="Code, name, product..." value="<?= e($search) ?>" style="padding-left: 32px; height: 38px;">
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

<!-- BoM Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table" id="bom-table">
            <thead>
                <tr>
                    <th>BoM Code</th>
                    <th>Name</th>
                    <th>Product</th>
                    <th style="text-align:center;">Components</th>
                    <th style="text-align:right;">Std. Time (min)</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($boms)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state" style="padding:40px;">
                                <div class="empty-state-icon"><i class="fa-solid fa-list-check"></i></div>
                                <h3>No Bills of Materials</h3>
                                <p>Create your first BoM to define production recipes.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($boms as $bom): ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/bom/view.php?id=<?= $bom['bom_id'] ?>"
                                   style="font-weight:600; color:var(--accent-primary); font-family:'Fira Code',monospace; font-size:0.8125rem;">
                                    <?= e($bom['bom_code']) ?>
                                </a>
                            </td>
                            <td>
                                <span style="font-weight:500; color:var(--text-primary);"><?= e($bom['bom_name']) ?></span>
                            </td>
                            <td>
                                <span style="font-size:0.8125rem; color:var(--text-primary);"><?= e($bom['product_name'] ?? '—') ?></span>
                                <div style="font-size:0.75rem; font-family:'Fira Code',monospace; color:var(--text-muted);"><?= e($bom['product_code'] ?? '') ?></div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge badge-info"><?= $bom['component_count'] ?></span>
                            </td>
                            <td style="text-align:right; font-size:0.8125rem; font-family:'Fira Code',monospace;">
                                <?= number_format($bom['standard_time_minutes'], 1) ?>
                            </td>
                            <td>
                                <?php if ($bom['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= format_datetime($bom['created_at']) ?>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; gap:6px; justify-content:flex-end;">
                                    <a href="<?= BASE_URL ?>/modules/bom/view.php?id=<?= $bom['bom_id'] ?>"
                                       class="btn btn-sm btn-secondary" title="View">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/bom/edit.php?id=<?= $bom['bom_id'] ?>"
                                       class="btn btn-sm btn-secondary" title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                </div>
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
