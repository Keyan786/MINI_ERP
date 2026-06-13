<?php
require 'c:/xampp/htdocs/MiniERP/db.php';
$tables = $conn->query("SHOW TABLES")->fetch_all();
foreach ($tables as $t) {
    $table = $t[0];
    $cols = $conn->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
    foreach ($cols as $col) {
        $field = $col['Field'];
        if (preg_match('/(user_id|assigned|responsible|person|_by|creator|manager)/i', $field) && $field !== 'user_id' && $table !== 'tbl_users' && $table !== 'tbl_user_sessions' && $table !== 'tbl_password_resets') {
            echo "Table: $table, Column: $field\n";
        }
    }
}
?>
