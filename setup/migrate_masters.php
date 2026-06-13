<?php
/**
 * Migration script to create master tables and register the module
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "Starting migration for Master Management...\n";

// 1. Create Tables
$tables = [
    "CREATE TABLE IF NOT EXISTS tbl_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        assignee_name VARCHAR(150) NOT NULL,
        mobile_number VARCHAR(20) DEFAULT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_assignee_name (assignee_name)
    )",
    "CREATE TABLE IF NOT EXISTS tbl_sales_persons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_person_name VARCHAR(150) NOT NULL,
        mobile_number VARCHAR(20) DEFAULT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sales_person_name (sales_person_name)
    )",
    "CREATE TABLE IF NOT EXISTS tbl_responsible_persons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        responsible_person_name VARCHAR(150) NOT NULL,
        mobile_number VARCHAR(20) DEFAULT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_responsible_person_name (responsible_person_name)
    )"
];

foreach ($tables as $sql) {
    if (!$conn->query($sql)) {
        die("Error creating table: " . $conn->error . "\n");
    }
}
echo "Master tables created.\n";

// 2. Register Module
$stmt = $conn->query("SELECT module_id FROM tbl_modules WHERE module_slug = 'master-management'");
if ($stmt->num_rows == 0) {
    $conn->query("INSERT INTO tbl_modules (module_name, module_slug, module_icon, display_order) VALUES ('Master Management', 'master-management', 'fa-solid fa-database', 90)");
    $newModuleId = $conn->insert_id;
    
    // Give Admin (role 1 users) full permissions
    $conn->query("INSERT IGNORE INTO tbl_user_permissions (user_id, module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report) 
                  SELECT user_id, $newModuleId, 1, 1, 1, 1, 1, 1 FROM tbl_users WHERE role_id = 1");
    echo "Registered Master Management module and granted Admin permissions.\n";
} else {
    echo "Module already registered.\n";
}

echo "Migration Complete.\n";
?>
