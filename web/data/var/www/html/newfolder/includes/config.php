<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - use relative paths from your web root
define('IMAGE_BASE_PATH', '/SAMPLE/'); // Points to the SAMPLE folder
define('FAF_FOLDER', 'FAF/');
define('OPTOS_FOLDER', 'optos/');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
