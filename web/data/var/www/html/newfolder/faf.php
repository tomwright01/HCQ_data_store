<?php
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

// Get parameters
$image_ref = isset($_GET['ref']) ? $_GET['ref'] : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$eye = isset($_GET['eye']) ? $_GET['eye'] : '';

// Validate parameters
if (empty($image_ref) || $patient_id <= 0) {
    die("Invalid parameters");
}

// Query to get patient info
$patient_info = [];
$sql_patient = "SELECT * FROM Patients WHERE patient_id = ?";
$stmt = $conn->prepare($sql_patient);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result_patient = $stmt->get_result();
if ($result_patient->num_rows > 0) {
    $patient_info = $result_patient->fetch_assoc();
}

// Query to get the image data and score
$sql = "SELECT * FROM FAF_Images WHERE faf_reference = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $image_ref);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $image_data = $result->fetch_assoc();
    $image_path = '/data/FAF/' . $image_data['faf_reference'];
    $score = $image_data['faf_score'];
} else {
    die("Image not found");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAF Image Viewer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .patient-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .image-container {
            text-align: center;
            margin: 20px 0;
        }
        .score-display {
            font-size: 24px;
            font-weight: bold;
            color: #4CAF50;
            margin: 20px 0;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        .btn-close {
            display: block;
            width: 100px;
            margin: 20px auto;
            padding: 10px;
            background: #4CAF50;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
        }
        .eye-indicator {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>FAF Image Viewer</h1>
        
        <div class="patient-info">
            <h3>Patient Information</h3>
            <p><strong>ID:</strong> <?= htmlspecialchars($patient_info['patient_id'] ?? 'N/A') ?></p>
            <p><strong>Gender:</strong> <?= htmlspecialchars($patient_info['gender'] ?? 'N/A') ?></p>
            <p><strong>Age:</strong> <?= isset($patient_info['year_of_birth']) ? (date('Y') - $patient_info['year_of_birth']) : 'N/A' ?></p>
            <p><strong>Location:</strong> <?= htmlspecialchars($patient_info['location'] ?? 'N/A') ?></p>
        </div>
        
        <div class="eye-indicator">
            <?= $eye === 'OD' ? 'Right Eye (OD)' : 'Left Eye (OS)' ?>
        </div>
        
        <div class="score-display">
            FAF Score: <?= htmlspecialchars($score) ?>
        </div>
        
        <div class="image-container">
            <img src="<?= htmlspecialchars($image_path) ?>" alt="FAF Image">
        </div>
        
        <a href="#" class="btn-close" onclick="window.close()">Close</a>
    </div>
</body>
</html>
