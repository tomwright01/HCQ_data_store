<?php
// Database configuration
define('DB_SERVER', 'mariadb');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'notgood');
define('DB_NAME', 'PatientData');

// Image configuration - use absolute paths
define('BASE_URL', 'http://localhost:8888');
define('FAF_PATH', '/SAMPLE/FAF/');
define('OPTOS_PATH', '/SAMPLE/optos/');
define('OCT_PATH', '/SAMPLE/OCT/');
define('VF_PATH', '/SAMPLE/VF/');
define('MFERG_PATH', '/SAMPLE/MFERG/');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to generate full image URL
function getImageUrl($type, $filename) {
    if (empty($filename)) return null;
    
    $pathMap = [
        'faf' => FAF_PATH,
        'optos' => OPTOS_PATH,
        'oct' => OCT_PATH,
        'vf' => VF_PATH,
        'mferg' => MFERG_PATH
    ];
    
    if (!array_key_exists($type, $pathMap)) return null;
    
    return BASE_URL . $pathMap[$type] . $filename;
}
?>
