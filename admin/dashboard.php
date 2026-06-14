<?php
/**
 * Admin Dashboard - Mini ERP System
 * Overview with stat cards, KPIs, and Analytics.
 */

$pageTitle = 'Admin Dashboard';
$currentModule = 'dashboard';

require_once __DIR__ . '/../includes/auth_check.php';
require_admin();

// ─── Dashboard Stats ───────────────────────────────────────────────────────
$stats = [];
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users");
$stats['total_users'] = $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users WHERE status = 'active'");
$stats['active_users'] = $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_user_approval_requests WHERE status = 'pending'");
$stats['pending_approvals'] = $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users WHERE status = 'rejected'");
$stats['rejected_users'] = $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_roles");
$stats['total_roles'] = $r->fetch_assoc()['cnt'];
$today = date('Y-m-d');
$r = $conn->query("SELECT COUNT(*) as cnt FROM tbl_audit_log WHERE DATE(created_at) = '$today'");
$stats['today_logs'] = $r->fetch_assoc()['cnt'];

// ─── KPI Queries ────────────────────────────────────────────────────────────

// 1. Fast-Moving Products (Last 30 days outbound)
$fastMovingQuery = "
    SELECT p.product_name, SUM(ABS(m.quantity)) as total_outbound
    FROM tbl_stock_movements m
    JOIN tbl_products p ON m.product_id = p.product_id
    WHERE m.quantity < 0 
      AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY m.product_id, p.product_name
    ORDER BY total_outbound DESC
    LIMIT 5
";
$fastMoving = $conn->query($fastMovingQuery)->fetch_all(MYSQLI_ASSOC);

// 2. Dead Stock (on-hand > 0, NO outbound in last 90 days)
$deadStockQuery = "
    SELECT p.product_name, p.on_hand_qty, 
           (p.on_hand_qty * p.cost_price) as dead_value
    FROM tbl_products p
    WHERE p.on_hand_qty > 0
      AND p.product_id NOT IN (
          SELECT product_id FROM tbl_stock_movements 
          WHERE quantity < 0 
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      )
    ORDER BY dead_value DESC
    LIMIT 5
";
$deadStock = $conn->query($deadStockQuery)->fetch_all(MYSQLI_ASSOC);

// 3. Low Stock Predictions (<14 days remaining based on 30d avg consumption)
$lowStockQuery = "
    SELECT 
        p.product_name, 
        (p.on_hand_qty - p.reserved_qty) as free_qty,
        SUM(ABS(m.quantity)) as out_30d,
        (SUM(ABS(m.quantity)) / 30) as daily_avg,
        ((p.on_hand_qty - p.reserved_qty) / NULLIF(SUM(ABS(m.quantity)) / 30, 0)) as days_remaining
    FROM tbl_products p
    JOIN tbl_stock_movements m ON p.product_id = m.product_id
    WHERE m.quantity < 0 
      AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND (p.on_hand_qty - p.reserved_qty) > 0
    GROUP BY p.product_id, p.product_name, p.on_hand_qty, p.reserved_qty
    HAVING days_remaining < 14
    ORDER BY days_remaining ASC
    LIMIT 5
";
$lowStock = $conn->query($lowStockQuery)->fetch_all(MYSQLI_ASSOC);

// 4. Monthly Inventory Trends (Last 6 Months)
$trendsQuery = "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as inbound,
        SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) as outbound
    FROM tbl_stock_movements
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month ASC
";
$monthlyTrends = $conn->query($trendsQuery)->fetch_all(MYSQLI_ASSOC);

// 5. Vendor Performance (Avg Days to Fulfill POs)
$vendorQuery = "
    SELECT 
        vendor_name, 
        COUNT(po_id) as po_count,
        AVG(DATEDIFF(received_at, confirmed_at)) as avg_days
    FROM tbl_purchase_orders
    WHERE status = 'fully_received'
      AND confirmed_at IS NOT NULL
      AND received_at IS NOT NULL
    GROUP BY vendor_id, vendor_name
    ORDER BY avg_days ASC
    LIMIT 5
";
$vendorPerformance = $conn->query($vendorQuery)->fetch_all(MYSQLI_ASSOC);

// Prepare data for Chart.js
$trendMonths = [];
$trendInbound = [];
$trendOutbound = [];
foreach ($monthlyTrends as $t) {
    $trendMonths[] = date('M Y', strtotime($t['month'] . '-01'));
    $trendInbound[] = (float)$t['inbound'];
    $trendOutbound[] = (float)$t['outbound'];
}

