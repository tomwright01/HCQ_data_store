<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '2000');

$message = '';
$messageType = '';

/**
 * Handle uploaded folder files (webkitdirectory)
 */
function processUploadedFolder($testType, $files)
{
    global $conn;
    $results = [
        'processed' => 0,
        'success'   => 0,
        'errors'    => []
    ];

    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = basename($files['name'][$i]);
        $tmpPath  = $files['tmp_name'][$i];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($extension, ['png', 'pdf', 'exp'])) {
            $results['errors'][] = ["file" => $filename, "error" => "Invalid file type"];
            continue;
        }

        $results['processed']++;

        try {
            // Validate filename format: patientid_eye_YYYYMMDD.ext
            $pattern = $testType === 'MFERG'
                ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
                : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

            if (!preg_match($pattern, $filename, $matches)) {
                throw new Exception("Invalid filename format (must be patientid_eye_YYYYMMDD.ext)");
            }

            $patientId = $matches[1];
            $eye       = strtoupper($matches[2]);
            $dateStr   = $matches[3];
            $fileExt   = strtolower($matches[4]);

            $testDate = DateTime::createFromFormat('Ymd', $dateStr);
            if (!$testDate) throw new Exception("Invalid date format in filename");
            $testDate = $testDate->format('Y-m-d');

            if (!getPatientById($patientId)) {
                throw new Exception("Patient $patientId not found in database");
            }

            $targetFile = $targetDir . $filename;

            // Special handling for PDF anonymization
            if (($testType === 'VF' || $testType === 'OCT' || $testType === 'MFERG') && $fileExt === 'pdf') {
                $tempDir = sys_get_temp_dir() . '/vf_anon_' . uniqid();
                mkdir($tempDir);

                $tempFile = $tempDir . '/' . $filename;
                move_uploaded_file($tmpPath, $tempFile);

                $cmd = sprintf(
                    '/bin/bash %s %s %s',
                    escapeshellarg('/usr/local/bin/anonymiseHVF.sh'),
                    escapeshellarg($tempFile),
                    escapeshellarg($tempDir)
                );
                exec($cmd, $out, $ret);

                if ($ret !== 0) throw new Exception("Failed to anonymize PDF: " . implode("\n", $out));

                $anonFile = $tempDir . '/' . $filename;
                if (!copy($anonFile, $targetFile)) throw new Exception("Failed to copy anonymized file");

                array_map('unlink', glob("$tempDir/*"));
                rmdir($tempDir);
            } else {
                if (!move_uploaded_file($tmpPath, $targetFile)) {
                    throw new Exception("Failed to move uploaded file");
                }
            }

            // Database update
            $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
            $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id=? AND date_of_test=?");
            $stmt->bind_param("ss", $patientId, $testDate);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();

            $testId = $test
                ? $test['test_id']
                : $patientId . '_' . date('Ymd', strtotime($testDate)) . '_' . substr(md5(uniqid()), 0, 4);

            if ($test) {
                $stmt = $conn->prepare("UPDATE tests SET $imageField=? WHERE test_id=?");
                $stmt->bind_param("ss", $filename, $testId);
            } else {
                $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $imageField) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $testId, $patientId, $testDate, $filename);
            }
            if (!$stmt->execute()) throw new Exception("Database error: " . $conn->error);

            $results['success']++;
        } catch (Exception $e) {
            $results['errors'][] = ["file" => $filename, "error" => $e->getMessage()];
        }
    }

    return $results;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            // Single file upload
            $testType  = $_POST['test_type'] ?? '';
            $eye       = $_POST['eye'] ?? '';
            $patientId = $_POST['patient_id'] ?? '';
            $testDate  = $_POST['test_date'] ?? '';

            if (empty($testType) || empty($eye) || empty($patientId) || empty($testDate)) {
                throw new Exception("All fields are required");
            }
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) throw new Exception("Invalid test type");
            if (!in_array($eye, ['OD', 'OS'])) throw new Exception("Eye must be OD or OS");
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK)
                throw new Exception("Please select a valid file");

            if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                $message = "Image uploaded and database updated successfully!";
                $messageType = 'success';
            } else throw new Exception("Failed to process image upload");

        } elseif (isset($_POST['bulk_folder_import'])) {
            // Folder upload
            $testType = $_POST['bulk_test_type'] ?? '';
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) throw new Exception("Invalid test type");
            if (!isset($_FILES['folder'])) throw new Exception("Please select a folder");

            $results = processUploadedFolder($testType, $_FILES['folder']);
            $message = "<h3>Bulk Folder Import Results</h3>
                        <p>Processed: {$results['processed']}, Success: {$results['success']}, Errors: "
                        . count($results['errors']) . "</p>";
            if (!empty($results['errors'])) {
                $message .= "<ul>";
                foreach ($results['errors'] as $error) {
                    $message .= "<li><strong>{$error['file']}</strong>: {$error['error']}</li>";
                }
                $message .= "</ul>";
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
    <title>Medical Image Importer</title>
    <style>
        /* Original CSS kept the same */
        :root { --primary: rgb(0,168,143); --primary-dark: rgb(0,140,120); --primary-light: rgba(0,168,143,0.1);
                --success: #28a745; --danger: #dc3545; --warning: #ffc107; --light: #f8f9fa; --dark: #343a40; --gray: #6c757d; }
        body { font-family: 'Arial', sans-serif; line-height: 1.6; color: var(--dark);
               display: flex; justify-content: center; align-items: center; min-height: 100vh; margin:0; }
        .container { width: 100%; max-width: 900px; background: white; padding: 30px; border-radius: 10px;
                     box-shadow: 0 4px 20px rgba(0,0,0,0.1); border: 1px solid #ddd; margin:20px; }
        h1,h2,h3,h4 { color: var(--primary); margin-top:0; }
        .form-section { margin-bottom:30px; padding-bottom:20px; border-bottom:1px solid #eee; }
        .form-group { margin-bottom:15px; } label { display:block; margin-bottom:5px; font-weight:bold; }
        select, input[type=text], input[type=date], input[type=file] { width:100%; padding:10px;
            border:1px solid #ddd; border-radius:4px; font-size:16px; box-sizing:border-box; }
        button[type=submit] { background-color: var(--primary); color:white; border:none; padding:12px 20px;
            border-radius:4px; cursor:pointer; font-size:16px; transition:background-color .3s; width:100%; margin-top:10px; }
        button[type=submit]:hover { background-color: var(--primary-dark); }
        .message { padding:15px; margin:20px 0; border-radius:4px; text-align:center; }
        .success { background:#e6f7e6; color:#3c763d; border:1px solid #d6e9c6; }
        .error { background:#f2dede; color:#a94442; border:1px solid #ebccd1; }
        .warning { background:#fcf8e3; color:#8a6d3b; border:1px solid #faebcc; }
    </style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>
    <?php if ($message): ?><div class="message <?= $messageType ?>"><?= $message ?></div><?php endif; ?>

    <div class="form-section">
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="test_type">Test Type:</label>
                <select name="test_type" required>
                    <option value="">Select</option>
                    <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                        <option value="<?= $type ?>"><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="eye">Eye:</label>
                <select name="eye" required>
                    <option value="">Select Eye</option>
                    <option value="OD">Right Eye (OD)</option>
                    <option value="OS">Left Eye (OS)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="patient_id">Patient ID:</label>
                <input type="text" name="patient_id" required>
            </div>
            <div class="form-group">
                <label for="test_date">Test Date:</label>
                <input type="date" name="test_date" required>
            </div>
            <div class="form-group">
                <label for="image">File:</label>
                <input type="file" name="image" accept="image/png,.pdf,.exp" required>
            </div>
            <button type="submit" name="import">Upload File</button>
        </form>
    </div>

    <div class="form-section">
        <h2>Bulk Folder Upload</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="bulk_test_type">Test Type:</label>
                <select name="bulk_test_type" required>
                    <option value="">Select</option>
                    <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                        <option value="<?= $type ?>"><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Select Folder:</label>
                <input type="file" name="folder[]" webkitdirectory directory multiple required>
            </div>
            <button type="submit" name="bulk_folder_import">Upload Folder</button>
        </form>
    </div>

    <a href="index.php">← Back to Dashboard</a>
</div>
</body>
</html>
