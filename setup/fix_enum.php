<?php
require_once __DIR__ . '/../db.php';
$conn->query("UPDATE tbl_users SET status = 'active' WHERE status NOT IN ('pending_setup', 'active', 'inactive', 'locked')");
if (!$conn->query("ALTER TABLE tbl_users MODIFY COLUMN status ENUM('pending_setup', 'active', 'inactive', 'locked') NOT NULL DEFAULT 'pending_setup'")) {
    echo $conn->error;
} else {
    echo "Status updated successfully.";
}
$conn->close();
