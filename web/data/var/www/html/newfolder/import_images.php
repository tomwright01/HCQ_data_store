<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Increase limits for bulk processing
set_time_limit(600);
ini_set('memory_limit', '1024M');

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

    // Validate and normalize paths
    $sourcePath = rtrim($sourcePath, '/') . '/';
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    
    if (!is_dir($sourcePath)) {
        throw new Exception("Source directory does not exist: " . htmlspecialchars($sourcePath));
    }

    // Create target directory if needed
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            throw new Exception("Failed to create target directory: " . htmlspecialchars($targetDir));
        }
    }

    // Recursive directory iterator
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
            $results['processed']++;
            $filename = $file->getFilename();
            $sourceFile = $file->getPathname();
            
            try {
                // Parse filename (patientid_eye_YYYYMMDD.png)
                if (!preg_match('/^([A-Za-z0-9]+)_(OD|OS)_(\d{8})\.png$/i', $filename, $matches)) {
                    throw new Exception("Invalid filename format");
                }

                $patientId = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];

                // Validate date
                $testDate = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$testDate) {
                    throw new Exception("Invalid date in filename");
                }
                $testDate = $testDate->format('Y-m-d');

                // Verify patient exists
                $patient = getPatientById($patientId);
                if (!$patient) {
                    throw new Exception("Patient $patientId not found in database");
                }

                // Prepare target path
                $targetFile = $targetDir . $filename;
                
                // Copy image to target directory
                if (file_exists($targetFile)) {
                    $results['warnings'][] = "Skipped existing file: $filename";
                    continue;
                }

                if (!copy($sourceFile, $targetFile)) {
                    throw new Exception("Failed to copy image to target directory");
                }

                // Prepare database fields
                $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
                
                // Check for existing test record
                $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
                $stmt->bind_param("ss", $patientId, $testDate);
                $stmt->execute();
                $test = $stmt->get_result()->fetch_assoc();

                // Generate test ID if new record
                $testId = $test ? $test['test_id'] : 
                    $patientId . '_' . date('Ymd', strtotime($testDate)) . '_' . substr(md5(uniqid()), 0, 4);

                // Update or insert test record
                if ($test) {
                    $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
                    $stmt->bind_param("ss", $filename, $testId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO tests 
                        (test_id, patient_id, date_of_test, $imageField) 
                        VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $testId, $patientId, $testDate, $filename);
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

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import'])) {
    try {
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
        $message .= "<div class='stat-box total'><span>Files Processed</span><strong>{$results['processed']}</strong></div>";
        $message .= "<div class='stat-box success'><span>Successful</span><strong>{$results['success']}</strong></div>";
        $message .= "<div class='stat-box errors'><span>Errors</span><strong>" . count($results['errors']) . "</strong></div>";
        $message .= "</div>";
        
        if (!empty($results['warnings'])) {
            $message .= "<div class='warning-box'><h4>Warnings:</h4><ul>";
            foreach (array_slice($results['warnings'], 0, 10) as $warning) {
                $message .= "<li>" . htmlspecialchars($warning) . "</li>";
            }
            if (count($results['warnings']) > 10) {
                $message .= "<li>... and " . (count($results['warnings']) - 10) . " more warnings</li>";
            }
            $message .= "</ul></div>";
        }
        
        if (!empty($results['errors'])) {
            $message .= "<div class='error-details'><h4>Error Details:</h4><ul>";
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
    <title>Automated Image Bulk Importer</title>
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
        select, input[type="text"] {
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
        .results-container {
            margin-top: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            padding: 15px;
            border-radius: 4px;
            text-align: center;
            background-color: #f8f9fa;
        }
        .stat-box span {
            display: block;
            font-size: 0.9em;
            color: #666;
        }
        .stat-box strong {
            font-size: 1.5em;
            color: var(--dark);
        }
        .stat-box.total strong { color: var(--primary); }
        .stat-box.success strong { color: var(--success); }
        .stat-box.errors strong { color: var(--danger); }
        .warning-box {
            background-color: #fff8e1;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            border-left: 4px solid var(--warning);
        }
        .error-details {
            max-height: 500px;
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
        <h1>Automated Image Bulk Importer</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Bulk Import Configuration</h2>
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
                    <label for="folder_path">Source Images Folder:</label>
                    <input type="text" name="folder_path" id="folder_path" required 
                           value="<?= htmlspecialchars(IMAGE_BASE_DIR . 'FAF/') ?>"
                           placeholder="e.g., /var/www/html/data/FAF/">
                </div>
                
                <div class="file-requirements">
                    <h3>Automatic Processing Requirements</h3>
                    <ul>
                        <li><strong>File Format:</strong> Must be PNG</li>
                        <li><strong>Naming Pattern:</strong> <code>patientid_eye_YYYYMMDD.png</code></li>
                        <li><strong>Example:</strong> <code>PT1001_OD_20230115.png</code></li>
                        <li><strong>Patient ID:</strong> Must exist in database</li>
                        <li><strong>Eye:</strong> Must be <code>OD</code> (right) or <code>OS</code> (left)</li>
                        <li><strong>Date:</strong> Must be valid <code>YYYYMMDD</code> format</li>
                    </ul>
                </div>
                
                <button type="submit" name="bulk_import" class="bulk-import-btn">
                    Process and Import All Images Automatically
                </button>
            </form>
        </div>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
