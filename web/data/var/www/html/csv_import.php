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
    $fileNameClean = preg_replace("/[^A-Za-z0-9 \.-_]/", '', $fileName);
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
                if (!is_readable($destPath)) throw new Exception("CSV file not readable at: $destPath");
                $handle = fopen($destPath, 'r');
                if ($handle === FALSE) throw new Exception("Could not open CSV file");

                // Begin transaction
                $conn->begin_transaction();

                // Skip header row
                fgetcsv($handle, 0, ",");
                $lineNumber = 1;

                while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                    $lineNumber++;
                    try {
                        // skip empty
                        if (count(array_filter($data)) === 0) continue;
                        if (count($data) < 19) {
                            throw new Exception("Row has only " . count($data) . " columns (minimum 19 required)");
                        }
                        // trim & normalize
                        $data = array_map('trim', $data);
                        $data = array_map(function($v) {
                            $v = trim($v ?? '');
                            $low = strtolower($v);
                            if ($v === '' || in_array($low, ['null','no value','missing'], true)) return null;
                            return $v;
                        }, $data);

                        // map fields
                        $subjectId          = $data[0] ?? '';
                        $dobObj             = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                        if (!$dobObj) throw new Exception("Invalid DoB: " . ($data[1] ?? '')); 
                        $dobFormatted       = $dobObj->format('Y-m-d');
                        $testDateObj        = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                        if (!$testDateObj) throw new Exception("Invalid test date: " . ($data[2] ?? '')); 
                        $testDate           = $testDateObj->format('Y-m-d');
                        $age                = is_numeric($data[3]) ? (int)$data[3] : null;
                        $testIdRaw          = trim($data[4] ?? '');
                        if ($testIdRaw === '') {
                            $testIdRaw = 'gen_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
                        }
                        $testId             = preg_replace('/\s+/', '_', $testIdRaw);
                        $eye                = null;
                        if ($data[5] !== null) {
                            $e = strtoupper($data[5]);
                            if (in_array($e, ['OD','OS'], true)) $eye = $e;
                        }
                        $reportDiagnosis    = 'no input';
                        if ($data[6] !== null) {
                            $rd = strtolower($data[6]);
                            if (in_array($rd, ['normal','abnormal','exclude'], true)) $reportDiagnosis = $rd;
                        }
                        $exclusion          = 'none';
                        if ($data[7] !== null) {
                            $ex = strtolower($data[7]);
                            if (in_array($ex, ['retinal detachment','generalized retinal dysfunction','unilateral testing'], true)) $exclusion = $ex;
                        }
                        $merciScore         = null;
                        if ($data[8] !== null) {
                            if (strtolower($data[8])==='unable') $merciScore = 'unable';
                            elseif (is_numeric($data[8])) $merciScore = (int)$data[8];
                        }
                        $merciDiagnosis     = 'no value';
                        if ($data[9] !== null) {
                            $md = strtolower($data[9]);
                            if (in_array($md, ['normal','abnormal'], true)) $merciDiagnosis = $md;
                        }
                        $errorType          = null;
                        if (!empty($data[10])) {
                            $et = strtoupper($data[10]);
                            if (in_array($et, ['TN','FP','TP','FN','NONE'], true)) $errorType = $et==='NONE'?'none':$et;
                        }
                        $fafGrade           = is_numeric($data[11])? (int)$data[11] : null;
                        $octScore           = is_numeric($data[12])? round((float)$data[12], 2) : null;
                        $vfScore            = is_numeric($data[13])? round((float)$data[13], 2) : null;
                        $actualDiagnosis    = 'other';
                        if (!empty($data[14])) {
                            $ad = ucfirst(strtolower($data[14]));
                            if (in_array($ad, ['RA','SLE','Sjogren','other'], true)) $actualDiagnosis = $ad;
                        }
                        $dosage             = is_numeric($data[15])? round((float)$data[15], 2):null;
                        $durationDays       = is_numeric($data[16])? (int)$data[16]:null;
                        $cumulativeDosage   = is_numeric($data[17])? round((float)$data[17], 2):null;
                        $dateDisc           = null;
                        if ($data[18] !== null) {
                            $dd = DateTime::createFromFormat('m/d/Y', $data[18]);
                            if ($dd) $dateDisc = $dd->format('Y-m-d');
                        }

                        // upsert patient
                        $patientId = getOrCreatePatient($conn, $subjectId, $subjectId, $dobFormatted, 'KH');
                        $results['patients']++;

                        // insert test
                        $testData = [
                            'test_id'              => $testId,
                            'patient_id'           => $patientId,
                            'location'             => 'KH',
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
                            'medication_name'      => null,
                            'dosage'               => $dosage,
                            'dosage_unit'          => 'mg',
                            'duration_days'        => $durationDays,
                            'cumulative_dosage'    => $cumulativeDosage,
                            'date_of_continuation' => $dateDisc,
                            'treatment_notes'      => null
                        ];
                        insertTest($conn, $testData);
                        $results['tests']++;

                    } catch (Exception $e) {
                        $results['errors'][] = "Line {$lineNumber}: " . $e->getMessage();
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
                if ($conn->in_transaction) $conn->rollback();
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
    <style>/* ... your existing CSS unchanged ... */</style>
</head>
<body>
<!-- ... rest of your original HTML ... -->
</body>
</html>
