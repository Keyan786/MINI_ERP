<?php
/**
 * Sales Orders - List & Kanban Views
 */

$pageTitle = 'Sales Orders';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';

$statusFilter = $_GET['status'] ?? 'all';

// Fetch SOs with total dynamically calculated (Ordered * Unit Price if Draft/Confirmed, else Delivered * Unit Price)
$sql = "SELECT so.so_id, so.so_number, so.customer_name_snapshot, so.status, so.created_at, u.full_name as sales_person,
        (SELECT SUM(
            CASE 
                WHEN so.status IN ('draft', 'confirmed') THEN l.ordered_qty * l.unit_price
                ELSE l.delivered_qty * l.unit_price
            END
        ) FROM tbl_so_lines l WHERE l.so_id = so.so_id) as dynamic_total
        FROM tbl_sales_orders so
        LEFT JOIN tbl_users u ON so.sales_person_id = u.user_id
        ORDER BY so.created_at DESC";

$orders = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// For List View filtering
$filteredOrders = [];
if ($statusFilter !== 'all') {
    foreach ($orders as $o) {
        if ($o['status'] === $statusFilter) {
            $filteredOrders[] = $o;
        }
    }
} else {
    $filteredOrders = $orders;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Sales Orders</h1>
        <p class="page-header-desc">Manage the entire sales lifecycle from draft to delivery.</p>
    </div>
    <div>
        
        <a href="<?= BASE_URL ?>/modules/sales/customers.php" class="btn btn-outline" style="margin-right: 10px;">
            <i class="fa-solid fa-users"></i> Customers
        </a>
        <a href="<?= BASE_URL ?>/modules/sales/create.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> New Sales Order
        </a>
    </div>
</div>

    <!-- LIST VIEW -->
    <div class="filter-bar animate-in">
        <div class="filter-tabs">
            <?php 
                $tabs = [
                    'all' => 'All', 
                    'draft' => 'Draft', 
                    'confirmed' => 'Confirmed', 
                    'partially_delivered' => 'Partially Delivered', 
                    'fully_delivered' => 'Fully Delivered', 
                    'cancelled' => 'Cancelled'
                ];
                foreach ($tabs as $k => $label): 
            ?>
                <a href="?status=<?= $k ?>" class="filter-tab <?= $statusFilter === $k ? 'active' : '' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="search-input">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search orders..." data-search-table="so-table">
        </div>
    </div>

    <div class="card animate-in">
        <div class="card-body" style="padding:0;">
            <?php if (empty($filteredOrders)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <h3>No Sales Orders Found</h3>
                    <p>Create a new sales order to get started.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table" id="so-table">
                        <thead>
                            <tr>
                                <th>SO Number</th>
                                <th>Creation Date</th>
                                <th>Customer</th>
                                <th>Sales Person</th>
                                <th>Status</th>
                                <th style="text-align:right;">Order Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filteredOrders as $o): ?>
                                <tr style="cursor:pointer;" onclick="window.location='<?= BASE_URL ?>/modules/sales/view.php?id=<?= $o['so_id'] ?>'">
                                    <td><strong><?= e($o['so_number']) ?></strong></td>
                                    <td><?= date('M d, Y H:i', strtotime($o['created_at'])) ?></td>
                                    <td><?= e($o['customer_name_snapshot']) ?></td>
                                    <td><?= e($o['sales_person']) ?></td>
                                    <td>
                                        <?php
                                            $badge = 'secondary';
                                            if ($o['status'] === 'confirmed') $badge = 'primary';
                                            if ($o['status'] === 'partially_delivered') $badge = 'warning';
                                            if ($o['status'] === 'fully_delivered') $badge = 'success';
                                            if ($o['status'] === 'cancelled') $badge = 'danger';
                                            $statusLabel = ucwords(str_replace('_', ' ', $o['status']));
                                        ?>
                                        <span class="badge badge-<?= $badge ?>"><?= $statusLabel ?></span>
                                    </td>
                                    <td style="text-align:right; font-weight:600;">₹<?= number_format((float)$o['dynamic_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
