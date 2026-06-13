<?php
/**
 * Sales Audit Log - Mini ERP System
 */

$pageTitle = 'Sales Audit Log';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Filter for Sales module only
$sql = "SELECT a.*, u.full_name as user_name 
        FROM tbl_audit_log a
        LEFT JOIN tbl_users u ON a.user_id = u.user_id
        WHERE a.module = 'Sales'
        ORDER BY a.created_at DESC 
        LIMIT $limit OFFSET $offset";
$logs = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Count total for pagination
$countRes = $conn->query("SELECT COUNT(*) as total FROM tbl_audit_log WHERE module = 'Sales'");
$totalLogs = $countRes->fetch_assoc()['total'];
$totalPages = ceil($totalLogs / $limit);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Sales Audit Log</h1>
        <p class="page-header-desc">Track all changes and actions performed within the Sales Module.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to Sales</a>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                <h3>No Sales Logs Found</h3>
                <p>There are no recorded actions in the Sales module yet.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Target ID</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:0.85rem; color:var(--text-secondary);">
                                    <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td>
                                    <?php if ($log['user_name']): ?>
                                        <strong><?= e($log['user_name']) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary" style="font-family:monospace; font-size:0.75rem;">
                                        <?= e($log['action']) ?>
                                    </span>
                                </td>
                                <td><?= e($log['target_type']) ?></td>
                                <td>
                                    <?php if ($log['target_type'] === 'SalesOrder'): ?>
                                        <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $log['target_id'] ?>">#<?= $log['target_id'] ?></a>
                                    <?php else: ?>
                                        <?= $log['target_id'] ?>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.85rem; color:var(--text-secondary); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($log['new_values']) ?>">
                                    <?= e($log['new_values']) ?: '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination animate-in" style="margin-top: 20px; display:flex; justify-content:center; gap:5px;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>" style="padding: 5px 12px;"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
