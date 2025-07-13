<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Set higher limits for bulk processing
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

$message = '';
$messageType = '';

function processBulkImport($testType, $directory) {
    global $conn;
    
    $results = [
        'success' => 0,
        'errors' => [],
        'total' => 0
    ];

    // Validate directory
    if (!is_dir($directory)) {
        throw new Exception("Directory does not exist: " . htmlspecialchars($directory));
    }

    // Scan directory recursively for PNG files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
            $results['total']++;
            $filename = $file->getFilename();
            $fullPath = $file->getPathname();

            try {
                // Parse filename (patientid_eye_YYYYMMDD.png)
                if (!preg_match('/^(\w+)_(OD|OS)_(\d{8})\.png$/i', $filename, $matches)) {
                    throw new Exception("Invalid filename format");
                }

                $patient_id = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];

                // Validate date
                $test_date = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$test_date) {
                    throw new Exception("Invalid date format");
                }
                $test_date = $test_date->format('Y-m-d');

                // Check if patient exists
                $patient = getPatientById($patient_id);
                if (!$patient) {
                    throw new Exception("Patient $patient_id not found");
                }

                // Prepare database update
                $fieldName = strtolower($testType) . '_reference_' . strtolower($eye);
                $testId = date('Ymd', strtotime($test_date)) . '_' . $patient_id . '_' . substr(md5(uniqid()), 0, 4);

                // Check if test exists
                $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
                $stmt->bind_param("ss", $patient_id, $test_date);
                $stmt->execute();
                $test = $stmt->get_result()->fetch_assoc();

                // Copy file to destination
                $destDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
                $destFile = $destDir . $filename;

                if (!copy($fullPath, $destFile)) {
                    throw new Exception("Failed to copy file");
                }

                // Update database
                if ($test) {
                    $stmt = $conn->prepare("UPDATE tests SET $fieldName = ? WHERE test_id = ?");
                    $stmt->bind_param("ss", $filename, $test['test_id']);
                } else {
                    $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $fieldName) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $testId, $patient_id, $test_date, $filename);
                }

                if (!$stmt->execute()) {
                    throw new Exception("Database update failed: " . $conn->error);
                }

                $results['success']++;
            } catch (Exception $e) {
                $results['errors'][] = [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'path' => $fullPath
                ];
            }
        }
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import'])) {
    try {
        $testType = $_POST['bulk_test_type'] ?? '';
        $directory = $_POST['directory_path'] ?? '';
        
        if (empty($testType) || empty($directory)) {
            throw new Exception("Test type and directory path are required");
        }
        
        if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
            throw new Exception("Invalid test type");
        }
        
        $directory = rtrim($directory, '/') . '/';
        $results = processBulkImport($testType, $directory);
        
        $message = "<h3>Bulk Import Results</h3>";
        $message .= "<div class='result-summary'>";
        $message .= "<p>Total files found: <strong>{$results['total']}</strong></p>";
        $message .= "<p class='text-success'>Successfully processed: <strong>{$results['success']}</strong></p>";
        $message .= "<p class='text-danger'>Failed: <strong>" . count($results['errors']) . "</strong></p>";
        $message .= "</div>";
        
        if (!empty($results['errors'])) {
            $message .= "<div class='error-details'><h4>Error Details:</h4><ul>";
            foreach (array_slice($results['errors'], 0, 50) as $error) {
                $message .= "<li><strong>{$error['file']}</strong>: {$error['error']}";
                $message .= "<br><small>{$error['path']}</small></li>";
            }
            if (count($results['errors']) > 50) {
                $message .= "<li>... and " . (count($results['errors']) - 50) . " more errors</li>";
            }
            $message .= "</ul></div>";
        }
        
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
    <title>Advanced Bulk Image Importer</title>
    <style>
        :root {
            --primary: #0066cc;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
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
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        select, input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }
        .btn:hover {
            background-color: #0055aa;
            transform: translateY(-2px);
        }
        .btn:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .message {
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #e6f7e6;
            border-left: 5px solid var(--success);
        }
        .error {
            background-color: #ffebee;
            border-left: 5px solid var(--danger);
        }
        .warning {
            background-color: #fff8e1;
            border-left: 5px solid var(--warning);
        }
        .error-details {
            max-height: 500px;
            overflow-y: auto;
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            border: 1px solid #eee;
        }
        .error-details ul {
            list-style-type: none;
            padding: 0;
        }
        .error-details li {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .error-details li:last-child {
            border-bottom: none;
        }
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .result-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .file-format {
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
        .progress-container {
            margin: 20px 0;
            display: none;
        }
        .progress-bar {
            height: 20px;
            background-color: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress {
            height: 100%;
            background-color: var(--primary);
            width: 0%;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Advanced Bulk Image Importer</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST" id="bulkImportForm">
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
                <label for="directory_path">Directory Path:</label>
                <input type="text" name="directory_path" id="directory_path" required 
                       value="<?= htmlspecialchars(IMAGE_BASE_DIR) ?>"
                       placeholder="e.g., /path/to/your/images/">
                <small>Enter the full path to the directory containing your image files</small>
            </div>
            
            <div class="file-format">
                <h3>File Requirements</h3>
                <p><strong>Naming Format:</strong> <code>patientid_eye_YYYYMMDD.png</code></p>
                <p><strong>Examples:</strong></p>
                <ul>
                    <li><code>PT1001_OD_20230115.png</code> (Patient PT1001, Right Eye, Jan 15 2023)</li>
                    <li><code>PT1002_OS_20230220.png</code> (Patient PT1002, Left Eye, Feb 20 2023)</li>
                </ul>
                <p><strong>Requirements:</strong></p>
                <ol>
                    <li>Files must be in PNG format</li>
                    <li>Patient must exist in database</li>
                    <li>Eye must be either OD (right) or OS (left)</li>
                    <li>Date must be in YYYYMMDD format</li>
                </ol>
            </div>
            
            <button type="submit" name="bulk_import" class="btn" id="importButton">
                Start Bulk Import
            </button>
        </form>
    </div>

    <script>
        document.getElementById('bulkImportForm').addEventListener('submit', function() {
            const btn = document.getElementById('importButton');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        });
    </script>
</body>
</html>
