<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - use absolute server paths
define('IMAGE_BASE_DIR', '/var/www/html/data/');  // Docker container path
define('IMAGE_BASE_URL', '/data/');               // Web-accessible URL path

// Allowed test types and their storage directories
define('ALLOWED_TEST_TYPES', [
    'FAF'   => 'FAF',
    'OCT'   => 'OCT',
    'VF'    => 'VF',
    'MFERG' => 'MFERG'
]);

// Allowed MIME types and their file extensions
define('ALLOWED_IMAGE_TYPES', [
    'image/png'       => 'png',
    'image/jpeg'      => 'jpg',
    'application/pdf' => 'pdf'
]);

// Max file size for uploads (10MB)
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

// Create database connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Get full URL path for an image file with validation
 * @param string|null $filename The image filename (from database)
 * @return string|null Full URL if image exists, null otherwise
 */
function getDynamicImagePath($filename) {
    if (empty($filename)) {
        return null;
    }

    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $full_path = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($full_path)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    
    return null; // Image not found in any directory
}

/**
 * Get the absolute filesystem path for a test type directory
 * @param string $testType One of: FAF, OCT, VF, MFERG
 * @return string Directory path
 * @throws InvalidArgumentException for invalid test types
 */
function getTestTypeDirectory($testType) {
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        throw new InvalidArgumentException("Invalid test type: $testType");
    }
    return IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType];
}

/**
 * Verify if a file is an allowed image type
 * @param string $tmpFilePath Temporary upload file path
 * @return bool
 */
function isAllowedImageType($tmpFilePath) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpFilePath);
    finfo_close($finfo);
    
    return array_key_exists($mimeType, ALLOWED_IMAGE_TYPES);
}

/**
 * Get patient by ID with basic sanitization
 * @param string $patient_id
 * @return array|null Patient data or null if not found
 */
function getPatientById($patient_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
?>
