<?php
/**
 * Damage & Returns - Database Migration
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "Starting Damage & Returns Migration...\n";

$conn->begin_transaction();
try {
    // 1. Update movement_type ENUM in tbl_stock_movements
    $conn->query("ALTER TABLE tbl_stock_movements MODIFY COLUMN movement_type ENUM('initial','purchase_in','manufacturing_in','sales_out','manufacturing_consume','adjustment','damage_out','sales_return_in','purchase_return_out') NOT NULL");
    echo "Updated movement_type ENUM in tbl_stock_movements.\n";

    // 2. Add returned_qty to tbl_so_lines
    $res = $conn->query("SHOW COLUMNS FROM tbl_so_lines LIKE 'returned_qty'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_so_lines ADD COLUMN returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER delivered_qty");
        echo "Added returned_qty to tbl_so_lines.\n";
    } else {
        echo "returned_qty already exists in tbl_so_lines.\n";
    }

    // 3. Add returned_qty to tbl_purchase_order_lines
    $res = $conn->query("SHOW COLUMNS FROM tbl_purchase_order_lines LIKE 'returned_qty'");
    if ($res->num_rows == 0) {
        $conn->query("ALTER TABLE tbl_purchase_order_lines ADD COLUMN returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER received_qty");
        echo "Added returned_qty to tbl_purchase_order_lines.\n";
    } else {
        echo "returned_qty already exists in tbl_purchase_order_lines.\n";
    }

    $conn->commit();
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
