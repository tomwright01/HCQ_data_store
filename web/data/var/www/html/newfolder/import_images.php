<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Increase system limits for bulk processing
set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '1000');

$message = '';
$messageType = '';

function processBulkImages($testType, $sourcePath) {
    global $conn;
    
    $results = [
        'processed' => 0,
        'success' => 0,
        'errors' => [],
        'warnings' => []
    ];

    // Normalize and validate paths
    $sourcePath = rtrim(realpath($sourcePath), '/') . '/';
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    
    if (!is_dir($sourcePath)) {
        throw new Exception("Source directory does not exist or is inaccessible: " . htmlspecialchars($sourcePath));
    }

    // Ensure target directory exists
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        throw new Exception("Failed to create target directory: " . htmlspecialchars($targetDir));
    }

    // Recursive directory scanner with error handling
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
    } catch (Exception $e) {
        throw new Exception("Failed to scan directory: " . $e->getMessage());
    }

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
            $results['processed']++;
            $filename = $file->getFilename();
            $sourceFile = $file->getPathname();
            
            try {
                // Parse filename with strict validation
                if (!preg_match('/^([A-Za-z0-9-]+)_(OD|OS)_(\d{8})\.png$/i', $filename, $matches)) {
                    throw new Exception("Filename must follow pattern: patientid_eye_YYYYMMDD.png");
                }

                $patientId = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];

                // Validate and format date
                $testDate = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$testDate || $testDate->format('Ymd') !== $dateStr) {
                    throw new Exception("Invalid or malformed date in filename");
                }
                $testDate = $testDate->format('Y-m-d');

                // Verify patient exists
                if (!getPatientById($patientId)) {
                    throw new Exception("Patient $patientId not found in database");
                }

                // Prepare target path and handle duplicates
                $targetFile = $targetDir . $filename;
                if (file_exists($targetFile)) {
                    $results['warnings'][] = "Skipped existing file: $filename";
                    continue;
                }

                // Copy with error handling
                if (!@copy($sourceFile, $targetFile)) {
                    $error = error_get_last();
                    throw new Exception("File copy failed: " . ($error['message'] ?? 'Unknown error'));
                }

                // Database operations
                $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
                $testId = generateTestId($patientId, $testDate);
                
                if (!updateOrCreateTestRecord($testId, $patientId, $testDate, $imageField, $filename)) {
                    throw new Exception("Database operation failed");
                }

                $results['success']++;
            } catch (Exception $e) {
                $results['errors'][] = [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'path' => $sourceFile
                ];
            }
        }
    }

    return $results;
}

function generateTestId($patientId, $testDate) {
    return $patientId . '_' . date('Ymd', strtotime($testDate)) . '_' . substr(md5(uniqid()), 0, 6);
}

