<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get parameters from URL
$ref = isset($_GET['ref']) ? $_GET['ref'] : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$eye = isset($_GET['eye']) ? $_GET['eye'] : '';

// Validate parameters
if (empty($ref) || empty($patient_id) || !in_array($eye, ['OD', 'OS'])) {
    die("Invalid parameters. Please provide valid reference, patient ID, and eye (OD/OS).");
}

// Get patient data
$patient = getPatientById($patient_id);
if (!$patient) {
    die("Patient not found.");
}

// Get visit data containing this FAF reference
$stmt = $conn->prepare("SELECT * FROM Visits 
                       WHERE patient_id = ? 
                       AND (faf_reference_OD = ? OR faf_reference_OS = ?)");
$stmt->bind_param("iss", $patient_id, $ref, $ref);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();

if (!$visit) {
    die("Visit data not found for this FAF image.");
}

// Get image path
$image_path = getDynamicImagePath($ref);
if (!$image_path) {
    die("FAF image not found in the system.");
}

// Calculate patient age
$current_year = date('Y');
$age = $current_year - $patient['year_of_birth'];

// Get MERCI score for the appropriate eye
$merci_score = ($eye == 'OD') ? $visit['merci_rating_right_eye'] : $visit['merci_rating_left_eye'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAF Viewer - Patient <?= $patient_id ?></title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .container {
            display: flex;
            height: 100vh;
        }
        
        .image-section {
            flex: 1;
            padding: 20px;
            background-color: #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .image-container {
            max-width: 100%;
            max-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }
        
        .info-section {
            flex: 1;
            padding: 30px;
            background-color: white;
            overflow-y: auto;
        }
        
        .patient-header {
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .patient-header h1 {
            color: #4CAF50;
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .patient-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }
        
        .meta-item {
            background-color: #f0f0f0;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .meta-item i {
            margin-right: 8px;
            color: #4CAF50;
        }
        
        .score-card {
            background-color: #f9f9f9;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .score-value {
            font-size: 72px;
            font-weight: bold;
            color: #4CAF50;
            text-align: center;
            margin: 20px 0;
            line-height: 1;
        }
        
        .score-label {
            text-align: center;
            font-size: 18px;
            color: #666;
            margin-bottom: 20px;
        }
        
        .progress-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin: 20px 0;
            height: 25px;
        }
        
        .progress-bar {
            height: 100%;
            background-color: #4CAF50;
            border-radius: 5px;
            width: <?= $merci_score ? ($merci_score / 5 * 100) : 0 ?>%;
            transition: width 0.5s ease;
            position: relative;
        }
        
        .progress-marker {
            position: absolute;
            right: -8px;
            top: -5px;
            background-color: #333;
            color: white;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .grading-card {
            background-color: #f9f9f9;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .grading-card h2 {
            color: #4CAF50;
            margin-top: 0;
            margin-bottom: 20px;
        }
        
        .grading-system {
            border-left: 3px solid #e0e0e0;
            padding-left: 15px;
            margin: 15px 0;
        }
        
        .stage {
            padding: 12px 0;
            border-bottom: 1px dashed #e0e0e0;
            display: flex;
            align-items: flex-start;
        }
        
        .stage:last-child {
            border-bottom: none;
        }
        
        .stage.active {
            background-color: #f0f9f0;
            margin: 0 -25px;
            padding: 12px 25px;
            border-left: 4px solid #4CAF50;
        }
        
        .stage-checkbox {
            margin-right: 15px;
            margin-top: 3px;
            accent-color: #4CAF50;
            transform: scale(1.3);
            pointer-events: none;
        }
        
        .stage-content {
            flex: 1;
        }
        
        .stage-number {
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 5px;
        }
        
        .stage-description {
            color: #555;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .grading-note {
            font-size: 13px;
            color: #666;
            font-style: italic;
            margin-top: 15px;
        }
        
        .visit-details {
            margin-top: 30px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .detail-label {
            font-weight: bold;
            width: 180px;
            color: #666;
            flex-shrink: 0;
        }
        
        .detail-value {
            flex: 1;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            padding: 12px 25px;
            margin-top: 25px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
            font-size: 16px;
        }
        
        .back-button:hover {
            background-color: #3d8b40;
        }
        
        .eye-indicator {
            display: inline-flex;
            align-items: center;
            padding: 5px 15px;
            background-color: #4CAF50;
            color: white;
            border-radius: 20px;
            font-size: 14px;
            margin-left: 15px;
            text-transform: uppercase;
        }
        
        .severity-scale {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            font-size: 12px;
            color: #666;
        }
        
        .scale-item {
            text-align: center;
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                height: auto;
            }
            
            .image-section {
                height: 50vh;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Image Section -->
        <div class="image-section">
            <div class="image-container">
                <img src="<?= htmlspecialchars($image_path) ?>" alt="FAF Image">
            </div>
            <div class="eye-indicator">
                <?= $eye ?> (<?= $eye == 'OD' ? 'Right Eye' : 'Left Eye' ?>)
            </div>
        </div>
        
        <!-- Information Section -->
        <div class="info-section">
            <div class="patient-header">
                <h1>
                    Patient <?= htmlspecialchars($patient_id) ?>
                    <?php if (!empty($patient['disease_name'])): ?>
                        <span style="font-size: 18px; margin-left: 15px; color: #666;">
                            (<?= htmlspecialchars($patient['disease_name']) ?>)
                        </span>
                    <?php endif; ?>
                </h1>
                <div class="patient-meta">
                    <div class="meta-item">
                        <i>👤</i> <?= $age ?> years
                    </div>
                    <div class="meta-item">
                        <i>⚥</i> <?= $patient['gender'] == 'm' ? 'Male' : 'Female' ?>
                    </div>
                    <div class="meta-item">
                        <i>📍</i> <?= htmlspecialchars($patient['location']) ?>
                    </div>
                    <?php if (!empty($patient['referring_doctor'])): ?>
                        <div class="meta-item">
                            <i>👨‍⚕️</i> Dr. <?= htmlspecialchars($patient['referring_doctor']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="score-card">
                <h2>MERCI Grading Score</h2>
                <div class="score-value"><?= $merci_score ? htmlspecialchars($merci_score) : 'N/A' ?></div>
                <div class="score-label">out of 5</div>
                
                <div class="progress-container">
                    <div class="progress-bar">
                        <?php if ($merci_score): ?>
                            <div class="progress-marker"><?= $merci_score ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="severity-scale">
                    <div class="scale-item">1 - Minimal</div>
                    <div class="scale-item">2 - Mild</div>
                    <div class="scale-item">3 - Moderate</div>
                    <div class="scale-item">4 - Significant</div>
                    <div class="scale-item">5 - Severe</div>
                </div>
            </div>
            
            <div class="grading-card">
                <h2>FAF Staging System</h2>
                
                <div class="grading-system">
                    <div class="stage <?= ($merci_score == 0) ? 'active' : '' ?>">
                        <input type="checkbox" class="stage-checkbox" <?= ($merci_score == 0) ? 'checked' : '' ?>>
                        <div class="stage-content">
                            <div class="stage-number">Stage 0</div>
                            <div class="stage-description">No hyperautofluorescence (normal)</div>
                        </div>
                    </div>
                    
                    <div class="stage <?= ($merci_score == 1) ? 'active' : '' ?>">
                        <input type="checkbox" class="stage-checkbox" <?= ($merci_score == 1) ? 'checked' : '' ?>>
                        <div class="stage-content">
                            <div class="stage-number">Stage 1</div>
                            <div class="stage-description">Localized parafoveal or pericentral hyperautofluorescence</div>
                        </div>
                    </div>
                    
                    <div class="stage <?= ($merci_score == 2) ? 'active' : '' ?>">
                        <input type="checkbox" class="stage-checkbox" <?= ($merci_score == 2) ? 'checked' : '' ?>>
                        <div class="stage-content">
                            <div class="stage-number">Stage 2</div>
                            <div class="stage-description">Hyperautofluorescence extending >180° around fovea</div>
                        </div>
                    </div>
                    
                    <div class="stage <?= ($merci_score == 3) ? 'active' : '' ?>">
                        <input type="checkbox" class="stage-checkbox" <?= ($merci_score == 3) ? 'checked' : '' ?>>
                        <div class="stage-content">
                            <div class="stage-number">Stage 3</div>
                            <div class="stage-description">Combined RPE defects (hypoautofluorescence) without foveal involvement</div>
                        </div>
                    </div>
                    
                    <div class="stage <?= ($merci_score == 4) ? 'active' : '' ?>">
                        <input type="checkbox" class="stage-checkbox" <?= ($merci_score == 4) ? 'checked' : '' ?>>
                        <div class="stage-content">
                            <div class="stage-number">Stage 4</div>
                            <div class="stage-description">Fovea-involving hypoautofluorescence</div>
                        </div>
                    </div>
                </div>
                
                <p class="grading-note">
                    This classification system addresses topographic characteristics of retinopathy 
                    using disease patterns and assesses risk of vision-threatening retinopathy.
                </p>
            </div>
            
            <div class="visit-details">
                <h2>Visit Information</h2>
                
                <div class="detail-row">
                    <div class="detail-label">Visit Date:</div>
                    <div class="detail-value"><?= htmlspecialchars($visit['visit_date']) ?></div>
                </div>
                
                <?php if (!empty($visit['visit_notes'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Clinical Notes:</div>
                        <div class="detail-value"><?= nl2br(htmlspecialchars($visit['visit_notes'])) ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="detail-row">
                    <div class="detail-label">Image Reference:</div>
                    <div class="detail-value"><?= htmlspecialchars($ref) ?></div>
                </div>
                
                <?php if (!empty($patient['disease_id'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Disease Code:</div>
                        <div class="detail-value">
                            <?= htmlspecialchars($patient['disease_id']) ?>
                            <?php if (!empty($patient['disease_name'])): ?>
                                (<?= htmlspecialchars($patient['disease_name']) ?>)
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <a href="index.php?search_patient_id=<?= $patient_id ?>" class="back-button">
                ← Back to Patient Record
            </a>
        </div>
    </div>
</body>
</html>
