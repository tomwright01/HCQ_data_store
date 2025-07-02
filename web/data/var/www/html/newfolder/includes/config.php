<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - use absolute paths
define('BASE_URL', 'http://localhost:8888'); // Change port if needed (e.g., http://localhost:8888)
define('IMAGE_BASE_PATH', '/SAMPLE/'); // Relative to web root

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to generate image URL
function getImageUrl($folder, $filename) {
    if (empty($filename)) return null;
    return BASE_URL . IMAGE_BASE_PATH . $folder . '/' . rawurlencode($filename);
}
?>
