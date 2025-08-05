<?php
// csv_import.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

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

                $conn->begin_transaction();
                // Skip header
                fgetcsv($handle, 0, ",", '"', "\0");
                $lineNumber = 1;

                while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
                    $lineNumber++;
                    try {
                        if (count(array_filter($data)) === 0) {
                            continue;
                        }
                        // Expecting 19 columns: 0–18
                        if (count($data) < 19) {
                            throw new Exception("Row has only " . count($data) . " columns (minimum 19 required)");
                        }

                        // Trim & normalize null-like
                        $data = array_map('trim', $data);
                        $data = array_map(function($v) {
                            $v = trim($v ?? '');
                            $low = strtolower($v);
                            return ($v === '' || in_array($low, ['null','no value','missing'], true))
                                ? null
                                : $v;
                        }, $data);

                        // [0] Subject ID
                        $subjectId = $data[0] ?? '';

                        // [1] Date of Birth
                        $dobObj = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                        if (!$dobObj) {
                            throw new Exception("Invalid DoB format '{$data[1]}'");
                        }
                        $dob = $dobObj->format('Y-m-d');

                        // [2] Date of Test
                        $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                        if (!$testDateObj) {
                            throw new Exception("Invalid test date format '{$data[2]}'");
                        }
                        $testDate = $testDateObj->format('Y-m-d');

                        // [3] Age
                        $age = (is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120)
                            ? (int)$data[3]
                            : null;

                        // [4] Test ID (mandatory)
                        $testIdRaw = $data[4] ?? '';
                        if (!$testIdRaw) {
                            throw new Exception("Missing Test ID at column 4");
                        }
                        $testId = preg_replace('/\s+/', '_', $testIdRaw);

                        // [5] Eye
                        $eye = null;
                        if ($data[5]) {
                            $u = strtoupper($data[5]);
                            if (in_array($u, ['OD','OS'], true)) {
                                $eye = $u;
                            }
                        }

                        // [6] Report Diagnosis
                        $reportDiagnosis = 'no input';
                        if ($data[6]) {
                            $lv = strtolower($data[6]);
                            if (in_array($lv, ['normal','abnormal','exclude'], true)) {
                                $reportDiagnosis = $lv;
                            }
                        }

                        // [7] Exclusion
                        $exclusion = 'none';
                        if ($data[7]) {
                            $lv = strtolower($data[7]);
                            if (in_array($lv, ['retinal detachment','generalized retinal dysfunction','unilateral testing'], true)) {
                                $exclusion = $lv;
                            }
                        }

                        // [8] MERCI Score
                        $merciScore = null;
                        if ($data[8] !== null) {
                            if (strtolower($data[8]) === 'unable') {
                                $merciScore = 'unable';
                            } elseif (is_numeric($data[8]) && $data[8] >= 0 && $data[8] <= 100) {
                                $merciScore = (int)$data[8];
                            }
                        }

                        // [9] MERCI Diagnosis
                        $merciDiagnosis = 'no value';
                        if ($data[9]) {
                            $lv = strtolower($data[9]);
                            if (in_array($lv, ['normal','abnormal'], true)) {
                                $merciDiagnosis = $lv;
                            }
                        }

                        // [10] Error Type
                        $errorType = null;
                        if ($data[10]) {
                            $uv = strtoupper($data[10]);
                            if (in_array($uv, ['TN','FP','TP','FN','NONE'], true)) {
                                $errorType = $uv === 'NONE' ? 'none' : $uv;
                            }
                        }

                        // [11] FAF Grade
                        $fafGrade = (is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4)
                            ? (int)$data[11]
                            : null;

                        // [12] OCT Score
                        $octScore = is_numeric($data[12])
                            ? round((float)$data[12], 2)
                            : null;

                        // [13] VF Score
                        $vfScore = is_numeric($data[13])
                            ? round((float)$data[13], 2)
                            : null;

                        // [14] Actual Diagnosis
                        $allowedDiag = ['RA','SLE','SJOGREN','OTHER'];
                        $actualDiagRaw = ucfirst(strtolower($data[14] ?? ''));
                        $actualDiagnosis = in_array($actualDiagRaw, $allowedDiag, true)
                            ? $actualDiagRaw
                            : 'other';

                        // [15] Dosage
                        $dosage = is_numeric($data[15])
                            ? round((float)$data[15], 2)
                            : null;

                        // [16] Duration Days
                        $durationDays = is_numeric($data[16])
                            ? (int)$data[16]
                            : null;

                        // [17] Cumulative Dosage
                        $cumDose = is_numeric($data[17])
                            ? round((float)$data[17], 2)
                            : null;

                        // [18] Date of Discontinuation
                        $discDate = null;
                        if ($data[18]) {
                            $dObj = DateTime::createFromFormat('m/d/Y', $data[18]);
                            if ($dObj) {
                                $discDate = $dObj->format('Y-m-d');
                            } else {
                                throw new Exception("Invalid discontinuation date '{$data[18]}'");
                            }
                        }

                        // upsert patient
                        $location = 'KH';
                        $patientId = getOrCreatePatient($conn, $subjectId, $subjectId, $dob, $location);
                        $results['patients']++;

                        // insert test
                        $testData = [
                            'test_id'              => $testId,
                            'patient_id'           => $patientId,
                            'location'             => $location,
                            'date_of_test'         => $testDate,
                            'age'                  => $age,
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
                            'duration_days'        => $durationDays,
                            'cumulative_dosage'    => $cumDose,
                            'date_of_continuation' => $discDate,
                            'treatment_notes'      => null
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
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>CSV Import Tool</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <style>
    /* (your CSS here) */
  </style>
</head>
<body>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="csv_file" accept=".csv" required>
    <button type="submit">Import</button>
  </form>
  <?php if ($message): ?>
    <div class="<?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <?php if (!empty($results['errors'])): ?>
    <h3>Errors:</h3>
    <ul>
      <?php foreach ($results['errors'] as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</body>
</html>
