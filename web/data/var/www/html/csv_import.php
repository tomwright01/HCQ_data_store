<?php
// Enable error reporting.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

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

// ----------------------
// Normalization helpers
// ----------------------
function normalize_actual_diagnosis($raw) {
    $map = [
        'ra' => 'RA',
        'sle' => 'SLE',
        'sjogren' => 'Sjogren',
        'sjögren' => 'Sjogren',
        'sjorgens' => 'Sjogren' // tolerate legacy spelling
    ];
    $k = strtolower(trim($raw ?? ''));
    return $map[$k] ?? null;
}

function normalize_exclusion($raw) {
    $allowed = [
        'retinal detachment' => 'retinal detachment',
        'generalized retinal dysfunction' => 'generalized retinal dysfunction',
        'unilateral testing' => 'unilateral testing',
        'none' => 'none'
    ];
    $k = strtolower(trim($raw ?? ''));
    return $allowed[$k] ?? 'none';
}

function normalize_report_diagnosis($raw) {
    $allowed = ['normal', 'abnormal', 'no input'];
    $k = strtolower(trim($raw ?? ''));
    return in_array($k, $allowed) ? $k : 'no input';
}

function normalize_merci_diagnosis($raw) {
    $allowed = ['normal', 'abnormal'];
    $k = strtolower(trim($raw ?? ''));
    return in_array($k, $allowed) ? $k : 'no value';
}

function normalize_error_type($raw) {
    $allowed = ['TN', 'FP', 'TP', 'FN', 'none'];
    $u = strtoupper(trim($raw ?? ''));
    if ($u === '') return null;
    if (in_array($u, $allowed)) {
        return ($u === 'NONE') ? 'none' : $u;
    }
    return null;
}

