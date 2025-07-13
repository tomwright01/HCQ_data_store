<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['bulk_import'])) {
            // Bulk upload from folder
            $testType = $_POST['bulk_test_type'] ?? '';
            $folderPath = $_POST['folder_path'] ?? '';
            
            if (empty($testType) || empty($folderPath)) {
                throw new Exception("Test type and folder path are required for bulk import");
            }
            
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type");
            }
            
            // Normalize folder path
            $folderPath = rtrim($folderPath, '/') . '/';
            
            // Verify folder exists
            if (!is_dir($folderPath)) {
                throw new Exception("Folder does not exist: " . htmlspecialchars($folderPath));
            }
            
            // Process all PNG files in the folder and subfolders
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            $validFiles = [];
            foreach ($files as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
                    $validFiles[] = $file->getPathname();
                }
            }
            
            if (empty($validFiles)) {
                throw new Exception("No PNG files found in the specified folder");
            }
            
            $successCount = 0;
            $errorCount = 0;
            $errorDetails = [];
            
            foreach ($validFiles as $file) {
                $filename = basename($file);
                
                // Extract patient_id, eye, and date from filename (format: patientid_eye_YYYYMMDD.png)
                $pattern = '/^([^_]+)_(OD|OS)_(\d{8})\.png$/i';
                if (!preg_match($pattern, $filename, $matches)) {
                    $errorDetails[] = "SKIPPED: Invalid filename format - " . htmlspecialchars($filename);
                    $errorCount++;
                    continue;
                }
                
                $patient_id = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];
                
                // Validate date
                $test_date = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$test_date) {
                    $errorDetails[] = "SKIPPED: Invalid date in filename - " . htmlspecialchars($filename);
                    $errorCount++;
                    continue;
                }
                $test_date = $test_date->format('Y-m-d');
                
                // Process the file
                if (importTestImage($testType, $eye, $patient_id, $test_date, $file)) {
                    $successCount++;
                } else {
                    $errorDetails[] = "FAILED: Could not process - " . htmlspecialchars($filename);
                    $errorCount++;
                }
            }
            
            // Prepare result message
            $message = "<strong>Bulk import completed:</strong><br>";
            $message .= "<span style='color:green'>✓ $successCount files processed successfully</span><br>";
            
            if ($errorCount > 0) {
                $message .= "<span style='color:red'>✗ $errorCount files failed</span>";
                $message .= "<div class='error-details'><strong>Error details:</strong><br>" . 
                           implode("<br>", array_slice($errorDetails, 0, 20));
                if ($errorCount > 20) {
                    $message .= "<br>... and " . ($errorCount - 20) . " more errors";
                }
                $message .= "</div>";
            }
            
            $messageType = $errorCount > 0 ? ($successCount > 0 ? 'warning' : 'error') : 'success';
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
    <title>Bulk Image Import</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }

        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 1000px;
            margin: 0 auto;
        }

        h1 {
            color: #0066cc;
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        select, input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }

        .btn {
            background-color: #0066cc;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 20px;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #0055aa;
        }

        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .success {
            background-color: #e6f7e6;
            border: 1px solid #a3d8a3;
            color: #2d662d;
        }

        .error {
            background-color: #ffebee;
            border: 1px solid #ffcdd2;
            color: #c62828;
        }

        .warning {
            background-color: #fff8e1;
            border: 1px solid #ffe0b2;
            color: #e65100;
        }

        .error-details {
            max-height: 300px;
            overflow-y: auto;
            background-color: #f9f9f9;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            border: 1px solid #eee;
            font-family: monospace;
            font-size: 14px;
        }

        .file-format {
            background-color: #f0f0f0;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            border-left: 4px solid #0066cc;
        }

        code {
            background-color: #e0e0e0;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: monospace;
        }

        .progress-container {
            display: none;
            margin: 20px 0;
        }

        .progress-bar {
            height: 20px;
            background-color: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress {
            height: 100%;
            background-color: #0066cc;
            width: 0%;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Bulk Image Import Tool</h1>

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
                <label for="folder_path">Folder Path:</label>
                <input type="text" name="folder_path" id="folder_path" required 
                       value="<?= htmlspecialchars(IMAGE_BASE_DIR) ?>"
                       placeholder="e.g., /var/www/html/data/FAF/">
            </div>
            
            <div class="file-format">
                <strong>Required File Naming Format:</strong>
                <p><code>patientid_eye_YYYYMMDD.png</code></p>
                <p>Examples:</p>
                <ul>
                    <li><code>12345_OD_20230715.png</code> (Patient 12345, Right Eye, July 15 2023)</li>
                    <li><code>67890_OS_20230820.png</code> (Patient 67890, Left Eye, August 20 2023)</li>
                </ul>
                <p>The system will automatically process all PNG files in the specified folder that match this format.</p>
            </div>
            
            <div class="progress-container" id="progressContainer">
                <p>Processing files... <span id="progressText">0%</span></p>
                <div class="progress-bar">
                    <div class="progress" id="progressBar"></div>
                </div>
            </div>
            
            <button type="submit" name="bulk_import" class="btn" id="importButton">
                Process All PNG Files in Folder
            </button>
        </form>
    </div>

    <script>
        document.getElementById('bulkImportForm').addEventListener('submit', function() {
            document.getElementById('progressContainer').style.display = 'block';
            document.getElementById('importButton').disabled = true;
            document.getElementById('importButton').textContent = 'Processing...';
            
            // Simple progress simulation (would be better with AJAX in real implementation)
            let progress = 0;
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            
            const interval = setInterval(() => {
                progress += 5;
                if (progress > 90) clearInterval(interval);
                progressBar.style.width = progress + '%';
                progressText.textContent = progress + '%';
            }, 300);
        });
    </script>
</body>
</html>
