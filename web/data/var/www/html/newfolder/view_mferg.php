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

// Get visit data containing this MFERG reference
$stmt = $conn->prepare("SELECT * FROM Visits 
                       WHERE patient_id = ? 
                       AND (mferg_reference_OD = ? OR mferg_reference_OS = ?)");
$stmt->bind_param("iss", $patient_id, $ref, $ref);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();

if (!$visit) {
    die("Visit data not found for this MFERG report.");
}

// Get PDF path
$pdf_path = getDynamicImagePath($ref, 'mferg');
if (!$pdf_path) {
    die("MFERG PDF report not found in the system.");
}

// Calculate patient age
$current_year = date('Y');
$age = $current_year - $patient['year_of_birth'];

// Get MFERG grading data from database
$grading_data = [
    'amplitude' => 50,  // Default to 50 if no value exists
    'implicit_time' => 50,
    'ring1' => 50,
    'ring2' => 50,
    'ring3' => 50,
    'ring4' => 50,
    'ring5' => 50,
    'overall_score' => 50
];

foreach ($grading_data as $key => $value) {
    $stmt = $conn->prepare("SELECT score_value FROM Grading 
                           WHERE visit_id = ? 
                           AND test_type = 'mferg' 
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
            $value = (int)$value;
            if ($value < 0) $value = 0;
            if ($value > 100) $value = 100;
            
            // Check if grading exists
            $stmt = $conn->prepare("SELECT grading_id FROM Grading 
                                   WHERE visit_id = ? 
                                   AND test_type = 'mferg' 
                                   AND eye_side = ? 
                                   AND score_type = ?");
            $stmt->bind_param("iss", $visit['visit_id'], $eye, $field);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                // Update existing
                $stmt = $conn->prepare("UPDATE Grading SET score_value = ? 
                                       WHERE grading_id = ?");
                $stmt->bind_param("ii", $value, $existing['grading_id']);
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO Grading 
                                      (visit_id, test_type, eye_side, score_type, score_value) 
                                      VALUES (?, 'mferg', ?, ?, ?)");
                $stmt->bind_param("issi", $visit['visit_id'], $eye, $field, $value);
            }
            
            if ($stmt->execute()) {
                $grading_data[$field] = $value;
            }
        }
    }
    
    // Refresh to show updated data
    header("Location: view_mferg.php?ref=$ref&patient_id=$patient_id&eye=$eye");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MFERG Report Viewer - Patient <?= $patient_id ?></title>
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
        
        .slider-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .slider-container input[type="range"] {
            flex: 1;
            height: 8px;
            -webkit-appearance: none;
            background: #ddd;
            border-radius: 4px;
        }
        
        .slider-container input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: #4CAF50;
            border-radius: 50%;
            cursor: pointer;
        }
        
        .slider-value {
            width: 40px;
            text-align: center;
            font-weight: bold;
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
                <!-- Waveform Characteristics -->
                <div class="grading-card">
                    <h2>Waveform Characteristics</h2>
                    <div class="form-group">
                        <label>Amplitude Score:</label>
                        <div class="slider-container">
                            <input type="range" name="amplitude" min="0" max="100" value="<?= $grading_data['amplitude'] ?>">
                            <span class="slider-value" id="amplitude-value"><?= $grading_data['amplitude'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Implicit Time Score:</label>
                        <div class="slider-container">
                            <input type="range" name="implicit_time" min="0" max="100" value="<?= $grading_data['implicit_time'] ?>">
                            <span class="slider-value" id="implicit-time-value"><?= $grading_data['implicit_time'] ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Ring Analysis -->
                <div class="grading-card">
                    <h2>Ring Analysis Scores</h2>
                    <div class="form-group">
                        <label>Ring 1 (Central):</label>
                        <div class="slider-container">
                            <input type="range" name="ring1" min="0" max="100" value="<?= $grading_data['ring1'] ?>">
                            <span class="slider-value" id="ring1-value"><?= $grading_data['ring1'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Ring 2:</label>
                        <div class="slider-container">
                            <input type="range" name="ring2" min="0" max="100" value="<?= $grading_data['ring2'] ?>">
                            <span class="slider-value" id="ring2-value"><?= $grading_data['ring2'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Ring 3:</label>
                        <div class="slider-container">
                            <input type="range" name="ring3" min="0" max="100" value="<?= $grading_data['ring3'] ?>">
                            <span class="slider-value" id="ring3-value"><?= $grading_data['ring3'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Ring 4:</label>
                        <div class="slider-container">
                            <input type="range" name="ring4" min="0" max="100" value="<?= $grading_data['ring4'] ?>">
                            <span class="slider-value" id="ring4-value"><?= $grading_data['ring4'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Ring 5 (Peripheral):</label>
                        <div class="slider-container">
                            <input type="range" name="ring5" min="0" max="100" value="<?= $grading_data['ring5'] ?>">
                            <span class="slider-value" id="ring5-value"><?= $grading_data['ring5'] ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Overall Score -->
                <div class="grading-card">
                    <h2>Overall Assessment</h2>
                    <div class="form-group">
                        <label>Overall Score:</label>
                        <div class="slider-container">
                            <input type="range" name="overall_score" min="0" max="100" value="<?= $grading_data['overall_score'] ?>">
                            <span class="slider-value" id="overall-score-value"><?= $grading_data['overall_score'] ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Interpretation:</label>
                        <div style="padding: 10px; background-color: #f0f0f0; border-radius: 5px;">
                            <div id="interpretation-text">
                                <?php
                                $score = $grading_data['overall_score'];
                                if ($score >= 80) {
                                    echo "Normal MFERG responses";
                                } elseif ($score >= 60) {
                                    echo "Mildly abnormal MFERG";
                                } elseif ($score >= 40) {
                                    echo "Moderately abnormal MFERG";
                                } elseif ($score >= 20) {
                                    echo "Severely abnormal MFERG";
                                } else {
                                    echo "Very severely abnormal MFERG";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="save-btn">Save All Scores</button>
                <div class="save-confirmation">Scores updated successfully!</div>
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
        
        // Slider value updates
        document.querySelectorAll('input[type="range"]').forEach(slider => {
            const valueDisplay = document.getElementById(`${slider.name}-value`);
            
            // Initial display
            valueDisplay.textContent = slider.value;
            
            // Update on change
            slider.addEventListener('input', function() {
                valueDisplay.textContent = this.value;
                
                // Update interpretation if overall score changes
                if (this.name === 'overall_score') {
                    updateInterpretation(this.value);
                }
            });
        });
        
        // Update interpretation text
        function updateInterpretation(score) {
            const interpretation = document.getElementById('interpretation-text');
            score = parseInt(score);
            
            if (score >= 80) {
                interpretation.textContent = "Normal MFERG responses";
            } else if (score >= 60) {
                interpretation.textContent = "Mildly abnormal MFERG";
            } else if (score >= 40) {
                interpretation.textContent = "Moderately abnormal MFERG";
            } else if (score >= 20) {
                interpretation.textContent = "Severely abnormal MFERG";
            } else {
                interpretation.textContent = "Very severely abnormal MFERG";
            }
        }
        
        // Form submission handling
        document.querySelector('form').addEventListener('submit', function(e) {
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
