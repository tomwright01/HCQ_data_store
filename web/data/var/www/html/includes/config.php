<?php 
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration
define('IMAGE_BASE_DIR', '/var/www/html/data/');  // Docker container path
define('IMAGE_BASE_URL', '/data/');  // Web-accessible URL path

// Allowed test types and their directory names
define('ALLOWED_TEST_TYPES', [
    'FAF' => 'FAF',
    'OCT' => 'OCT',
    'VF' => 'VF',
    'MFERG' => 'MFERG'
]);

define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', [
    'image/png' => 'png',
    'application/pdf' => 'pdf'
]);

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Get full URL path for an image file
 * @param string|null $filename The image filename
 * @return string|null Full URL or null if not found
 */
function getDynamicImagePath($filename) {
    if (empty($filename)) return null;
    
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $fullPath = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($fullPath)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    
    return null;
}

/**
 * Get the directory path for a test type
 * @param string $testType One of ALLOWED_TEST_TYPES
 * @return string Directory path
 */
function getTestTypeDirectory($testType) {
    return IMAGE_BASE_DIR . (ALLOWED_TEST_TYPES[$testType] ?? '');
}
?>
