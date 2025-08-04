
<?php
// Enable error reporting (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

// Upload directory
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Initialize
$message      = '';
$messageClass = '';
$results      = [
    'patients' => 0,
    'tests'    => 0,
    'errors'   => []
];
$fileName = '';

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $fileTmpPath   = $_FILES['csv_file']['tmp_name'];
    $fileName      = $_FILES['csv_file']['name'];
    $fileNameClean = preg_replace("/[^A-Za-z0-9 \.\-_]/", '', $fileName);
    $fileExt       = strtolower(pathinfo($fileNameClean, PATHINFO_EXTENSION));

    if ($fileExt !== 'csv') {
        $message      = "Only CSV files are allowed.";
        $messageClass = 'error';
    } else {
        $newFileName = uniqid('import_', true) . '.' . $fileExt;
        $destPath    = $uploadDir . $newFileName;

        if (!move_uploaded_file($fileTmpPath, $destPath)) {
            $message      = "Failed to move uploaded file.";
            $messageClass = 'error';
        } else {
            try {
                if (!file_exists($destPath) || !is_readable($destPath)) {
                    throw new Exception("CSV file not readable at: $destPath");
                }
                if (($handle = fopen($destPath, "r")) === FALSE) {
                    throw new Exception("Could not open CSV file");
                }

                // Begin transaction
                $conn->begin_transaction();

                // Skip header row
                fgetcsv($handle, 0, ",", '"', "\0");
                $lineNumber = 1;

                while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
                    $lineNumber++;
                    try {
                        // Skip blank rows
                        if (count(array_filter($data)) === 0) continue;
                        if (count($data) < 18) {
                            throw new Exception("Row has only " . count($data) . " columns (minimum 18 required)");
                        }

                        // Trim & normalize null-like values
                        $data = array_map('trim', $data);
                        $data = array_map(function($v) {
                            $v = trim($v ?? '');
                            $lower = strtolower($v);
                            if ($v === '' || in_array($lower, ['null', 'no value', 'missing'])) {
                                return null;
                            }
                            return $v;
                        }, $data);

                        // [0] Subject ID
                        $subjectId = $data[0] ?? '';

                        // [1] Date of Birth (MM/DD/YYYY)
                        $dobObj = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                        if (!$dobObj) {
                            throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL') . " - Expected MM/DD/YYYY");
                        }
                        $dobFormatted = $dobObj->format('Y-m-d');

                        // [2] Date of Test (MM/DD/YYYY)
                        $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                        if (!$testDateObj) {
                            throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL') . " - Expected MM/DD/YYYY");
                        }

                        // [3] Test ID (provided)
                        $testIdRaw = $data[3] ?? '';
                        if (empty($testIdRaw)) {
                            throw new Exception("Test ID is required");
                        }
                        $testId = preg_replace('/\s+/', '_', $testIdRaw);

                        // [4] Eye
                        $eyeValue = $data[4] ?? null;
                        $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                        // Default location
                        $location = 'KH';

                        // Patient upsert
                        $patientId = getOrCreatePatient($conn, $subjectId, $subjectId, $dobFormatted, $location);
                        $results['patients']++;

                        // [5] Report Diagnosis
                        $reportDiagnosisValue = $data[5] ?? null;
                        $reportDiagnosis = 'no input';
                        if ($reportDiagnosisValue !== null) {
                            $lv = strtolower($reportDiagnosisValue);
                            if (in_array($lv, ['normal', 'abnormal', 'exclude'])) {
                                $reportDiagnosis = $lv;
                            }
                        }

                        // [6] Exclusion
                        $exclusionValue = $data[6] ?? null;
                        $exclusion = 'none';
                        if ($exclusionValue !== null) {
                            $lv = strtolower($exclusionValue);
                            if (in_array($lv, ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) {
                                $exclusion = $lv;
                            }
                        }

                        // [7] MERCI Score
                        $merciScoreValue = $data[7] ?? null;
                        $merciScore = null;
                        if (isset($merciScoreValue)) {
                            if (strtolower($merciScoreValue) === 'unable') {
                                $merciScore = 'unable';
                            } elseif (is_numeric($merciScoreValue) && $merciScoreValue >= 0 && $merciScoreValue <= 100) {
                                $merciScore = (int)$merciScoreValue;
                            }
                        }

                        // [8] MERCI Diagnosis
                        $merciDiagnosisValue = $data[8] ?? null;
                        $merciDiagnosis = 'no value';
                        if ($merciDiagnosisValue !== null) {
                            $lv = strtolower($merciDiagnosisValue);
                            if (in_array($lv, ['normal', 'abnormal'])) {
                                $merciDiagnosis = $lv;
                            }
                        }

                        // [9] Error Type
                        $errorTypeValue = $data[9] ?? null;
                        $allowedErrorTypes = ['TN', 'FP', 'TP', 'FN', 'none'];
                        $errorType = null;
                        if ($errorTypeValue !== null && $errorTypeValue !== '') {
                            $uv = strtoupper(trim($errorTypeValue));
                            if (in_array($uv, $allowedErrorTypes)) {
                                $errorType = ($uv === 'NONE') ? 'none' : $uv;
                            } else {
                                $results['errors'][] = "Line $lineNumber: Invalid error_type '{$errorTypeValue}' - set to NULL";
                            }
                        }

                        // [10] FAF Grade
                        $fafGrade = (isset($data[10]) && is_numeric($data[10]) && $data[10] >= 1 && $data[10] <= 4)
                            ? (int)$data[10]
                            : null;

                        // [11] OCT Score
                        $octScore = isset($data[11]) && is_numeric($data[11])
                            ? round(floatval($data[11]), 2)
                            : null;

                        // [12] VF Score
                        $vfScore = isset($data[12]) && is_numeric($data[12])
                            ? round(floatval($data[12]), 2)
                            : null;

                        // [13] Actual Diagnosis
                        $allowedDiagnosis = ['RA', 'SLE', 'Sjogren', 'other'];
                        $actualDiagnosis = null;
                        if (!empty($data[13])) {
                            $d = ucfirst(strtolower(trim($data[13])));
                            $actualDiagnosis = in_array($d, $allowedDiagnosis) ? $d : 'other';
                        }

                        // [14] Dosage
                        $dosage = isset($data[14]) && is_numeric($data[14])
                            ? round(floatval($data[14]), 2)
                            : null;

                        // [15] Duration Days
                        $durationDays = isset($data[15]) && is_numeric($data[15])
                            ? (int)$data[15]
                            : null;

                        // [16] Cumulative Dosage
                        $cumulativeDosage = isset($data[16]) && is_numeric($data[16])
                            ? round(floatval($data[16]), 2)
                            : null;

                        // [17] Date of Continuation (MM/DD/YYYY)
                        $dateOfContinuationValue = $data[17] ?? null;
                        $date_of_continuation = null;
                        if ($dateOfContinuationValue !== null) {
                            $contObj = DateTime::createFromFormat('m/d/Y', $dateOfContinuationValue);
                            if ($contObj) {
                                $date_of_continuation = $contObj->format('Y-m-d');
                            } else {
                                $results['errors'][] = "Line $lineNumber: Invalid date_of_continuation format '{$dateOfContinuationValue}' - expected MM/DD/YYYY";
                            }
                        }

                        // Build test data
                        $testData = [
                            'test_id'              => $testId,
                            'patient_id'           => $patientId,
                            'location'             => $location,
                            'date_of_test'         => $testDateObj->format('Y-m-d'),
                            'age'                  => null,
                            'eye'                  => $eye,
                            'report_diagnosis'     => $reportDiagnosis,
                            'exclusion'            => $exclusion,
                            'merci_score'          => $merciScore,
                            'merci_diagnosis'      => $merciDiagnosis,
                            'error_type'           => $errorType,
                            'faf_grade'            => $fafGrade,
                            'oct_score'            => $octScore,
                            'vf_score'             => $vfScore,
                            'actual_diagnosis'     => $actualDiagnosis,
                            'dosage'               => $dosage,
                            'dosage_unit'          => 'mg',
                            'duration_days'        => $durationDays,
                            'cumulative_dosage'    => $cumulativeDosage,
                            'date_of_continuation' => $date_of_continuation
                        ];

                        // Insert or update
                        insertTest($testData);
                        $results['tests']++;
                    } catch (Exception $e) {
                        $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                    }
                }

                fclose($handle);

                // Commit or rollback
                if (empty($results['errors'])) {
                    $conn->commit();
                    $message      = "Successfully processed {$results['patients']} patients and {$results['tests']} tests.";
                    $messageClass = 'success';
                } else {
                    $conn->rollback();
                    $message      = "Completed with " . count($results['errors']) . " errors. No data was imported.";
                    $messageClass = 'error';
                }
            } catch (Exception $e) {
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
                $message      = "Fatal error: " . $e->getMessage();
                $messageClass = 'error';
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
        * { box-sizing: border-box; margin:0; padding:0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg,#f0f4f9,#ffffff);
            color: var(--text-color);
        }
        header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 25px 15px; color: white;
            display: flex; align-items: center; gap: 15px; flex-wrap: wrap;
        }
        .header-content { display:flex; align-items:center; gap:15px; flex:1; }
        .logo { height:50px; }
        h1 { margin:0; font-size:2.2rem; }
        .container { max-width:1250px; margin:30px auto 60px; padding:15px; }
        .card {
            background:white; border-radius:var(--radius); padding:25px; margin-bottom:30px;
            box-shadow:0 20px 50px -10px rgba(0,0,0,0.08); border:1px solid rgba(0,0,0,0.05);
        }
        .card-title {
            display:flex; align-items:center; gap:10px; font-size:1.5rem;
            margin-bottom:15px; color:var(--primary-color);
        }
        .upload-area {
            border:2px dashed var(--primary-light); border-radius:8px;
            padding:35px; text-align:center; transition:all .3s;
            background-color:rgba(178,226,226,0.08); margin-bottom:20px;
        }
        .upload-area:hover {
            border-color:var(--primary-color);
            background-color:rgba(178,226,226,0.15);
        }
        .upload-icon { font-size:3.5rem; color:var(--primary-color); margin-bottom:10px; }
        .file-input { display:none; }
        .file-label {
            display:inline-block; padding:12px 24px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color:white; border-radius:6px; cursor:pointer; font-weight:600;
        }
        .file-name { margin-top:8px; font-size:0.9rem; color:#555; }
        .btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color:white; padding:14px 30px; border:none; border-radius:8px;
            cursor:pointer; font-size:1rem; font-weight:600; display:inline-flex;
            align-items:center; gap:8px; transition:all .25s;
        }
        .btn:hover { filter:brightness(1.05); }
        .message { border-radius:8px; padding:15px 18px; display:flex; gap:12px; align-items:center; margin-bottom:12px; }
        .success { background:rgba(40,167,69,0.1); border-left:5px solid #28a745; color:#155724; }
        .error { background:rgba(220,53,69,0.1); border-left:5px solid #dc3545; color:#721c24; }
        .stats-cards {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:20px; margin:18px 0 10px;
        }
        .stat-card {
            background:#f9fcfd; border-radius:8px; padding:18px; text-align:center;
            border:1px solid rgba(0,168,143,0.15);
        }
        .stat-value { font-size:2.4rem; font-weight:700; color:var(--primary-dark); }
        .stat-label { font-size:0.75rem; letter-spacing:1px; text-transform:uppercase; color:#555; }
        .error-list {
            margin-top:12px; border:1px solid #e4e8ed; border-radius:6px;
            max-height:260px; overflow:auto; background:white;
        }
        .error-item {
            padding:12px 14px; border-bottom:1px solid #e9ecf2;
            display:flex; gap:10px; align-items:flex-start; font-size:0.9rem;
        }
        .back-link {
            display:inline-flex; align-items:center; gap:6px;
            color:var(--primary-dark); text-decoration:none; font-weight:600; margin-top:10px;
        }
        .back-link i { font-size:1rem; }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <img src="images/kensington-logo-white.png" alt="Logo" class="logo">
            <h1>CSV Import Tool</h1>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <div class="card-title"><i class="fas fa-file-import"></i> Import Patient &amp; Test Data</div>
            <form method="post" enctype="multipart/form-data" id="importForm" style="display:flex; flex-wrap:wrap; gap:20px; align-items:center;">
                <div style="flex:1; min-width:250px;">
                    <div class="upload-area" id="dropZone">
                        <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div style="font-size:1.1rem; font-weight:600;">Drag & Drop your CSV file here</div>
                        <div style="margin:8px 0;">or</div>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" class="file-input" required>
                        <label for="csv_file" class="file-label"><i class="fas fa-folder-open"></i> Select File</label>
                        <div class="file-name" id="fileName"><?= htmlspecialchars($fileName ?: 'No file selected') ?></div>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <button type="submit" class="btn"><i class="fas fa-upload"></i> Import File</button>
                    <div style="font-size:0.8rem; color:#555;">
                        Expected order: subject id, DoB (MM/DD/YYYY), test date (MM/DD/YYYY), test ID, eye, report diagnosis, exclusion, MERCI score, MERCI diagnosis, error type, FAF grade, OCT score, VF score, actual diagnosis, dosage, duration, cumulative dosage, date of continuation.
                    </div>
                </div>
            </form>

            <?php if ($message): ?>
                <div class="message <?= $messageClass ?>">
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

            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Return to Dashboard</a>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('csv_file');
        const fileNameDisplay = document.getElementById('fileName');
        const dropZone = document.getElementById('dropZone');

        fileInput.addEventListener('change', function() {
            fileNameDisplay.textContent = this.files.length ? this.files[0].name : 'No file selected';
        });

        ['dragenter','dragover','dragleave','drop'].forEach(evt =>
            dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); })
        );

        ['dragenter','dragover'].forEach(evt =>
            dropZone.addEventListener(evt, () => {
                dropZone.style.borderColor = 'var(--primary-color)';
                dropZone.style.backgroundColor = 'rgba(178,226,226,0.2)';
            })
        );

        ['dragleave','drop'].forEach(evt =>
            dropZone.addEventListener(evt, () => {
                dropZone.style.borderColor = 'var(--primary-light)';
                dropZone.style.backgroundColor = 'rgba(178,226,226,0.08)';
            })
        );

        dropZone.addEventListener('drop', function(e) {
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files;
                fileNameDisplay.textContent = files[0].name;
            }
        });
    </script>
</body>
</html>

