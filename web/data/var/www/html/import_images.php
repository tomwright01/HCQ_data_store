<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0); // unlimited time because many files
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '1000');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /* ======== SINGLE FILE UPLOAD ======== */
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
                throw new Exception("Eye must be either OD or OS");
            }
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid file");
            }

            if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                $message = "File uploaded and processed successfully!";
                $messageType = 'success';
            } else {
                throw new Exception("Failed to process uploaded file");
            }
        }

        /* ======== BULK UPLOAD (FOLDER) ======== */
        elseif (isset($_POST['bulk_import'])) {
            $testType = $_POST['bulk_test_type'] ?? '';
            if (empty($testType)) throw new Exception("Test type is required");
            if (!isset($_FILES['bulk_files']) || empty($_FILES['bulk_files']['name'][0])) {
                throw new Exception("Please select a folder with files");
            }

            $results = ['processed' => 0, 'success' => 0, 'errors' => []];

            foreach ($_FILES['bulk_files']['tmp_name'] as $i => $tmpName) {
                $originalName = $_FILES['bulk_files']['name'][$i];

                try {
                    // validate filename
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
                    if (!$testDate) throw new Exception("Invalid date format in filename");
                    if (!getPatientById($patientId)) throw new Exception("Patient $patientId not found");

                    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

                    $targetFile = $targetDir . $originalName;

                    // move immediately
                    if (!move_uploaded_file($tmpName, $targetFile)) {
                        throw new Exception("Failed to move file $originalName");
                    }

                    // DB update
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
                    $results['errors'][] = ['file' => $originalName, 'error' => $e->getMessage()];
                }
                $results['processed']++;

                // free memory after each file
                clearstatcache();
            }

            // summary
            $message = "<div><h3>Folder Upload Results</h3>";
            $message .= "<p>Processed: {$results['processed']}, Success: {$results['success']}, Errors: " . count($results['errors']) . "</p>";
            if (!empty($results['errors'])) {
                $message .= "<ul>";
                foreach ($results['errors'] as $error) {
                    $message .= "<li>{$error['file']}: {$error['error']}</li>";
                }
                $message .= "</ul>";
            }
            $message .= "</div>";
            $messageType = empty($results['errors']) ? 'success' : 'warning';
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
</head>
<body>
    <h1>Medical Image Importer</h1>
    <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Single Upload -->
    <h2>Single File Upload</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type:
            <select name="test_type" required>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                    <option value="<?= $type ?>"><?= $type ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Eye:
            <select name="eye" required>
                <option value="OD">OD</option>
                <option value="OS">OS</option>
            </select>
        </label>
        <label>Patient ID: <input type="text" name="patient_id" required></label>
        <label>Test Date: <input type="date" name="test_date" required></label>
        <label>File: <input type="file" name="image" accept="image/png,.pdf,.exp" required></label>
        <button type="submit" name="import">Upload</button>
    </form>

    <!-- Folder Upload -->
    <h2>Folder Upload (process one by one)</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type:
            <select name="bulk_test_type" required>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                    <option value="<?= $type ?>"><?= $type ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Select Folder:
            <input type="file" name="bulk_files[]" webkitdirectory multiple required>
        </label>
        <button type="submit" name="bulk_import">Process Folder</button>
    </form>
</body>
</html>
