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
    'MFERG' => 'MFERG'  // Added for MFERG tests
]);

// File size and type restrictions
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // Increased to 50MB for EXP files
define('ALLOWED_IMAGE_TYPES', [
    'image/png' => 'png',
    'application/pdf' => 'pdf',
    'application/octet-stream' => 'exp',  // Added for EXP files
    'text/plain' => 'exp'                // Alternative MIME type for EXP
]);

// Create database connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Get full URL path for an image file with download support
 * @param string|null $filename The image filename
 * @return array|null Associative array with 'url' and 'force_download' flag
 */
function getDynamicImagePath($filename) {
    if (empty($filename)) return null;
    
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $fullPath = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($fullPath)) {
            return [
                'url' => IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename),
                'force_download' => ($extension === 'exp') // Force download for EXP files
            ];
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
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        throw new InvalidArgumentException("Invalid test type: $testType");
    }
    return IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType];
}

/**
 * Verify if a directory exists and is writable
 * @param string $testType Test type from ALLOWED_TEST_TYPES
 * @return bool True if directory is usable
 */
function verifyTestDirectory($testType) {
    $dir = getTestTypeDirectory($testType);
    if (!is_dir($dir)) {
        return mkdir($dir, 0777, true);
    }
    return is_writable($dir);
}

// Verify all required directories exist on startup
foreach (ALLOWED_TEST_TYPES as $type => $dir) {
    if (!verifyTestDirectory($type)) {
        error_log("Warning: Could not verify directory for $type tests");
    }
}
