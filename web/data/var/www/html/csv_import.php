<?php
// Enable error reporting (turn off in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load shared config & helpers
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Upload directory
$uploadDir = "/var/www/html/uploads/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Initialize variables
$message = '';
$messageClass = '';
$results = [
    'patients' => 0,
    'tests' => 0,
    'errors' => []
];
$fileName = '';

function normalize_merci_score($v) {
    if ($v === null || $v === '') return null;
    if (strtolower($v) === 'unable') return 'unable';
    if (is_numeric($v) && $v >= 0 && $v <= 100) return (int)$v;
    return null;
}

function normalize_actual_diagnosis($v) {
    $allowed = ['RA', 'SLE', 'Sjogren', 'other'];
    if (empty($v)) return null;
    $normalized = ucfirst(strtolower(trim($v)));
    if ($normalized === 'Sjorgens') {
        $normalized = 'Sjogren';
    }
    return in_array($normalized, $allowed) ? $normalized : 'other';
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Error uploading file: " . ($_FILES['csv_file']['error'] ?? 'No file selected');
        $messageClass = 'error';
    } else {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileNameClean = preg_replace("/[^A-Za-z0-9 \.\-_]/", '', $fileName);
        $fileExt = strtolower(pathinfo($fileNameClean, PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $message = "Only CSV files are allowed.";
            $messageClass = 'error';
        } else {
            $newFileName = uniqid('import_', true) . '.' . $fileExt;
            $destPath = $uploadDir . $newFileName;

            if (!move_uploaded_file($fileTmpPath, $destPath)) {
                $message = "Failed to move uploaded file.";
                $messageClass = 'error';
            } else {
                try {
                    if (!file_exists($destPath)) {
                        throw new Exception("CSV file not found at: $destPath");
                    }
                    if (!is_readable($destPath)) {
                        throw new Exception("CSV file is not readable. Check permissions.");
                    }

                    if (($handle = fopen($destPath, "r")) === FALSE) {
                        throw new Exception("Could not open CSV file");
                    }

                    $conn->begin_transaction();

                    // Skip header
                    fgetcsv($handle, 0, ",", '"', "\0");
                    $lineNumber = 1;

                    while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
                        $lineNumber++;
                        try {
                            if (count(array_filter($data)) === 0) continue;

                            if (count($data) < 18) {
                                throw new Exception("Row has only " . count($data) . " columns (minimum 18 required)");
                            }

                            $data = array_map('trim', $data);
                            $data = array_map(function($v) {
                                $v = trim($v ?? '');
                                $lower = strtolower($v);
                                if ($v === '' || in_array($lower, ['null', 'no value', 'missing'])) {
                                    return null;
                                }
                                return $v;
                            }, $data);

                            // Patient
                            $subjectId = $data[0] ?? '';
                            $dobRaw = $data[1] ?? '';
                            $dobObj = DateTime::createFromFormat('m/d/Y', $dobRaw);
                            if (!$dobObj) {
                                throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }
                            $dobFormatted = $dobObj->format('Y-m-d');
                            $location = 'KH';
                            $patientId = $subjectId;

                            // Patient-level actual diagnosis from col 15
                            $patientActualDiagnosis = normalize_actual_diagnosis($data[14] ?? null) ?? 'other';

                            upsertPatient($patientId, $subjectId, $dobFormatted, $location, $patientActualDiagnosis);
                            $results['patients']++;

                            // Test
                            $testDateRaw = $data[2] ?? '';
                            $testDateObj = DateTime::createFromFormat('m/d/Y', $testDateRaw);
                            if (!$testDateObj) {
                                throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }
                            $testDate = $testDateObj->format('Y-m-d');

                            $ageValue = $data[3] ?? null;
                            $age = (isset($ageValue) && is_numeric($ageValue) && $ageValue >= 0 && $ageValue <= 100) ? (int)round($ageValue) : null;

                            $testNumber = $data[4] ?? null;
                            if ($testNumber !== null && !is_numeric($testNumber)) {
                                throw new Exception("Invalid TEST_ID: must be a number");
                            }

                            $eyeValue = $data[5] ?? null;
                            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                            $testDateFormattedShort = $testDateObj->format('Ymd');
                            $testId = $testDateFormattedShort . ($eye ? $eye : '') . ($testNumber ? $testNumber : '');

                            // Report diagnosis
                            $reportDiagnosisRaw = $data[6] ?? null;
                            $reportDiagnosis = 'no input';
                            if ($reportDiagnosisRaw !== null) {
                                $lower = strtolower($reportDiagnosisRaw);
                                if (in_array($lower, ['normal', 'abnormal', 'exclude'])) {
                                    $reportDiagnosis = $lower;
                                }
                            }

                            // Exclusion
                            $exclusionRaw = $data[7] ?? null;
                            $exclusion = 'none';
                            if ($exclusionRaw !== null) {
                                $lower = strtolower($exclusionRaw);
                                if (in_array($lower, ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) {
                                    $exclusion = $lower;
                                }
                            }

                            // MERCI
                            $merciScoreRaw = $data[8] ?? null;
                            $merciScore = normalize_merci_score($merciScoreRaw);
                            $merciDiagnosisRaw = $data[9] ?? null;
                            $merciDiagnosis = 'no value';
                            if ($merciDiagnosisRaw !== null) {
                                $lower = strtolower($merciDiagnosisRaw);
                                if (in_array($lower, ['normal', 'abnormal'])) {
                                    $merciDiagnosis = $lower;
                                }
                            }

                            // Error type
                            $errorTypeRaw = $data[10] ?? null;
                            $allowedErrorTypes = ['TN', 'FP', 'TP', 'FN', 'none'];
                            $errorType = null;
                            if ($errorTypeRaw !== null && $errorTypeRaw !== '') {
                                $upper = strtoupper(trim($errorTypeRaw));
                                if (in_array($upper, $allowedErrorTypes)) {
                                    $errorType = ($upper === 'NONE') ? 'none' : $upper;
                                } else {
                                    $results['errors'][] = "Line $lineNumber: Invalid error_type '{$errorTypeRaw}' - set to NULL";
                                }
                            }

                            // FAF / OCT / VF
                            $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                            $octScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;
                            $vfScore = isset($data[13]) && is_numeric($data[13]) ? round(floatval($data[13]), 2) : null;

                            // Test-level actual diagnosis
                            $testActualDiagnosis = normalize_actual_diagnosis($data[14] ?? null);

                            // Dosage/duration/cumulative
                            $dosage = isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;
                            $durationDays = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;
                            $cumulativeDosage = isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

                            $testData = [
                                'test_id' => $testId,
                                'patient_id' => $patientId,
                                'location' => $location,
                                'date_of_test' => $testDate,
                                'age' => $age,
                                'eye' => $eye,
                                'report_diagnosis' => $reportDiagnosis,
                                'exclusion' => $exclusion,
                                'merci_score' => $merciScore,
                                'merci_diagnosis' => $merciDiagnosis,
                                'error_type' => $errorType,
                                'faf_grade' => $fafGrade,
                                'oct_score' => $octScore,
                                'vf_score' => $vfScore,
                                'actual_diagnosis' => $testActualDiagnosis,
                                'medication_name' => null,
                                'dosage' => $dosage,
                                'dosage_unit' => 'mg',
                                'duration_days' => $durationDays,
                                'cumulative_dosage' => $cumulativeDosage,
                                'date_of_continuation' => null,
                                'treatment_notes' => null
                            ];

                            insertTest($conn, $testData);
                            $results['tests']++;
                        } catch (Exception $e) {
                            $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                        }
                    }

                    fclose($handle);

                    if (empty($results['errors'])) {
                        $conn->commit();
                        $message = "Successfully processed {$results['patients']} patients and {$results['tests']} tests.";
                        $messageClass = 'success';
                    } else {
                        $conn->rollback();
                        $message = "Completed with " . count($results['errors']) . " errors. No data was imported.";
                        $messageClass = 'error';
                    }
                } catch (Exception $e) {
                    if (method_exists($conn, 'rollback')) {
                        $conn->rollback();
                    }
                    $message = "Fatal error: " . $e->getMessage();
                    $messageClass = 'error';
                }
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
    <title>CSV Import Tool | Hydroxychloroquine Data Repository</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: rgb(0, 168, 143);
            --primary-dark: rgb(0, 140, 120);
            --primary-light: rgb(178, 226, 226);
            --text-color: #212529;
            --radius: 10px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg,#f0f4f9,#ffffff);
            color: var(--text-color);
        }
        header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 25px 15px;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .header-content {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        .logo { height: 50px; }
        h1 { margin: 0; font-size: 2.2rem; }
        .container {
            max-width: 1250px;
            margin: 30px auto 60px;
            padding: 0 15px;
        }
        .card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            margin-bottom: 30px;
            position: relative;
            box-shadow: 0 20px 50px -10px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.05);
        }
        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        .card-title i { font-size:1.6rem; }
        .upload-area {
            border: 2px dashed var(--primary-light);
            border-radius: 8px;
            padding: 35px;
            text-align: center;
            position: relative;
            transition: all .3s;
            background-color: rgba(178, 226, 226, 0.08);
        }
        .upload-area:hover {
            border-color: var(--primary-color);
            background-color: rgba(178, 226, 226, 0.15);
        }
        .upload-icon { font-size: 3.5rem; color: var(--primary-color); margin-bottom:10px; }
        .file-input { display: none; }
        .file-label {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight:600;
            margin-top:10px;
        }
        .file-label i { margin-right:6px; }
        .btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            color: white;
            padding: 14px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size:1rem;
            font-weight:600;
            display: inline-flex;
            align-items:center;
            gap:8px;
            transition: all .25s;
        }
        .btn:hover { filter:brightness(1.05); }
        .message {
            border-radius: 8px;
            padding: 15px 18px;
            display:flex;
            gap:12px;
            align-items:center;
            font-weight:500;
            margin-bottom:12px;
        }
        .success { background: rgba(40, 167, 69, 0.1); border-left: 5px solid #28a745; color: #155724; }
        .error { background: rgba(220, 53, 69, 0.1); border-left:5px solid #dc3545; color: #721c24; }
        .stats-cards {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:20px;
            margin:18px 0 10px;
        }
        .stat-card {
            background: #f9fcfd;
            border-radius: 8px;
            padding: 18px;
            display:flex;
            flex-direction: column;
            align-items:center;
            gap:6px;
            border:1px solid rgba(0,168,143,0.15);
        }
        .stat-value { font-size:2.4rem; font-weight:700; color: var(--primary-dark); }
        .stat-label { font-size:0.75rem; letter-spacing:1px; text-transform:uppercase; color:#555; }
        .error-list {
            margin-top:12px;
            border:1px solid #e4e8ed;
            border-radius:6px;
            max-height:260px;
            overflow:auto;
            background:#fff;
        }
        .error-item {
            padding:12px 14px;
            border-bottom:1px solid #e9ecf2;
            display:flex;
            gap:10px;
            align-items:flex-start;
            font-size:0.9rem;
        }
        .error-item:last-child { border-bottom:none; }
        .back-link {
            display:inline-flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
            color: var(--primary-dark);
            font-weight:600;
            margin-top:10px;
        }
        .back-link i { font-size:1rem; }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <img src="images/kensington-logo-white.png" alt="Kensington Health Logo" class="logo">
            <h1>CSV Import Tool</h1>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <div class="card-title"><i class="fas fa-file-import"></i> Import Patient &amp; Test Data</div>
            <form method="post" action="" enctype="multipart/form-data" id="importForm" style="display:flex; flex-wrap:wrap; gap:20px; align-items:center;">
                <div style="flex:1; min-width:250px;">
                    <div class="upload-area" id="dropZone">
                        <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div style="font-size:1.1rem; font-weight:600;">Drag & Drop your CSV file here</div>
                        <div style="margin:8px 0;">or</div>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" class="file-input" required>
                        <label for="csv_file" class="file-label"><i class="fas fa-folder-open"></i> Select File</label>
                        <div class="file-name" id="fileName" style="margin-top:8px; font-size:0.9rem; color:#555;"><?= htmlspecialchars($fileName ?: 'No file selected') ?></div>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <button type="submit" name="submit" class="btn"><i class="fas fa-upload"></i> Import File</button>
                    <div style="font-size:0.8rem; color:#555;">Expected format: 18+ columns. Dates in MM/DD/YYYY.</div>
                </div>
            </form>

            <?php if ($message): ?>
                <div class="message <?= $messageClass === 'success' ? 'success' : 'error' ?>">
                    <i class="fas <?= $messageClass === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['patients'] ?></div>
                        <div class="stat-label">Patients Processed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['tests'] ?></div>
                        <div class="stat-label">Tests Imported</div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['errors'])): ?>
                <div style="margin-top:15px;">
                    <div class="card-title" style="color:#b02a37;"><i class="fas fa-exclamation-triangle"></i> Errors Encountered (<?= count($results['errors']) ?>)</div>
                    <div class="error-list">
                        <?php foreach ($results['errors'] as $error): ?>
                            <div class="error-item">
                                <i class="fas fa-times-circle" style="color:#dc3545; margin-top:3px;"></i>
                                <div><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($fileName): ?>
                <div style="margin-top:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <div class="message" style="background: #eef7fb; border-left:5px solid var(--primary-color);">
                        <i class="fas fa-file-csv" style="color: var(--primary-color);"></i>
                        File processed: <strong style="margin-left:6px;"><?= htmlspecialchars($fileName) ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Return to Dashboard</a>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('csv_file');
        const fileNameDisplay = document.getElementById('fileName');
        const dropZone = document.getElementById('dropZone');

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileNameDisplay.textContent = this.files[0].name;
            } else {
                fileNameDisplay.textContent = 'No file selected';
            }
        });

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt =>
            dropZone.addEventListener(evt, preventDefaults, false)
        );
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(evt =>
            dropZone.addEventListener(evt, () => {
                dropZone.style.borderColor = 'var(--primary-color)';
                dropZone.style.backgroundColor = 'rgba(178, 226, 226, 0.2)';
            }, false)
        );
        ['dragleave', 'drop'].forEach(evt =>
            dropZone.addEventListener(evt, () => {
                dropZone.style.borderColor = 'var(--primary-light)';
                dropZone.style.backgroundColor = 'rgba(178, 226, 226, 0.08)';
            }, false)
        );

        dropZone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                fileInput.files = files;
                fileNameDisplay.textContent = files[0].name;
            }
        });
    </script>
</body>
</html>
