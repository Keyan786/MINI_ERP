<?php
/**
 * Product Catalog - Mini ERP System
 * Lists all products with filtering, search, and stock status indicators.
 */

$pageTitle = 'Product Management';
$currentModule = 'products';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

// ─── Filters ─────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$categoryFilter = intval($_GET['category'] ?? 0);
$statusFilter = $_GET['status'] ?? 'all'; // all, active, inactive
$stockFilter = $_GET['stock'] ?? 'all'; // all, in_stock, low_stock, out_of_stock
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($categoryFilter > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryFilter;
    $types .= "i";
}

if ($statusFilter === 'active') {
    $where[] = "p.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $where[] = "p.is_active = 0";
}

if ($stockFilter === 'in_stock') {
    $where[] = "(p.on_hand_qty - p.reserved_qty) > p.min_stock_level";
} elseif ($stockFilter === 'low_stock') {
    $where[] = "(p.on_hand_qty - p.reserved_qty) > 0 AND (p.on_hand_qty - p.reserved_qty) <= p.min_stock_level";
} elseif ($stockFilter === 'out_of_stock') {
    $where[] = "(p.on_hand_qty - p.reserved_qty) <= 0";
}

$whereClause = implode(" AND ", $where);

// Count total
$countSql = "SELECT COUNT(*) as total FROM tbl_products p WHERE $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalProducts = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$totalPages = max(1, ceil($totalProducts / $perPage));

// Fetch products
$sql = "SELECT p.*, c.category_name, v.vendor_name as default_vendor_name
        FROM tbl_products p
        LEFT JOIN tbl_product_categories c ON p.category_id = c.category_id
        LEFT JOIN tbl_vendors v ON p.default_vendor_id = v.vendor_id
        WHERE $whereClause
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch categories for filter dropdown
$categories = $conn->query("SELECT category_id, category_name FROM tbl_product_categories WHERE is_active = 1 ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Product Management</h1>
        <p class="page-header-desc">Manage your product catalog and inventory items</p>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>/modules/products/categories.php" class="btn btn-secondary">
            <i class="fa-solid fa-tags"></i> Categories
        </a>
        <a href="<?= BASE_URL ?>/modules/products/create.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> New Product
        </a>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar animate-in">
    <div class="filter-tabs">
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'all', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'all' ? 'active' : '' ?>">All</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'in_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'in_stock' ? 'active' : '' ?>">In Stock</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'low_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'low_stock' ? 'active' : '' ?>">Low Stock</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['stock' => 'out_of_stock', 'page' => 1])) ?>" class="filter-tab <?= $stockFilter === 'out_of_stock' ? 'active' : '' ?>">Out of Stock</a>
    </div>

    <div class="search-input">
        <i class="fa-solid fa-search"></i>
        <form method="GET" style="margin:0;">
            <?php if ($categoryFilter): ?><input type="hidden" name="category" value="<?= $categoryFilter ?>"><?php endif; ?>
            <?php if ($stockFilter !== 'all'): ?><input type="hidden" name="stock" value="<?= e($stockFilter) ?>"><?php endif; ?>
            <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?= e($search) ?>">
        </form>
    </div>

    <select class="form-control form-select" style="max-width:180px;" onchange="location.href='?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>&category='+this.value">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['category_id'] ?>" <?= $categoryFilter == $cat['category_id'] ? 'selected' : '' ?>><?= e($cat['category_name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Product Table -->
<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table" id="products-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th style="text-align:right;">Sales Price</th>
                    <th style="text-align:right;">Cost Price</th>
                    <th style="text-align:right;">On-Hand</th>
                    <th style="text-align:right;">Free-to-Use</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state" style="padding:40px;">
                                <div class="empty-state-icon"><i class="fa-solid fa-box-open"></i></div>
                                <h3>No Products Found</h3>
                                <p><?= !empty($search) ? 'Try adjusting your search criteria.' : 'Create your first product to get started.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $prod): ?>
                        <?php
                            $freeQty = get_free_qty($prod['on_hand_qty'], $prod['reserved_qty']);
                        ?>
                        <tr>
                            <td>
                                <span style="font-family:'Fira Code',monospace; font-size:0.8125rem; font-weight:500; color:var(--accent-primary);">
                                    <?= e($prod['product_code']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $prod['product_id'] ?>" style="font-weight:600; color:var(--text-primary);">
                                    <?= e($prod['product_name']) ?>
                                </a>
                                <?php if (!$prod['is_active']): ?>
                                    <span class="badge badge-secondary" style="margin-left:4px;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size:0.8125rem; color:var(--text-muted);">
                                    <?= $prod['category_name'] ? e($prod['category_name']) : '—' ?>
                                </span>
                            </td>
                            <td style="text-align:right; font-weight:500;">
                                <?= fmt_price($prod['sales_price']) ?>
                            </td>
                            <td style="text-align:right; color:var(--text-muted);">
                                <?= fmt_price($prod['cost_price']) ?>
                            </td>
                            <td style="text-align:right; font-weight:500;">
                                <?= fmt_qty($prod['on_hand_qty']) ?> <span style="font-size:0.75rem; color:var(--text-muted);"><?= e($prod['uom']) ?></span>
                            </td>
                            <td style="text-align:right; font-weight:500;">
                                <?= fmt_qty($freeQty) ?>
                            </td>
                            <td>
                                <?= stock_status_badge($freeQty, $prod['min_stock_level']) ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="btn-group">
                                    <a href="<?= BASE_URL ?>/modules/products/view.php?id=<?= $prod['product_id'] ?>" class="btn btn-sm btn-secondary" title="View">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/products/edit.php?id=<?= $prod['product_id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
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
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
