<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get parameters from URL
$ref = $_GET['ref'] ?? '';
$patient_id = $_GET['patient_id'] ?? '';
$eye = $_GET['eye'] ?? '';
$test_type = $_GET['type'] ?? 'FAF'; // Default to FAF if not specified

// Validate parameters
if (empty($ref) || empty($patient_id) || !in_array($eye, ['OD', 'OS'])) {
    die("Invalid parameters. Please provide valid reference, patient ID, and eye (OD/OS).");
}

// Get patient data
$patient = getPatientById($patient_id);
if (!$patient) {
    die("Patient not found.");
}

// Get test data containing this image reference
$fieldName = strtolower($test_type) . '_reference_' . strtolower($eye);
$sql = "SELECT * FROM tests WHERE patient_id = ? AND $fieldName = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $patient_id, $ref);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if (!$test) {
    die("Test data not found for this image reference.");
}

// Get image path
$image_path = getDynamicImagePath($ref);
if (!$image_path) {
    die("Image not found in the system.");
}

// Calculate patient age
$age = !empty($patient['date_of_birth']) ? 
    date_diff(date_create($patient['date_of_birth']), date_create('today'))->y : 'N/A';

// Get MERCI score
$merci_score = $test['merci_score'] ?? 'N/A';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $test_type ?> Viewer - Patient <?= $patient_id ?></title>
    <style>
        /* [Keep all your existing CSS styles from view_faf.php] */
        /* ... */
    </style>
</head>
<body>
    <div class="container">
        <!-- Image Section with Controls -->
        <div class="image-section" id="image-section">
            <!-- [Keep your existing image controls] -->
            <div class="image-wrapper">
                <div class="image-container" id="image-container">
                    <img src="<?= htmlspecialchars($image_path) ?>" alt="<?= $test_type ?> Image" id="faf-image">
                </div>
            </div>
            
            <div class="eye-indicator">
                <?= $eye ?> (<?= $eye == 'OD' ? 'Right Eye' : 'Left Eye' ?>)
            </div>
        </div>
        
        <!-- Information Section -->
        <div class="info-section">
            <!-- [Keep your existing patient info section] -->
            
            <a href="index.php?search_patient_id=<?= $patient_id ?>" class="back-button">
                ← Back to Patient Record
            </a>
        </div>
    </div>

    <!-- [Keep your existing JavaScript] -->
</body>
</html>
