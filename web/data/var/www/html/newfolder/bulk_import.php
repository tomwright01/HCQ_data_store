<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Increase system limits for bulk processing
set_time_limit(0); // No time limit
ini_set('memory_limit', '1024M'); // 1GB memory
ini_set('max_execution_time', '300'); // 5 minutes

$message = '';
$message_type = '';

function bulkImportImages($sourcePath) {
    global $conn;
    
    $results = [
        'total' => 0,
        'success' => 0,
        'errors' => [],
        'test_types' => []
    ];

    // Normalize directory path
    $sourcePath = rtrim($sourcePath, '/') . '/';
    
    if (!is_dir($sourcePath)) {
        throw new Exception("Source directory not found: " . htmlspecialchars($sourcePath));
    }

    // Recursive directory iterator
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'gif'])) {
            $results['total']++;
            $filename = $file->getFilename();
            $filepath = $file->getPathname();
            
            try {
                // Parse filename (patientid_eye_YYYYMMDD.ext)
                if (!preg_match('/^(\d+)_(OD|OS)_(\d{8})\./i', $filename, $matches)) {
                    throw new Exception("Invalid filename format - should be PATIENTID_EYE_YYYYMMDD.ext");
                }

                $patientId = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];

                // Determine test type from directory structure
                $testType = 'FAF'; // Default
                foreach (ALLOWED_TEST_TYPES as $type => $dir) {
                    if (stripos($filepath, $dir) !== false) {
                        $testType = $type;
                        break;
                    }
                }

                // Validate date
                $testDate = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$testDate) {
                    throw new Exception("Invalid date format in filename");
                }
                $testDate = $testDate->format('Y-m-d');

                // Verify patient exists
                if (!getPatientById($patientId)) {
                    throw new Exception("Patient $patientId not found in database");
                }

                // Prepare target directory
                $targetDir = getTestTypeDirectory($testType);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }

                // Copy file to target directory
                $targetFile = $targetDir . '/' . $filename;
                if (!copy($filepath, $targetFile)) {
                    throw new Exception("Failed to copy file to target directory");
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
                    $stmt = $conn->prepare("UPDATE tests SET $imageField = ?, eye = ?, updated_at = CURRENT_TIMESTAMP WHERE test_id = ?");
                    $stmt->bind_param("sss", $filename, $eye, $testId);
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
                $results['test_types'][$testType] = ($results['test_types'][$testType] ?? 0) + 1;
            } catch (Exception $e) {
                $results['errors'][] = [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'path' => $filepath
                ];
            }
        }
    }

    return $results;
}

// Process bulk import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import'])) {
    $sourcePath = $_POST['source_path'] ?? '/var/www/html/data';
    
    try {
        $results = bulkImportImages($sourcePath);
        
        $message = "Successfully processed {$results['success']}/{$results['total']} files.";
        $message_type = 'success';
        
        // Add test type breakdown
        if (!empty($results['test_types'])) {
            $message .= "<br>Breakdown by test type:";
            foreach ($results['test_types'] as $type => $count) {
                $message .= "<br>- $type: $count";
            }
        }
        
        // Show errors if any
        if (!empty($results['errors'])) {
            $message_type = 'warning';
            $message .= "<br><br>Encountered " . count($results['errors']) . " errors:";
            foreach (array_slice($results['errors'], 0, 5) as $error) {
                $message .= "<br>{$error['file']}: {$error['error']}";
            }
            if (count($results['errors']) > 5) {
                $message .= "<br>...and " . (count($results['errors']) - 5) . " more errors";
            }
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Image Importer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #0066cc;
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #0066cc;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #0055aa;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #e6f7e6;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
        }
        .error {
            background-color: #ffebee;
            border: 1px solid #ef9a9a;
            color: #c62828;
        }
        .warning {
            background-color: #fff8e1;
            border: 1px solid #ffe082;
            color: #f57f17;
        }
        .file-requirements {
            background-color: #f0f7ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #0066cc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Bulk Image Importer</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="source_path">Source Directory Path:</label>
                <input type="text" name="source_path" id="source_path" 
                       value="/var/www/html/data" required>
            </div>
            
            <div class="file-requirements">
                <h3>File Requirements:</h3>
                <ul>
                    <li>Files must be named: <strong>PATIENTID_EYE_YYYYMMDD.ext</strong></li>
                    <li>Example: <code>12345_OD_20230715.png</code></li>
                    <li>Supported extensions: PNG, JPG, JPEG, GIF</li>
                    <li>Patient must exist in database</li>
                    <li>Test type is automatically detected from directory structure</li>
                </ul>
            </div>
            
            <button type="submit" name="bulk_import">Process Images</button>
        </form>
    </div>
</body>
</html>
