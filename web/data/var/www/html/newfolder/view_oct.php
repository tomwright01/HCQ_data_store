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

// Get visit data containing this OCT reference
$stmt = $conn->prepare("SELECT * FROM Visits 
                       WHERE patient_id = ? 
                       AND (oct_reference_OD = ? OR oct_reference_OS = ?)");
$stmt->bind_param("iss", $patient_id, $ref, $ref);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();

if (!$visit) {
    die("Visit data not found for this OCT image.");
}

// Get image path
$image_path = getDynamicImagePath($ref, 'oct');
if (!$image_path) {
    die("OCT image not found in the system.");
}

// Calculate patient age
$current_year = date('Y');
$age = $current_year - $patient['year_of_birth'];

// Get MERCI score and OCT stage from Grading table
$stmt = $conn->prepare("SELECT score_value FROM Grading 
                       WHERE visit_id = ? 
                       AND test_type = 'oct' 
                       AND eye_side = ? 
                       AND score_type = 'stage'");
$stmt->bind_param("is", $visit['visit_id'], $eye);
$stmt->execute();
$grading_result = $stmt->get_result()->fetch_assoc();
$current_stage = $grading_result ? $grading_result['score_value'] : null;

// Get brightness setting
$stmt = $conn->prepare("SELECT score_value FROM Grading 
                       WHERE visit_id = ? 
                       AND test_type = 'oct' 
                       AND eye_side = ? 
                       AND score_type = 'brightness'");
$stmt->bind_param("is", $visit['visit_id'], $eye);
$stmt->execute();
$brightness_result = $stmt->get_result()->fetch_assoc();
$current_brightness = $brightness_result ? $brightness_result['score_value'] : 1.0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle stage update
    if (isset($_POST['stage'])) {
        $new_stage = (int)$_POST['stage'];
        if ($new_stage >= 0 && $new_stage <= 4) {
            // Check if grading exists
            $stmt = $conn->prepare("SELECT grading_id FROM Grading 
                                   WHERE visit_id = ? 
                                   AND test_type = 'oct' 
                                   AND eye_side = ? 
                                   AND score_type = 'stage'");
            $stmt->bind_param("is", $visit['visit_id'], $eye);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                // Update existing
                $stmt = $conn->prepare("UPDATE Grading SET score_value = ? 
                                       WHERE grading_id = ?");
                $stmt->bind_param("ii", $new_stage, $existing['grading_id']);
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO Grading 
                                      (visit_id, test_type, eye_side, score_type, score_value) 
                                      VALUES (?, 'oct', ?, 'stage', ?)");
                $stmt->bind_param("isi", $visit['visit_id'], $eye, $new_stage);
            }
            
            if ($stmt->execute()) {
                $current_stage = $new_stage;
            }
        }
    }
    
    // Handle brightness update
    if (isset($_POST['brightness'])) {
        $new_brightness = (float)$_POST['brightness'];
        if ($new_brightness >= 0.1 && $new_brightness <= 3.0) {
            // Check if brightness setting exists
            $stmt = $conn->prepare("SELECT grading_id FROM Grading 
                                   WHERE visit_id = ? 
                                   AND test_type = 'oct' 
                                   AND eye_side = ? 
                                   AND score_type = 'brightness'");
            $stmt->bind_param("is", $visit['visit_id'], $eye);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                // Update existing
                $stmt = $conn->prepare("UPDATE Grading SET score_value = ? 
                                       WHERE grading_id = ?");
                $stmt->bind_param("di", $new_brightness, $existing['grading_id']);
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO Grading 
                                      (visit_id, test_type, eye_side, score_type, score_value) 
                                      VALUES (?, 'oct', ?, 'brightness', ?)");
                $stmt->bind_param("isd", $visit['visit_id'], $eye, $new_brightness);
            }
            
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
    <title>OCT Viewer - Patient <?= $patient_id ?></title>
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
                    <img src="<?= htmlspecialchars($image_path) ?>" alt="OCT Image" id="oct-image"
                         style="filter: brightness(<?= $current_brightness ?>);">
                </div>
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
                <h2>OCT Grading</h2>
                <div class="score-value"><?= $current_stage !== null ? htmlspecialchars($current_stage) : 'N/A' ?></div>
                <div class="score-label">out of 4</div>
                
                <div class="progress-container">
                    <div class="progress-bar" style="width: <?= $current_stage !== null ? ($current_stage / 4 * 100) : 0 ?>%;">
                        <?php if ($current_stage !== null): ?>
                            <div class="progress-marker"><?= $current_stage ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="severity-scale">
                    <div class="scale-item">0 - Normal</div>
                    <div class="scale-item">1 - Mild</div>
                    <div class="scale-item">2 - Moderate</div>
                    <div class="scale-item">3 - Severe</div>
                    <div class="scale-item">4 - Very Severe</div>
                </div>
            </div>
            
            <div class="grading-card">
                <h2>OCT Staging System</h2>
                
                <form method="POST" class="stage-form">
                    <div class="grading-system">
                        <div class="stage <?= ($current_stage == 0) ? 'active' : '' ?>">
                            <input type="radio" name="stage" value="0" class="stage-radio" 
                                   id="stage-0" <?= ($current_stage == 0) ? 'checked' : '' ?>>
                            <div class="stage-content">
                                <label for="stage-0">
                                    <div class="stage-number">Stage 0</div>
                                    <div class="stage-description">Normal retinal layers with no visible abnormalities</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="stage <?= ($current_stage == 1) ? 'active' : '' ?>">
                            <input type="radio" name="stage" value="1" class="stage-radio" 
                                   id="stage-1" <?= ($current_stage == 1) ? 'checked' : '' ?>>
                            <div class="stage-content">
                                <label for="stage-1">
                                    <div class="stage-number">Stage 1</div>
                                    <div class="stage-description">Mild retinal layer disruption or thinning</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="stage <?= ($current_stage == 2) ? 'active' : '' ?>">
                            <input type="radio" name="stage" value="2" class="stage-radio" 
                                   id="stage-2" <?= ($current_stage == 2) ? 'checked' : '' ?>>
                            <div class="stage-content">
                                <label for="stage-2">
                                    <div class="stage-number">Stage 2</div>
                                    <div class="stage-description">Moderate retinal layer disruption with visible abnormalities</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="stage <?= ($current_stage == 3) ? 'active' : '' ?>">
                            <input type="radio" name="stage" value="3" class="stage-radio" 
                                   id="stage-3" <?= ($current_stage == 3) ? 'checked' : '' ?>>
                            <div class="stage-content">
                                <label for="stage-3">
                                    <div class="stage-number">Stage 3</div>
                                    <div class="stage-description">Severe retinal layer disruption with significant abnormalities</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="stage <?= ($current_stage == 4) ? 'active' : '' ?>">
                            <input type="radio" name="stage" value="4" class="stage-radio" 
                                   id="stage-4" <?= ($current_stage == 4) ? 'checked' : '' ?>>
                            <div class="stage-content">
                                <label for="stage-4">
                                    <div class="stage-number">Stage 4</div>
                                    <div class="stage-description">Very severe disruption with complete layer breakdown</div>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="save-btn">Save OCT Stage</button>
                    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage'])): ?>
                        <div class="save-confirmation" style="display: block;">
                            Stage updated successfully!
                        </div>
                    <?php endif; ?>
                </form>
                
                <p class="grading-note">
                    This classification system evaluates the severity of retinal layer abnormalities visible on OCT imaging.
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
                
                <div class="detail-row">
                    <div class="detail-label">OCT Stage:</div>
                    <div class="detail-value">
                        <?= !is_null($current_stage) ? "Stage $current_stage" : "Not graded" ?>
                    </div>
                </div>
            </div>
            
            <a href="index.php?search_patient_id=<?= $patient_id ?>" class="back-button">
                ← Back to Patient Record
            </a>
        </div>
    </div>

    <script>
        // Image Zoom and Brightness Controls
        const imageContainer = document.getElementById('image-container');
        const octImage = document.getElementById('oct-image');
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
            octImage.style.filter = `brightness(${brightnessSlider.value})`;
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
                    btn.textContent = 'Save OCT Stage';
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
