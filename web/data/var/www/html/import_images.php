<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '2000');

$message = '';
$messageType = '';

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

        // Only accept allowed file types
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
            // Parse filename (patientid_eye_YYYYMMDD.ext)
            $pattern = $testType === 'MFERG' ?
                '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i' :
                '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

            if (!preg_match($pattern, $filename, $matches)) {
                throw new Exception("Invalid filename format. Expected patientid_eye_YYYYMMDD.ext");
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

            // Handle anonymization for VF/OCT PDF
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

            // Update database
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
        if (isset($_POST['bulk_folder_import'])) {
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

            $messageType = empty($results['errors']) ? 'success' : (count($results['success']) > 0 ? 'warning' : 'error');
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
    <h1>Bulk Import from Folder</h1>
    <?php if ($message): ?>
        <div class="<?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <label for="bulk_test_type">Test Type:</label>
        <select name="bulk_test_type" id="bulk_test_type" required>
            <option value="">Select Test Type</option>
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <label>Select Folder:</label>
        <input type="file" name="folder[]" webkitdirectory directory multiple required>
        <br><br>
        <button type="submit" name="bulk_folder_import">Upload Folder</button>
    </form>
    <br>
    <a href="index.php">← Back to Dashboard</a>
</body>
</html>
