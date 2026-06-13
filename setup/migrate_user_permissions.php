<?php
/**
 * Migration script to create tbl_user_permissions and seed data
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

echo "Starting migration for Dynamic User Rights Management...\n";

// 1. Create table
$sql = "CREATE TABLE IF NOT EXISTS tbl_user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module_id INT NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    can_approve TINYINT(1) DEFAULT 0,
    can_report TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_module (user_id, module_id),
    FOREIGN KEY (user_id) REFERENCES tbl_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES tbl_modules(module_id) ON DELETE CASCADE
)";

if ($conn->query($sql)) {
    echo "tbl_user_permissions table created or already exists.\n";
} else {
    die("Error creating table: " . $conn->error . "\n");
}

// 2. Fetch all users and seed based on their current role_id
$users = $conn->query("SELECT user_id, role_id FROM tbl_users");
$insertStmt = $conn->prepare("INSERT IGNORE INTO tbl_user_permissions 
    (user_id, module_id, can_view, can_create, can_edit, can_delete, can_approve, can_report)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

$seededCount = 0;
while ($u = $users->fetch_assoc()) {
    $userId = $u['user_id'];
    $roleId = $u['role_id'];

    if ($roleId) {
        $rolePerms = $conn->query("SELECT module_id, can_view, can_create, can_edit, can_delete FROM tbl_role_permissions WHERE role_id = $roleId");
        while ($rp = $rolePerms->fetch_assoc()) {
            $mId = $rp['module_id'];
            $cV = $rp['can_view'];
            $cC = $rp['can_create'];
            $cE = $rp['can_edit'];
            $cD = $rp['can_delete'];
            // Admin role = role_id 1
            $cA = ($roleId == 1) ? 1 : 0; // default approve
            $cR = ($roleId == 1) ? 1 : 0; // default report
            
            $insertStmt->bind_param("iiiiiiii", $userId, $mId, $cV, $cC, $cE, $cD, $cA, $cR);
            $insertStmt->execute();
            if ($insertStmt->affected_rows > 0) {
                $seededCount++;
            }
        }
    }
}

echo "Successfully seeded $seededCount permission records for existing users.\n";
echo "Migration Complete.\n";
?>
