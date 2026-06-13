<?php
/**
 * Inventory & Product Management — Database Migration
 * Creates 4 new tables: tbl_product_categories, tbl_vendors, tbl_products, tbl_stock_movements
 * Seeds default categories.
 * 
 * Run: C:\xampp\php\php.exe c:\xampp\htdocs\MiniERP\setup\migrate_inventory.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Mini ERP — Inventory Module Migration                 ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$success = [];

// ─── Table 1: tbl_product_categories ────────────────────────────────────────
echo "[1/4] Creating tbl_product_categories... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_product_categories` (
    `category_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(100) NOT NULL UNIQUE,
    `description`   TEXT DEFAULT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`    INT DEFAULT NULL,
    `updated_by`    INT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_category_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_category_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_product_categories';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_product_categories: ' . $conn->error;
}

// ─── Table 2: tbl_vendors ──────────────────────────────────────────────────
echo "[2/4] Creating tbl_vendors... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_vendors` (
    `vendor_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_name`     VARCHAR(150) NOT NULL,
    `contact_person`  VARCHAR(100) DEFAULT NULL,
    `email`           VARCHAR(150) DEFAULT NULL,
    `phone`           VARCHAR(20) DEFAULT NULL,
    `address`         TEXT DEFAULT NULL,
    `city`            VARCHAR(100) DEFAULT NULL,
    `state`           VARCHAR(100) DEFAULT NULL,
    `country`         VARCHAR(100) DEFAULT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`      INT DEFAULT NULL,
    `updated_by`      INT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_vendor_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_vendor_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_vendor_name` (`vendor_name`),
    INDEX `idx_vendor_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_vendors';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_vendors: ' . $conn->error;
}

// ─── Table 3: tbl_products ─────────────────────────────────────────────────
echo "[3/4] Creating tbl_products... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_products` (
    `product_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `product_code`      VARCHAR(50) NOT NULL UNIQUE,
    `product_name`      VARCHAR(200) NOT NULL,
    `description`       TEXT DEFAULT NULL,
    `category_id`       INT DEFAULT NULL,
    `uom`               VARCHAR(20) NOT NULL DEFAULT 'Pcs',
    `sales_price`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `cost_price`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `on_hand_qty`       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `reserved_qty`      DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `min_stock_level`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `procure_on_demand` TINYINT(1) NOT NULL DEFAULT 0,
    `procurement_type`  ENUM('purchase','manufacturing') DEFAULT NULL,
    `default_vendor_id` INT DEFAULT NULL,
    `default_bom_id`    INT DEFAULT NULL,
    `image_path`        VARCHAR(255) DEFAULT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`        INT DEFAULT NULL,
    `updated_by`        INT DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_product_category`   FOREIGN KEY (`category_id`) REFERENCES `tbl_product_categories`(`category_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_product_vendor`     FOREIGN KEY (`default_vendor_id`) REFERENCES `tbl_vendors`(`vendor_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_product_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_product_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_product_code` (`product_code`),
    INDEX `idx_product_name` (`product_name`),
    INDEX `idx_product_category` (`category_id`),
    INDEX `idx_product_active` (`is_active`),
    INDEX `idx_product_procure` (`procure_on_demand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_products';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_products: ' . $conn->error;
}

// ─── Table 4: tbl_stock_movements ──────────────────────────────────────────
echo "[4/4] Creating tbl_stock_movements... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_stock_movements` (
    `movement_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `product_id`     INT NOT NULL,
    `movement_type`  ENUM('initial','purchase_in','manufacturing_in','sales_out','manufacturing_consume','adjustment') NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id`   INT DEFAULT NULL,
    `quantity`        DECIMAL(12,3) NOT NULL,
    `qty_before`     DECIMAL(12,3) NOT NULL,
    `qty_after`      DECIMAL(12,3) NOT NULL,
    `notes`          TEXT DEFAULT NULL,
    `created_by`     INT DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_movement_product`    FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_movement_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_movement_product` (`product_id`),
    INDEX `idx_movement_type` (`movement_type`),
    INDEX `idx_movement_ref` (`reference_type`),
    INDEX `idx_movement_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_stock_movements';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_stock_movements: ' . $conn->error;
}

// ─── Seed Product Categories ────────────────────────────────────────────────
echo "\nSeeding product categories... ";
$categories = [
    ['Raw Materials', 'Basic materials used in manufacturing processes'],
    ['Finished Goods', 'Completed products ready for sale and distribution'],
    ['Semi-Finished', 'Partially processed items awaiting further manufacturing'],
    ['Consumables', 'Items consumed during daily operations'],
    ['Packaging', 'Packaging materials and shipping containers'],
];

$inserted = 0;
$stmt = $conn->prepare("INSERT IGNORE INTO `tbl_product_categories` (`category_name`, `description`, `created_by`) VALUES (?, ?, 1)");
foreach ($categories as $cat) {
    $stmt->bind_param("ss", $cat[0], $cat[1]);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $inserted++;
}
$stmt->close();
echo "$inserted categories seeded.\n";

// ─── Summary ───────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════════════\n";
if (empty($errors)) {
    echo "✅ Migration completed successfully!\n";
    echo "   Tables created: " . implode(', ', $success) . "\n";
} else {
    echo "⚠ Migration completed with errors:\n";
    foreach ($errors as $err) {
        echo "   ✗ $err\n";
    }
}
echo "══════════════════════════════════════════════════════════\n";

$conn->close();
?>
