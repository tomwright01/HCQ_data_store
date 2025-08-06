<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get parameters from URL
$ref = $_GET['ref'] ?? '';
$patient_id = $_GET['patient_id'] ?? '';
$eye = $_GET['eye'] ?? '';
$test_type = 'FAF'; // Hardcoded for FAF viewer

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
    die("Test data not found for this FAF image reference.");
}

// Get image path using the config function
$image_path = getDynamicImagePath($ref);
if (!$image_path) {
    die("FAF image not found in the system.");
}

// Calculate patient age
$age = !empty($patient['date_of_birth']) ? 
    date_diff(date_create($patient['date_of_birth']), date_create('today'))->y : 'N/A';

// Get all diagnostic data directly from database
$merci_score = $test['merci_score'] ?? 'N/A';
$report_diagnosis = $test['report_diagnosis'] ?? 'Not specified';
$exclusion = $test['exclusion'] ?? 'None';
$merci_diagnosis = $test['merci_diagnosis'] ?? 'Not specified';
$error_type = $test['error_type'] ?? 'N/A';
$faf_grade = $test['faf_grade'] ?? 'N/A';
$oct_score = $test['oct_score'] ?? 'N/A';
$vf_score = $test['vf_score'] ?? 'N/A';
$test_date = $test['date_of_test'] ?? 'Unknown';

