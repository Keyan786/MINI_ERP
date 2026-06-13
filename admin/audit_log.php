<?php
/**
 * Audit Log Viewer - Mini ERP System
 * Searchable, filterable audit trail for all system actions.
 */

$pageTitle = 'Audit Log';
$currentModule = 'audit-log';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Filters ────────────────────────────────────────────────────────────────
$filterModule = $_GET['module'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$conditions = [];
$params = [];
$types = '';

if (!empty($filterModule)) {
    $conditions[] = "module = ?";
    $params[] = $filterModule;
    $types .= 's';
}
if (!empty($filterAction)) {
    $conditions[] = "action = ?";
    $params[] = $filterAction;
    $types .= 's';
}
if (!empty($filterUser)) {
    $conditions[] = "user_name LIKE ?";
    $params[] = "%$filterUser%";
    $types .= 's';
}
if (!empty($filterDateFrom)) {
    $conditions[] = "DATE(created_at) >= ?";
    $params[] = $filterDateFrom;
    $types .= 's';
}
if (!empty($filterDateTo)) {
    $conditions[] = "DATE(created_at) <= ?";
    $params[] = $filterDateTo;
    $types .= 's';
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count
$countSql = "SELECT COUNT(*) as cnt FROM tbl_audit_log $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalLogs = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalLogs / $perPage));

// Fetch logs
$sql = "SELECT * FROM tbl_audit_log $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get distinct modules and actions for filter dropdowns
$modulesList = $conn->query("SELECT DISTINCT module FROM tbl_audit_log ORDER BY module")->fetch_all(MYSQLI_ASSOC);
$actionsList = $conn->query("SELECT DISTINCT action FROM tbl_audit_log ORDER BY action")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Audit Log</h1>
        <p class="page-header-desc"><?= number_format($totalLogs) ?> total entries</p>
    </div>
</div>

<!-- Filters -->
<div class="card animate-in" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" action="" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div style="flex:1; min-width:140px;">
                <label class="form-label">Module</label>
                <select name="module" class="form-control form-select">
                    <option value="">All Modules</option>
                    <?php foreach ($modulesList as $m): ?>
                        <option value="<?= e($m['module']) ?>" <?= $filterModule === $m['module'] ? 'selected' : '' ?>><?= e($m['module']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Action</label>
                <select name="action" class="form-control form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actionsList as $a): ?>
                        <option value="<?= e($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1; min-width:140px;">
                <label class="form-label">User</label>
                <input type="text" name="user" class="form-control" placeholder="Search user..." value="<?= e($filterUser) ?>">
            </div>
            <div style="min-width:140px;">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>">
            </div>
            <div style="min-width:140px;">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>">
            </div>
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="card animate-in">
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                <h3>No Log Entries</h3>
                <p>No audit log entries match your filters.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table" id="audit-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Module</th>
                            <th>Action</th>
                            <th>Record</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="color:var(--text-muted); font-size:0.75rem;">#<?= $log['log_id'] ?></td>
                                <td style="font-weight:500; font-size:0.8125rem; color:var(--text-primary);"><?= e($log['user_name']) ?></td>
                                <td style="font-size:0.8125rem;"><?= e($log['module']) ?></td>
                                <td><span class="audit-action <?= strtolower($log['action']) ?>"><?= e($log['action']) ?></span></td>
                                <td style="font-size:0.8125rem; color:var(--text-secondary);">
                                    <?php if ($log['record_type']): ?>
                                        <?= e($log['record_type']) ?><?= $log['record_id'] ? ' #' . $log['record_id'] : '' ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?= e($log['ip_address'] ?? '—') ?></td>
                                <td style="font-size:0.8125rem; color:var(--text-muted);" title="<?= e($log['created_at']) ?>">
                                    <?= format_datetime($log['created_at']) ?>
                                </td>
                                <td>
                                    <?php if ($log['old_values'] || $log['new_values']): ?>
                                        <span class="audit-detail-toggle">View Details</span>
                                        <div class="audit-detail-content">
                                            <?php if ($log['old_values']): ?>
                                                <strong>Old:</strong> <?= e($log['old_values']) ?>
                                            <?php endif; ?>
                                            <?php if ($log['old_values'] && $log['new_values']): ?>

                                            <?php endif; ?>
                                            <?php if ($log['new_values']): ?>
                                                <strong>New:</strong> <?= e($log['new_values']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.75rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                        $queryParams = array_filter([
                            'module' => $filterModule, 'action' => $filterAction,
                            'user' => $filterUser, 'date_from' => $filterDateFrom, 'date_to' => $filterDateTo
                        ]);
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-solid fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-solid fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
