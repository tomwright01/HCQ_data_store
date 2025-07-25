<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '1000');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /* ====================== SINGLE FILE UPLOAD ====================== */
        if (isset($_POST['import'])) {
            $testType = $_POST['test_type'] ?? '';
            $eye = $_POST['eye'] ?? '';
            $patientId = $_POST['patient_id'] ?? '';
            $testDate = $_POST['test_date'] ?? '';

            if (empty($testType) || empty($eye) || empty($patientId) || empty($testDate)) {
                throw new Exception("All fields are required");
            }
            if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
                throw new Exception("Invalid test type selected");
            }
            if (!in_array($eye, ['OD', 'OS'])) {
                throw new Exception("Eye must be either OD (right) or OS (left)");
            }
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid image file");
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $fileInfo->file($_FILES['image']['tmp_name']);
            $allowedMimes = [
                'image/png',
                'application/pdf' => ['VF', 'OCT'],
                'application/octet-stream' => ['MFERG'],
                'text/plain' => ['MFERG']
            ];

            if (!(
                $mime === 'image/png' ||
                (in_array($testType, $allowedMimes['application/pdf'] ?? []) && $mime === 'application/pdf') ||
                (in_array($testType, $allowedMimes['application/octet-stream'] ?? []) && $mime === 'application/octet-stream') ||
                (in_array($testType, $allowedMimes['text/plain'] ?? []) && $mime === 'text/plain')
            )) {
                throw new Exception("Invalid file type for $testType.");
            }

            // Special handling for VF PDF anonymization
            if ($testType === 'VF' && $mime === 'application/pdf') {
                $tempDir = sys_get_temp_dir() . '/vf_anon_' . uniqid();
                if (!mkdir($tempDir)) throw new Exception("Failed to create temp directory");

                $filename = $patientId . '_' . $eye . '_' . date('Ymd', strtotime($testDate)) . '.pdf';
                $tempFile = $tempDir . '/' . $filename;

                if (!move_uploaded_file($_FILES['image']['tmp_name'], $tempFile)) {
                    throw new Exception("Failed to process uploaded PDF");
                }

                $command = sprintf(
                    '/bin/bash %s %s %s',
                    escapeshellarg('Resources/anonymiseHVF/anonymiseHVF.sh'),
                    escapeshellarg($tempFile),
                    escapeshellarg($tempDir)
                );
                exec($command, $output, $returnVar);

                if ($returnVar !== 0) {
                    throw new Exception("Failed to anonymize PDF: " . implode("\n", $output));
                }

                $anonFile = $tempDir . '/' . $filename;
                if (!file_exists($anonFile)) throw new Exception("Anonymized file not found");

                if (importTestImage($testType, $eye, $patientId, $testDate, $anonFile)) {
                    $message = "PDF uploaded, anonymized, and database updated successfully!";
                    $messageType = 'success';
                } else throw new Exception("Failed to process anonymized PDF upload");

                array_map('unlink', glob("$tempDir/*"));
                rmdir($tempDir);
            } else {
                if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                    $message = "Image uploaded and database updated successfully!";
                    $messageType = 'success';
                } else throw new Exception("Failed to process image upload");
            }
        }

        /* ====================== BULK IMPORT FROM FOLDER ====================== */
        elseif (isset($_POST['bulk_import'])) {
            $testType = $_POST['bulk_test_type'] ?? '';
            if (empty($testType)) throw new Exception("Test type is required");
            if (!isset($_FILES['bulk_files']) || empty($_FILES['bulk_files']['name'][0])) {
                throw new Exception("Please select a folder with files");
            }

            $results = [
                'processed' => 0,
                'success' => 0,
                'errors' => []
            ];

            foreach ($_FILES['bulk_files']['tmp_name'] as $i => $tmpName) {
                $originalName = $_FILES['bulk_files']['name'][$i];
                $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                try {
                    // Validate filename: patientid_eye_YYYYMMDD.ext
                    $pattern = ($testType === 'MFERG')
                        ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
                        : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

                    if (!preg_match($pattern, $originalName, $matches)) {
                        throw new Exception("Invalid filename format ($originalName)");
                    }

                    $patientId = $matches[1];
                    $eye = strtoupper($matches[2]);
                    $dateStr = $matches[3];

                    $testDate = DateTime::createFromFormat('Ymd', $dateStr);
                    if (!$testDate) throw new Exception("Invalid date format in filename: $dateStr");
                    if (!getPatientById($patientId)) throw new Exception("Patient $patientId not found");

                    // Move file to permanent storage
                    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                    $targetFile = $targetDir . $originalName;

                    if (!move_uploaded_file($tmpName, $targetFile)) {
                        throw new Exception("Failed to move uploaded file: $originalName");
                    }

                    // DB update or insert
                    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
                    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
                    $stmt->bind_param("ss", $patientId, $testDate->format('Y-m-d'));
                    $stmt->execute();
                    $test = $stmt->get_result()->fetch_assoc();

                    $testId = $test ? $test['test_id'] :
                        $patientId . '_' . $testDate->format('Ymd') . '_' . substr(md5(uniqid()), 0, 4);

                    if ($test) {
                        $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
                        $stmt->bind_param("ss", $originalName, $testId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO tests 
                            (test_id, patient_id, date_of_test, $imageField) 
                            VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("ssss", $testId, $patientId, $testDate->format('Y-m-d'), $originalName);
                    }
                    if (!$stmt->execute()) throw new Exception("Database error: " . $conn->error);

                    $results['success']++;
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'file' => $originalName,
                        'error' => $e->getMessage(),
                        'path' => $tmpName
                    ];
                }
                $results['processed']++;
            }

            // Build message output
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
                        htmlspecialchars($error['error']) . "<br><small>" . htmlspecialchars($error['path']) . "</small></li>";
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
    <title>Medical Image Importer</title>
    <style>
        /* Keep your existing CSS styles here */
        .preview-list { margin-top: 10px; font-size: 14px; }
        .preview-list li { margin-bottom: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Medical Image Importer</h1>
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <!-- Single Upload Form -->
        <div class="form-section">
            <h2>Single File Upload</h2>
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
                    <label for="image">File (PNG for all tests except VF, PDF for VF):</label>
                    <input type="file" name="image" id="image" accept="image/png,.pdf,.exp" required>
                </div>
                <button type="submit" name="import">Upload File</button>
            </form>
        </div>

        <!-- Bulk Folder Upload -->
        <div class="form-section">
            <h2>Bulk Import from Folder</h2>
            <form method="POST" enctype="multipart/form-data">
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
                    <label for="bulk_files">Select Folder:</label>
                    <input type="file" name="bulk_files[]" id="bulk_files" webkitdirectory multiple required>
                    <ul class="preview-list" id="file-preview"></ul>
                </div>
                <button type="submit" name="bulk_import" class="bulk-import-btn">
                    Process All Files in Folder
                </button>
            </form>
        </div>

        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>

<script>
document.getElementById('bulk_files').addEventListener('change', function(e) {
    const list = document.getElementById('file-preview');
    list.innerHTML = '';
    Array.from(e.target.files).forEach(file => {
        const li = document.createElement('li');
        li.textContent = file.webkitRelativePath || file.name;
        list.appendChild(li);
    });
});
</script>
</body>
</html>
