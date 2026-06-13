<?php
/**
 * Product Categories Management - Mini ERP System
 * List, create, edit categories with inline modals.
 */

$pageTitle = 'Product Categories';
$currentModule = 'products';

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

$errors = [];

// ─── Handle Create/Edit POST ─────────────────────────────────────────────────
if (is_post()) {
    if (!csrf_validate()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $categoryId = intval($_POST['category_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($categoryName)) {
            $errors[] = 'Category name is required.';
        } elseif (strlen($categoryName) > 100) {
            $errors[] = 'Category name must be 100 characters or less.';
        }

        if (empty($errors)) {
            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO tbl_product_categories (category_name, description, is_active, created_by) VALUES (?, ?, ?, ?)");
                $userId = $_SESSION['user_id'];
                $stmt->bind_param("ssii", $categoryName, $description, $isActive, $userId);
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    log_action($conn, 'Product Management', ACTION_CREATE, 'Category', $newId, null, ['category_name' => $categoryName]);
                    set_flash('success', 'Category "' . $categoryName . '" created successfully.');
                    redirect('/modules/products/categories.php');
                } else {
                    if ($conn->errno === 1062) {
                        $errors[] = 'A category with this name already exists.';
                    } else {
                        $errors[] = 'Failed to create category.';
                    }
                }
                $stmt->close();
            } elseif ($action === 'edit' && $categoryId > 0) {
                // Fetch old data for audit
                $stmt = $conn->prepare("SELECT category_name, description, is_active FROM tbl_product_categories WHERE category_id = ?");
                $stmt->bind_param("i", $categoryId);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE tbl_product_categories SET category_name = ?, description = ?, is_active = ?, updated_by = ? WHERE category_id = ?");
                $userId = $_SESSION['user_id'];
                $stmt->bind_param("ssiii", $categoryName, $description, $isActive, $userId, $categoryId);
                if ($stmt->execute()) {
                    log_action($conn, 'Product Management', ACTION_UPDATE, 'Category', $categoryId,
                        $old, ['category_name' => $categoryName, 'description' => $description, 'is_active' => $isActive]);
                    set_flash('success', 'Category updated successfully.');
                    redirect('/modules/products/categories.php');
                } else {
                    if ($conn->errno === 1062) {
                        $errors[] = 'A category with this name already exists.';
                    } else {
                        $errors[] = 'Failed to update category.';
                    }
                }
                $stmt->close();
            }
        }
    }
}

// ─── Handle Delete ───────────────────────────────────────────────────────────
if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    // Handled via separate check
} elseif (isset($_GET['delete'])) {
    $deleteId = intval($_GET['delete']);
    if ($deleteId > 0) {
        // Check if category has products
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_products WHERE category_id = ?");
        $stmt->bind_param("i", $deleteId);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($count > 0) {
            set_flash('error', 'Cannot delete category — ' . $count . ' product(s) are assigned to it.');
        } else {
            $stmt = $conn->prepare("SELECT category_name FROM tbl_product_categories WHERE category_id = ?");
            $stmt->bind_param("i", $deleteId);
            $stmt->execute();
            $cat = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM tbl_product_categories WHERE category_id = ?");
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                log_action($conn, 'Product Management', ACTION_DELETE, 'Category', $deleteId, $cat, null);
                set_flash('success', 'Category deleted.');
            }
            $stmt->close();
        }
        redirect('/modules/products/categories.php');
    }
}

// ─── Fetch Categories ────────────────────────────────────────────────────────
$categories = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM tbl_products p WHERE p.category_id = c.category_id) as product_count,
           u.full_name as creator_name
    FROM tbl_product_categories c
    LEFT JOIN tbl_users u ON c.created_by = u.user_id
    ORDER BY c.category_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Check if we're editing
$editCategory = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    foreach ($categories as $cat) {
        if ($cat['category_id'] == $editId) {
            $editCategory = $cat;
            break;
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Product Categories</h1>
        <p class="page-header-desc">Manage product classification categories</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('create-modal').classList.add('active')">
        <i class="fa-solid fa-plus"></i> New Category
    </button>
</div>

<?php if (!empty($errors)): ?>
    <div class="animate-in" style="background: var(--color-danger-bg); border: 1px solid rgba(220,38,38,0.15); border-radius: var(--border-radius-sm); padding: 14px 16px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--color-danger);">
        <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i>
        <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card animate-in">
    <div class="table-wrapper">
        <table class="data-table" id="categories-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state" style="padding:40px;">
                                <div class="empty-state-icon"><i class="fa-solid fa-tags"></i></div>
                                <h3>No Categories</h3>
                                <p>Create your first product category to get started.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>
                                <span style="font-weight:600; color:var(--text-primary);"><?= e($cat['category_name']) ?></span>
                            </td>
                            <td style="max-width:300px;">
                                <span style="color:var(--text-muted); font-size:0.8125rem;"><?= $cat['description'] ? e(substr($cat['description'], 0, 80)) . (strlen($cat['description']) > 80 ? '…' : '') : '—' ?></span>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?= $cat['product_count'] ?></span>
                            </td>
                            <td>
                                <?php if ($cat['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8125rem; color:var(--text-muted);">
                                <?= format_datetime($cat['created_at']) ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="btn-group">
                                    <a href="?edit=<?= $cat['category_id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <?php if ($cat['product_count'] == 0): ?>
                                        <a href="?delete=<?= $cat['category_id'] ?>" class="btn btn-sm btn-danger" title="Delete"
                                           onclick="return confirm('Delete this category?')">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="create-modal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-plus" style="color:var(--accent-primary); margin-right:8px;"></i>New Category</h3>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
        </div>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="category_name">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="category_name" id="category_name" class="form-control" required maxlength="100" placeholder="e.g. Raw Materials">
                </div>
                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" placeholder="Brief description of this category..."></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_active" checked style="accent-color:var(--accent-primary);width:16px;height:16px;">
                        <span class="form-label" style="margin-bottom:0;">Active</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal (shown via query param) -->
<?php if ($editCategory): ?>
<div class="modal-overlay active" id="edit-modal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="color:var(--color-warning); margin-right:8px;"></i>Edit Category</h3>
            <a href="<?= BASE_URL ?>/modules/products/categories.php" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="category_id" value="<?= $editCategory['category_id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="edit_category_name">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="category_name" id="edit_category_name" class="form-control" required maxlength="100"
                           value="<?= e($editCategory['category_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_description">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"><?= e($editCategory['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_active" <?= $editCategory['is_active'] ? 'checked' : '' ?> style="accent-color:var(--accent-primary);width:16px;height:16px;">
                        <span class="form-label" style="margin-bottom:0;">Active</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= BASE_URL ?>/modules/products/categories.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
