<?php
/**
 * Sales Module — Database Migration
 * Creates 3 new tables: tbl_customers, tbl_sales_orders, tbl_so_lines
 * 
 * Run: C:\xampp\php\php.exe c:\xampp\htdocs\MiniERP\setup\migrate_sales.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Mini ERP — Sales Module Migration                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$success = [];

// ─── Table 1: tbl_customers ───────────────────────────────────────────────
echo "[1/3] Creating tbl_customers... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_customers` (
    `customer_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `customer_name` VARCHAR(150) NOT NULL,
    `email`         VARCHAR(150) DEFAULT NULL,
    `phone`         VARCHAR(20) DEFAULT NULL,
    `address`       TEXT DEFAULT NULL,
    `city`          VARCHAR(100) DEFAULT NULL,
    `state`         VARCHAR(100) DEFAULT NULL,
    `country`       VARCHAR(100) DEFAULT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`    INT DEFAULT NULL,
    `updated_by`    INT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_customer_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_customer_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_customer_name` (`customer_name`),
    INDEX `idx_customer_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_customers';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_customers: ' . $conn->error;
}

// ─── Table 2: tbl_sales_orders ────────────────────────────────────────────
echo "[2/3] Creating tbl_sales_orders... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_sales_orders` (
    `so_id`                     INT AUTO_INCREMENT PRIMARY KEY,
    `so_number`                 VARCHAR(50) NOT NULL UNIQUE,
    `customer_id`               INT DEFAULT NULL,
    `customer_name_snapshot`    VARCHAR(150) DEFAULT NULL,
    `customer_address_snapshot` TEXT DEFAULT NULL,
    `sales_person_id`           INT DEFAULT NULL,
    `status`                    ENUM('draft', 'confirmed', 'partially_delivered', 'fully_delivered', 'cancelled') NOT NULL DEFAULT 'draft',
    `created_by`                INT DEFAULT NULL,
    `updated_by`                INT DEFAULT NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_so_customer` FOREIGN KEY (`customer_id`) REFERENCES `tbl_customers`(`customer_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_so_sales_person` FOREIGN KEY (`sales_person_id`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_so_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_so_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_so_number` (`so_number`),
    INDEX `idx_so_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_sales_orders';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_sales_orders: ' . $conn->error;
}

// ─── Table 3: tbl_so_lines ────────────────────────────────────────────────
echo "[3/3] Creating tbl_so_lines... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_so_lines` (
    `line_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `so_id`         INT NOT NULL,
    `product_id`    INT NOT NULL,
    `ordered_qty`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `delivered_qty` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `uom`           VARCHAR(20) DEFAULT NULL,
    `unit_price`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT `fk_so_line_so` FOREIGN KEY (`so_id`) REFERENCES `tbl_sales_orders`(`so_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_so_line_product` FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_so_lines';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_so_lines: ' . $conn->error;
}

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