$current_brightness = 1.0; // Default brightness

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['brightness'])) {
        $new_brightness = (float)$_POST['brightness'];
        if ($new_brightness >= 0.1 && $new_brightness <= 3.0) {
            $current_brightness = $new_brightness;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAF Viewer - Patient <?= htmlspecialchars($patient_id) ?></title>
    <style>
        :root {
            --primary-color: #00a88f;
            --primary-dark: #008774;
            --text-color: #333;
            --bg-color: #fff;
            --light-bg: #f5f5f5;
            --border-color: #e0e0e0;
            --meta-bg: #f0f0f0;
            --card-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg);
            color: var(--text-color);
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
            background: var(--primary-color);
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
            background: var(--primary-dark);
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
            transition: transform 0.3s ease;
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
            background-color: var(--bg-color);
            overflow-y: auto;
        }
        
        .patient-header {
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .patient-header h1 {
            color: var(--primary-color);
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
            background-color: var(--meta-bg);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .meta-item i {
            margin-right: 8px;
            color: var(--primary-color);
        }
        
        .score-card {
            background-color: var(--light-bg);
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            box-shadow: var(--card-shadow);
        }
        
        .score-value {
            font-size: 72px;
            font-weight: bold;
            color: var(--primary-color);
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
            background-color: var(--border-color);
            border-radius: 5px;
            margin: 20px 0;
            height: 25px;
        }
        
        .progress-bar {
            height: 100%;
            background-color: var(--primary-color);
            border-radius: 5px;
            width: <?= $merci_score !== 'N/A' ? ($merci_score / 100 * 100) : 0 ?>%;
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
        
        .diagnostic-card {
            background-color: var(--light-bg);
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            box-shadow: var(--card-shadow);
        }
        
        .diagnostic-card h2 {
            color: var(--primary-color);
            margin-top: 0;
            margin-bottom: 20px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
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
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
            font-size: 16px;
        }
        
        .back-button:hover {
            background-color: var(--primary-dark);
        }
        
        .eye-indicator {
            display: inline-flex;
            align-items: center;
            padding: 5px 15px;
            background-color: var(--primary-color);
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
        
        .fullscreen-btn {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: rgba(0, 168, 143, 0.7);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            z-index: 10;
        }
        
        .fullscreen-btn:hover {
            background: rgba(0, 168, 143, 0.9);
        }
        
        .image-section.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 1000;
        }
        
        .image-section.fullscreen .image-wrapper {
            width: 100%;
            height: 100%;
        }
        
        .image-section.fullscreen .fullscreen-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
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
        <div class="image-section" id="image-section">
            <div class="image-controls">
                <div class="control-group zoom-controls">
                    <button class="control-btn zoom-out">-</button>
                    <button class="control-btn zoom-reset">1:1</button>
                    <button class="control-btn zoom-in">+</button>
                </div>
                <div class="control-group">
                    <button class="control-btn" id="center-eye-btn">👁️</button>
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
            
            <button class="fullscreen-btn" id="fullscreen-btn">Fullscreen</button>
            
            <div class="eye-indicator">
                <?= $eye ?> (<?= $eye == 'OD' ? 'Right Eye' : 'Left Eye' ?>)
            </div>
        </div>
        
        <!-- Information Section -->
        <div class="info-section">
            <div class="patient-header">
                <h1>
                    Patient <?= htmlspecialchars($patient_id) ?>
                    <span class="eye-indicator">
                        <?= $eye ?> (<?= $eye == 'OD' ? 'Right Eye' : 'Left Eye' ?>)
                    </span>
                </h1>
                <div class="patient-meta">
                    <div class="meta-item">
                        <i>👤</i> <?= $age ?> years
                    </div>
                    <?php if (!empty($patient['subject_id'])): ?>
                        <div class="meta-item">
                            <i>🆔</i> <?= htmlspecialchars($patient['subject_id']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="score-card">
                <h2>MERCI Score</h2>
                <div class="score-value"><?= $merci_score !== 'N/A' ? htmlspecialchars($merci_score) : 'N/A' ?></div>
                <div class="score-label">out of 100</div>
                
                <div class="progress-container">
                    <div class="progress-bar">
                        <?php if ($merci_score !== 'N/A'): ?>
                            <div class="progress-marker"><?= $merci_score ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="severity-scale">
                    <div class="scale-item">0-20 </div>
                    <div class="scale-item">21-40 </div>
                    <div class="scale-item">41-60 </div>
                    <div class="scale-item">61-80 </div>
                    <div class="scale-item">81-100</div>
                </div>
            </div>
            
            <div class="diagnostic-card">
                <h2>Diagnostic Information</h2>
                
                <div class="detail-row">
                    <div class="detail-label">Test Date:</div>
                    <div class="detail-value"><?= htmlspecialchars($test_date) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Report Diagnosis:</div>
                    <div class="detail-value"><?= htmlspecialchars($report_diagnosis) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">MERCI Diagnosis:</div>
                    <div class="detail-value"><?= htmlspecialchars($merci_diagnosis) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Exclusion:</div>
                    <div class="detail-value"><?= htmlspecialchars($exclusion) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Error Type:</div>
                    <div class="detail-value"><?= htmlspecialchars($error_type) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">FAF Grade:</div>
                    <div class="detail-value"><?= htmlspecialchars($faf_grade) ?></div>
                </div>
                
                <?php if ($oct_score !== 'N/A'): ?>
                    <div class="detail-row">
                        <div class="detail-label">OCT Score:</div>
                        <div class="detail-value"><?= htmlspecialchars($oct_score) ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($vf_score !== 'N/A'): ?>
                    <div class="detail-row">
                        <div class="detail-label">VF Score:</div>
                        <div class="detail-value"><?= htmlspecialchars($vf_score) ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="detail-row">
                    <div class="detail-label">Image Reference:</div>
                    <div class="detail-value"><?= htmlspecialchars($ref) ?></div>
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
        const fafImage = document.getElementById('faf-image');
        const imageSection = document.getElementById('image-section');
        const fullscreenBtn = document.getElementById('fullscreen-btn');
        const imageWrapper = document.querySelector('.image-wrapper');
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
        
        // Center eye functionality
        document.getElementById('center-eye-btn').addEventListener('click', centerEye);
        
        function centerEye() {
            const imgWidth = fafImage.naturalWidth;
            const imgHeight = fafImage.naturalHeight;
            
            // Calculate center coordinates (assuming eye is roughly centered in the image)
            const centerX = imgWidth / 2;
            const centerY = imgHeight / 2;
            
            // Calculate the viewport dimensions
            const viewportWidth = imageWrapper.clientWidth;
            const viewportHeight = imageWrapper.clientHeight;
            
            // Calculate the scale needed to fit the image to the viewport
            const scaleX = viewportWidth / imgWidth;
            const scaleY = viewportHeight / imgHeight;
            const scale = Math.min(scaleX, scaleY);
            
            // Calculate the translation needed to center the eye
            const translateX = (viewportWidth / 2 - centerX * scale) / scale;
            const translateY = (viewportHeight / 2 - centerY * scale) / scale;
            
            // Apply the transformation
            imageContainer.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
            
            // Update current zoom level
            currentZoom = scale;
        }
        
        // Fullscreen functionality
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                imageSection.classList.add('fullscreen');
                if (imageSection.requestFullscreen) {
                    imageSection.requestFullscreen();
                } else if (imageSection.webkitRequestFullscreen) {
                    imageSection.webkitRequestFullscreen();
                } else if (imageSection.msRequestFullscreen) {
                    imageSection.msRequestFullscreen();
                }
                
                // Center the eye in fullscreen mode
                setTimeout(centerEye, 100);
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        });
        
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                imageSection.classList.remove('fullscreen');
                // Reset to normal view when exiting fullscreen
                imageContainer.style.transform = `scale(${currentZoom})`;
            }
        });
        
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
            } else if (e.key === 'f') {
                fullscreenBtn.click();
            } else if (e.key === 'c') {
                centerEye();
            }
        });
    </script>
</body>
</html>
