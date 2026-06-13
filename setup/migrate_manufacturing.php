<?php
/**
 * Manufacturing & BoM — Database Migration
 * Creates 5 new tables:
 *   1. tbl_bom              — Bill of Materials header
 *   2. tbl_bom_lines        — BoM component lines
 *   3. tbl_manufacturing_orders — Manufacturing Order header
 *   4. tbl_mo_components    — MO component snapshots
 *   5. tbl_mo_work_orders   — Work order / operation steps
 *
 * Run: C:\xampp\php\php.exe c:\xampp\htdocs\MiniERP\setup\migrate_manufacturing.php
 * Or:  http://localhost/MiniERP/setup/migrate_manufacturing.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Mini ERP — Manufacturing Module Migration             ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$success = [];

// ─── Table 1: tbl_bom ──────────────────────────────────────────────────────
echo "[1/5] Creating tbl_bom... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_bom` (
    `bom_id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `bom_code`                VARCHAR(50) NOT NULL UNIQUE,
    `bom_name`                VARCHAR(200) NOT NULL,
    `product_id`              INT NOT NULL,
    `quantity`                DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    `standard_time_minutes`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes`                   TEXT DEFAULT NULL,
    `is_active`               TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`              INT DEFAULT NULL,
    `updated_by`              INT DEFAULT NULL,
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_bomhdr_product`    FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bomhdr_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_bomhdr_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tbl_users`(`user_id`) ON DELETE SET NULL,

    INDEX `idx_bom_product` (`product_id`),
    INDEX `idx_bom_active` (`is_active`),
    INDEX `idx_bom_code` (`bom_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_bom';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_bom: ' . $conn->error;
}

// ─── Table 2: tbl_bom_lines ────────────────────────────────────────────────
echo "[2/5] Creating tbl_bom_lines... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_bom_lines` (
    `bom_line_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `bom_id`        INT NOT NULL,
    `product_id`    INT NOT NULL,
    `quantity`      DECIMAL(12,3) NOT NULL,
    `uom`           VARCHAR(20) NOT NULL,
    `notes`         TEXT DEFAULT NULL,
    `sort_order`    INT NOT NULL DEFAULT 0,

    CONSTRAINT `fk_bomln_bom`     FOREIGN KEY (`bom_id`)     REFERENCES `tbl_bom`(`bom_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bomln_product` FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`) ON DELETE RESTRICT,

    INDEX `idx_bomln_bom` (`bom_id`),
    INDEX `idx_bomln_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_bom_lines';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_bom_lines: ' . $conn->error;
}

// ─── Table 3: tbl_manufacturing_orders ─────────────────────────────────────
echo "[3/5] Creating tbl_manufacturing_orders... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_manufacturing_orders` (
    `mo_id`              INT AUTO_INCREMENT PRIMARY KEY,
    `mo_number`          VARCHAR(50) NOT NULL UNIQUE,

    -- Product & BoM
    `product_id`         INT NOT NULL,
    `bom_id`             INT DEFAULT NULL,
    `quantity`           DECIMAL(12,3) NOT NULL,
    `produced_qty`       DECIMAL(12,3) NOT NULL DEFAULT 0.000,

    -- Status
    `status`             ENUM('draft','confirmed','done','cancelled') NOT NULL DEFAULT 'draft',

    -- Assignment & Planning
    `assigned_user_id`   INT DEFAULT NULL,
    `planned_start`      DATE DEFAULT NULL,
    `planned_end`        DATE DEFAULT NULL,
    `actual_start`       DATETIME DEFAULT NULL,
    `actual_end`         DATETIME DEFAULT NULL,

    `notes`              TEXT DEFAULT NULL,

    -- Action tracking
    `created_by`         INT DEFAULT NULL,
    `confirmed_by`       INT DEFAULT NULL,
    `confirmed_at`       DATETIME DEFAULT NULL,
    `cancelled_by`       INT DEFAULT NULL,
    `cancelled_at`       DATETIME DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT `fk_mohdr_product`      FOREIGN KEY (`product_id`)       REFERENCES `tbl_products`(`product_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mohdr_bom`          FOREIGN KEY (`bom_id`)           REFERENCES `tbl_bom`(`bom_id`)          ON DELETE SET NULL,
    CONSTRAINT `fk_mohdr_assigned`     FOREIGN KEY (`assigned_user_id`) REFERENCES `tbl_users`(`user_id`)       ON DELETE SET NULL,
    CONSTRAINT `fk_mohdr_created_by`   FOREIGN KEY (`created_by`)       REFERENCES `tbl_users`(`user_id`)       ON DELETE SET NULL,
    CONSTRAINT `fk_mohdr_confirmed_by` FOREIGN KEY (`confirmed_by`)     REFERENCES `tbl_users`(`user_id`)       ON DELETE SET NULL,
    CONSTRAINT `fk_mohdr_cancelled_by` FOREIGN KEY (`cancelled_by`)     REFERENCES `tbl_users`(`user_id`)       ON DELETE SET NULL,

    -- Indexes
    INDEX `idx_mo_status`   (`status`),
    INDEX `idx_mo_product`  (`product_id`),
    INDEX `idx_mo_bom`      (`bom_id`),
    INDEX `idx_mo_assigned` (`assigned_user_id`),
    INDEX `idx_mo_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_manufacturing_orders';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_manufacturing_orders: ' . $conn->error;
}

// ─── Table 4: tbl_mo_components ────────────────────────────────────────────
echo "[4/5] Creating tbl_mo_components... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_mo_components` (
    `mo_component_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `mo_id`             INT NOT NULL,
    `product_id`        INT NOT NULL,

    -- Product snapshot
    `product_code`      VARCHAR(50) NOT NULL,
    `product_name`      VARCHAR(200) NOT NULL,
    `uom`               VARCHAR(20) NOT NULL,

    -- Quantities
    `required_qty`      DECIMAL(12,3) NOT NULL,
    `consumed_qty`      DECIMAL(12,3) NOT NULL DEFAULT 0.000,

    -- Cost snapshot (set at confirmation)
    `unit_cost`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    CONSTRAINT `fk_mocomp_mo`      FOREIGN KEY (`mo_id`)      REFERENCES `tbl_manufacturing_orders`(`mo_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mocomp_product` FOREIGN KEY (`product_id`) REFERENCES `tbl_products`(`product_id`)         ON DELETE RESTRICT,

    INDEX `idx_mocomp_mo` (`mo_id`),
    INDEX `idx_mocomp_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_mo_components';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_mo_components: ' . $conn->error;
}

// ─── Table 5: tbl_mo_work_orders ───────────────────────────────────────────
echo "[5/5] Creating tbl_mo_work_orders... ";
$sql = "CREATE TABLE IF NOT EXISTS `tbl_mo_work_orders` (
    `wo_id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `mo_id`                       INT NOT NULL,
    `operation_name`              VARCHAR(200) NOT NULL,
    `work_center`                 VARCHAR(100) NOT NULL,
    `sequence`                    INT NOT NULL DEFAULT 0,
    `expected_duration_minutes`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `actual_duration_minutes`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`                      ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `started_at`                  DATETIME DEFAULT NULL,
    `completed_at`                DATETIME DEFAULT NULL,
    `assigned_user_id`            INT DEFAULT NULL,
    `notes`                       TEXT DEFAULT NULL,

    CONSTRAINT `fk_mowo_mo`       FOREIGN KEY (`mo_id`)              REFERENCES `tbl_manufacturing_orders`(`mo_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mowo_assigned` FOREIGN KEY (`assigned_user_id`)   REFERENCES `tbl_users`(`user_id`)              ON DELETE SET NULL,

    INDEX `idx_mowo_mo` (`mo_id`),
    INDEX `idx_mowo_status` (`status`),
    INDEX `idx_mowo_sequence` (`mo_id`, `sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql)) {
    echo "OK\n";
    $success[] = 'tbl_mo_work_orders';
} else {
    echo "FAILED: " . $conn->error . "\n";
    $errors[] = 'tbl_mo_work_orders: ' . $conn->error;
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
    if (!empty($success)) {
        echo "   Tables created: " . implode(', ', $success) . "\n";
    }
}
echo "══════════════════════════════════════════════════════════\n";

$conn->close();
?>
