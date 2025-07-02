<?php
// Database configuration
define('DB_HOST', 'mariadb');
define('DB_USER', 'root');
define('DB_PASS', 'notgood');
define('DB_NAME', 'PatientData');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
