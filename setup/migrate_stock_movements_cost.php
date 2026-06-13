<?php
/**
 * Inventory Cost Tracking — Database Migration
 * Adds unit_cost and movement_value columns to tbl_stock_movements
 * Backfills existing records with the current product cost price.
 * 
 * Run: C:\xampp\php\php.exe c:\xampp\htdocs\MiniERP\setup\migrate_stock_movements_cost.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Mini ERP — Stock Movement Cost Tracking Migration     ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Check if columns already exist
$check_cost = $conn->query("SHOW COLUMNS FROM `tbl_stock_movements` LIKE 'unit_cost'");
if ($check_cost->num_rows == 0) {
    echo "Adding unit_cost column... ";
    $sql = "ALTER TABLE `tbl_stock_movements` ADD COLUMN `unit_cost` DECIMAL(12,2) DEFAULT NULL AFTER `qty_after`";
    if ($conn->query($sql)) {
        echo "OK\n";
    } else {
        echo "FAILED: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "unit_cost column already exists.\n";
}

$check_val = $conn->query("SHOW COLUMNS FROM `tbl_stock_movements` LIKE 'movement_value'");
if ($check_val->num_rows == 0) {
    echo "Adding movement_value column... ";
    $sql = "ALTER TABLE `tbl_stock_movements` ADD COLUMN `movement_value` DECIMAL(12,2) DEFAULT NULL AFTER `unit_cost`";
    if ($conn->query($sql)) {
        echo "OK\n";
    } else {
        echo "FAILED: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "movement_value column already exists.\n";
}

// Backfill existing rows
echo "Backfilling cost values for existing movements... ";
$sql = "UPDATE `tbl_stock_movements` sm 
        JOIN `tbl_products` p ON sm.product_id = p.product_id 
        SET sm.unit_cost = p.cost_price, 
            sm.movement_value = ABS(sm.quantity) * p.cost_price 
        WHERE sm.unit_cost IS NULL OR sm.movement_value IS NULL";

if ($conn->query($sql)) {
    echo "OK (Rows affected: " . $conn->affected_rows . ")\n";
} else {
    echo "FAILED: " . $conn->error . "\n";
}

echo "\nMigration completed successfully!\n";
$conn->close();
?>