$vendorNames = [];
$vendorDays = [];
foreach ($vendorPerformance as $v) {
    $vendorNames[] = $v['vendor_name'];
    $vendorDays[] = round((float)$v['avg_days'], 1);
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Include Chart.js -->
<script src="<?= BASE_URL ?>/assets/js/chart.js"></script>

<div class="page-header animate-in" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1>Admin Dashboard</h1>
        <p class="page-header-desc">Overview and KPIs for Mini ERP</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/admin/audit_log.php" class="btn btn-secondary">
            <i class="fa-solid fa-timeline"></i> View Recent Activity
        </a>
    </div>
</div>

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

<!-- Analytics Grid Row 1: Charts -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;" class="animate-in">
    <!-- Monthly Trends Chart -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-chart-line" style="color: var(--accent-primary); margin-right: 8px;"></i> Monthly Inventory Trends (6 Mo)</h3>
        </div>
        <div class="card-body">
            <canvas id="monthlyTrendsChart" height="100"></canvas>
        </div>
    </div>

    <!-- Vendor Performance Chart -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-truck-fast" style="color: var(--color-warning); margin-right: 8px;"></i> Vendor Performance (Avg Days to Fulfill)</h3>
        </div>
        <div class="card-body">
            <canvas id="vendorPerformanceChart" height="200"></canvas>
        </div>
    </div>
</div>

<!-- Analytics Grid Row 2: Tables -->
<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;" class="animate-in">
    <!-- Fast Moving Products -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-bolt" style="color: var(--color-success); margin-right: 8px;"></i> Fast-Moving Products (30 Days)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Product</th><th style="text-align:right;">Qty Out</th></tr></thead>
                    <tbody>
                        <?php if (empty($fastMoving)): ?>
                            <tr><td colspan="2" style="text-align:center; padding: 20px;">No data</td></tr>
                        <?php else: ?>
                            <?php foreach ($fastMoving as $fm): ?>
                                <tr>
                                    <td style="font-weight: 500;"><?= e($fm['product_name']) ?></td>
                                    <td style="text-align:right; color: var(--color-success); font-weight: 600;">
                                        <?= number_format($fm['total_outbound'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Low Stock Predictions -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-triangle-exclamation" style="color: var(--color-warning); margin-right: 8px;"></i> Low Stock Predictions</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Product</th><th style="text-align:right;">Days Left</th></tr></thead>
                    <tbody>
                        <?php if (empty($lowStock)): ?>
                            <tr><td colspan="2" style="text-align:center; padding: 20px;">Stock levels are healthy!</td></tr>
                        <?php else: ?>
                            <?php foreach ($lowStock as $ls): ?>
                                <tr>
                                    <td style="font-weight: 500;"><?= e($ls['product_name']) ?></td>
                                    <td style="text-align:right; color: var(--color-danger); font-weight: 600;">
                                        <?= round($ls['days_remaining'], 1) ?> days
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Dead Stock -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-skull-crossbones" style="color: var(--color-danger); margin-right: 8px;"></i> Dead Stock (90 Days No Mvmt)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Product</th><th style="text-align:right;">Value Tied Up</th></tr></thead>
                    <tbody>
                        <?php if (empty($deadStock)): ?>
                            <tr><td colspan="2" style="text-align:center; padding: 20px;">No dead stock detected.</td></tr>
                        <?php else: ?>
                            <?php foreach ($deadStock as $ds): ?>
                                <tr>
                                    <td style="font-weight: 500;"><?= e($ds['product_name']) ?><br>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">Qty: <?= number_format($ds['on_hand_qty'], 2) ?></span>
                                    </td>
                                    <td style="text-align:right; color: var(--color-danger); font-weight: 600;">
                                        ₹<?= number_format($ds['dead_value'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Colors based on our CSS variables (approximated for JS)
    const accentPrimary = '#3b82f6';
    const accentLight = 'rgba(59, 130, 246, 0.1)';
    const colorSuccess = '#10b981';
    const colorSuccessLight = 'rgba(16, 185, 129, 0.1)';
    const colorWarning = '#f59e0b';
    const colorWarningLight = 'rgba(245, 158, 11, 0.1)';
    
    // Monthly Trends Chart
    const ctxTrends = document.getElementById('monthlyTrendsChart').getContext('2d');
    new Chart(ctxTrends, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendMonths) ?>,
            datasets: [
                {
                    label: 'Inbound Qty',
                    data: <?= json_encode($trendInbound) ?>,
                    borderColor: colorSuccess,
                    backgroundColor: colorSuccessLight,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Outbound Qty',
                    data: <?= json_encode($trendOutbound) ?>,
                    borderColor: accentPrimary,
                    backgroundColor: accentLight,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Vendor Performance Chart
    const ctxVendor = document.getElementById('vendorPerformanceChart').getContext('2d');
    new Chart(ctxVendor, {
        type: 'bar',
        data: {
            labels: <?= json_encode($vendorNames) ?>,
            datasets: [{
                label: 'Avg Days to Fulfill',
                data: <?= json_encode($vendorDays) ?>,
                backgroundColor: colorWarning,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Days' } }
            }
        }
    });
});
</script>

<style>
    @media (max-width: 1200px) {
        .main-content > div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
