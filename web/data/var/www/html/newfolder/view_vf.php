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

// Get visit data containing this VF reference
$stmt = $conn->prepare("SELECT * FROM Visits 
                       WHERE patient_id = ? 
                       AND (vf_reference_OD = ? OR vf_reference_OS = ?)");
$stmt->bind_param("iss", $patient_id, $ref, $ref);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();

if (!$visit) {
    die("Visit data not found for this VF report.");
}

// Get PDF path
$pdf_path = getDynamicImagePath($ref, 'vf');
if (!$pdf_path) {
    die("VF PDF report not found in the system.");
}

// Calculate patient age
$current_year = date('Y');
$age = $current_year - $patient['year_of_birth'];

// Get VF grading data from database
$grading_data = [
    'stage' => null,
    'reliability' => null,
    'defect_pattern' => null,
    'glaucoma_hemifield' => null,
    'md' => null,
    'psd' => null,
    'vfi' => null
];

foreach ($grading_data as $key => $value) {
    $stmt = $conn->prepare("SELECT score_value FROM Grading 
                           WHERE visit_id = ? 
                           AND test_type = 'vf' 
                           AND eye_side = ? 
                           AND score_type = ?");
    $stmt->bind_param("iss", $visit['visit_id'], $eye, $key);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $grading_data[$key] = $result['score_value'];
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $field => $value) {
        if (array_key_exists($field, $grading_data)) {
            // Check if grading exists
            $stmt = $conn->prepare("SELECT grading_id FROM Grading 
                                   WHERE visit_id = ? 
                                   AND test_type = 'vf' 
                                   AND eye_side = ? 
                                   AND score_type = ?");
            $stmt->bind_param("iss", $visit['visit_id'], $eye, $field);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                // Update existing
                $stmt = $conn->prepare("UPDATE Grading SET score_value = ? 
                                       WHERE grading_id = ?");
                $stmt->bind_param("si", $value, $existing['grading_id']);
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO Grading 
                                      (visit_id, test_type, eye_side, score_type, score_value) 
                                      VALUES (?, 'vf', ?, ?, ?)");
                $stmt->bind_param("isss", $visit['visit_id'], $eye, $field, $value);
            }
            
            if ($stmt->execute()) {
                $grading_data[$field] = $value;
            }
        }
    }
    
    // Refresh to show updated data
    header("Location: view_vf.php?ref=$ref&patient_id=$patient_id&eye=$eye");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VF Report Viewer - Patient <?= $patient_id ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js"></script>
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
        
        .pdf-section {
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
        
        .pdf-controls {
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
        
        .page-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .page-info {
            color: white;
            font-size: 14px;
        }
        
        .pdf-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        #pdf-canvas {
            max-width: 100%;
            max-height: 100%;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
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
        
        .grading-section {
            margin: 25px 0;
        }
        
        .grading-card {
            background-color: #f9f9f9;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .grading-card h2 {
            color: #4CAF50;
            margin-top: 0;
            margin-bottom: 20px;
        }
        
        .grading-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        
        .form-group select, 
        .form-group input[type="text"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .checkbox-item input {
            margin: 0;
        }
        
        .numeric-value {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .numeric-value input {
            width: 80px;
        }
        
        .save-btn {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
            transition: background-color 0.3s;
        }
        
        .save-btn:hover {
            background-color: #3d8b40;
        }
        
        .save-confirmation {
            color: #4CAF50;
            font-size: 14px;
            margin-top: 10px;
            display: none;
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
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            padding: 5px 15px;
            background-color: #4CAF50;
            color: white;
            border-radius: 20px;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        @media (max-width: 1200px) {
            .container {
                flex-direction: column;
                height: auto;
            }
            
            .pdf-section {
                height: 60vh;
            }
            
            .pdf-controls {
                top: 10px;
                right: 10px;
                padding: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- PDF Viewer Section -->
        <div class="pdf-section">
            <div class="pdf-controls">
                <div class="control-group page-controls">
                    <button class="control-btn" id="prev-page">←</button>
                    <span class="page-info">Page <span id="page-num">1</span>/<span id="page-count">0</span></span>
                    <button class="control-btn" id="next-page">→</button>
                </div>
                <div class="control-group zoom-controls">
                    <button class="control-btn" id="zoom-out">-</button>
                    <button class="control-btn" id="zoom-reset">100%</button>
                    <button class="control-btn" id="zoom-in">+</button>
                </div>
            </div>
            
            <div class="pdf-container">
                <canvas id="pdf-canvas"></canvas>
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
            
            <form method="POST" class="grading-form">
                <!-- VF Stage Grading -->
                <div class="grading-card">
                    <h2>VF Stage Classification</h2>
                    <div class="form-group">
                        <label>VF Stage:</label>
                        <select name="stage">
                            <option value="0" <?= $grading_data['stage'] == 0 ? 'selected' : '' ?>>0 - Normal</option>
                            <option value="1" <?= $grading_data['stage'] == 1 ? 'selected' : '' ?>>1 - Mild defects (1-3 points)</option>
                            <option value="2" <?= $grading_data['stage'] == 2 ? 'selected' : '' ?>>2 - Moderate defects (4-6 points)</option>
                            <option value="3" <?= $grading_data['stage'] == 3 ? 'selected' : '' ?>>3 - Severe defects (7-9 points)</option>
                            <option value="4" <?= $grading_data['stage'] == 4 ? 'selected' : '' ?>>4 - Very severe (10+ points or central loss)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Reliability Indicators -->
                <div class="grading-card">
                    <h2>Reliability Indicators</h2>
                    <div class="form-group">
                        <label>Fixation Losses:</label>
                        <select name="reliability">
                            <option value="0" <?= $grading_data['reliability'] == 0 ? 'selected' : '' ?>>0 - Excellent (&lt;15%)</option>
                            <option value="1" <?= $grading_data['reliability'] == 1 ? 'selected' : '' ?>>1 - Good (15-25%)</option>
                            <option value="2" <?= $grading_data['reliability'] == 2 ? 'selected' : '' ?>>2 - Fair (25-33%)</option>
                            <option value="3" <?= $grading_data['reliability'] == 3 ? 'selected' : '' ?>>3 - Poor (&gt;33%)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Defect Patterns -->
                <div class="grading-card">
                    <h2>Defect Patterns</h2>
                    <div class="form-group">
                        <label>Pattern Type:</label>
                        <div class="checkbox-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="defect_pattern[]" value="nasal_step" <?= strpos($grading_data['defect_pattern'] ?? '', 'nasal_step') !== false ? 'checked' : '' ?>>
                                Nasal Step
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="defect_pattern[]" value="arcuate" <?= strpos($grading_data['defect_pattern'] ?? '', 'arcuate') !== false ? 'checked' : '' ?>>
                                Arcuate
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="defect_pattern[]" value="altitudinal" <?= strpos($grading_data['defect_pattern'] ?? '', 'altitudinal') !== false ? 'checked' : '' ?>>
                                Altitudinal
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="defect_pattern[]" value="central" <?= strpos($grading_data['defect_pattern'] ?? '', 'central') !== false ? 'checked' : '' ?>>
                                Central
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="defect_pattern[]" value="generalized" <?= strpos($grading_data['defect_pattern'] ?? '', 'generalized') !== false ? 'checked' : '' ?>>
                                Generalized
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Glaucoma Hemifield Test -->
                <div class="grading-card">
                    <h2>Glaucoma Hemifield Test</h2>
                    <div class="form-group">
                        <label>Result:</label>
                        <select name="glaucoma_hemifield">
                            <option value="0" <?= $grading_data['glaucoma_hemifield'] == 0 ? 'selected' : '' ?>>0 - Within normal limits</option>
                            <option value="1" <?= $grading_data['glaucoma_hemifield'] == 1 ? 'selected' : '' ?>>1 - Borderline</option>
                            <option value="2" <?= $grading_data['glaucoma_hemifield'] == 2 ? 'selected' : '' ?>>2 - Outside normal limits</option>
                        </select>
                    </div>
                </div>
                
                <!-- Numeric Values -->
                <div class="grading-card">
                    <h2>Numeric Values</h2>
                    <div class="form-group">
                        <label>MD (dB):</label>
                        <div class="numeric-value">
                            <input type="number" step="0.01" name="md" value="<?= htmlspecialchars($grading_data['md'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>PSD (dB):</label>
                        <div class="numeric-value">
                            <input type="number" step="0.01" name="psd" value="<?= htmlspecialchars($grading_data['psd'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>VFI (%):</label>
                        <div class="numeric-value">
                            <input type="number" step="1" name="vfi" value="<?= htmlspecialchars($grading_data['vfi'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="save-btn">Save All Gradings</button>
                <div class="save-confirmation">Gradings updated successfully!</div>
            </form>
            
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
                    <div class="detail-label">Report Reference:</div>
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

    <script>
        // PDF.js configuration
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';
        
        let pdfDoc = null,
            pageNum = 1,
            pageRendering = false,
            pageNumPending = null,
            scale = 1.0,
            canvas = document.getElementById('pdf-canvas'),
            ctx = canvas.getContext('2d');
        
        // Load the PDF
        function loadPDF(url) {
            pdfjsLib.getDocument(url).promise.then(function(pdfDoc_) {
                pdfDoc = pdfDoc_;
                document.getElementById('page-count').textContent = pdfDoc.numPages;
                
                // Initial render
                renderPage(1);
            }).catch(function(error) {
                console.error('Error loading PDF:', error);
                alert('Error loading PDF document');
            });
        }
        
        // Render a page
        function renderPage(num) {
            pageRendering = true;
            pdfDoc.getPage(num).then(function(page) {
                const viewport = page.getViewport({ scale: scale });
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                
                const renderContext = {
                    canvasContext: ctx,
                    viewport: viewport
                };
                
                const renderTask = page.render(renderContext);
                
                renderTask.promise.then(function() {
                    pageRendering = false;
                    if (pageNumPending !== null) {
                        renderPage(pageNumPending);
                        pageNumPending = null;
                    }
                });
            });
            
            document.getElementById('page-num').textContent = num;
        }
        
        // Queue rendering of new page
        function queueRenderPage(num) {
            if (pageRendering) {
                pageNumPending = num;
            } else {
                renderPage(num);
            }
        }
        
        // Navigation controls
        document.getElementById('prev-page').addEventListener('click', function() {
            if (pageNum <= 1) return;
            pageNum--;
            queueRenderPage(pageNum);
        });
        
        document.getElementById('next-page').addEventListener('click', function() {
            if (pageNum >= pdfDoc.numPages) return;
            pageNum++;
            queueRenderPage(pageNum);
        });
        
        // Zoom controls
        document.getElementById('zoom-out').addEventListener('click', function() {
            scale = Math.max(scale - 0.25, 0.5);
            renderPage(pageNum);
        });
        
        document.getElementById('zoom-reset').addEventListener('click', function() {
            scale = 1.0;
            renderPage(pageNum);
        });
        
        document.getElementById('zoom-in').addEventListener('click', function() {
            scale = Math.min(scale + 0.25, 3.0);
            renderPage(pageNum);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                if (pageNum > 1) {
                    pageNum--;
                    queueRenderPage(pageNum);
                }
                e.preventDefault();
            } else if (e.key === 'ArrowRight' || e.key === 'PageDown') {
                if (pageNum < pdfDoc.numPages) {
                    pageNum++;
                    queueRenderPage(pageNum);
                }
                e.preventDefault();
            } else if (e.key === '+') {
                scale = Math.min(scale + 0.25, 3.0);
                renderPage(pageNum);
                e.preventDefault();
            } else if (e.key === '-') {
                scale = Math.max(scale - 0.25, 0.5);
                renderPage(pageNum);
                e.preventDefault();
            } else if (e.key === '0') {
                scale = 1.0;
                renderPage(pageNum);
                e.preventDefault();
            }
        });
        
        // Form submission handling
        document.querySelector('form').addEventListener('submit', function(e) {
            // Handle checkbox array for defect patterns
            const checkboxes = document.querySelectorAll('input[name="defect_pattern[]"]:checked');
            const patterns = Array.from(checkboxes).map(cb => cb.value).join(',');
            
            // Create hidden input for defect patterns
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'defect_pattern';
            hiddenInput.value = patterns;
            this.appendChild(hiddenInput);
            
            // Show save confirmation
            const confirmation = document.querySelector('.save-confirmation');
            confirmation.style.display = 'block';
            setTimeout(() => {
                confirmation.style.display = 'none';
            }, 3000);
        });
        
        // Initialize PDF viewer
        loadPDF('<?= htmlspecialchars($pdf_path) ?>');
    </script>
</body>
</html>