function updateOrCreateTestRecord($testId, $patientId, $testDate, $fieldName, $filename) {
    global $conn;
    
    // Check for existing test
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
    $stmt->bind_param("ss", $patientId, $testDate);
    $stmt->execute();
    $existingTest = $stmt->get_result()->fetch_assoc();
    
    // Prepare appropriate query
    if ($existingTest) {
        $query = "UPDATE tests SET $fieldName = ? WHERE test_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $filename, $existingTest['test_id']);
    } else {
        $query = "INSERT INTO tests (test_id, patient_id, date_of_test, $fieldName) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssss", $testId, $patientId, $testDate, $filename);
    }
    
    return $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            // Single file upload processing
            $testType = $_POST['test_type'] ?? '';
            $eye = $_POST['eye'] ?? '';
            $patientId = $_POST['patient_id'] ?? '';
            $testDate = $_POST['test_date'] ?? '';
            
            // Validate all fields
            if (empty($testType) || empty($eye) || empty($patientId) || empty($testDate)) {
                throw new Exception("All fields are required");
            }
            
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }
            
            if (!in_array($eye, ['OD', 'OS'])) {
                throw new Exception("Eye must be either OD (right) or OS (left)");
            }
            
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid image file");
            }
            
            // Validate file type
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $fileInfo->file($_FILES['image']['tmp_name']);
            if ($mime !== 'image/png') {
                throw new Exception("Only PNG images are allowed (detected: $mime)");
            }
            
            // Process the upload
            if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                $message = "Image uploaded and database updated successfully!";
                $messageType = 'success';
            } else {
                throw new Exception("Failed to process image upload");
            }
        } elseif (isset($_POST['bulk_import'])) {
            // Bulk import processing
            $testType = $_POST['bulk_test_type'] ?? '';
            $sourcePath = $_POST['folder_path'] ?? '';
            
            if (empty($testType) || empty($sourcePath)) {
                throw new Exception("Test type and folder path are required");
            }
            
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }
            
            $results = processBulkImages($testType, $sourcePath);
            
            // Prepare comprehensive results message
            $message = "<div class='results-summary'>";
            $message .= "<h3>Bulk Import Results</h3>";
            $message .= "<div class='stats-grid'>";
            $message .= "<div class='stat-box'><span>Files Processed</span><strong>{$results['processed']}</strong></div>";
            $message .= "<div class='stat-box success'><span>Successful</span><strong>{$results['success']}</strong></div>";
            $message .= "<div class='stat-box error'><span>Errors</span><strong>" . count($results['errors']) . "</strong></div>";
            $message .= "</div>";
            
            if (!empty($results['warnings'])) {
                $message .= "<div class='warning-section'><h4>Warnings:</h4><ul>";
                foreach (array_slice($results['warnings'], 0, 10) as $warning) {
                    $message .= "<li>" . htmlspecialchars($warning) . "</li>";
                }
                if (count($results['warnings']) > 10) {
                    $message .= "<li>... and " . (count($results['warnings']) - 10) . " more warnings</li>";
                }
                $message .= "</ul></div>";
            }
            
            if (!empty($results['errors'])) {
                $message .= "<div class='error-section'><h4>Errors:</h4><ul>";
                foreach (array_slice($results['errors'], 0, 20) as $error) {
                    $message .= "<li><strong>" . htmlspecialchars($error['file']) . "</strong>: " . 
                               htmlspecialchars($error['error']) . 
                               "<br><small>" . htmlspecialchars($error['path']) . "</small></li>";
                }
                if (count($results['errors']) > 20) {
                    $message .= "<li>... and " . (count($results['errors']) - 20) . " more errors</li>";
                }
                $message .= "</ul></div>";
            }
            
            $message .= "</div>";
            
            $messageType = empty($results['errors']) ? 'success' : 
                          ($results['success'] > 0 ? 'warning' : 'error');
        }
    } catch (Exception $e) {
        $message = "<strong>Error:</strong> " . $e->getMessage();
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Image Importer</title>
    <style>
        :root {
            --primary: #0066cc;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: var(--light);
            padding: 20px;
            margin: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1, h2, h3, h4 {
            color: var(--primary);
            margin-top: 0;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        select, input[type="text"], input[type="date"], input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        button[type="submit"] {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
            width: 100%;
            margin-top: 10px;
        }
        
        button[type="submit"]:hover {
            background-color: #0055aa;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .bulk-import-btn {
            background-color: var(--success);
        }
        
        .bulk-import-btn:hover {
            background-color: #218838;
        }
        
        .message {
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
            border-left: 5px solid;
        }
        
        .success {
            background-color: #e6f7e6;
            border-color: var(--success);
        }
        
        .error {
            background-color: #ffebee;
            border-color: var(--danger);
        }
        
        .warning {
            background-color: #fff8e1;
            border-color: var(--warning);
        }
        
        .results-summary {
            margin-top: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-box {
            padding: 15px;
            border-radius: 4px;
            text-align: center;
            background-color: var(--light);
        }
        
        .stat-box span {
            display: block;
            font-size: 0.9em;
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .stat-box strong {
            font-size: 1.8em;
            font-weight: 600;
        }
        
        .stat-box.success {
            background-color: rgba(40, 167, 69, 0.1);
        }
        
        .stat-box.success strong {
            color: var(--success);
        }
        
        .stat-box.error {
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        .stat-box.error strong {
            color: var(--danger);
        }
        
        .warning-section, .error-section {
            margin: 20px 0;
            padding: 15px;
            border-radius: 4px;
        }
        
        .warning-section {
            background-color: #fff8e1;
            border-left: 4px solid var(--warning);
        }
        
        .error-section {
            background-color: #f8f9fa;
            border-left: 4px solid var(--danger);
            max-height: 500px;
            overflow-y: auto;
        }
        
        .error-section ul, .warning-section ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        
        .error-section li, .warning-section li {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .error-section li:last-child, .warning-section li:last-child {
            border-bottom: none;
        }
        
        .requirements-box {
            background-color: #f0f7ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid var(--primary);
        }
        
        code {
            background-color: #e0e0e0;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .back-link:hover {
            color: #0055aa;
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Medical Image Importer</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Single Image Upload</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="test_type">Test Type:</label>
                    <select name="test_type" id="test_type" required>
                        <option value="">Select Test Type</option>
                        <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                            <option value="<?= $type ?>"><?= $type ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="eye">Eye:</label>
                    <select name="eye" id="eye" required>
                        <option value="">Select Eye</option>
                        <option value="OD">Right Eye (OD)</option>
                        <option value="OS">Left Eye (OS)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="patient_id">Patient ID:</label>
                    <input type="text" name="patient_id" id="patient_id" required>
                </div>
                
                <div class="form-group">
                    <label for="test_date">Test Date:</label>
                    <input type="date" name="test_date" id="test_date" required>
                </div>
                
                <div class="form-group">
                    <label for="image">Image File (PNG only):</label>
                    <input type="file" name="image" id="image" accept="image/png" required>
                </div>
                
                <button type="submit" name="import">Upload Image</button>
            </form>
        </div>

        <div class="form-section">
            <h2>Bulk Import from Folder</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="bulk_test_type">Test Type:</label>
                    <select name="bulk_test_type" id="bulk_test_type" required>
                        <option value="">Select Test Type</option>
                        <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                            <option value="<?= $type ?>"><?= $type ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="folder_path">Source Folder Path:</label>
                    <input type="text" name="folder_path" id="folder_path" required 
                           value="<?= htmlspecialchars(IMAGE_BASE_DIR . 'FAF/') ?>"
                           placeholder="e.g., /var/www/html/data/FAF/">
                </div>
                
                <div class="requirements-box">
                    <h3>Bulk Import Requirements</h3>
                    <ul>
                        <li>All files must be in <strong>PNG format</strong></li>
                        <li>File naming pattern: <code>patientid_eye_YYYYMMDD.png</code></li>
                        <li>Example: <code>PT1001_OD_20230115.png</code></li>
                        <li>Patient ID must exist in the database</li>
                        <li>Eye must be either <code>OD</code> (right) or <code>OS</code> (left)</li>
                        <li>Date must be in valid <code>YYYYMMDD</code> format</li>
                        <li>System will process all matching files in the specified folder and subfolders</li>
                    </ul>
                </div>
                
                <button type="submit" name="bulk_import" class="bulk-import-btn">
                    Process All Images in Folder
                </button>
            </form>
        </div>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
