<?php
/**
 * Database Connection - Mini ERP System
 * Provides a mysqli connection to the ERP database.
 * Include this file in any script that needs DB access.
 */

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "ERP";

// Create connection with utf8mb4 charset
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for full Unicode support
$conn->set_charset("utf8mb4");

// Enable exception-based error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
?>
