<?php
// csv_import.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Upload directory
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message      = '';
$messageClass = '';
$results      = [
    'patients' => 0,
    'tests'    => 0,
    'errors'   => []
];
$fileName = '';

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
                if (!is_readable($destPath)) {
                    throw new Exception("CSV file not readable at: $destPath");
                }
                $handle = fopen($destPath, "r");
                if ($handle === FALSE) {
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

                        // Trim and normalize null-like values
                        $data = array_map('trim', $data);
                        $data = array_map(function($v) {
                            $v = trim($v ?? '');
                            $lower = strtolower($v);
                            if ($v === '' || in_array($lower, ['null','no value','missing'])) {
                                return null;
                            }
                            return $v;
                        }, $data);

                        // [0] Subject ID
                        $subjectId = $data[0];

                        // [1] Date of Birth
                        $dobObj = DateTime::createFromFormat('m/d/Y', $data[1])
                               ?: DateTime::createFromFormat('Y-m-d', $data[1]);
                        if (!$dobObj) {
                            throw new Exception("Invalid DoB format: '{$data[1]}'");
                        }
                        $dobFormatted = $dobObj->format('Y-m-d');

                        // [2] Date of Test
                        $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2])
                                   ?: DateTime::createFromFormat('Y-m-d', $data[2]);
                        if (!$testDateObj) {
                            throw new Exception("Invalid test date format: '{$data[2]}'");
                        }
                        $dateOfTest = $testDateObj->format('Y-m-d');

                        // [3] Test ID (as provided)
                        $testIdRaw = $data[3] ?? '';
                        if ($testIdRaw === null || $testIdRaw === '') {
                            throw new Exception("Missing Test ID at column 4");
                        }
                        $testId = preg_replace('/\s+/', '_', trim($testIdRaw));

                        // [4] Eye
                        $eyeVal = strtoupper($data[4] ?? '');
                        $eye = in_array($eyeVal, ['OD','OS'], true) ? $eyeVal : null;

                        // default location
                        $location = 'KH';

                        // Upsert patient
                        $patientId = getOrCreatePatient($conn, $subjectId, $subjectId, $dobFormatted, $location);
                        $results['patients']++;

                        // [5] Report Diagnosis
                        $reportDiagnosis = 'no input';
                        if ($data[5] !== null) {
                            $v = strtolower($data[5]);
                            if (in_array($v, ['normal','abnormal','exclude'], true)) {
                                $reportDiagnosis = $v;
                            }
                        }

                        // [6] Exclusion
                        $exclusion = 'none';
                        if ($data[6] !== null) {
                            $v = strtolower($data[6]);
                            if (in_array($v, ['retinal detachment','generalized retinal dysfunction','unilateral testing'], true)) {
                                $exclusion = $v;
                            }
                        }

                        // [7] MERCI Score
                        $merciScore = null;
                        if ($data[7] !== null) {
                            if (strcasecmp($data[7],'unable') === 0) {
                                $merciScore = 'unable';
                            } elseif (is_numeric($data[7])) {
                                $merciScore = (int)$data[7];
                            }
                        }

                        // [8] MERCI Diagnosis
                        $merciDiagnosis = 'no value';
                        if ($data[8] !== null) {
                            $v = strtolower($data[8]);
                            if (in_array($v, ['normal','abnormal'], true)) {
                                $merciDiagnosis = $v;
                            }
                        }

                        // [9] Error Type
                        $errorType = null;
                        if ($data[9] !== null) {
                            $v = strtoupper($data[9]);
                            if (in_array($v, ['TN','FP','TP','FN','NONE'], true)) {
                                $errorType = ($v === 'NONE') ? 'none' : $v;
                            }
                        }

                        // [10] FAF Grade
                        $fafGrade = (is_numeric($data[10]) && $data[10] >= 1 && $data[10] <= 4)
                            ? (int)$data[10]
                            : null;

                        // [11] OCT Score
                        $octScore = is_numeric($data[11]) ? round((float)$data[11],2) : null;

                        // [12] VF Score
                        $vfScore = is_numeric($data[12]) ? round((float)$data[12],2) : null;

                        // [13] Actual Diagnosis
                        $actualDiagnosis = null;
                        if ($data[13] !== null) {
                            $d = ucfirst(strtolower($data[13]));
                            $allowed = ['RA','SLE','Sjogren','other'];
                            $actualDiagnosis = in_array($d,$allowed,true) ? $d : 'other';
                        }

                        // [14] Dosage
                        $dosage = is_numeric($data[14]) ? round((float)$data[14],2) : null;

                        // [15] Duration (days)
                        $durationDays = is_numeric($data[15]) ? (int)$data[15] : null;

                        // [16] Cumulative Dosage
                        $cumulativeDosage = is_numeric($data[16]) ? round((float)$data[16],2) : null;

                        // [17] Date of Continuation
                        $dateOfContRaw = $data[17];
                        $dateOfContinuation = null;
                        if ($dateOfContRaw !== null && $dateOfContRaw !== '') {
                            $contObj = DateTime::createFromFormat('m/d/Y', $dateOfContRaw)
                                     ?: DateTime::createFromFormat('Y-m-d', $dateOfContRaw);
                            if ($contObj) {
                                $dateOfContinuation = $contObj->format('Y-m-d');
                            } else {
                                $results['errors'][] = "Line $lineNumber: invalid continuation date '{$dateOfContRaw}'";
                            }
                        }

                        // Build and insert
                        $testData = [
                            'test_id'              => $testId,
                            'patient_id'           => $patientId,
                            'location'             => $location,
                            'date_of_test'         => $dateOfTest,
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
                            'medication_name'      => null,
                            'dosage'               => $dosage,
                            'dosage_unit'          => 'mg',
                            'duration_days'        => $durationDays,
                            'cumulative_dosage'    => $cumulativeDosage,
                            'date_of_continuation' => $dateOfContinuation,
                            'treatment_notes'      => null
                        ];
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
        :root { --primary-color:rgb(0,168,143); --primary-dark:rgb(0,140,120); --primary-light:rgb(178,226,226); --text-color:#212529; --radius:10px; }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#f0f4f9,#fff);color:var(--text-color);}
        header{background:linear-gradient(135deg,var(--primary-color),var(--primary-dark));padding:25px;color:white;display:flex;align-items:center;gap:15px;flex-wrap:wrap;}
        .header-content{display:flex;align-items:center;gap:15px;flex:1;}
        .logo{height:50px;}
        h1{margin:0;font-size:2.2rem;}
        .container{max-width:1250px;margin:30px auto 60px;padding:15px;}
        .card{background:white;border-radius:var(--radius);padding:25px;margin-bottom:30px;box-shadow:0 20px 50px -10px rgba(0,0,0,0.08);border:1px solid rgba(0,0,0,0.05);}
        .card-title{display:flex;align-items:center;gap:10px;font-size:1.5rem;margin-bottom:15px;color:var(--primary-color);}
        .upload-area{border:2px dashed var(--primary-light);border-radius:8px;padding:35px;text-align:center;background-color:rgba(178,226,226,0.08);margin-bottom:20px;transition:all .3s;}
        .upload-area:hover{border-color:var(--primary-color);background-color:rgba(178,226,226,0.15);}
        .upload-icon{font-size:3.5rem;color:var(--primary-color);margin-bottom:10px;}
        .file-input{display:none;}
        .file-label{display:inline-block;padding:12px 24px;background:linear-gradient(135deg,var(--primary-color),var(--primary-dark));color:white;border-radius:6px;cursor:pointer;font-weight:600;}
        .file-name{margin-top:8px;font-size:0.9rem;color:#555;}
        .btn{background:linear-gradient(135deg,var(--primary-color),var(--primary-dark));color:white;padding:14px 30px;border:none;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:600;display:inline-flex;align-items:center;gap:8px;transition:all .25s;}
        .btn:hover{filter:brightness(1.05);}
        .message{border-radius:8px;padding:15px 18px;display:flex;gap:12px;align-items:center;margin-bottom:12px;}
        .success{background:rgba(40,167,69,0.1);border-left:5px solid #28a745;color:#155724;}
        .error{background:rgba(220,53,69,0.1);border-left:5px solid #dc3545;color:#721c24;}
        .stats-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin:18px 0 10px;}
        .stat-card{background:#f9fcfd;border-radius:8px;padding:18px;text-align:center;border:1px solid rgba(0,168,143,0.15);}
        .stat-value{font-size:2.4rem;font-weight:700;color:var(--primary-dark);}
        .stat-label{font-size:0.75rem;letter-spacing:1px;text-transform:uppercase;color:#555;}
        .error-list{margin-top:12px;border:1px solid #e4e8ed;border-radius:6px;max-height:260px;overflow:auto;background:white;}
        .error-item{padding:12px 14px;border-bottom:1px solid #eee;display:flex;gap:8px;}
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
            <form method="post" enctype="multipart/form-data" id="importForm" style="display:flex;flex-wrap:wrap;gap:20px;align-items:center;">
                <div style="flex:1;min-width:250px;">
                    <div class="upload-area" id="dropZone">
                        <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" class="file-input" required>
                        <label for="csv_file" class="file-label"><i class="fas fa-folder-open"></i> Select File</label>
                        <div class="file-name" id="fileName"><?= htmlspecialchars($fileName ?: 'No file selected') ?></div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <button type="submit" class="btn"><i class="fas fa-upload"></i> Import File</button>
                    <div style="font-size:0.8rem;color:#555;">
                        Expected order: subject id, DoB, test date, test ID, eye, report diagnosis, exclusion, MERCI score, MERCI diagnosis, error type, FAF grade, OCT score, VF score, actual diagnosis, dosage, duration, cumulative dosage, date of continuation.
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
                                <i class="fas fa-times-circle" style="color:#dc3545;"></i>
                                <div><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <a href="index.php" class="btn" style="margin-top:20px;"><i class="fas fa-arrow-left"></i> Return to Dashboard</a>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('csv_file');
        const fileNameDisplay = document.getElementById('fileName');
        const dropZone = document.getElementById('dropZone');

        fileInput.addEventListener('change', () => {
            fileNameDisplay.textContent = fileInput.files.length ? fileInput.files[0].name : 'No file selected';
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

        dropZone.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files;
                fileNameDisplay.textContent = files[0].name;
            }
        });
    </script>
</body>
</html>
