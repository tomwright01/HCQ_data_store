<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            // Single file upload
            $testType = $_POST['test_type'] ?? '';
            $eye = $_POST['eye'] ?? '';
            $patient_id = $_POST['patient_id'] ?? '';
            $test_date = $_POST['test_date'] ?? '';
            
            // Validate inputs
            if (empty($testType) || empty($eye) || empty($patient_id) || empty($test_date)) {
                throw new Exception("All fields are required");
            }
            
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type");
            }
            
            if (!in_array($eye, ['OD', 'OS'])) {
                throw new Exception("Invalid eye selection");
            }
            
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid image file");
            }
            
            // Check file type (PNG only)
            $fileType = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if ($fileType !== 'png') {
                throw new Exception("Only PNG images are allowed");
            }
            
            // Process the upload using our function
            if (importTestImage($testType, $eye, $patient_id, $test_date, $_FILES['image']['tmp_name'])) {
                $message = "Image uploaded and database updated successfully!";
                $messageType = 'success';
            } else {
                throw new Exception("Failed to process image upload");
            }
        } elseif (isset($_POST['bulk_import'])) {
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
            
            // Process all PNG files in the folder
            $files = glob($folderPath . '*.png');
            if (empty($files)) {
                throw new Exception("No PNG files found in the specified folder");
            }
            
            $successCount = 0;
            $errorCount = 0;
            $errorDetails = [];
            
            foreach ($files as $file) {
                $filename = basename($file);
                
                // Extract patient_id, eye, and date from filename (format: patientid_eye_YYYYMMDD.png)
                $pattern = '/^([^_]+)_(OD|OS)_(\d{8})\.png$/i';
                if (!preg_match($pattern, $filename, $matches)) {
                    $errorDetails[] = "Invalid filename format: " . htmlspecialchars($filename);
                    $errorCount++;
                    continue;
                }
                
                $patient_id = $matches[1];
                $eye = strtoupper($matches[2]);
                $dateStr = $matches[3];
                
                // Validate date
                $test_date = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$test_date) {
                    $errorDetails[] = "Invalid date in filename: " . htmlspecialchars($filename);
                    $errorCount++;
                    continue;
                }
                $test_date = $test_date->format('Y-m-d');
                
                // Process the file
                if (importTestImage($testType, $eye, $patient_id, $test_date, $file)) {
                    $successCount++;
                } else {
                    $errorDetails[] = "Failed to process: " . htmlspecialchars($filename);
                    $errorCount++;
                }
            }
            
            $message = "Bulk import completed: $successCount files processed successfully, $errorCount files failed.";
            if ($errorCount > 0) {
                $message .= "<div class='error-details'><strong>Error details:</strong><br>" . 
                           implode("<br>", array_slice($errorDetails, 0, 5));
                if ($errorCount > 5) {
                    $message .= "<br>... and " . ($errorCount - 5) . " more errors";
                }
                $message .= "</div>";
            }
            
            $messageType = $errorCount > 0 ? ($successCount > 0 ? 'warning' : 'error') : 'success';
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Medical Images</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: white;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            border: 1px solid #ddd;
        }

        h1 {
            color: rgb(0, 168, 143);
            font-size: 28px;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }

        button[type="submit"] {
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
            transition: background-color 0.3s;
        }

        button[type="submit"]:hover {
            background-color: rgb(0, 140, 120);
        }

        .bulk-import-btn {
            background-color: rgb(76, 175, 80);
        }

        .bulk-import-btn:hover {
            background-color: rgb(69, 160, 73);
        }

        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }

        .success {
            background-color: #dff0d8;
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

        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: rgb(0, 168, 143);
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .form-section h2 {
            color: rgb(0, 168, 143);
            font-size: 20px;
            margin-bottom: 15px;
        }

        .error-details {
            max-height: 200px;
            overflow-y: auto;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        small.help-text {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 0.9em;
        }

        code {
            background-color: #f0f0f0;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Import Medical Images</h1>

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
                    <label for="folder_path">Folder Path:</label>
                    <input type="text" name="folder_path" id="folder_path" required 
                           value="<?= htmlspecialchars(IMAGE_BASE_DIR . 'FAF/') ?>"
                           placeholder="e.g., /var/www/html/data/FAF/">
                    <small class="help-text">
                        Must contain PNG files named like: <code>patientid_eye_YYYYMMDD.png</code><br>
                        Example: <code>12345_OD_20230715.png</code> (Patient ID: 12345, Right Eye, July 15, 2023)
                    </small>
                </div>
                
                <button type="submit" name="bulk_import" class="bulk-import-btn">Import All PNG Files in Folder</button>
            </form>
        </div>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
