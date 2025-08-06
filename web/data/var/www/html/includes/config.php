<?php 
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration
define('IMAGE_BASE_DIR', '/var/www/html/data/');
define('IMAGE_BASE_URL', '/data/');

// Allowed test types
define('ALLOWED_TEST_TYPES', ['FAF', 'OCT', 'VF', 'MFERG']);

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get image path
function getDynamicImagePath($filename) {
    if (empty($filename)) return null;
    
    foreach (ALLOWED_TEST_TYPES as $type) {
        $full_path = IMAGE_BASE_DIR . $type . '/' . $filename;
        if (file_exists($full_path)) {
            return IMAGE_BASE_URL . $type . '/' . rawurlencode($filename);
        }
    }
    
    return null;
}
