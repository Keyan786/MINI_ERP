<?php
/**
 * User Management - Mini ERP System
 * Lists all users with status filtering and management actions.
 */

$pageTitle = 'User Management';
$currentModule = 'user-management';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Filters ────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'pending', 'active', 'rejected', 'suspended'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'all';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// ─── Build Query ────────────────────────────────────────────────────────────
$whereClause = '';
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $whereClause = "WHERE u.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

// Count total
$countSql = "SELECT COUNT(*) as cnt FROM tbl_users u $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, ceil($totalUsers / $perPage));

// Fetch users
$sql = "SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at, u.last_login_at,
               u.approved_by, u.approved_at, u.rejection_reason,
               r.role_name,
               approver.full_name AS approver_name
        FROM tbl_users u
        LEFT JOIN tbl_roles r ON u.role_id = r.role_id
        LEFT JOIN tbl_users approver ON u.approved_by = approver.user_id
        $whereClause
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Status Counts ──────────────────────────────────────────────────────────
$countResult = $conn->query("SELECT status, COUNT(*) as cnt FROM tbl_users GROUP BY status");
$statusCounts = ['all' => 0, 'pending' => 0, 'active' => 0, 'rejected' => 0, 'suspended' => 0];
while ($row = $countResult->fetch_assoc()) {
    $statusCounts[$row['status']] = $row['cnt'];
    $statusCounts['all'] += $row['cnt'];
}

// ─── Handle Suspend/Reactivate Actions ──────────────────────────────────────
if (is_post() && isset($_POST['action_type'])) {
    if (!csrf_validate()) {
        set_flash('error', 'Invalid security token.');
        redirect('/admin/users.php');
    }

    $targetUserId = intval($_POST['target_user_id'] ?? 0);
    $actionType = $_POST['action_type'];

    if ($targetUserId > 0 && $targetUserId !== $_SESSION['user_id']) {
        // Get current user data
        $stmt = $conn->prepare("SELECT user_id, full_name, status FROM tbl_users WHERE user_id = ?");
        $stmt->bind_param("i", $targetUserId);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($targetUser) {
            $oldStatus = $targetUser['status'];
            $newStatus = null;

            if ($actionType === 'suspend' && $oldStatus === 'active') {
                $newStatus = 'suspended';
            } elseif ($actionType === 'reactivate' && $oldStatus === 'suspended') {
                $newStatus = 'active';
            }

            if ($newStatus) {
                $stmt = $conn->prepare("UPDATE tbl_users SET status = ? WHERE user_id = ?");
                $stmt->bind_param("si", $newStatus, $targetUserId);
                $stmt->execute();
                $stmt->close();

                log_action($conn, 'User Management', strtoupper($actionType), 'User', $targetUserId,
                    ['status' => $oldStatus], ['status' => $newStatus]);

                set_flash('success', 'User ' . $targetUser['full_name'] . ' has been ' . $newStatus . '.');
            }
        }
    }
    redirect('/admin/users.php?status=' . $statusFilter);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>User Management</h1>
        <p class="page-header-desc">Manage user accounts, approvals, and access</p>
    </div>
</div>

<!-- Filter Tabs -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <?php foreach (['all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'rejected' => 'Rejected', 'suspended' => 'Suspended'] as $key => $label): ?>
            <a href="<?= BASE_URL ?>/admin/users.php?status=<?= $key ?>" 
               class="filter-tab <?= $statusFilter === $key ? 'active' : '' ?>">
                <?= $label ?> <span style="opacity:0.7; margin-left:2px;">(<?= $statusCounts[$key] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="search-input">
        <i class="fa-solid fa-search"></i>
        <input type="text" class="form-control" placeholder="Search users..." data-search-table="users-table">
    </div>
</div>

<!-- Users Table -->
<div class="card animate-in">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-users-slash"></i></div>
                <h3>No Users Found</h3>
                <p>No users match the current filter.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table" id="users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Registered</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="table-user">
                                        <div class="table-user-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                                        <div class="table-user-info">
                                            <div class="name"><?= e($user['full_name']) ?></div>
                                            <div class="email"><?= e($user['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($user['role_name']): ?>
                                        <span class="badge badge-primary"><?= e($user['role_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.8125rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= status_badge($user['status']) ?></td>
                                <td style="font-size:0.8125rem; color:var(--text-secondary);">
                                    <?php if ($user['approver_name']): ?>
                                        <?= e($user['approver_name']) ?>
                                        <br><span class="text-muted" style="font-size:0.725rem;"><?= format_datetime($user['approved_at']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.8125rem; color:var(--text-muted);"><?= format_datetime($user['created_at']) ?></td>
                                <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $user['last_login_at'] ? time_ago($user['last_login_at']) : '—' ?></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($user['status'] === 'pending'): ?>
                                            <?php
                                                // Find the pending request for this user
                                                $stmtReq = $conn->prepare("SELECT request_id FROM tbl_user_approval_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                                                $stmtReq->bind_param("i", $user['user_id']);
                                                $stmtReq->execute();
                                                $reqResult = $stmtReq->get_result()->fetch_assoc();
                                                $stmtReq->close();
                                            ?>
                                            <?php if ($reqResult): ?>
                                                <a href="<?= BASE_URL ?>/admin/approve_user.php?id=<?= $reqResult['request_id'] ?>" class="btn btn-sm btn-success" title="Review">
                                                    <i class="fa-solid fa-check"></i> Review
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif ($user['status'] === 'active' && $user['user_id'] !== $_SESSION['user_id']): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Suspend this user?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                <input type="hidden" name="action_type" value="suspend">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Suspend"><i class="fa-solid fa-ban"></i></button>
                                            </form>
                                        <?php elseif ($user['status'] === 'suspended'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate this user?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                <input type="hidden" name="action_type" value="reactivate">
                                                <button type="submit" class="btn btn-sm btn-success" title="Reactivate"><i class="fa-solid fa-rotate-left"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?= $statusFilter ?>&page=<?= $page - 1 ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-solid fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?status=<?= $statusFilter ?>&page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?status=<?= $statusFilter ?>&page=<?= $page + 1 ?>"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa-solid fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
