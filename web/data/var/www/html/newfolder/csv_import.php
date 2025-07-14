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
    'patients_inserted' => 0,
    'patients_updated' => 0,
    'tests_inserted' => 0,
    'tests_updated' => 0,
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

            // Generate more unique patient ID
            $patientId = substr($subjectId, 0, 6) . substr($dob->format('Y'), -2) . substr($dob->format('m'), -2);
            
            // Insert or update patient
            $patientAction = getOrUpdatePatient($conn, $patientId, $subjectId, $dobFormatted);
            if ($patientAction === 'inserted') {
                $results['patients_inserted']++;
            } elseif ($patientAction === 'updated') {
                $results['patients_updated']++;
            }
            
            // Process Test data
            $testDate = DateTime::createFromFormat('n/j/Y', $data[2] ?? '');
            if (!$testDate) {
                throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL'));
            }
            
            $eyeValue = $data[4] ?? null;
            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;
            
            // Check for existing test
            $existingTestId = findExistingTest($conn, $patientId, $testDate->format('Y-m-d'), $eye);
            
            if ($existingTestId) {
                $testId = $existingTestId;
            } else {
                // Generate new test ID
                $testDateFormatted = $testDate->format('Ymd');
                $baseTestId = $testDateFormatted . ($eye ? $eye : '');
                $testId = generateUniqueTestId($conn, $baseTestId);
            }

            // Process other test data fields...
            // [Rest of your field processing code remains the same]
            
            $testData = [
                'test_id' => $testId,
                'patient_id' => $patientId,
                // [All other test data fields...]
            ];

            // Insert or update test
            $testAction = insertOrUpdateTest($conn, $testData);
            if ($testAction === 'inserted') {
                $results['tests_inserted']++;
            } elseif ($testAction === 'updated') {
                $results['tests_updated']++;
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    // Commit or rollback
    if (count($results['errors']) > 10) {
        $conn->rollback();
        $message = "Too many errors (" . count($results['errors']) . ") - rolled back all changes";
        $messageClass = 'error';
    } else {
        $conn->commit();
        $message = "Import completed with: 
            {$results['patients_inserted']} new patients, 
            {$results['patients_updated']} updated patients,
            {$results['tests_inserted']} new tests, 
            {$results['tests_updated']} updated tests";
        $messageClass = empty($results['errors']) ? 'success' : 'warning';
    }
    
} catch (Exception $e) {
    $message = "Fatal error: " . $e->getMessage();
    $messageClass = 'error';
    if (isset($conn) && method_exists($conn, 'rollback')) {
        $conn->rollback();
    }
}

// Database functions
function getOrUpdatePatient($conn, $patientId, $subjectId, $dob) {
    // Check if patient exists
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing patient
        $update = $conn->prepare("UPDATE patients SET 
            subject_id = ?, 
            date_of_birth = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE patient_id = ?");
        $update->bind_param("sss", $subjectId, $dob, $patientId);
        $update->execute();
        return 'updated';
    }
    
    // Insert new patient
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $patientId, $subjectId, $dob);
    
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    
    return 'inserted';
}

function findExistingTest($conn, $patientId, $testDate, $eye) {
    $stmt = $conn->prepare("SELECT test_id FROM tests 
        WHERE patient_id = ? AND date_of_test = ? AND eye = ? 
        LIMIT 1");
    $stmt->bind_param("sss", $patientId, $testDate, $eye);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return ($result->num_rows > 0) ? $result->fetch_assoc()['test_id'] : null;
}

function generateUniqueTestId($conn, $baseId) {
    $counter = 0;
    $testId = $baseId;
    
    while (true) {
        $stmt = $conn->prepare("SELECT test_id FROM tests WHERE test_id = ?");
        $stmt->bind_param("s", $testId);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            return $testId;
        }
        
        $counter++;
        $testId = $baseId . chr(96 + $counter); // a, b, c, etc.
        
        if ($counter > 26) { // Prevent infinite loop
            throw new Exception("Could not generate unique test ID for base: $baseId");
        }
    }
}

function insertOrUpdateTest($conn, $testData) {
    $sql = "INSERT INTO tests (
        test_id, patient_id, date_of_test, age, eye, report_diagnosis, exclusion,
        merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        date_of_test = VALUES(date_of_test),
        age = VALUES(age),
        eye = VALUES(eye),
        report_diagnosis = VALUES(report_diagnosis),
        exclusion = VALUES(exclusion),
        merci_score = VALUES(merci_score),
        merci_diagnosis = VALUES(merci_diagnosis),
        error_type = VALUES(error_type),
        faf_grade = VALUES(faf_grade),
        oct_score = VALUES(oct_score),
        vf_score = VALUES(vf_score),
        updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    
    // [Same parameter binding as before...]
    
    if (!$stmt->execute()) {
        throw new Exception("Test insert/update failed: " . $stmt->error);
    }
    
    return ($stmt->affected_rows === 1) ? 'inserted' : 'updated';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Import Results</title>
    <style>
        /* [Previous styles remain the same] */
        .warning {
            color: #ffc107;
            border-left: 4px solid #ffc107;
            padding-left: 10px;
        }
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
                    <th>New Patients</th>
                    <td><?= $results['patients_inserted'] ?></td>
                </tr>
                <tr>
                    <th>Updated Patients</th>
                    <td><?= $results['patients_updated'] ?></td>
                </tr>
                <tr>
                    <th>New Tests</th>
                    <td><?= $results['tests_inserted'] ?></td>
                </tr>
                <tr>
                    <th>Updated Tests</th>
                    <td><?= $results['tests_updated'] ?></td>
                </tr>
            </table>
            
            <?php if (!empty($results['errors'])): ?>
                <h3>Errors Encountered (<?= count($results['errors']) ?>):</h3>
                <div class="error-list">
                    <?php foreach (array_slice($results['errors'], 0, 50) as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                    <?php if (count($results['errors']) > 50): ?>
                        <p>...and <?= count($results['errors']) - 50 ?> more errors</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <p><a href="index.php">Return to Dashboard</a></p>
    </div>
</body>
</html>
