<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get parameters from URL
$image_ref = isset($_GET['ref']) ? $_GET['ref'] : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$eye = isset($_GET['eye']) ? $_GET['eye'] : '';

// Validate parameters
if (empty($image_ref) || $patient_id <= 0 || !in_array($eye, ['OD', 'OS'])) {
    die("Invalid parameters - Image reference, patient ID, and eye side (OD/OS) are required");
}

// Get patient information
$sql_patient = "SELECT * FROM Patients WHERE patient_id = ?";
$stmt = $conn->prepare($sql_patient);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result_patient = $stmt->get_result();

if ($result_patient->num_rows === 0) {
    die("Patient not found");
}
$patient_info = $result_patient->fetch_assoc();

// Get image data and score
$sql_image = "SELECT * FROM FAF_Images WHERE faf_reference = ?";
$stmt = $conn->prepare($sql_image);
$stmt->bind_param("s", $image_ref);
$stmt->execute();
$result_image = $stmt->get_result();

if ($result_image->num_rows === 0) {
    die("FAF image record not found in database");
}
$image_data = $result_image->fetch_assoc();

// Define paths - Adjusted for your specific directory structure
$base_dir = '/var/www/html/data/FAF/';  // Absolute server path
$web_path = '/data/FAF/' . rawurlencode($image_ref);  // Web-accessible path with proper encoding
$full_path = $base_dir . $image_ref;

// Debug output (remove in production)
error_log("Trying to access image at: " . $full_path);

// Verify image exists with multiple fallbacks
if (!file_exists($full_path)) {
    // Try alternative path if first attempt fails
    $alt_path = '/var/www/html/data/FAF/' . rawurldecode($image_ref);
    if (file_exists($alt_path)) {
        $full_path = $alt_path;
        $web_path = '/data/FAF/' . rawurlencode(rawurldecode($image_ref));
    } else {
        // Try case-insensitive match (for Linux servers)
        $files = scandir($base_dir);
        $found = false;
        foreach ($files as $file) {
            if (strcasecmp($file, $image_ref) === 0) {
                $full_path = $base_dir . $file;
                $web_path = '/data/FAF/' . rawurlencode($file);
                $found = true;
                break;
            }
        }
        if (!$found) {
            die("Image file not found at: " . $full_path . 
                "<br>Tried alternatives but couldn't locate the file.<br>" .
                "Please verify the file exists in /var/www/html/data/FAF/");
        }
    }
}

// Verify the file is actually an image
$valid_extensions = ['png', 'jpg', 'jpeg', 'gif'];
$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
if (!in_array($ext, $valid_extensions)) {
    die("Invalid image file type: " . $ext);
}

// Calculate patient age
$current_year = date('Y');
$patient_age = isset($patient_info['year_of_birth']) ? $current_year - $patient_info['year_of_birth'] : 'N/A';

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAF Image Viewer - Patient <?= $patient_id ?></title>
    <style>
        /* [Previous CSS remains exactly the same] */
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Fundus Autofluorescence (FAF) Image</h1>
            <div class="score-display">
                FAF Score: <?= htmlspecialchars($image_data['faf_score'] ?? 'N/A') ?>
            </div>
        </div>
        
        <div class="patient-info">
            <!-- [Patient info display remains the same] -->
        </div>
        
        <div class="eye-indicator">
            <?= $eye === 'OD' ? 'Right Eye (OD)' : 'Left Eye (OS)' ?>
        </div>
        
        <div class="image-container">
            <div class="image-frame">
                <img src="<?= htmlspecialchars($web_path) ?>" 
                     alt="FAF Image for Patient <?= htmlspecialchars($patient_id) ?>" 
                     class="image-display"
                     onerror="this.onerror=null;this.src='/images/image-not-found.png';">
                <div class="metadata">
                    Image reference: <?= htmlspecialchars($image_ref) ?>
                    <br>File path: <?= htmlspecialchars($full_path) ?>
                </div>
            </div>
        </div>
        
        <center>
            <button class="btn-close" onclick="window.close()">Close Viewer</button>
        </center>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const img = document.querySelector('.image-display');
            
            // Verify image loaded successfully
            img.addEventListener('error', function() {
                console.error('Failed to load image:', this.src);
                alert('Failed to load the FAF image. Please check the file exists on the server.');
            });
            
            // [Rest of the JavaScript remains the same]
        });
    </script>
</body>
</html>
