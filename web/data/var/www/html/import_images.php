<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '2000');

$message = '';
$messageType = '';

/**
 * Process uploaded folder using webkitdirectory
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
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = basename($files['name'][$i]);
        $tmpPath  = $files['tmp_name'][$i];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($extension, ['png', 'pdf', 'exp'])) {
            $results['errors'][] = [
                'file'  => $filename,
                'error' => "Invalid file extension: $extension",
                'path'  => $filename
            ];
            continue;
        }

        $results['processed']++;

        try {
            // Validate filename format: patientid_eye_YYYYMMDD.ext
            $pattern = $testType === 'MFERG' ?
                '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i' :
                '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

            if (!preg_match($pattern, $filename, $matches)) {
                throw new Exception("Invalid filename format (expected patientid_eye_YYYYMMDD.ext)");
            }

            $patientId = $matches[1];
            $eye       = strtoupper($matches[2]);
            $dateStr   = $matches[3];
            $fileExt   = strtolower($matches[4]);

            $testDate = DateTime::createFromFormat('Ymd', $dateStr);
            if (!$testDate) {
                throw new Exception("Invalid date in filename (must be YYYYMMDD)");
            }
            $testDate = $testDate->format('Y-m-d');

            if (!getPatientById($patientId)) {
                throw new Exception("Patient $patientId not found in database");
            }

            $targetFile = $targetDir . $filename;

            // Anonymize VF/OCT PDF if required
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

                if ($ret !== 0) {
                    throw new Exception("Failed to anonymize PDF: " . implode("\n", $out));
                }

                $anonFile = $tempDir . '/' . $filename;
                if (!copy($anonFile, $targetFile)) {
                    throw new Exception("Failed to copy anonymized PDF");
                }
                array_map('unlink', glob("$tempDir/*"));
                rmdir($tempDir);
            } else {
                if (!move_uploaded_file($tmpPath, $targetFile)) {
                    throw new Exception("Failed to move file to target directory");
                }
            }

            // Update DB record
            $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
            $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id=? AND date_of_test=?");
            $stmt->bind_param("ss", $patientId, $testDate);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();

            $testId = $test ? $test['test_id'] :
                $patientId . '_' . date('Ymd', strtotime($testDate)) . '_' . substr(md5(uniqid()), 0, 4);

            if ($test) {
                $stmt = $conn->prepare("UPDATE tests SET $imageField=? WHERE test_id=?");
                $stmt->bind_param("ss", $filename, $testId);
            } else {
                $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $imageField) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $testId, $patientId, $testDate, $filename);
            }

            if (!$stmt->execute()) {
                throw new Exception("Database error: " . $conn->error);
            }

            $results['success']++;
        } catch (Exception $e) {
            $results['errors'][] = [
                'file'  => $filename,
                'error' => $e->getMessage(),
                'path'  => $filename
            ];
        }
    }

    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            // --- Single file upload (unchanged) ---
            $testType  = $_POST['test_type'] ?? '';
            $eye       = $_POST['eye'] ?? '';
            $patientId = $_POST['patient_id'] ?? '';
            $testDate  = $_POST['test_date'] ?? '';

            if (empty($testType) || empty($eye) || empty($patientId) || empty($testDate)) {
                throw new Exception("All fields are required");
            }

            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }

            if (!in_array($eye, ['OD', 'OS'])) {
                throw new Exception("Eye must be OD or OS");
            }

            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid file");
            }

            if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                $message = "Image uploaded and database updated successfully!";
                $messageType = 'success';
            } else {
                throw new Exception("Failed to process image upload");
            }

        } elseif (isset($_POST['bulk_folder_import'])) {
            // --- Folder upload ---
            $testType = $_POST['bulk_test_type'] ?? '';
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }

            if (!isset($_FILES['folder'])) {
                throw new Exception("Please select a folder");
            }

            $results = processUploadedFolder($testType, $_FILES['folder']);

            $message = "<h3>Bulk Folder Import Results</h3>";
            $message .= "<p>Processed: {$results['processed']}, Success: {$results['success']}, Errors: " . count($results['errors']) . "</p>";
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
    <title>Medical Image Importer</title>
</head>
<body>
    <h1>Medical Image Importer</h1>
    <?php if ($message): ?>
        <div class="<?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <h2>Single File Upload</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type:</label>
        <select name="test_type" required>
            <option value="">Select</option>
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select><br><br>
        <label>Eye:</label>
        <select name="eye" required>
            <option value="">Select</option>
            <option value="OD">Right Eye (OD)</option>
            <option value="OS">Left Eye (OS)</option>
        </select><br><br>
        <label>Patient ID:</label>
        <input type="text" name="patient_id" required><br><br>
        <label>Test Date:</label>
        <input type="date" name="test_date" required><br><br>
        <label>File:</label>
        <input type="file" name="image" accept="image/png,.pdf,.exp" required><br><br>
        <button type="submit" name="import">Upload File</button>
    </form>

    <h2>Bulk Folder Upload</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type:</label>
        <select name="bulk_test_type" required>
            <option value="">Select</option>
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select><br><br>
        <label>Select Folder:</label>
        <input type="file" name="folder[]" webkitdirectory directory multiple required><br><br>
        <button type="submit" name="bulk_folder_import">Upload Folder</button>
    </form>

    <br><a href="index.php">← Back to Dashboard</a>
</body>
</html>
