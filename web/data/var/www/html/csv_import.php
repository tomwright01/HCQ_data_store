<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Base data directory
$baseDataDir = "/var/www/html/data/";

// Initialize variables
$selectedFile = $_POST['csv_file'] ?? '';
$customPath = $_POST['custom_path'] ?? '';
$csvFilePath = '';
$message = '';
$messageClass = '';
$results = [
    'patients' => 0,
    'tests' => 0,
    'errors' => []
];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Determine which file input was used
    if (!empty($customPath)) {
        // Use custom path if provided
        $csvFilePath = $customPath;
        $inputMethod = 'custom';
    } else {
        // Use dropdown selection if no custom path
        $csvFilePath = $baseDataDir . basename($selectedFile);
        $inputMethod = 'dropdown';
    }
    
    // Validate file selection
    if (empty($csvFilePath)) {
        $message = "Please either select a file or enter a file path";
        $messageClass = 'error';
    } else {
        // Verify file exists and is CSV
        if (!file_exists($csvFilePath)) {
            $message = "File does not exist: " . htmlspecialchars($csvFilePath);
            $messageClass = 'error';
        } elseif (strtolower(pathinfo($csvFilePath, PATHINFO_EXTENSION)) !== 'csv') {
            $message = "Selected file is not a CSV file: " . htmlspecialchars($csvFilePath);
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

// Database functions (unchanged from previous version)
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
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);
    
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

// Get list of available CSV files
$availableFiles = [];
if (is_dir($baseDataDir)) {
    $files = scandir($baseDataDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'csv') {
            $availableFiles[] = $file;
        }
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
        select, input[type="text"], input[type="submit"] { padding: 8px 12px; font-size: 16px; margin-bottom: 10px; }
        input[type="submit"] { background-color: #007bff; color: white; border: none; cursor: pointer; }
        input[type="submit"]:hover { background-color: #0069d9; }
        .file-selector { margin-bottom: 20px; }
        .file-selector label { margin-bottom: 5px; }
        .file-selector p { margin-top: 0; font-size: 0.9em; color: #666; }
        .tabs { display: flex; margin-bottom: 15px; }
        .tab { padding: 10px 15px; background: #eee; cursor: pointer; border: 1px solid #ddd; border-bottom: none; }
        .tab.active { background: #fff; border-bottom: 1px solid #fff; }
        .tab-content { display: none; padding: 15px; border: 1px solid #ddd; }
        .tab-content.active { display: block; }
    </style>
    <script>
        function showTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Deactivate all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId + '-content').classList.add('active');
            
            // Activate selected tab
            document.getElementById(tabId + '-tab').classList.add('active');
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>CSV Import Tool</h1>
        
        <form method="post" action="">
            <div class="tabs">
                <div id="default-tab" class="tab active" onclick="showTab('default')">Default Directory</div>
                <div id="custom-tab" class="tab" onclick="showTab('custom')">Custom Path</div>
            </div>
            
            <div id="default-content" class="tab-content active">
                <div class="file-selector">
                    <label for="csv_file">Select CSV File from Default Directory:</label>
                    <select name="csv_file" id="csv_file">
                        <option value="">-- Select a file --</option>
                        <?php foreach ($availableFiles as $file): ?>
                            <option value="<?= htmlspecialchars($file) ?>" <?= $selectedFile === $file ? 'selected' : '' ?>>
                                <?= htmlspecialchars($file) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p>Default directory: <?= htmlspecialchars($baseDataDir) ?></p>
                </div>
            </div>
            
            <div id="custom-content" class="tab-content">
                <div class="file-selector">
                    <label for="custom_path">Enter Full Path to CSV File:</label>
                    <input type="text" name="custom_path" id="custom_path" value="<?= htmlspecialchars($customPath) ?>" placeholder="e.g., /path/to/your/file.csv">
                    <p>Example: /home/user/data/patient_data.csv</p>
                </div>
            </div>
            
            <input type="submit" name="submit" value="Import File">
        </form>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="results">
                <h2 class="<?= $messageClass ?>"><?= $message ?></h2>
                
                <?php if (!empty($csvFilePath)): ?>
                    <p>File processed: <?= htmlspecialchars($csvFilePath) ?></p>
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
