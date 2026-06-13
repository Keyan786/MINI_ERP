<?php
/**
 * Database Migration - User Auth Update
 * Adds new columns and modifies status for the updated User Management workflow.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure this is run from CLI or by admin
if (php_sapi_name() !== 'cli') {
    session_start();
    if (!isset($_SESSION['logged_in']) || $_SESSION['role_id'] != 1) {
        die("Unauthorized access.");
    }
}

echo "Starting migration for User Auth Update...\n\n";

$queries = [
    "ALTER TABLE `tbl_users` ADD COLUMN `password_setup_token` VARCHAR(100) NULL UNIQUE AFTER `password_hash`",
    "ALTER TABLE `tbl_users` ADD COLUMN `token_expiry` DATETIME NULL AFTER `password_setup_token`",
    "ALTER TABLE `tbl_users` ADD COLUMN `is_password_set` TINYINT(1) NOT NULL DEFAULT 0 AFTER `token_expiry`",
    "ALTER TABLE `tbl_users` ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_password_set`",
    "ALTER TABLE `tbl_users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `force_password_change`",
    "ALTER TABLE `tbl_users` ADD COLUMN `department` VARCHAR(100) NULL AFTER `role_id`",
    "ALTER TABLE `tbl_users` MODIFY COLUMN `status` ENUM('pending_setup', 'active', 'inactive', 'locked') NOT NULL DEFAULT 'pending_setup'"
];

foreach ($queries as $sql) {
    try {
        if ($conn->query($sql)) {
            echo "[SUCCESS] " . substr($sql, 0, 70) . "...\n";
        } else {
            // Ignore Duplicate column name error (1060)
            if ($conn->errno == 1060) {
                echo "[SKIPPED] Column already exists for: " . substr($sql, 0, 70) . "...\n";
            } else {
                echo "[ERROR] " . $conn->error . " for query: " . $sql . "\n";
            }
        }
    } catch (Exception $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
}

// Update existing users to active so we don't break existing logins
$conn->query("UPDATE `tbl_users` SET `status` = 'active', `is_password_set` = 1 WHERE `status` NOT IN ('pending_setup', 'active', 'inactive', 'locked')");
$conn->query("UPDATE `tbl_users` SET `status` = 'active', `is_password_set` = 1 WHERE `password_hash` IS NOT NULL AND `password_hash` != '' AND `is_password_set` = 0");

echo "\nMigration completed.\n";
