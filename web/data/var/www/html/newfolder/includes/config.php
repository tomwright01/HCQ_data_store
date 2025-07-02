<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - USE YOUR EXACT PATH
define('IMAGE_BASE_DIR', 'file:///C:/Users/owenc/downloads/SAMPLE/');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Simple function to get image path
function getLocalImagePath($folder, $filename) {
    return IMAGE_BASE_DIR . $folder . '/' . rawurlencode($filename);
}
?>
