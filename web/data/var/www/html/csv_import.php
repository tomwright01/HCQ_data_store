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

                // Initialize test counter for duplicate handling
                $testCounts = [];

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
                            
                            // Validate minimum columns (now 13 with location)
                            if (count($data) < 13) {
                                throw new Exception("Row has only " . count($data) . " columns (minimum 13 required with location)");
                            }

                            // Clean and format data
                            $data = array_map('trim', $data);
                            $data = array_map(function($v) { 
                                $v = trim($v ?? '');
                                return ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'no value') ? null : $v; 
                            }, $data);

                            // Process Patient (Subject ID and DoB)
                            $subjectId = $data[0] ?? '';
                            $dob = DateTime::createFromFormat('Y-m-d', $data[1] ?? '');
                            if (!$dob) {
                                throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL') . " - Expected YYYY-MM-DD");
                            }
                            $dobFormatted = $dob->format('Y-m-d');

                            // Get location from last column
                            $location = $data[count($data)-1] ?? 'KH'; // Default to 'KH' if not specified
                            $location = str_replace(['"', "'"], '', $location); // Clean any quotes
                            $location = in_array($location, ['KH', 'Montreal', 'Dal', 'Ivey']) ? $location : 'KH';

                            // Generate patient_id (first 8 chars of subjectId + last 2 of DoB year)
                            $patientId = $subjectId;
                            
                            // Insert or get existing patient
                            $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $dobFormatted, $location);
                            
                            // Process Test data
                            $testDate = DateTime::createFromFormat('Y-m-d', $data[2] ?? '');
                            if (!$testDate) {
                                throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL') . " - Expected YYYY-MM-DD");
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

                            // FIXED: error_type with NULL handling
                            $errorTypeValue = $data[9] ?? null;
                            $allowedErrorTypes = ['TN', 'FP', 'TP', 'FN', 'none'];
                            $errorType = null; // Default to NULL
                            
                            if ($errorTypeValue !== null && $errorTypeValue !== '') {
                                $upperValue = strtoupper(trim($errorTypeValue));
                                if (in_array($upperValue, $allowedErrorTypes)) {
                                    $errorType = ($upperValue === 'NONE') ? 'none' : $upperValue;
                                } else {
                                    $results['errors'][] = "Line $lineNumber: Invalid error_type '{$errorTypeValue}' - set to NULL";
                                }
                            }

                            $fafGrade = (isset($data[10]) && is_numeric($data[10]) && $data[10] >= 1 && $data[10] <= 4) ? (int)$data[10] : null;
                            $octScore = isset($data[11]) && is_numeric($data[11]) ? round(floatval($data[11]), 2) : null;
                            $vfScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;

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
            }
        }
    }
}

// Database functions (remain the same as before)
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
            test_id, patient_id, location, date_of_test, age, eye, report_diagnosis, exclusion,
            merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Convert values for database
    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' : 
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score'];
    
    $errorTypeForDb = $testData['error_type']; // Already NULL or valid value
    
    $stmt->bind_param(
        "ssssisssissddd",
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
        $errorTypeForDb,
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import Tool</title>
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #343a40;
            --text-color: #212529;
            --border-radius: 6px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background-color: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        h1 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-weight: 600;
            text-align: center;
        }
        
        h2 {
            font-size: 1.4rem;
            margin-bottom: 15px;
        }
        
        h3 {
            font-size: 1.2rem;
            margin: 20px 0 10px;
            color: var(--dark-gray);
        }
        
        .upload-area {
            background: var(--light-gray);
            border: 2px dashed var(--primary-color);
            border-radius: var(--border-radius);
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            background: var(--medium-gray);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-gray);
        }
        
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background: white;
            font-size: 16px;
        }
        
        .btn {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .results {
            background: var(--light-gray);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            font-weight: 600;
        }
        
        .success {
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        
        .error {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }
        
        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        
        tr:hover {
            background-color: rgba(52, 152, 219, 0.1);
        }
        
        .error-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            padding: 15px;
            background: white;
        }
        
        .error-list p {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            color: var(--error-color);
        }
        
        .error-list p:last-child {
            border-bottom: none;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .file-info {
            background: white;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            .upload-area {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>CSV Import Tool</h1>
        
        <div class="upload-area">
            <form method="post" action="" enctype="multipart/form-data" class="form-group">
                <label for="csv_file">Select CSV File to Upload</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                <button type="submit" name="submit" class="btn btn-block" style="margin-top: 20px;">
                    <i class="fas fa-upload"></i> Import File
                </button>
            </form>
        </div>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="results">
                <div class="message <?= $messageClass ?>"><?= $message ?></div>
                
                <?php if (!empty($fileName)): ?>
                    <div class="file-info">
                        <p><strong>File uploaded:</strong> <?= htmlspecialchars($fileName) ?></p>
                        <p><strong>File size:</strong> <?= round($fileSize / 1024, 2) ?> KB</p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Patients Processed</td>
                                <td><?= $results['patients'] ?></td>
                            </tr>
                            <tr>
                                <td>Tests Imported</td>
                                <td><?= $results['tests'] ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <?php if (!empty($results['errors'])): ?>
                    <h3>Errors Encountered (<?= count($results['errors']) ?>):</h3>
                    <div class="error-list">
                        <?php foreach ($results['errors'] as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <a href="index.php" class="back-link">&larr; Return to Dashboard</a>
    </div>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</body>
</html>
