<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Increase limits for bulk processing
set_time_limit(300);
ini_set('memory_limit', '512M');

$message = '';
$messageType = '';

function processBulkImport($testType, $sourceDir) {
    global $conn;
    
    $results = [
        'success' => 0,
        'errors' => [],
        'total' => 0
    ];

    // Validate and normalize directory path
    $sourceDir = rtrim($sourceDir, '/') . '/';
    if (!is_dir($sourceDir)) {
        throw new Exception("Source directory does not exist: " . htmlspecialchars($sourceDir));
    }

    // Create target directory if it doesn't exist
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            throw new Exception("Failed to create target directory: " . htmlspecialchars($targetDir));
        }
    }

    // Scan for PNG files recursively
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
            $results['total']++;
            $filename = $file->getFilename();
            $sourcePath = $file->getPathname();

            try {
                // Parse filename (patientid_eye_YYYYMMDD.png)
                if (!preg_match('/^([^_]+)_(OD|OS)_(\d{8})\.png$/i', $filename, $matches)) {
                    throw new Exception("Filename doesn't match required pattern");
                }

                $patient_id = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];

                // Validate date
                $test_date = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$test_date) {
                    throw new Exception("Invalid date format in filename");
                }
                $test_date = $test_date->format('Y-m-d');

                // Verify patient exists
                $patient = getPatientById($patient_id);
                if (!$patient) {
                    throw new Exception("Patient $patient_id not found in database");
                }

                // Prepare database fields
                $fieldName = strtolower($testType) . '_reference_' . strtolower($eye);
                $targetPath = $targetDir . $filename;

                // Check if file already exists in target location
                if (file_exists($targetPath)) {
                    throw new Exception("File already exists in target location");
                }

                // Copy file to target directory
                if (!copy($sourcePath, $targetPath)) {
                    throw new Exception("Failed to copy file to target directory");
                }

                // Check if test record already exists
                $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
                $stmt->bind_param("ss", $patient_id, $test_date);
                $stmt->execute();
                $test = $stmt->get_result()->fetch_assoc();

                // Generate unique test ID if new record
                $testId = $test ? $test['test_id'] : 
                    date('Ymd', strtotime($test_date)) . '_' . $patient_id . '_' . substr(md5(uniqid()), 0, 4);

                // Update or insert test record
                if ($test) {
                    $stmt = $conn->prepare("UPDATE tests SET $fieldName = ? WHERE test_id = ?");
                } else {
                    $stmt = $conn->prepare("INSERT INTO tests 
                        (test_id, patient_id, date_of_test, $fieldName) 
                        VALUES (?, ?, ?, ?)");
                }
                
                $stmt->bind_param("ssss", $filename, $testId, $patient_id, $test_date);
                
                if (!$stmt->execute()) {
                    throw new Exception("Database error: " . $conn->error);
                }

                $results['success']++;
            } catch (Exception $e) {
                $results['errors'][] = [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'path' => $sourcePath
                ];
            }
        }
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            // [Keep existing single file upload code]
        } elseif (isset($_POST['bulk_import'])) {
            $testType = $_POST['bulk_test_type'] ?? '';
            $sourceDir = $_POST['folder_path'] ?? '';
            
            if (empty($testType) || empty($sourceDir)) {
                throw new Exception("Test type and folder path are required");
            }
            
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }
            
            $results = processBulkImport($testType, $sourceDir);
            
            // Prepare detailed results message
            $message = "<div class='result-header'>";
            $message .= "<h3>Bulk Import Results</h3>";
            $message .= "<p>Scanned directory: <code>" . htmlspecialchars($sourceDir) . "</code></p>";
            $message .= "<div class='result-stats'>";
            $message .= "<p class='stat-total'>Total files found: <strong>{$results['total']}</strong></p>";
            $message .= "<p class='stat-success'>Successfully processed: <strong>{$results['success']}</strong></p>";
            $message .= "<p class='stat-failed'>Failed: <strong>" . count($results['errors']) . "</strong></p>";
            $message .= "</div></div>";
            
            if (!empty($results['errors'])) {
                $message .= "<div class='error-details'><h4>Error Details:</h4><ul>";
                foreach (array_slice($results['errors'], 0, 50) as $error) {
                    $message .= "<li><strong>" . htmlspecialchars($error['file']) . "</strong>: " . 
                               htmlspecialchars($error['error']) . 
                               "<br><small>" . htmlspecialchars($error['path']) . "</small></li>";
                }
                if (count($results['errors']) > 50) {
                    $message .= "<li>... and " . (count($results['errors']) - 50) . " more errors</li>";
                }
                $message .= "</ul></div>";
            }
            
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
            --primary: #0066cc;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: var(--light);
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
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
        select, input[type="text"], input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
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
        }
        button[type="submit"]:hover {
            background-color: #0055aa;
            transform: translateY(-2px);
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
        .result-header {
            margin-bottom: 20px;
        }
        .result-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 15px 0;
        }
        .stat-total { color: var(--dark); }
        .stat-success { color: var(--success); }
        .stat-failed { color: var(--danger); }
        .error-details {
            max-height: 400px;
            overflow-y: auto;
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #eee;
        }
        .error-details ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        .error-details li {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .error-details li:last-child {
            border-bottom: none;
        }
        .file-requirements {
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
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary);
            text-decoration: none;
            font-weight: bold;
        }
        .back-link:hover {
            text-decoration: underline;
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
                
                <button type="submit" name="import">Import Image</button>
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
                
                <div class="file-requirements">
                    <h3>File Requirements</h3>
                    <ul>
                        <li>Files must be in <strong>PNG format</strong></li>
                        <li>Naming pattern: <code>patientid_eye_YYYYMMDD.png</code></li>
                        <li>Example: <code>PT1001_OD_20230115.png</code></li>
                        <li>Patient must exist in database</li>
                        <li>Eye must be either <strong>OD</strong> (right) or <strong>OS</strong> (left)</li>
                        <li>Date must be in <strong>YYYYMMDD</strong> format</li>
                    </ul>
                </div>
                
                <button type="submit" name="bulk_import" class="bulk-import-btn">
                    Process All PNG Files in Folder
                </button>
            </form>
        </div>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
