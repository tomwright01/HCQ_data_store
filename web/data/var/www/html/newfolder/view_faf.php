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

// Define paths
$base_dir = '/var/www/html/data/FAF/';  // Absolute server path
$web_path = '/data/FAF/' . $image_ref;  // Web-accessible path
$full_path = $base_dir . $image_ref;

// Verify image exists
if (!file_exists($full_path)) {
    die("Image file not found at: " . $full_path);
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
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .patient-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        .info-item {
            margin-bottom: 5px;
        }
        .info-label {
            font-weight: bold;
            color: #4CAF50;
        }
        .image-container {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .image-frame {
            display: inline-block;
            border: 1px solid #ddd;
            padding: 10px;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
        }
        .image-display {
            max-width: 100%;
            max-height: 600px;
            display: block;
            margin: 0 auto;
        }
        .score-display {
            font-size: 28px;
            font-weight: bold;
            color: white;
            background: #4CAF50;
            padding: 15px 25px;
            border-radius: 8px;
            display: inline-block;
            margin: 20px 0;
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.2);
        }
        .eye-indicator {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin: 15px 0;
            padding: 10px;
            background: #e9f5e9;
            border-radius: 5px;
            display: inline-block;
        }
        .btn-close {
            display: inline-block;
            padding: 12px 25px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .btn-close:hover {
            background: #3d8b40;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .metadata {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
        }
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
            <div class="info-item">
                <span class="info-label">Patient ID:</span>
                <span><?= htmlspecialchars($patient_info['patient_id'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Name:</span>
                <span><?= htmlspecialchars($patient_info['name'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Age:</span>
                <span><?= $patient_age ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Gender:</span>
                <span><?= htmlspecialchars($patient_info['gender'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Location:</span>
                <span><?= htmlspecialchars($patient_info['location'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Disease:</span>
                <span><?= htmlspecialchars($patient_info['disease_name'] ?? 'N/A') ?></span>
            </div>
        </div>
        
        <div class="eye-indicator">
            <?= $eye === 'OD' ? 'Right Eye (OD)' : 'Left Eye (OS)' ?>
        </div>
        
        <div class="image-container">
            <div class="image-frame">
                <img src="<?= htmlspecialchars($web_path) ?>" alt="FAF Image" class="image-display">
                <div class="metadata">
                    Image reference: <?= htmlspecialchars($image_ref) ?>
                </div>
            </div>
        </div>
        
        <center>
            <button class="btn-close" onclick="window.close()">Close Viewer</button>
        </center>
    </div>

    <script>
        // Enhance the viewer with some interactivity
        document.addEventListener('DOMContentLoaded', function() {
            const img = document.querySelector('.image-display');
            
            // Add zoom functionality on click
            img.addEventListener('click', function() {
                this.style.maxWidth = this.style.maxWidth === '100%' ? '150%' : '100%';
            });
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    window.close();
                }
            });
        });
    </script>
</body>
</html>
