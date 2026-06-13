<?php
/**
 * Delete (Deactivate) Product - Mini ERP System
 * Soft-deletes a product by setting is_active = 0. POST only.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

if (!is_post()) {
    redirect('/modules/products/index.php');
}

if (!csrf_validate()) {
    set_flash('error', 'Invalid security token.');
    redirect('/modules/products/index.php');
}

$productId = intval($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect('/modules/products/index.php');
}

// Fetch product for audit
$stmt = $conn->prepare("SELECT product_name, product_code, is_active FROM tbl_products WHERE product_id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/modules/products/index.php');
}

// Deactivate
$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("UPDATE tbl_products SET is_active = 0, updated_by = ? WHERE product_id = ?");
$stmt->bind_param("ii", $userId, $productId);
$stmt->execute();
$stmt->close();

// Audit log
log_action($conn, 'Product Management', ACTION_DEACTIVATE, 'Product', $productId,
    ['is_active' => 1],
    ['is_active' => 0, 'deactivated_by' => $_SESSION['user_name']]
);

set_flash('success', 'Product "' . $product['product_name'] . '" has been deactivated.');
redirect('/modules/products/index.php');
?>
