<?php
// Enable error reporting
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

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Check if file was uploaded without errors
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Error uploading file: " . ($_FILES['csv_file']['error'] ?? 'No file selected');
        $messageClass = 'error';
    } else {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileType = $_FILES['csv_file']['type'];
        
        // Sanitize file name
        $fileNameClean = preg_replace("/[^A-Za-z0-9 \.\-_]/", '', $fileName);
        
        // Verify file extension
        $fileExt = strtolower(pathinfo($fileNameClean, PATHINFO_EXTENSION));
        if ($fileExt !== 'csv') {
            $message = "Only CSV files are allowed";
            $messageClass = 'error';
        } else {
            // Create unique filename to prevent overwrites
            $newFileName = uniqid('', true) . '.' . $fileExt;
            $destPath = $uploadDir . $newFileName;
            
            // Move the uploaded file
            if (!move_uploaded_file($fileTmpPath, $destPath)) {
                $message = "Failed to move uploaded file";
                $messageClass = 'error';
            } else {
                // Proceed with import if file is valid
                $conn = new mysqli($servername, $username, $password, $dbname);

                // Check connection
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }

                try {
                    // Verify file exists and is readable
                    if (!file_exists($destPath)) {
                        throw new Exception("CSV file not found at: $destPath");
                    }
                    if (!is_readable($destPath)) {
                        throw new Exception("CSV file is not readable. Check permissions.");
                    }

                    // Open CSV file
                    if (($handle = fopen($destPath, "r")) === FALSE) {
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
                            
                            // Validate minimum columns (18 columns expected)
                            if (count($data) < 18) {
                                throw new Exception("Row has only " . count($data) . " columns (minimum 18 required)");
                            }

                            // Clean and format data
                            $data = array_map('trim', $data);
                            $data = array_map(function($v) { 
                                $v = trim($v ?? '');
                                return ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'no value' || strtolower($v) === 'missing') ? null : $v; 
                            }, $data);

                            // Process Patient (Subject ID [0] and DoB [1])
                            $subjectId = $data[0] ?? '';
                            $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                            if (!$dob) {
                                throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }
                            $dobFormatted = $dob->format('Y-m-d');

                            // Default location
                            $location = 'KH';

                            // Generate patient_id (use subjectId)
                            $patientId = $subjectId;
                            
                            // Insert or get existing patient
                            $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $dobFormatted, $location);
                            
                            // Process Test data
                            $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                            if (!$testDate) {
                                throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL') . " - Expected MM/DD/YYYY");
                            }
                            
                            // Process Age (column 4/[3])
                            $ageValue = $data[3] ?? null;
                            $age = (isset($ageValue) && is_numeric($ageValue) && $ageValue >= 0 && $ageValue <= 100) 
                                ? (int)round($ageValue) 
                                : null;

                            // Process TEST_ID (column 5/[4]) - Now part of test_id
                            $testNumber = $data[4] ?? null;
                            if ($testNumber !== null && !is_numeric($testNumber)) {
                                throw new Exception("Invalid TEST_ID: must be a number");
                            }

                            // Process Eye (column 6/[5])
                            $eyeValue = $data[5] ?? null;
                            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                            // Generate test_id (date + eye + test number)
                            $testDateFormatted = $testDate->format('Ymd'); // Format as YYYYMMDD
                            $testId = $testDateFormatted . ($eye ? $eye : '') . ($testNumber ? $testNumber : '');

                            // Process report diagnosis (column 7/[6])
                            $reportDiagnosisValue = $data[6] ?? null;
                            $reportDiagnosis = 'no input';
                            if ($reportDiagnosisValue !== null) {
                                $lowerValue = strtolower($reportDiagnosisValue);
                                if (in_array($lowerValue, ['normal', 'abnormal', 'exclude'])) {
                                    $reportDiagnosis = $lowerValue;
                                } elseif ($lowerValue !== 'missing' && $lowerValue !== '') {
                                    $reportDiagnosis = 'no input';
                                }
                            }

                            // Process exclusion (column 8/[7])
                            $exclusionValue = $data[7] ?? null;
                            $exclusion = 'none';
                            if ($exclusionValue !== null) {
                                $lowerValue = strtolower($exclusionValue);
                                if (in_array($lowerValue, ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) {
                                    $exclusion = $lowerValue;
                                }
                            }

                            // Process MERCI score (column 9/[8])
                            $merciScoreValue = $data[8] ?? null;
                            $merciScore = null;
                            if (isset($merciScoreValue)) {
                                if (strtolower($merciScoreValue) === 'unable') {
                                    $merciScore = 'unable';
                                } elseif (is_numeric($merciScoreValue) && $merciScoreValue >= 0 && $merciScoreValue <= 100) {
                                    $merciScore = (int)$merciScoreValue;
                                }
                            }

                            // Process MERCI diagnosis (column 10/[9])
                            $merciDiagnosisValue = $data[9] ?? null;
                            $merciDiagnosis = 'no value';
                            if ($merciDiagnosisValue !== null) {
                                $lowerValue = strtolower($merciDiagnosisValue);
                                if (in_array($lowerValue, ['normal', 'abnormal'])) {
                                    $merciDiagnosis = $lowerValue;
                                }
                            }

                            // Process error type (column 11/[10])
                            $errorTypeValue = $data[10] ?? null;
                            $allowedErrorTypes = ['TN', 'FP', 'TP', 'FN', 'none'];
                            $errorType = null;
                            
                            if ($errorTypeValue !== null && $errorTypeValue !== '') {
                                $upperValue = strtoupper(trim($errorTypeValue));
                                if (in_array($upperValue, $allowedErrorTypes)) {
                                    $errorType = ($upperValue === 'NONE') ? 'none' : $upperValue;
                                } else {
                                    $results['errors'][] = "Line $lineNumber: Invalid error_type '{$errorTypeValue}' - set to NULL";
                                }
                            }

                            // Process FAF grade (column 12/[11])
                            $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;

                            // Process OCT score (column 13/[12])
                            $octScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;

                            // Process VF score (column 14/[13])
                            $vfScore = isset($data[13]) && is_numeric($data[13]) ? round(floatval($data[13]), 2) : null;

                            // Process Diagnosis (column 15/[14])
                            $actualDiagnosis = isset($data[14]) && $data[14] !== '' ? 
                                               substr(trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $data[14])), 0, 100) : 
                                               null;

                            // Process dosage (column 16/[15])
                            $dosage = isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;

                            // Process duration (column 17/[16])
                            $durationDays = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;

                            // Process cumulative dosage (column 18/[17])
                            $cumulativeDosage = isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

                            // Process discontinuation date (column 19/[18])
                            $discontinuationDate = null;
                            if (isset($data[18]) && $data[18] !== '') {
                                if (is_numeric($data[18])) {
                                    // Assume it's a year if it's just a number
                                    $discontinuationDate = DateTime::createFromFormat('Y', $data[18]);
                                    if ($discontinuationDate) {
                                        $discontinuationDate = $discontinuationDate->format('Y-m-d');
                                    }
                                } else {
                                    // Try to parse as date
                                    $discontinuationDate = DateTime::createFromFormat('m/d/Y', $data[18]);
                                    if ($discontinuationDate) {
                                        $discontinuationDate = $discontinuationDate->format('Y-m-d');
                                    }
                                }
                            }

                            $testData = [
                                'test_id' => $testId,
                                'patient_id' => $patientId,
                                'location' => $location,
                                'date_of_test' => $testDate->format('Y-m-d'),
                                'test_number' => $testNumber ?? $testId, // Using test_id if test_number is null
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
            }
        }
    }
}

