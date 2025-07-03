<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration
define('IMAGE_BASE_DIR', '/var/www/html/data/'); // Docker container path
define('IMAGE_BASE_URL', '/data/'); // Web-accessible URL path

// Test type folders
define('FAF_FOLDER', 'FAF/');
define('OCT_FOLDER', 'OCT/');
define('VF_FOLDER', 'VF/');
define('MFERG_FOLDER', 'MFERG/');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get image path (both local and web accessible)
function getImagePath($testType, $filename) {
    if (empty($filename)) return false;
    
    // Map test types to folders
    $folders = [
        'FAF' => FAF_FOLDER,
        'OCT' => OCT_FOLDER,
        'VF' => VF_FOLDER,
        'MFERG' => MFERG_FOLDER
    ];
    
    if (!isset($folders[$testType])) {
        return false;
    }
    
    $localPath = IMAGE_BASE_DIR . $folders[$testType] . $filename;
    $webPath = IMAGE_BASE_URL . $folders[$testType] . rawurlencode($filename);
    
    return $webPath;
}
?>
