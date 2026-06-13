<?php
require 'c:/xampp/htdocs/MiniERP/db.php';
// Add User Rights module
$stmt = $conn->query("SELECT module_id FROM tbl_modules WHERE module_slug = 'user-rights'");
if ($stmt->num_rows == 0) {
    $conn->query("INSERT INTO tbl_modules (module_name, module_slug, module_icon, display_order) VALUES ('User Rights', 'user-rights', 'fa-solid fa-shield', 100)");
    $newModuleId = $conn->insert_id;
    // Give Admin view permission so they can see it in sidebar
    $conn->query("INSERT INTO tbl_user_permissions (user_id, module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report) 
                  SELECT user_id, $newModuleId, 1, 1, 1, 1, 1, 1 FROM tbl_users WHERE role_id = 1");
    echo "Added User Rights module to DB.";
} else {
    echo "Module already exists.";
}
?>
