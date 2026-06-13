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
$validStatuses = ['all', 'pending_setup', 'active', 'inactive', 'locked'];
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
               u.department, u.is_password_set, u.password_changed_at, u.force_password_change,
               r.role_name
        FROM tbl_users u
        LEFT JOIN tbl_roles r ON u.role_id = r.role_id
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
$statusCounts = ['all' => 0, 'pending_setup' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0];
while ($row = $countResult->fetch_assoc()) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = $row['cnt'];
        $statusCounts['all'] += $row['cnt'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>User Management</h1>
        <p class="page-header-desc">Manage user accounts, roles, and authentication status.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/admin/user_create.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add User</a>
    </div>
</div>

<!-- Filter Tabs -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <?php foreach (['all' => 'All', 'pending_setup' => 'Pending Setup', 'active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked'] as $key => $label): ?>
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
                            <th>Role & Dept</th>
                            <th>Status</th>
                            <th>Password Status</th>
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
                                    <div>
                                        <?php if ($user['role_name']): ?>
                                            <span class="badge badge-primary"><?= e($user['role_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.8125rem;">—</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($user['department']): ?>
                                        <div style="font-size:0.725rem; color:var(--text-muted); margin-top:4px;">
                                            <i class="fa-solid fa-building" style="margin-right:2px;"></i> <?= e($user['department']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $badgeClass = 'secondary';
                                        $statusText = ucfirst($user['status']);
                                        if ($user['status'] === 'active') $badgeClass = 'success';
                                        if ($user['status'] === 'inactive') $badgeClass = 'warning';
                                        if ($user['status'] === 'locked') $badgeClass = 'danger';
                                        if ($user['status'] === 'pending_setup') {
                                            $badgeClass = 'info';
                                            $statusText = 'Pending Setup';
                                        }
                                    ?>
                                    <span class="badge badge-<?= $badgeClass ?>"><?= $statusText ?></span>
                                </td>
                                <td style="font-size:0.8125rem; color:var(--text-secondary);">
                                    <?php if ($user['is_password_set']): ?>
                                        <div style="color:var(--color-success); margin-bottom: 2px;"><i class="fa-solid fa-check-circle"></i> Set</div>
                                        <?php if ($user['password_changed_at']): ?>
                                            <span class="text-muted" style="font-size:0.725rem;">Changed <?= date('M d, Y', strtotime($user['password_changed_at'])) ?></span>
                                        <?php endif; ?>
                                        <?php if ($user['force_password_change']): ?>
                                            <div style="margin-top:2px;"><span class="badge badge-warning" style="font-size:0.6rem; padding: 2px 4px;">Force Change Pending</span></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fa-regular fa-clock"></i> Not Set</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.8125rem; color:var(--text-muted);"><?= $user['last_login_at'] ? time_ago($user['last_login_at']) : 'Never' ?></td>
                                <td>
                                    <?php if ($user['user_id'] !== $_SESSION['user_id']): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline dropdown-toggle" type="button" onclick="this.nextElementSibling.classList.toggle('show')">
                                                Actions <i class="fa-solid fa-chevron-down" style="font-size: 0.7em; margin-left:4px;"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-right" style="position: absolute; right: 0; z-index: 100; background: #fff; border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none; min-width: 160px; padding: 4px 0;">
                                                <?php if ($user['status'] === 'pending_setup'): ?>
                                                    <form method="POST" action="<?= BASE_URL ?>/admin/user_actions.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                        <input type="hidden" name="action" value="resend_invite">
                                                        <input type="hidden" name="return_status" value="<?= $statusFilter ?>">
                                                        <button type="submit" class="dropdown-item" style="width:100%; text-align:left; border:none; background:none; padding: 8px 16px; cursor:pointer;"><i class="fa-regular fa-paper-plane" style="width:20px;"></i> Resend Invite</button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <form method="POST" action="<?= BASE_URL ?>/admin/user_actions.php" onsubmit="return confirm('Send a password reset email to this user?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="return_status" value="<?= $statusFilter ?>">
                                                    <button type="submit" class="dropdown-item" style="width:100%; text-align:left; border:none; background:none; padding: 8px 16px; cursor:pointer;"><i class="fa-solid fa-key" style="width:20px;"></i> Reset Password</button>
                                                </form>
                                                
                                                <button type="button" class="dropdown-item" onclick="openPasswordModal(<?= $user['user_id'] ?>, '<?= e(addslashes($user['full_name'])) ?>')" style="width:100%; text-align:left; border:none; background:none; padding: 8px 16px; cursor:pointer;"><i class="fa-solid fa-shield-halved" style="width:20px;"></i> Set New Password</button>
                                                
                                                <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                                
                                                <?php if ($user['status'] !== 'active'): ?>
                                                    <form method="POST" action="<?= BASE_URL ?>/admin/user_actions.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                        <input type="hidden" name="action" value="change_status">
                                                        <input type="hidden" name="new_status" value="active">
                                                        <input type="hidden" name="return_status" value="<?= $statusFilter ?>">
                                                        <button type="submit" class="dropdown-item" style="width:100%; text-align:left; border:none; background:none; padding: 8px 16px; cursor:pointer; color:var(--color-success);"><i class="fa-solid fa-check" style="width:20px;"></i> Mark as Active</button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($user['status'] !== 'inactive'): ?>
                                                    <form method="POST" action="<?= BASE_URL ?>/admin/user_actions.php" onsubmit="return confirm('Deactivate this user? They will not be able to log in.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="target_user_id" value="<?= $user['user_id'] ?>">
                                                        <input type="hidden" name="action" value="change_status">
                                                        <input type="hidden" name="new_status" value="inactive">
                                                        <input type="hidden" name="return_status" value="<?= $statusFilter ?>">
                                                        <button type="submit" class="dropdown-item" style="width:100%; text-align:left; border:none; background:none; padding: 8px 16px; cursor:pointer; color:var(--color-warning);"><i class="fa-solid fa-user-minus" style="width:20px;"></i> Mark as Inactive</button>
                                                    </form>
                                                <?php endif; ?>
                                                

                                            </div>
                                        </div>
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

<!-- Password Change Modal -->
<div id="passwordModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div class="card animate-in" style="width: 400px; max-width: 90%; background:#fff; padding: 20px;">
        <h3 style="margin-top:0;">Set New Password</h3>
        <p style="font-size: 0.85rem; color: var(--text-secondary);">Directly change the password for <strong id="modalUserName"></strong>.</p>
        
        <form method="POST" action="<?= BASE_URL ?>/admin/user_actions.php">
            <?= csrf_field() ?>
            <input type="hidden" name="target_user_id" id="modalUserId" value="">
            <input type="hidden" name="action" value="admin_change_password">
            <input type="hidden" name="return_status" value="<?= $statusFilter ?>">
            
            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <input type="text" name="new_password" class="form-control" required minlength="6" placeholder="Enter new password">
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn btn-outline" onclick="closePasswordModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPasswordModal(userId, userName) {
    document.getElementById('modalUserId').value = userId;
    document.getElementById('modalUserName').innerText = userName;
    document.getElementById('passwordModal').style.display = 'flex';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}

// Simple dropdown toggle logic for actions
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            menu.classList.remove('show');
        });
    }
});
</script>
<style>
.dropdown-menu.show { display: block !important; }
.dropdown-item:hover { background-color: var(--surface-hover); }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
