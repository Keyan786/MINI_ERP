<?php
/**
 * Purchase Management — Database Migration
 * Creates 2 new tables: tbl_purchase_orders, tbl_purchase_order_lines
 * 
 * Run: C:\xampp\php\php.exe c:\xampp\htdocs\MiniERP\setup\migrate_purchase.php
 * Or:  http://localhost/MiniERP/setup/migrate_purchase.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Mini ERP — Purchase Module Migration                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$success = [];

// ─── Table 1: tbl_purchase_orders ───────────────────────────────────────────
echo "[1/2] Creating tbl_purchase_orders... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_purchase_orders` (
    `po_id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `po_number`             VARCHAR(50) NOT NULL UNIQUE,

    -- Vendor (live FK + snapshot)
    `vendor_id`             INT NOT NULL,
    `vendor_name`           VARCHAR(150) NOT NULL,
    `vendor_contact_person` VARCHAR(100) DEFAULT NULL,
    `vendor_email`          VARCHAR(150) DEFAULT NULL,
    `vendor_phone`          VARCHAR(20) DEFAULT NULL,
    `vendor_address`        TEXT DEFAULT NULL,

    -- PO Details
    `responsible_user_id`   INT DEFAULT NULL,
    `status`                ENUM('draft','confirmed','partially_received','fully_received','cancelled')
                              NOT NULL DEFAULT 'draft',
    `notes`                 TEXT DEFAULT NULL,

    -- Dual totals
    `ordered_total`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `received_total`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,

    -- Action tracking
    `created_by`            INT DEFAULT NULL,
    `confirmed_by`          INT DEFAULT NULL,
    `confirmed_at`          DATETIME DEFAULT NULL,
    `received_by`           INT DEFAULT NULL,
    `received_at`           DATETIME DEFAULT NULL,
    `cancelled_by`          INT DEFAULT NULL,
    `cancelled_at`          DATETIME DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT `fk_po_vendor`       FOREIGN KEY (`vendor_id`)           REFERENCES `tbl_vendors`(`vendor_id`)  ON DELETE RESTRICT,
    CONSTRAINT `fk_po_responsible`  FOREIGN KEY (`responsible_user_id`) REFERENCES `tbl_users`(`user_id`)      ON DELETE SET NULL,
    CONSTRAINT `fk_po_created_by`   FOREIGN KEY (`created_by`)          REFERENCES `tbl_users`(`user_id`)      ON DELETE SET NULL,
    CONSTRAINT `fk_po_confirmed_by` FOREIGN KEY (`confirmed_by`)        REFERENCES `tbl_users`(`user_id`)      ON DELETE SET NULL,
    CONSTRAINT `fk_po_received_by`  FOREIGN KEY (`received_by`)         REFERENCES `tbl_users`(`user_id`)      ON DELETE SET NULL,
    CONSTRAINT `fk_po_cancelled_by` FOREIGN KEY (`cancelled_by`)        REFERENCES `tbl_users`(`user_id`)      ON DELETE SET NULL,

    -- Indexes
    INDEX `idx_po_status`  (`status`),
    INDEX `idx_po_vendor`  (`vendor_id`),
    INDEX `idx_po_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_purchase_orders';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_purchase_orders: ' . $conn->error;
}

// ─── Table 2: tbl_purchase_order_lines ──────────────────────────────────────
echo "[2/2] Creating tbl_purchase_order_lines... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_purchase_order_lines` (
    `line_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `po_id`          INT NOT NULL,
    `product_id`     INT NOT NULL,

    -- Product snapshot
    `product_code`   VARCHAR(50) NOT NULL,
    `product_name`   VARCHAR(200) NOT NULL,
    `uom`            VARCHAR(20) NOT NULL,

    -- Quantities & pricing
    `ordered_qty`    DECIMAL(12,3) NOT NULL,
    `cost_price`     DECIMAL(12,2) NOT NULL,
    `line_total`     DECIMAL(14,2) NOT NULL,
    `received_qty`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,

    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_pol_po`      FOREIGN KEY (`po_id`)      REFERENCES `tbl_purchase_orders`(`po_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pol_product` FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`)   ON DELETE RESTRICT,
    INDEX `idx_pol_po`      (`po_id`),
    INDEX `idx_pol_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_purchase_order_lines';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_purchase_order_lines: ' . $conn->error;
}

// ─── Summary ────────────────────────────────────────────────────────────────
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
