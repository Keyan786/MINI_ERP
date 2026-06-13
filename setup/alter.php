<?php
require 'c:/xampp/htdocs/MiniERP/db.php';
if ($conn->query("ALTER TABLE tbl_users ADD COLUMN permissions_updated_at DATETIME DEFAULT NULL")) {
    echo "Success";
} else {
    echo "Error: " . $conn->error;
}
?>
