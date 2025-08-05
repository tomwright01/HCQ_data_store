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
                        if (count(array_filter($data)) === 0) {
                            continue;
                        }
                        if (count($data) < 17) {
                            throw new Exception("Row has only " . count($data) . " columns (minimum 17 required)");
                        }

                        // Trim & normalize null-like values
                        $data = array_map('trim', $data);
                        $data = array_map(function($v) {
                            $v = trim($v ?? '');
                            $lower = strtolower($v);
                            if ($v === '' || in_array($lower, ['null','no value','missing'], true)) {
                                return null;
                            }
                            return $v;
                        }, $data);

                        // --- COLUMN MAPPING ---
                        // [0] Subject ID
                        $subjectId = $data[0] ?? '';
                        // [1] Date of Birth (MM/DD/YYYY)
                        $dobObj = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                        if (!$dobObj) {
                            throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL'));
                        }
                        $dob = $dobObj->format('Y-m-d');
                        // [2] Date of Test (MM/DD/YYYY)
                        $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                        if (!$testDateObj) {
                            throw new Exception("Invalid date format for Test Date: " . ($data[2] ?? 'NULL'));
                        }
                        $testDate = $testDateObj->format('Y-m-d');
                        // [3] Age
                        $age = (is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 150) 
                            ? (int)$data[3] 
                            : null;
                        // [4] Test ID (provided)
                        $testIdRaw = $data[4];
                        if (empty($testIdRaw)) {
                            $testIdRaw = 'gen_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
                        }
                        $testId = preg_replace('/\s+/', '_', $testIdRaw);
                        // [5] Eye
                        $eye = null;
                        if ($data[5] !== null) {
                            $u = strtoupper($data[5]);
                            if (in_array($u, ['OD','OS'], true)) {
                                $eye = $u;
                            }
                        }
                        // [6] Report Diagnosis
                        $reportDiag = 'no input';
                        if ($data[6] !== null) {
                            $lv = strtolower($data[6]);
                            if (in_array($lv,['normal','abnormal','exclude'],true)) {
                                $reportDiag = $lv;
                            }
                        }
                        // [7] Exclusion
                        $exclusion = 'none';
                        if ($data[7] !== null) {
                            $lv = strtolower($data[7]);
                            if (in_array($lv, ['retinal detachment','generalized retinal dysfunction','unilateral testing'],true)) {
                                $exclusion = $lv;
                            }
                        }
                        // [8] MERCI Score
                        $merciScore = null;
                        if ($data[8] !== null) {
                            if (strtolower($data[8])==='unable') {
                                $merciScore = 'unable';
                            } elseif (is_numeric($data[8]) && $data[8]>=0 && $data[8]<=100) {
                                $merciScore = (int)$data[8];
                            }
                        }
                        // [9] MERCI Diagnosis
                        $merciDiag = 'no value';
                        if ($data[9] !== null) {
                            $lv = strtolower($data[9]);
                            if (in_array($lv,['normal','abnormal'],true)) {
                                $merciDiag = $lv;
                            }
                        }
                        // [10] Error Type
                        $errorType = null;
                        if ($data[10] !== null) {
                            $uv = strtoupper(trim($data[10]));
                            if (in_array($uv,['TN','FP','TP','FN','NONE'],true)) {
                                $errorType = ($uv==='NONE')?'none':$uv;
                            }
                        }
                        // [11] FAF Grade
                        $fafGrade = (is_numeric($data[11]) && $data[11]>=1 && $data[11]<=4)
                            ? (int)$data[11]
                            : null;
                        // [12] Actual Diagnosis
                        $allowedDiag = ['RA','SLE','Sjogren','other'];
                        $actualDiag = 'other';
                        if ($data[12]!==null) {
                            $d = ucfirst(strtolower(trim($data[12])));
                            if (in_array($d,$allowedDiag,true)) {
                                $actualDiag = $d;
                            }
                        }
                        // [13] Dosage
                        $dosage = is_numeric($data[13]) ? round((float)$data[13],2) : null;
                        // [14] Duration Days
                        $duration = is_numeric($data[14]) ? (int)$data[14] : null;
                        // [15] Cumulative Dosage
                        $cumDose = is_numeric($data[15]) ? round((float)$data[15],2) : null;
                        // [16] Date of Discontinuation (MM/DD/YYYY)
                        $discDate = null;
                        if ($data[16]!==null) {
                            $dObj = DateTime::createFromFormat('m/d/Y',$data[16]);
                            if ($dObj) {
                                $discDate = $dObj->format('Y-m-d');
                            } else {
                                $results['errors'][] = "Line $lineNumber: Invalid discontinuation date '{$data[16]}'";
                            }
                        }

                        // --- UPSERT PATIENT ---
                        $locationDefault = 'KH';
                        $patientId = getOrCreatePatient(
                            $conn,
                            $subjectId,
                            $subjectId,
                            $dob,
                            $locationDefault
                        );
                        $results['patients']++;

                        // --- INSERT TEST ---
                        $stmt = $conn->prepare("
                            INSERT INTO tests (
                                test_id, patient_id, location, date_of_test, age, eye,
                                report_diagnosis, exclusion, merci_score, merci_diagnosis,
                                error_type, faf_grade, actual_diagnosis,
                                dosage, duration_days, cumulative_dosage, date_of_discontinuation
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        $stmt->bind_param(
                            "ssssisississssidd",
                            $testId,
                            $patientId,
                            $locationDefault,
                            $testDate,
                            $age,
                            $eye,
                            $reportDiag,
                            $exclusion,
                            $merciScore,
                            $merciDiag,
                            $errorType,
                            $fafGrade,
                            $actualDiag,
                            $dosage,
                            $duration,
                            $cumDose,
                            $discDate
                        );
                        if (!$stmt->execute()) {
                            throw new Exception("DB insert failed: " . $stmt->error);
                        }
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
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>CSV Import Tool</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <!-- your existing CSS here -->
</head>
<body>
  <div>
    <h1>CSV Import Tool</h1>
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="csv_file" accept=".csv" required>
      <button type="submit">Import</button>
    </form>

    <?php if ($message): ?>
      <div class="<?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
      <p>Patients: <?= $results['patients'] ?> | Tests: <?= $results['tests'] ?></p>
    <?php endif; ?>

    <?php if (!empty($results['errors'])): ?>
      <h2>Errors (<?= count($results['errors']) ?>)</h2>
      <ul>
        <?php foreach ($results['errors'] as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</body>
</html>
