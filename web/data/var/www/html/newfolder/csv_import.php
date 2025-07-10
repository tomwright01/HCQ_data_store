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

    // Skip header row if exists (with fixed fgetcsv parameters)
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
                $v = trim($v ?? ''); // Ensure we never pass null
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
            $testDateFormatted = $testDate->format('Y-m-d');
            
            // Generate test_id (patientId + test date without hyphens)
            $testId = $patientId . str_replace('-', '', $testDateFormatted);

            // Prepare test data with null checks
            $eyeValue = $data[3] ?? null;
            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

            $reportDiagnosisValue = $data[4] ?? null;
            $reportDiagnosis = ($reportDiagnosisValue !== null && in_array(strtolower($reportDiagnosisValue), ['normal', 'abnormal'])) 
                ? strtolower($reportDiagnosisValue) 
                : 'no input';

            $exclusionValue = $data[5] ?? null;
            $exclusion = ($exclusionValue !== null && in_array(strtolower($exclusionValue), 
                ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) 
                ? strtolower($exclusionValue) 
                : 'none';

            $merciScore = (isset($data[6]) && is_numeric($data[6]) && $data[6] >= 1 && $data[6] <= 100) ? (int)$data[6] : null;

            $merciDiagnosisValue = $data[7] ?? null;
            $merciDiagnosis = ($merciDiagnosisValue !== null && in_array(strtolower($merciDiagnosisValue), ['normal', 'abnormal'])) 
                ? strtolower($merciDiagnosisValue) 
                : 'no value';

            $errorTypeValue = $data[8] ?? null;
            $errorType = ($errorTypeValue !== null && in_array(strtoupper($errorTypeValue), ['TN', 'FP'])) 
                ? strtoupper($errorTypeValue) 
                : 'none';

            $fafGrade = (isset($data[9]) && is_numeric($data[9]) && $data[9] >= 1 && $data[9] <= 4) ? (int)$data[9] : null;
            $octScore = isset($data[10]) && is_numeric($data[10]) ? round(floatval($data[10]), 2) : null;
            $vfScore = isset($data[11]) && is_numeric($data[11]) ? round(floatval($data[11]), 2) : null;

            $testData = [
                'test_id' => $testId,
                'patient_id' => $patientId,
                'date_of_test' => $testDateFormatted,
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
    // Debug: Show incoming parameters
    //die("DEBUG - getOrCreatePatient() called with:
    //   Patient ID: $patientId
    //    Subject ID: $subjectId
    //    DoB: $dob");
    
    // Check if patient exists
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Debug: Show query results
    //die("DEBUG - Patient check results:
    //    Num rows: " . $result->num_rows . "
    //    Patient ID from DB: " . ($result->num_rows > 0 ? $result->fetch_assoc()['patient_id'] : 'None'));
    
    if ($result->num_rows > 0) {
        die("DEBUG - Returning existing patient ID: $patientId");
        return $patientId;
    }
    
    // Create new patient
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $patientId, $subjectId, $dob);
    
    if (!$stmt->execute()) {
        die("DEBUG - Patient insert failed: " . $stmt->error);
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    
    global $results;
    $results['patients']++;
    
    //die("DEBUG - Successfully created new patient:
    //    Patient ID: $patientId
    //    Subject ID: $subjectId
    //    DoB: $dob");
    return $patientId;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, date_of_test, eye, report_diagnosis, exclusion,
            merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "ssssssisiddd",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['date_of_test'],
        $testData['eye'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $testData['merci_score'],
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
