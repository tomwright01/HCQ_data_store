<?php 
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - using your SAMPLE folder structure
define('IMAGE_BASE_DIR', '/var/www/html/data/'); // Docker container path
define('IMAGE_BASE_URL', '/data/'); // Web-accessible URL path

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get image path (dynamically checks file system)
function getDynamicImagePath($filename) {
    if (empty($filename)) return false;
    
    $testTypes = ['FAF', 'OCT', 'VF', 'MFERG'];
    
    foreach ($testTypes as $type) {
        $fullPath = IMAGE_BASE_DIR . $type . '/' . $filename;
        if (file_exists($fullPath)) {
            return IMAGE_BASE_URL . $type . '/' . rawurlencode($filename);
        }
    }
    
    return false;
}
?>

