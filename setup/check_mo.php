<?php
require 'c:/xampp/htdocs/MiniERP/db.php';
$res = $conn->query("SHOW COLUMNS FROM tbl_manufacturing_orders");
while ($r = $res->fetch_assoc()) {
    echo $r['Field'] . "\n";
}
?>