// Database functions
function getOrCreatePatient($conn, $patientId, $subjectId, $dob, $location = 'KH') {
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
    
    global $results;
    $results['patients']++;
    
    return $patientId;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, location, date_of_test, test_number, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score,
            faf_reference_od, faf_reference_os, oct_reference_od, oct_reference_os,
            vf_reference_od, vf_reference_os, mferg_reference_od, mferg_reference_os,
            actual_diagnosis, medication_name, dosage, dosage_unit, duration_days,
            cumulative_dosage, date_of_continuation, treatment_notes
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
            ?, NULL, ?, 'mg', ?, ?, ?, NULL
        )
    ");
    
    // Convert values for database
    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' : 
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);
    
    $errorTypeForDb = $testData['error_type'];
    
    $stmt->bind_param(
        "sssssisssssddddssssssssssssssis",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test'],
        $testData['test_number'],
        $testData['age'],
        $testData['eye'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $merciScoreForDb,
        $testData['merci_diagnosis'],
        $errorTypeForDb,
        $testData['faf_grade'],
        $testData['oct_score'],
        $testData['vf_score'],
        // Image references (set to NULL)
        $testData['actual_diagnosis'],
        $testData['dosage'],
        $testData['duration_days'],
        $testData['cumulative_dosage'],
        $testData['date_of_continuation']
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
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
                <h2 class="<?= $messageClass ?>"><?= $message ?></h2>
                
                <?php if (!empty($fileName)): ?>
                    <p>File uploaded: <?= htmlspecialchars($fileName) ?></p>
                <?php endif; ?>
                
                <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
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
