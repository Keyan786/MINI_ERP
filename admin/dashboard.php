<?php
/**
 * Admin Dashboard - Mini ERP System
 * Overview with stat cards and recent activity.
 */

$pageTitle = 'Admin Dashboard';
$currentModule = 'dashboard';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Dashboard Stats ───────────────────────────────────────────────────────
$stats = [];

// Total Users
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users");
$stats['total_users'] = $r->fetch_assoc()['cnt'];

// Active Users
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users WHERE status = 'active'");
$stats['active_users'] = $r->fetch_assoc()['cnt'];

// Pending Approvals
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_user_approval_requests WHERE status = 'pending'");
$stats['pending_approvals'] = $r->fetch_assoc()['cnt'];

// Rejected Users
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users WHERE status = 'rejected'");
$stats['rejected_users'] = $r->fetch_assoc()['cnt'];

// Total Roles
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_roles");
$stats['total_roles'] = $r->fetch_assoc()['cnt'];

// Audit Log Entries (today)
$today = date('Y-m-d');
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_audit_log WHERE DATE(created_at) = '$today'");
$stats['today_logs'] = $r->fetch_assoc()['cnt'];

// ─── Recent Pending Requests ────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT ar.request_id, ar.request_type, ar.created_at, u.full_name, u.email, u.phone
    FROM tbl_user_approval_requests ar
    JOIN tbl_users u ON ar.user_id = u.user_id
    WHERE ar.status = 'pending'
    ORDER BY ar.created_at DESC
    LIMIT 5
");
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Recent Audit Logs ─────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT log_id, user_name, module, action, record_type, created_at, ip_address
    FROM tbl_audit_log
    ORDER BY created_at DESC
    LIMIT 8
");
$stmt->execute();
$recentLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include __DIR__ . '/../includes/header.php';
?>

<!-- Stat Cards -->
<div class="stat-grid animate-in">
    <div class="stat-card">
        <div class="stat-card-icon blue"><i class="fa-solid fa-users"></i></div>
        <div class="stat-card-value"><?= $stats['total_users'] ?></div>
        <div class="stat-card-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon green"><i class="fa-solid fa-user-check"></i></div>
        <div class="stat-card-value"><?= $stats['active_users'] ?></div>
        <div class="stat-card-label">Active Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon amber"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-card-value"><?= $stats['pending_approvals'] ?></div>
        <div class="stat-card-label">Pending Approvals</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon red"><i class="fa-solid fa-user-xmark"></i></div>
        <div class="stat-card-value"><?= $stats['rejected_users'] ?></div>
        <div class="stat-card-label">Rejected Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon purple"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="stat-card-value"><?= $stats['total_roles'] ?></div>
        <div class="stat-card-label">System Roles</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon cyan"><i class="fa-solid fa-clipboard-list"></i></div>
        <div class="stat-card-value"><?= $stats['today_logs'] ?></div>
        <div class="stat-card-label">Actions Today</div>
    </div>
</div>

<!-- Content Grid -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;" class="animate-in">

    <!-- Pending Approval Requests -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-user-clock" style="color: var(--color-warning); margin-right: 8px;"></i> Pending Approvals</h3>
            <a href="<?= BASE_URL ?>/admin/users.php?status=pending" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($pendingRequests)): ?>
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-state-icon"><i class="fa-solid fa-check-circle"></i></div>
                    <h3>All Caught Up!</h3>
                    <p>No pending approval requests.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $req): ?>
                                <tr>
                                    <td>
                                        <div class="table-user">
                                            <div class="table-user-avatar"><?= strtoupper(substr($req['full_name'], 0, 1)) ?></div>
                                            <div class="table-user-info">
                                                <div class="name"><?= e($req['full_name']) ?></div>
                                                <div class="email"><?= e($req['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= status_badge($req['request_type'] === 'registration' ? 'info' : 'warning') ?> <?= e(ucfirst($req['request_type'])) ?></td>
                                    <td style="font-size:0.8125rem; color:var(--text-muted);"><?= time_ago($req['created_at']) ?></td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/admin/approve_user.php?id=<?= $req['request_id'] ?>" class="btn btn-sm btn-primary">Review</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-timeline" style="color: var(--accent-primary); margin-right: 8px;"></i> Recent Activity</h3>
            <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($recentLogs)): ?>
                <div class="empty-state" style="padding: 40px 20px;">
                    <div class="empty-state-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <h3>No Activity Yet</h3>
                    <p>System activity will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="font-weight:500; color:var(--text-primary); font-size:0.8125rem;"><?= e($log['user_name']) ?></td>
                                    <td><span class="audit-action <?= strtolower($log['action']) ?>"><?= e($log['action']) ?></span></td>
                                    <td style="font-size:0.8125rem; color:var(--text-secondary);"><?= e($log['module']) ?></td>
                                    <td style="font-size:0.8125rem; color:var(--text-muted);"><?= time_ago($log['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    @media (max-width: 1024px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
