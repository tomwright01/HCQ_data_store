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

                            // Generate patient_id (use subjectId as patient_id)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import Tool | Hydroxychloroquine Data Repository</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: rgb(0, 168, 143);
            --primary-dark: rgb(0, 140, 120);
            --primary-light: rgb(178, 226, 226);
            --secondary-color: rgb(102, 194, 164);
            --success-color: #28a745;
            --error-color: #dc3545;
            --warning-color: #ffc107;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #343a40;
            --text-color: #212529;
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
            padding: 0;
            margin: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo {
            height: 50px;
        }
        
        h1 {
            font-size: 2.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: white;
        }
        
        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .card-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
        }
        
        .card-title i {
            margin-right: 10px;
            font-size: 1.8rem;
        }
        
        .upload-area {
            border: 2px dashed var(--primary-light);
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            margin-bottom: 20px;
            transition: all 0.3s;
            background-color: rgba(178, 226, 226, 0.1);
        }
        
        .upload-area:hover {
            border-color: var(--primary-color);
            background-color: rgba(178, 226, 226, 0.2);
        }
        
        .upload-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .file-input {
            display: none;
        }
        
        .file-label {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 168, 143, 0.3);
        }
        
        .file-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 168, 143, 0.4);
        }
        
        .file-name {
            margin-top: 15px;
            font-size: 0.9rem;
            color: var(--dark-gray);
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(0, 168, 143, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 168, 143, 0.4);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .message i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .success {
            background-color: rgba(40, 167, 69, 0.15);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        
        .error {
            background-color: rgba(220, 53, 69, 0.15);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        
        .warning {
            background-color: rgba(255, 193, 7, 0.15);
            color: var(--warning-color);
            border-left: 4px solid var(--warning-color);
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .results-table th, 
        .results-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }
        
        .results-table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }
        
        .results-table tr:nth-child(even) {
            background-color: var(--light-gray);
        }
        
        .results-table tr:hover {
            background-color: rgba(0, 168, 143, 0.05);
        }
        
        .error-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            padding: 0;
            margin: 20px 0;
        }
        
        .error-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--medium-gray);
            font-size: 0.9rem;
        }
        
        .error-item:last-child {
            border-bottom: none;
        }
        
        .error-item i {
            color: var(--error-color);
            margin-right: 8px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--dark-gray);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            margin-top: 20px;
            transition: all 0.3s;
        }
        
        .back-link i {
            margin-right: 8px;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            color: var(--primary-dark);
        }
        
        .back-link:hover i {
            transform: translateX(-3px);
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .logo {
                margin-bottom: 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .upload-area {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <img src="images/kensington-logo-white.png" alt="Kensington Health Logo" class="logo">
            <h1>CSV Import Tool</h1>
        </div>
    </header>
    
    <div class="container">
        <div class="card">
            <h2 class="card-title"><i class="fas fa-file-import"></i> Import Patient Data</h2>
            
            <form method="post" action="" enctype="multipart/form-data" id="importForm">
                <div class="upload-area" id="dropZone">
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <h3>Drag & Drop your CSV file here</h3>
                    <p>or</p>
                    <input type="file" name="csv_file" id="csv_file" class="file-input" accept=".csv" required>
                    <label for="csv_file" class="file-label">
                        <i class="fas fa-folder-open"></i> Select File
                    </label>
                    <div class="file-name" id="fileName">No file selected</div>
                </div>
                
                <button type="submit" name="submit" class="btn btn-block">
                    <i class="fas fa-upload"></i> Import File
                </button>
            </form>
        </div>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-chart-bar"></i> Import Results</h2>
                
                <?php if ($message): ?>
                    <div class="message <?= $messageClass ?>">
                        <i class="fas <?= $messageClass === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($fileName)): ?>
                    <div class="message">
                        <i class="fas fa-file-csv"></i>
                        File processed: <strong><?= htmlspecialchars($fileName) ?></strong>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($results['patients']) || !empty($results['tests'])): ?>
                    <div class="stats-cards">
                        <div class="stat-card">
                            <div class="stat-value"><?= $results['patients'] ?></div>
                            <div class="stat-label">Patients Processed</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= $results['tests'] ?></div>
                            <div class="stat-label">Tests Imported</div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($results['errors'])): ?>
                    <h3 style="margin: 20px 0 10px; color: var(--error-color);">
                        <i class="fas fa-exclamation-triangle"></i> Errors Encountered (<?= count($results['errors']) ?>)
                    </h3>
                    <div class="error-list">
                        <?php foreach ($results['errors'] as $error): ?>
                            <div class="error-item">
                                <i class="fas fa-times-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Return to Dashboard
        </a>
    </div>

    <script>
        // File input handling
        const fileInput = document.getElementById('csv_file');
        const fileName = document.getElementById('fileName');
        const dropZone = document.getElementById('dropZone');
        
        fileInput.addEventListener('change', function(e) {
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name;
            } else {
                fileName.textContent = 'No file selected';
            }
        });
        
        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dropZone.style.borderColor = 'var(--primary-color)';
            dropZone.style.backgroundColor = 'rgba(178, 226, 226, 0.3)';
        }
        
        function unhighlight() {
            dropZone.style.borderColor = 'var(--primary-light)';
            dropZone.style.backgroundColor = 'rgba(178, 226, 226, 0.1)';
        }
        
        dropZone.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                fileName.textContent = files[0].name;
            }
        }
    </script>
</body>
</html>
