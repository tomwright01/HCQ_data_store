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

                            // Process TEST_ID (column 5/[4])
                            $testNumber = $data[4] ?? null;
                            if ($testNumber !== null && !is_numeric($testNumber)) {
                                throw new Exception("Invalid TEST_ID: must be a number");
                            }

                            // Process Eye (column 6/[5])
                            $eyeValue = $data[5] ?? null;
                            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                            // Generate test_id (date + eye + test number)
                            $testDateFormatted = $testDate->format('Ymd');
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
                            $allowedDiagnosis = ['RA', 'SLE', 'Sjorgens', 'other'];
                            if (!empty($data[14])) {
                                $diag = ucfirst(strtolower(trim($data[14]))); // normalize
                                $actualDiagnosis = in_array($diag, $allowedDiagnosis) ? $diag : 'other';
                            } else {
                                $actualDiagnosis = null;
                            }

                            // Process dosage (column 16/[15])
                            $dosage = isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;

                            // Process duration (column 17/[16])
                            $durationDays = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;

                            // Process cumulative dosage (column 18/[17])
                            $cumulativeDosage = isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

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
                                'cumulative_dosage' => $cumulativeDosage
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
            test_id, patient_id, location, date_of_test, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, dosage, duration_days,
            cumulative_dosage
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' : 
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);
    
    $stmt->bind_param(
        "ssssissssssdddsdid",
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
        $testData['cumulative_dosage']
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
            flex-direction: column;
            justify-content: center;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            text-align: center;
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
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
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
