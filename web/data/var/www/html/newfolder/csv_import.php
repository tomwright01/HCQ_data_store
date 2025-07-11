<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// CSV file path
$csvFilePath = "/var/www/html/data/Patient Info Master 1(Retrospective Data).csv";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize results
$results = [
    'patients' => 0,
    'tests' => 0,
    'errors' => []
];

// Initialize test counter for duplicate handling
$testCounts = [];

try {
    // Verify file exists and is readable
    if (!file_exists($csvFilePath)) {
        throw new Exception("CSV file not found at: $csvFilePath");
    }
    if (!is_readable($csvFilePath)) {
        throw new Exception("CSV file is not readable. Check permissions.");
    }

    // Open CSV file
    if (($handle = fopen($csvFilePath, "r")) === FALSE) {
        throw new Exception("Could not open CSV file");
    }

    // Start transaction
    $conn->begin_transaction();

    // Skip header row if exists
    fgetcsv($handle, 0, ",", '"', "\0");
    
    $lineNumber = 1; // Start counting from 1 (header is line 0)
    
    while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
        $lineNumber++;
        
        try {
            // Skip empty rows
            if (count(array_filter($data)) === 0) continue;
            
            // Validate minimum columns
            if (count($data) < 12) {
                throw new Exception("Row has only " . count($data) . " columns (minimum 12 required)");
            }

            // Clean and format data
            $data = array_map('trim', $data);
            $data = array_map(function($v) { 
                $v = trim($v ?? '');
                return ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'no value') ? null : $v; 
            }, $data);

            // Process Patient (Subject ID and DoB)
            $subjectId = $data[0] ?? '';
            $dob = DateTime::createFromFormat('n/j/Y', $data[1] ?? '');
            if (!$dob) {
                throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL'));
            }
            $dobFormatted = $dob->format('Y-m-d');

            // Generate patient_id (first 8 chars of subjectId + last 2 of DoB year)
            $patientId = substr($subjectId, 0, 8) . substr($data[1] ?? '', -2);
            
            // Insert or get existing patient
            $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $dobFormatted);
            
            // Process Test data
            $testDate = DateTime::createFromFormat('n/j/Y', $data[2] ?? '');
            if (!$testDate) {
                throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL'));
            }
            
            // Generate test_id (date + eye + letter if duplicate)
            $testDateFormatted = $testDate->format('Ymd'); // Format as YYYYMMDD
            $eyeValue = $data[4] ?? null;
            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;
            $baseTestId = $testDateFormatted . ($eye ? $eye : '');

            // Handle duplicates by appending a, b, c, etc.
            if (!isset($testCounts[$baseTestId])) {
                $testCounts[$baseTestId] = 0;
                $testId = $baseTestId;
            } else {
                $testCounts[$baseTestId]++;
                $letter = chr(97 + $testCounts[$baseTestId]); // 97 = 'a' in ASCII
                $testId = $baseTestId . $letter;
            }

            // Process Age (column 4/[3])
            $ageValue = $data[3] ?? null;
            $age = (isset($ageValue) && is_numeric($ageValue) && $ageValue >= 0 && $ageValue <= 100) 
                ? (int)round($ageValue) 
                : null;

            $reportDiagnosisValue = $data[5] ?? null;
            $reportDiagnosis = ($reportDiagnosisValue !== null && in_array(strtolower($reportDiagnosisValue), ['normal', 'abnormal'])) 
                ? strtolower($reportDiagnosisValue) 
                : 'no input';

            $exclusionValue = $data[6] ?? null;
            $exclusion = ($exclusionValue !== null && in_array(strtolower($exclusionValue), 
                ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) 
                ? strtolower($exclusionValue) 
                : 'none';

            // Handle MERCI score (0-100 range or 'unable')
            $merciScoreValue = $data[7] ?? null;
            $merciScore = null;
            if (isset($merciScoreValue)) {
                if (strtolower($merciScoreValue) === 'unable') {
                    $merciScore = 'unable';
                } elseif (is_numeric($merciScoreValue) && $merciScoreValue >= 0 && $merciScoreValue <= 100) {
                    $merciScore = (int)$merciScoreValue;
                }
            }

            $merciDiagnosisValue = $data[8] ?? null;
            $merciDiagnosis = ($merciDiagnosisValue !== null && in_array(strtolower($merciDiagnosisValue), ['normal', 'abnormal'])) 
                ? strtolower($merciDiagnosisValue) 
                : 'no value';

            $errorTypeValue = $data[9] ?? null;
            $errorType = ($errorTypeValue !== null && in_array(strtoupper($errorTypeValue), ['TN', 'FP', 'TP', 'FN'])) 
                ? strtoupper($errorTypeValue) 
                : 'none';

            $fafGrade = (isset($data[10]) && is_numeric($data[10]) && $data[10] >= 1 && $data[10] <= 4) ? (int)$data[10] : null;
            $octScore = isset($data[11]) && is_numeric($data[11]) ? round(floatval($data[11]), 2) : null;
            $vfScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;

            $testData = [
                'test_id' => $testId,
                'patient_id' => $patientId,
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
                'vf_score' => $vfScore
            ];

            // Insert Test
            insertTest($conn, $testData);
            $results['tests']++;
            
        } catch (Exception $e) {
            $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    // Commit or rollback
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

// Database functions
function getOrCreatePatient($conn, $patientId, $subjectId, $dob) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $patientId;
    }
    
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $patientId, $subjectId, $dob);
    
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    
    global $results;
    $results['patients']++;
    
    return $patientId;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, date_of_test, age, eye, report_diagnosis, exclusion,
            merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Convert MERCI score values for database
    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' : 
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);
    
    $stmt->bind_param(
        "sssisssisiddd",
        $testData['test_id'],
        $testData['patient_id'],
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
        $testData['vf_score']
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Import Results</title>
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
    </style>
</head>
<body>
    <div class="container">
        <h1>CSV Import Results</h1>
        <p>File processed: <?= htmlspecialchars($csvFilePath) ?></p>
        
        <div class="results">
            <h2 class="<?= $messageClass ?>"><?= $message ?></h2>
            
            <table>
                <tr>
                    <th>Patients Processed</th>
                    <td><?= $results['patients'] ?></td>
                </tr>
                <tr>
                    <th>Tests Imported</th>
                    <td><?= $results['tests'] ?></td>
                </tr>
            </table>
            
            <?php if (!empty($results['errors'])): ?>
                <h3>Errors Encountered:</h3>
                <div class="error-list">
                    <?php foreach ($results['errors'] as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <p><a href="index.php">Return to Dashboard</a></p>
    </div>
</body>
</html>
