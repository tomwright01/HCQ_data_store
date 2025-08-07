<?php
// Include your DB connection and functions
require_once 'config.php';      // contains $conn setup
require_once 'functions.php';   // contains getOrCreatePatient, insertOrUpdateTest

// Path to your CSV file
$csvFile = 'path/to/your/file.csv';

// Open CSV file
if (!file_exists($csvFile) || !is_readable($csvFile)) {
    die("CSV file not found or not readable");
}

if (($handle = fopen($csvFile, 'r')) === false) {
    die("Failed to open CSV file");
}

$lineNumber = 0;

// Optional: if CSV has header, uncomment this line to skip header
// fgetcsv($handle);

while (($data = fgetcsv($handle)) !== false) {
    $lineNumber++;

    try {
        // [0] Subject ID
        $subjectId = trim($data[0] ?? '');
        if ($subjectId === '') {
            throw new Exception("Missing Subject ID");
        }

        // [1] Date of Birth (MM/DD/YYYY)
        $dobObj = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
        if (!$dobObj) {
            throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL'));
        }
        $dobFormatted = $dobObj->format('Y-m-d');

        // Get or create patient
        $patientId = getOrCreatePatient($conn, $subjectId, $dobFormatted);

        // [2] Date of Test (MM/DD/YYYY)
        $testDateObj = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
        if (!$testDateObj) {
            throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL'));
        }
        $testDateFormatted = $testDateObj->format('Y-m-d');

        // [3] Age (optional, numeric 0-100)
        $age = (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 100) ? (int)$data[3] : null;

        // [4] Test ID (string, fallback to generated)
        $testIdRaw = trim($data[4] ?? '');
        if ($testIdRaw === '') {
            $testIdRaw = 'gen_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        }
        $testId = preg_replace('/\s+/', '_', $testIdRaw);

        // [5] Eye ('OD' or 'OS')
        $eyeValue = strtoupper(trim($data[5] ?? ''));
        $eye = in_array($eyeValue, ['OD', 'OS']) ? $eyeValue : null;
        if ($eye === null) {
            throw new Exception("Invalid eye value '{$data[5]}' - must be 'OD' or 'OS'");
        }

        // [6] Report Diagnosis ('normal', 'abnormal', 'exclude', or default 'no input')
        $reportDiagnosisValue = strtolower(trim($data[6] ?? ''));
        $validReportDiagnosis = ['normal', 'abnormal', 'exclude'];
        $reportDiagnosis = in_array($reportDiagnosisValue, $validReportDiagnosis) ? $reportDiagnosisValue : 'no input';

        // [7] Exclusion ('retinal detachment', 'generalized retinal dysfunction', 'unilateral testing', or default 'none')
        $exclusionValue = strtolower(trim($data[7] ?? ''));
        $validExclusions = ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'];
        $exclusion = in_array($exclusionValue, $validExclusions) ? $exclusionValue : 'none';

        // [8] MERCI Score (int 0-100 or 'unable' or null)
        $merciScoreRaw = strtolower(trim($data[8] ?? ''));
        if ($merciScoreRaw === 'unable') {
            $merciScore = 'unable';
        } elseif (is_numeric($merciScoreRaw) && $merciScoreRaw >= 0 && $merciScoreRaw <= 100) {
            $merciScore = (int)$merciScoreRaw;
        } else {
            $merciScore = null;
        }

        // [9] MERCI Diagnosis ('normal', 'abnormal', or default 'no value')
        $merciDiagnosisRaw = strtolower(trim($data[9] ?? ''));
        $validMerciDiagnosis = ['normal', 'abnormal'];
        $merciDiagnosis = in_array($merciDiagnosisRaw, $validMerciDiagnosis) ? $merciDiagnosisRaw : 'no value';

        // [10] Error Type ('TN','FP','TP','FN','none' or null)
        $errorTypeRaw = strtoupper(trim($data[10] ?? ''));
        $validErrorTypes = ['TN', 'FP', 'TP', 'FN', 'NONE'];
        $errorType = in_array($errorTypeRaw, $validErrorTypes) ? ($errorTypeRaw === 'NONE' ? 'none' : $errorTypeRaw) : null;

        // [11] FAF Grade (int 1-4 or null)
        $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;

        // [12] OCT Score (float or null)
        $octScore = (isset($data[12]) && is_numeric($data[12])) ? round(floatval($data[12]), 2) : null;

        // [13] VF Score (float or null)
        $vfScore = (isset($data[13]) && is_numeric($data[13])) ? round(floatval($data[13]), 2) : null;

        // [14] Actual Diagnosis ('RA','SLE','Sjogren','other')
        $actualDiagnosisRaw = ucfirst(strtolower(trim($data[14] ?? '')));
        $validActualDiagnosis = ['RA', 'SLE', 'Sjogren'];
        $actualDiagnosis = in_array($actualDiagnosisRaw, $validActualDiagnosis) ? $actualDiagnosisRaw : 'other';

        // [15] Dosage (float or null)
        $dosage = (isset($data[15]) && is_numeric($data[15])) ? round(floatval($data[15]), 2) : null;

        // [16] Duration Days (int or null)
        $durationDays = (isset($data[16]) && is_numeric($data[16])) ? (int)$data[16] : null;

        // [17] Cumulative Dosage (float or null)
        $cumulativeDosage = (isset($data[17]) && is_numeric($data[17])) ? round(floatval($data[17]), 2) : null;

        // [18] Date of Continuation (string or null)
        $dateOfContinuationRaw = trim($data[18] ?? '');
        $dateOfContinuation = $dateOfContinuationRaw !== '' ? $dateOfContinuationRaw : null;

        // Build eye data array for DB insert/update
        $eyeData = [
            'eye' => $eye,
            'age' => $age,
            'report_diagnosis' => $reportDiagnosis,
            'exclusion' => $exclusion,
            'merci_score' => $merciScore,
            'merci_diagnosis' => $merciDiagnosis,
            'error_type' => $errorType,
            'faf_grade' => $fafGrade,
            'oct_score' => $octScore,
            'vf_score' => $vfScore,
            'actual_diagnosis' => $actualDiagnosis,
            'medication_name' => null,         // No CSV data, default NULL
            'dosage' => $dosage,
            'dosage_unit' => 'mg',             // Default unit
            'duration_days' => $durationDays,
            'cumulative_dosage' => $cumulativeDosage,
            'date_of_continuation' => $dateOfContinuation,
            'treatment_notes' => null          // No CSV data, default NULL
        ];

        // Default test location
        $location = 'KH';

        // Insert or update test and eye data
        insertOrUpdateTest($conn, $testId, $patientId, $location, $testDateFormatted, $eyeData);

        echo "Line $lineNumber: Imported test $testId for patient $patientId eye $eye\n";

    } catch (Exception $e) {
        echo "Line $lineNumber: Error - " . $e->getMessage() . "\n";
    }
}

fclose($handle);
