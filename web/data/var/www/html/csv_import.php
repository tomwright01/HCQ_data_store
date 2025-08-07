<?php
require_once 'config.php';
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (!file_exists($file)) {
        die("File not found.");
    }

    $results = [
        'inserted' => 0,
        'errors' => [],
        'patients' => 0
    ];

    if (($handle = fopen($file, 'r')) !== false) {
        $lineNumber = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if ($lineNumber === 1) continue; // Skip header

            if (count($data) < 18) {
                $results['errors'][] = "Line $lineNumber: Not enough columns.";
                continue;
            }

            $data = array_map('trim', $data);
            $data = array_map(function ($v) {
                $v = trim($v ?? '');
                return in_array(strtolower($v), ['null', 'no value', 'missing', '']) ? null : $v;
            }, $data);

            try {
                $subjectId = $data[0];
                $dobObj = DateTime::createFromFormat('m/d/Y', $data[1]);
                if (!$dobObj) throw new Exception("Invalid DoB format");
                $dobFormatted = $dobObj->format('Y-m-d');

                $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2]);
                if (!$testDateObj) throw new Exception("Invalid test date format");
                $testDateFormatted = $testDateObj->format('Y-m-d');

                $age = (is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) ? (int)$data[3] : null;

                $testIdRaw = $data[4] ?? '';
                $testId = $testIdRaw !== '' ? preg_replace('/\s+/', '_', $testIdRaw) : 'gen_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

                $eyeValue = strtoupper($data[5] ?? '');
                $eye = in_array($eyeValue, ['OD', 'OS']) ? $eyeValue : null;

                $reportDiagnosis = in_array(strtolower($data[6]), ['normal', 'abnormal', 'exclude']) ? strtolower($data[6]) : 'no input';

                $exclusion = in_array(strtolower($data[7]), ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing']) ? strtolower($data[7]) : 'none';

                $merciScore = is_numeric($data[8]) && $data[8] >= 0 && $data[8] <= 100 ? (int)$data[8] : (strtolower($data[8]) === 'unable' ? 'unable' : null);

                $merciDiagnosis = in_array(strtolower($data[9]), ['normal', 'abnormal']) ? strtolower($data[9]) : 'no value';

                $errorType = in_array(strtoupper($data[10]), ['TN', 'FP', 'TP', 'FN', 'NONE']) ? strtolower($data[10]) : null;

                $fafGrade = (is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                $octScore = is_numeric($data[12]) ? round((float)$data[12], 2) : null;
                $vfScore = is_numeric($data[13]) ? round((float)$data[13], 2) : null;

                $actualDiagnosis = in_array(ucfirst(strtolower($data[14])), ['RA', 'SLE', 'Sjogren', 'Other']) ? ucfirst(strtolower($data[14])) : 'Other';

                $dosage = is_numeric($data[15]) ? round((float)$data[15], 2) : null;
                $durationDays = is_numeric($data[16]) ? (int)$data[16] : null;
                $cumulativeDosage = is_numeric($data[17]) ? round((float)$data[17], 2) : null;

                $dateOfContinuation = null;
                if (!empty($data[18])) {
                    $contObj = DateTime::createFromFormat('m/d/Y', $data[18]);
                    if ($contObj) {
                        $dateOfContinuation = $contObj->format('Y-m-d');
                    }
                }

                $location = 'KH'; // Default location
                $patientId = getOrCreatePatient($conn, $subjectId, $subjectId, $dobFormatted, $location);
                $results['patients']++;

                $testData = [
                    'test_id'              => $testId,
                    'patient_id'           => $patientId,
                    'location'             => $location,
                    'date_of_test'         => $testDateFormatted,
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
                    'date_of_continuation' => $dateOfContinuation,
                    'treatment_notes'      => null
                ];

                $testDbId = insertTest($conn, $testData);
                $results['inserted']++;

                if ($eye) {
                    insertTestEye($conn, $testDbId, $eye);
                }

                insertAuditLog($conn, $testId, "Imported test for subject $subjectId");

            } catch (Exception $e) {
                $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
            }
        }

        fclose($handle);
    }

    // Redirect or display results
    echo "<pre>";
    print_r($results);
    echo "</pre>";
} else {
    echo "No file uploaded.";
}
