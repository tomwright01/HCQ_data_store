<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "hospital_eye_reports";

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

    // Skip header row if exists
    fgetcsv($handle);
    
    $lineNumber = 1; // Start counting from 1 (header is line 0)
    
    while (($data = fgetcsv($handle)) !== FALSE) {
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
                $v = trim($v);
                return ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'no value') ? null : $v; 
            }, $data);

            // Process Patient (Subject ID and DoB)
            $subjectId = $data[0];
            $dob = DateTime::createFromFormat('n/j/Y', $data[1]);
            if (!$dob) {
                throw new Exception("Invalid date format for DoB: " . $data[1]);
            }
            $dobFormatted = $dob->format('Y-m-d');

            // Insert or get existing patient
            $patientId = getOrCreatePatient($conn, $subjectId, $dobFormatted);
            
            // Process Test data
            $testDate = DateTime::createFromFormat('n/j/Y', $data[2]);
            if (!$testDate) {
                throw new Exception("Invalid date format for DoT: " . $data[2]);
            }
            $testDateFormatted = $testDate->format('Y-m-d');

            // Prepare test data
            $testData = [
                'patient_id' => $patientId,
                'date_of_test' => $testDateFormatted,
                'eye' => in_array(strtoupper($data[3]), ['OD', 'OS']) ? strtoupper($data[3]) : null,
                'report_diagnosis' => in_array(strtolower($data[4]), ['normal', 'abnormal']) ? strtolower($data[4]) : 'no input',
                'exclusion' => in_array(strtolower($data[5]), ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing']) ? strtolower($data[5]) : 'none',
                'merci_score' => is_numeric($data[6]) && $data[6] >= 1 && $data[6] <= 100 ? (int)$data[6] : null,
                'merci_diagnosis' => in_array(strtolower($data[7]), ['normal', 'abnormal']) ? strtolower($data[7]) : 'no value',
                'error_type' => in_array(strtoupper($data[8]), ['TN', 'FP']) ? strtoupper($data[8]) : 'none',
                'faf_grade' => is_numeric($data[9]) && $data[9] >= 1 && $data[9] <= 4 ? (int)$data[9] : null,
                'oct_score' => is_numeric($data[10]) ? round(floatval($data[10]), 2) : null,
                'vf_score' => is_numeric($data[11]) ? round(floatval($data[11]), 2) : null
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
function getOrCreatePatient($conn, $subjectId, $dob) {
    // Check if patient exists
    $stmt = $conn->prepare("SELECT patient_id FROM Patients WHERE subject_id = ?");
    $stmt->bind_param("s", $subjectId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['patient_id'];
    }
    
    // Create new patient
    $stmt = $conn->prepare("INSERT INTO Patients (subject_id, date_of_birth) VALUES (?, ?)");
    $stmt->bind_param("ss", $subjectId, $dob);
    
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    
    global $results;
    $results['patients']++;
    
    return $conn->insert_id;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO Tests (
            patient_id, date_of_test, eye, report_diagnosis, exclusion,
            merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "isssssisidd",
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