// ----------------------
// Main import logic
// ----------------------
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
            $message = "Only CSV files are allowed";
            $messageClass = 'error';
        } else {
            $newFileName = uniqid('', true) . '.' . $fileExt;
            $destPath = $uploadDir . $newFileName;
            if (!move_uploaded_file($fileTmpPath, $destPath)) {
                $message = "Failed to move uploaded file";
                $messageClass = 'error';
            } else {
                $conn = new mysqli($servername, $username, $password, $dbname);
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

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

                    // Skip header if present (assumes first row is header)
                    fgetcsv($handle, 0, ",", '"', "\0");
                    $lineNumber = 1;

                    while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
                        $lineNumber++;
                        try {
                            if (count(array_filter($data)) === 0) continue;
                            if (count($data) < 18) {
                                throw new Exception("Row has only " . count($data) . " columns (minimum 18 required)");
                            }

                            // Trim and normalize null-like tokens
                            $data = array_map('trim', $data);
                            $data = array_map(function($v) {
                                $vl = strtolower($v ?? '');
                                return ($v === '' || in_array($vl, ['null', 'no value', 'missing'])) ? null : $v;
                            }, $data);

                            // -------- Patient ----------
                            $subjectId = $data[0] ?? '';
                            $dobRaw = $data[1] ?? '';
                            $dob = DateTime::createFromFormat('m/d/Y', $dobRaw);
                            if (!$dob) {
                                throw new Exception("Invalid date format for DoB: " . ($dobRaw ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }
                            $dobFormatted = $dob->format('Y-m-d');
                            $location = 'KH';
                            $patientId = $subjectId;
                            $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $dobFormatted, $location);

                            // -------- Test ----------
                            $testDateRaw = $data[2] ?? '';
                            $testDate = DateTime::createFromFormat('m/d/Y', $testDateRaw);
                            if (!$testDate) {
                                throw new Exception("Invalid date format for test date: " . ($testDateRaw ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }

                            $ageValue = $data[3] ?? null;
                            $age = (isset($ageValue) && is_numeric($ageValue) && $ageValue >= 0 && $ageValue <= 100)
                                ? (int)round($ageValue)
                                : null;

                            $testNumber = $data[4] ?? null;
                            if ($testNumber !== null && !is_numeric($testNumber)) {
                                throw new Exception("Invalid TEST_ID: must be a number");
                            }

                            $eyeValue = $data[5] ?? null;
                            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                            $testDateFormatted = $testDate->format('Ymd');
                            $testId = $testDateFormatted . ($eye ? $eye : '') . ($testNumber ? $testNumber : '');

                            $reportDiagnosis = normalize_report_diagnosis($data[6] ?? null);
                            $exclusion = normalize_exclusion($data[7] ?? null);

                            // MERCI
                            $merciScoreValue = $data[8] ?? null;
                            $merciScore = null;
                            if (!is_null($merciScoreValue)) {
                                if (strtolower($merciScoreValue) === 'unable') {
                                    $merciScore = 'unable';
                                } elseif (is_numeric($merciScoreValue) && $merciScoreValue >= 0 && $merciScoreValue <= 100) {
                                    $merciScore = (int)$merciScoreValue;
                                }
                            }

                            $merciDiagnosis = normalize_merci_diagnosis($data[9] ?? null);
                            $errorType = normalize_error_type($data[10] ?? null);
                            if ($data[10] !== null && $errorType === null) {
                                $results['errors'][] = "Line $lineNumber: Invalid error_type '{$data[10]}' - set to NULL";
                            }

                            $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                            $octScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;
                            $vfScore = isset($data[13]) && is_numeric($data[13]) ? round(floatval($data[13]), 2) : null;

                            $actualDiagnosis = normalize_actual_diagnosis($data[14] ?? null);
                            if ($data[14] !== null && $actualDiagnosis === null) {
                                $actualDiagnosis = 'other';
                            }

                            $dosage = isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;
                            $durationDays = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;
                            $cumulativeDosage = isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

                            $discontinuationDate = null;
                            if (isset($data[18]) && $data[18] !== '') {
                                if (is_numeric($data[18])) {
                                    $tmp = DateTime::createFromFormat('Y', $data[18]);
                                    if ($tmp) {
                                        $discontinuationDate = $tmp->format('Y-m-d');
                                    }
                                } else {
                                    $tmp = DateTime::createFromFormat('m/d/Y', $data[18]);
                                    if ($tmp) {
                                        $discontinuationDate = $tmp->format('Y-m-d');
                                    }
                                }
                            }

                            $testData = [
                                'test_id' => $testId,
                                'patient_id' => $patientId,
                                'location' => $location,
                                'date_of_test' => $testDate->format('Y-m-d'),
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
                                'actual_diagnosis' => $actualDiagnosis,
                                'dosage' => $dosage,
                                'duration_days' => $durationDays,
                                'cumulative_dosage' => $cumulativeDosage,
                                'date_of_continuation' => $discontinuationDate
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
                        $message = "Successfully processed {$results['patients']} patients and {$results['tests']} tests";
                        $messageClass = 'success';
                    } else {
                        $conn->rollback();
                        $message = "Completed with " . count($results['errors']) . " errors. No data was imported.";
                        $messageClass = 'error';
                    }
                } catch (Exception $e) {
                    $message = "Fatal error: " . $e->getMessage();
                    $messageClass = 'error';
                    if (isset($conn) && method_exists($conn, 'rollback')) {
                        $conn->rollback();
                    }
                }
            }
        }
    }
}

// ----------------------
// DB helper functions
// ----------------------
function getOrCreatePatient($conn, $patientId, $subjectId, $dob, $location = 'KH') {
    global $results;
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $patientId;
    }

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patientId, $subjectId, $dob, $location);

    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }

    $results['patients']++;
    return $patientId;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, location, date_of_test, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, dosage, duration_days,
            cumulative_dosage, date_of_continuation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $merciScoreForDb = null;
    if ($testData['merci_score'] === 'unable') {
        $merciScoreForDb = 'unable';
    } elseif (!is_null($testData['merci_score'])) {
        $merciScoreForDb = $testData['merci_score'];
    }

    $stmt->bind_param(
        "ssssissssssdddsdids",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test'],
        $testData['age'],
        $testData['eye'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $merciScoreForDb,
        $testData['merci_diagnosis'],
        $testData['error_type'],
        $testData['faf_grade'],
        $testData['oct_score'],
        $testData['vf_score'],
        $testData['actual_diagnosis'],
        $testData['dosage'],
        $testData['duration_days'],
        $testData['cumulative_dosage'],
        $testData['date_of_continuation']
    );

    if (!$stmt->execute()) {
        $context = json_encode([
            'test_id' => $testData['test_id'],
            'patient_id' => $testData['patient_id'],
            'error' => $stmt->error
        ]);
        throw new Exception("Test insert failed: " . $stmt->error . " | Context: " . $context);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Import Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .results { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .success { color: #28a745; border-left: 4px solid #28a745; padding-left: 10px; }
        .error { color: #dc3545; border-left: 4px solid #dc3545; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .error-list { max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; }
        form { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="file"], input[type="submit"] { padding: 8px 12px; font-size: 16px; }
        input[type="submit"] { background-color: #007bff; color: white; border: none; cursor: pointer; }
        input[type="submit"]:hover { background-color: #0069d9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>CSV Import Tool</h1>

        <form method="post" action="" enctype="multipart/form-data">
            <label for="csv_file">Select CSV File to Upload:</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
            <input type="submit" name="submit" value="Import File">
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="results">
                <h2 class="<?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></h2>

                <?php if (!empty($fileName)): ?>
                    <p>File uploaded: <?= htmlspecialchars($fileName) ?></p>
                <?php endif; ?>

                <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
                    <table>
                        <tr>
                            <th>Patients Processed</th>
                            <td><?= htmlspecialchars($results['patients']) ?></td>
                        </tr>
                        <tr>
                            <th>Tests Imported</th>
                            <td><?= htmlspecialchars($results['tests']) ?></td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php if (!empty($results['errors'])): ?>
                    <h3>Errors Encountered:</h3>
                    <div class="error-list">
                        <?php foreach ($results['errors'] as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p><a href="index.php">Return to Dashboard</a></p>
    </div>
</body>
</html>
