<?php
/**
 * Customer Management - Mini ERP System
 */

$pageTitle = 'Customers';
$currentModule = 'sales';

require_once __DIR__ . '/../../includes/auth_check.php';

// Handle Add Customer POST
if (is_post() && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    if (csrf_validate()) {
        $name = trim($_POST['customer_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        
        if (empty($name)) {
            set_flash('error', 'Customer name is required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO tbl_customers (customer_name, email, phone, address, city, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $name, $email, $phone, $address, $city, $_SESSION['user_id']);
            if ($stmt->execute()) {
                set_flash('success', 'Customer added successfully.');
            } else {
                set_flash('error', 'Failed to add customer. ' . $conn->error);
            }
            $stmt->close();
        }
    }
    redirect('/modules/sales/customers.php');
}

// Fetch Customers
$stmt = $conn->query("SELECT * FROM tbl_customers ORDER BY customer_name ASC");
$customers = $stmt->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header animate-in">
    <div>
        <h1>Customers</h1>
        <p class="page-header-desc">Manage your customer database.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="btn btn-outline" style="margin-right: 10px;">Back to Sales</a>
        <button class="btn btn-primary" onclick="document.getElementById('addCustomerModal').style.display='flex'">
            <i class="fa-solid fa-plus"></i> Add Customer
        </button>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body" style="padding:0;">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-users"></i></div>
                <h3>No Customers Found</h3>
                <p>Start by adding your first customer.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><strong><?= e($c['customer_name']) ?></strong></td>
                                <td style="font-size:0.85rem;">
                                    <?php if($c['email']) echo "<div><i class='fa-regular fa-envelope'></i> " . e($c['email']) . "</div>"; ?>
                                    <?php if($c['phone']) echo "<div><i class='fa-solid fa-phone'></i> " . e($c['phone']) . "</div>"; ?>
                                </td>
                                <td style="font-size:0.85rem; color:var(--text-secondary);">
                                    <?= e($c['address']) ?><br>
                                    <?= e($c['city']) ?>
                                </td>
                                <td>
                                    <?php if ($c['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div id="addCustomerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div class="card animate-in" style="width: 500px; max-width: 90%; background:#fff; padding: 20px;">
        <h3 style="margin-top:0;">Add New Customer</h3>
        
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_customer">
            
            <div class="form-group">
                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                <input type="text" name="customer_name" class="form-control" required>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="form-group col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control">
            </div>
            
            <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control">
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('addCustomerModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
