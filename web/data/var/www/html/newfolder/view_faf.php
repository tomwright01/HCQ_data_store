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

// Get MERCI score and FAF stage
$merci_score = ($eye == 'OD') ? $visit['merci_rating_right_eye'] : $visit['merci_rating_left_eye'];
$current_stage = ($eye == 'OD') ? $visit['faf_stage_OD'] : $visit['faf_stage_OS'];
$current_brightness = ($eye == 'OD') ? ($visit['faf_brightness_OD'] ?? 1.0) : ($visit['faf_brightness_OS'] ?? 1.0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle stage update
    if (isset($_POST['stage'])) {
        $new_stage = (int)$_POST['stage'];
        if ($new_stage >= 0 && $new_stage <= 4) {
            $field = ($eye == 'OD') ? 'faf_stage_OD' : 'faf_stage_OS';
            $stmt = $conn->prepare("UPDATE Visits SET $field = ? WHERE visit_id = ?");
            $stmt->bind_param("ii", $new_stage, $visit['visit_id']);
            if ($stmt->execute()) {
                $current_stage = $new_stage;
            }
        }
    }
    
    // Handle brightness update
    if (isset($_POST['brightness'])) {
        $new_brightness = (float)$_POST['brightness'];
        if ($new_brightness >= 0.1 && $new_brightness <= 3.0) {
            $field = ($eye == 'OD') ? 'faf_brightness_OD' : 'faf_brightness_OS';
            $stmt = $conn->prepare("UPDATE Visits SET $field = ? WHERE visit_id = ?");
            $stmt->bind_param("di", $new_brightness, $visit['visit_id']);
            if ($stmt->execute()) {
                $current_brightness = $new_brightness;
            }
        }
    }
}
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
            position: relative;
        }
        
        .image-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.7);
            padding: 10px;
            border-radius: 5px;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .control-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .control-btn {
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
        }
        
        .control-btn:hover {
            background: #3d8b40;
        }
        
        .zoom-controls {
            display: flex;
            gap: 5px;
        }
        
        .brightness-control {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .brightness-slider {
            width: 100px;
        }
        
        .image-wrapper {
            max-width: 100%;
            max-height: 100%;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .image-container {
            transition: filter 0.3s ease;
            transform-origin: center center;
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
            transition: width 0.3s ease;
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
        
        .stage-radio {
            margin-right: 15px;
            margin-top: 3px;
            accent-color: #4CAF50;
            transform: scale(1.3);
            cursor: pointer;
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
        
        .stage-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .save-btn {
            align-self: flex-start;
            padding: 8px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
            transition: background-color 0.3s;
        }
        
        .save-btn:hover {
            background-color: #3d8b40;
        }
        
        .save-confirmation {
            color: #4CAF50;
            font-size: 14px;
            margin-top: 10px;
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
            
            .image-controls {
                top: 10px;
                right: 10px;
                padding: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Image Section with Controls -->
        <div class="image-section">
            <div class="image-controls">
                <div class="control-group zoom-controls">
                    <button class="control-btn zoom-out">-</button>
                    <button class="control-btn zoom-reset">1:1</button>
                    <button class="control-btn zoom-in">+</button>
                </div>
                <form method="POST" class="control-group brightness-control">
                    <button type="button" class="control-btn brightness-down">-</button>
                    <input type="range" class="brightness-slider" name="brightness" min="0.1" max="3.0" step="0.1" 
                           value="<?= $current_brightness ?>">
                    <button type="button" class="control-btn brightness-up">+</button>
                    <button type="submit" class="control-btn" style="margin-left: 5px;">✓</button>
                </form>
            </div>
            
            <div class="image-wrapper">
                <div class="image-container" id="image-container">
                    <img src="<?= htmlspecialchars($image_path) ?>" alt="FAF Image" id="faf-image"
                         style="filter: brightness(<?= $current_brightness ?>);">
                </div>
            </div>
            <div class="eye-indicator">
                <?= $eye ?> (<?= $eye == 'OD' ? 'Right Eye' : 'Left Eye' ?>)
            </div>
        </div>
        
        <!-- [Rest of the HTML remains exactly the same as previous version] -->
    </div>

    <script>
        // Image Zoom and Brightness Controls
        const imageContainer = document.getElementById('image-container');
        const fafImage = document.getElementById('faf-image');
        let currentZoom = 1;
        const brightnessSlider = document.querySelector('.brightness-slider');
        
        // Zoom functionality
        document.querySelector('.zoom-in').addEventListener('click', () => {
            currentZoom = Math.min(currentZoom + 0.1, 3);
            updateZoom();
        });
        
        document.querySelector('.zoom-out').addEventListener('click', () => {
            currentZoom = Math.max(currentZoom - 0.1, 0.1);
            updateZoom();
        });
        
        document.querySelector('.zoom-reset').addEventListener('click', () => {
            currentZoom = 1;
            updateZoom();
        });
        
        function updateZoom() {
            imageContainer.style.transform = `scale(${currentZoom})`;
        }
        
        // Brightness controls
        document.querySelector('.brightness-up').addEventListener('click', () => {
            brightnessSlider.value = parseFloat(brightnessSlider.value) + 0.1;
            updatePreviewBrightness();
        });
        
        document.querySelector('.brightness-down').addEventListener('click', () => {
            brightnessSlider.value = parseFloat(brightnessSlider.value) - 0.1;
            updatePreviewBrightness();
        });
        
        brightnessSlider.addEventListener('input', updatePreviewBrightness);
        
        function updatePreviewBrightness() {
            fafImage.style.filter = `brightness(${brightnessSlider.value})`;
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === '+') {
                currentZoom = Math.min(currentZoom + 0.1, 3);
                updateZoom();
            } else if (e.key === '-') {
                currentZoom = Math.max(currentZoom - 0.1, 0.1);
                updateZoom();
            } else if (e.key === '0') {
                currentZoom = 1;
                updateZoom();
            }
        });
        
        // Stage form submission feedback
        document.querySelector('.stage-form')?.addEventListener('submit', function() {
            const btn = this.querySelector('.save-btn');
            const confirmation = this.querySelector('.save-confirmation');
            if (btn) {
                btn.textContent = 'Saving...';
                setTimeout(() => {
                    btn.textContent = 'Save FAF Stage';
                    if (confirmation) {
                        confirmation.style.display = 'block';
                        setTimeout(() => {
                            confirmation.style.display = 'none';
                        }, 3000);
                    }
                }, 1000);
            }
        });
    </script>
</body>
</html>
