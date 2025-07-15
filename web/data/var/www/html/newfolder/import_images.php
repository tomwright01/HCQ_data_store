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
        'errors' => []
    ];

    // Validate and normalize paths
    $sourcePath = rtrim($sourcePath, '/') . '/';
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    
    if (!is_dir($sourcePath)) {
        throw new Exception("Source directory does not exist: " . htmlspecialchars($sourcePath));
    }

    // Create target directory if needed
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        throw new Exception("Failed to create target directory: " . htmlspecialchars($targetDir));
    }

    // Get all PNG files recursively
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // Start transaction for bulk processing
    $conn->begin_transaction();

    try {
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
                $results['processed']++;
                $filename = $file->getFilename();
                $sourceFile = $file->getPathname();
                
                try {
                    // Parse filename (patientid_eye_YYYYMMDD.png)
                    if (!preg_match('/^([A-Za-z0-9]+)_(OD|OS)_(\d{8})\.png$/i', $filename, $matches)) {
                        throw new Exception("Invalid filename format - must be patientid_eye_YYYYMMDD.png");
                    }

                    $patientId = $matches[1];
                    $eye = strtoupper($matches[2]);
                    $dateStr = $matches[3];

                    // Validate date
                    $testDate = DateTime::createFromFormat('Ymd', $dateStr);
                    if (!$testDate) {
                        throw new Exception("Invalid date in filename (must be YYYYMMDD)");
                    }
                    $testDate = $testDate->format('Y-m-d');

                    // Verify patient exists
                    $patient = getPatientById($patientId);
                    if (!$patient) {
                        throw new Exception("Patient $patientId not found in database");
                    }

                    // Prepare target path
                    $targetFile = $targetDir . $filename;
                    
                    // Copy image to target directory (overwrite if exists)
                    if (!copy($sourceFile, $targetFile)) {
                        throw new Exception("Failed to copy image to target directory");
                    }

                    // Prepare database field name based on test type
                    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
                    
                    // Check for existing test record
                    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
                    $stmt->bind_param("sss", $patientId, $testDate, $eye);
                    $stmt->execute();
                    $testResult = $stmt->get_result();
                    $testExists = $testResult->num_rows > 0;
                    $testId = $testExists ? $testResult->fetch_assoc()['test_id'] : null;

                    // Generate test ID if new record
                    if (!$testExists) {
                        $testId = $patientId . '_' . str_replace('-', '', $testDate) . '_' . $eye;
                    }

                    // Update or insert test record
                    if ($testExists) {
                        $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
                        $stmt->bind_param("ss", $filename, $testId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO tests 
                            (test_id, patient_id, date_of_test, eye, $imageField) 
                            VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssss", $testId, $patientId, $testDate, $eye, $filename);
                    }

                    if (!$stmt->execute()) {
                        throw new Exception("Database error: " . $conn->error);
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

        // Commit transaction if no critical errors
        if (count($results['errors']) < 50) { // Allow some failures without rollback
            $conn->commit();
        } else {
            $conn->rollback();
            throw new Exception("Too many errors (" . count($results['errors']) . "), transaction rolled back");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['bulk_import'])) {
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
            
            // Prepare results message
            $message = "<div class='results-container'>";
            $message .= "<h3>Bulk Import Results</h3>";
            $message .= "<div class='stats-grid'>";
            $message .= "<div class='stat-box'><span>Files Processed</span><strong>{$results['processed']}</strong></div>";
            $message .= "<div class='stat-box success'><span>Successful</span><strong>{$results['success']}</strong></div>";
            $message .= "<div class='stat-box error'><span>Errors</span><strong>" . count($results['errors']) . "</strong></div>";
            $message .= "</div>";
            
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
    <title>Medical Image Bulk Importer</title>
    <style>
        :root {
            --primary: rgb(0, 168, 143);
            --primary-dark: rgb(0, 140, 120);
            --primary-light: rgba(0, 168, 143, 0.1);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: white;
            padding: 0;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .container {
            width: 100%;
            max-width: 900px;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border: 1px solid #ddd;
            margin: 20px;
        }
        
        h1, h2, h3, h4 {
            color: var(--primary);
            margin-top: 0;
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        select, input[type="text"] {
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
            transition: background-color 0.3s;
            width: 100%;
            margin-top: 10px;
        }
        
        button[type="submit"]:hover {
            background-color: var(--primary-dark);
        }
        
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }
        
        .success {
            background-color: #e6f7e6;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        
        .warning {
            background-color: #fcf8e3;
            color: #8a6d3b;
            border: 1px solid #faebcc;
        }
        
        .results-container {
            margin-top: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 15px 0;
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
        }
        
        .stat-box strong {
            font-size: 1.5em;
            font-weight: 600;
        }
        
        .stat-box.success strong {
            color: var(--success);
        }
        
        .stat-box.error strong {
            color: var(--danger);
        }
        
        .error-section {
            max-height: 300px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .error-section ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        
        .error-section li {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .error-section li:last-child {
            border-bottom: none;
        }
        
        .requirements-box {
            background-color: var(--primary-light);
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
        }
        
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: var(--primary);
            text-decoration: none;
            font-weight: bold;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
                margin: 10px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Medical Image Bulk Importer</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

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
                    <h3>File Requirements for Bulk Import</h3>
                    <ul>
                        <li>All files must be in <strong>PNG format</strong></li>
                        <li>Files must be named: <code>patientid_eye_YYYYMMDD.png</code></li>
                        <li>Example: <code>12345_OD_20230715.png</code></li>
                        <li>Patient ID must exist in the database</li>
                        <li>Eye must be either <strong>OD</strong> (right) or <strong>OS</strong> (left)</li>
                        <li>Date must be in <strong>YYYYMMDD</strong> format</li>
                        <li>Will process all matching files in the folder and subfolders</li>
                    </ul>
                </div>
                
                <button type="submit" name="bulk_import">
                    Process All Images in Folder
                </button>
            </form>
        </div>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
