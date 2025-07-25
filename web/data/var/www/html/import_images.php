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
    $results = ['processed'=>0,'success'=>0,'errors'=>[]];
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = basename($files['name'][$i]);
        $tmpPath = $files['tmp_name'][$i];

        $results['processed']++;

        try {
            $pattern = $testType === 'MFERG'
                ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
                : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';
            if (!preg_match($pattern, $filename, $matches)) {
                throw new Exception("Invalid filename format (must be patientid_eye_YYYYMMDD.ext)");
            }

            $patientId = $matches[1];
            $eye = strtoupper($matches[2]);
            $testDate = DateTime::createFromFormat('Ymd', $matches[3]);
            if (!$testDate) throw new Exception("Invalid date");
            $testDate = $testDate->format('Y-m-d');

            if (!getPatientById($patientId)) throw new Exception("Patient $patientId not found");

            $targetFile = $targetDir . $filename;
            if (!move_uploaded_file($tmpPath, $targetFile)) throw new Exception("File move failed");

            $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
            $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
            $stmt->bind_param("ss", $patientId, $testDate);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();

            $testId = $test ? $test['test_id'] : $patientId.'_'.date('Ymd',strtotime($testDate)).'_'.substr(md5(uniqid()),0,4);
            if ($test) {
                $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
                $stmt->bind_param("ss", $filename, $testId);
            } else {
                $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $imageField) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $testId, $patientId, $testDate, $filename);
            }
            $stmt->execute();
            $results['success']++;
        } catch (Exception $e) {
            $results['errors'][] = ['file'=>$filename, 'error'=>$e->getMessage()];
        }
    }
    return $results;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import'])) {
            $testType  = $_POST['test_type'];
            $eye       = $_POST['eye'];
            $patientId = $_POST['patient_id'];
            $testDate  = $_POST['test_date'];

            if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
                $message = "Image uploaded and database updated successfully!";
                $messageType = 'success';
            } else throw new Exception("Failed to process image");
        }
        elseif (isset($_POST['bulk_folder_import'])) {
            $testType = $_POST['bulk_test_type'];
            $results = processUploadedFolder($testType, $_FILES['folder']);
            $message = "<h3>Bulk Folder Import Results</h3>
                        <p>Processed: {$results['processed']}, Success: {$results['success']}, Errors: ".count($results['errors'])."</p>";
            foreach($results['errors'] as $e){
                $message .= "<p><strong>{$e['file']}</strong>: {$e['error']}</p>";
            }
            $messageType = empty($results['errors']) ? 'success' : ($results['success']>0 ? 'warning' : 'error');
        }
    } catch (Exception $e) {
        $message = "<strong>Error:</strong> ".$e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Medical Image Importer</title>
<style>
/* Minimal styling */
body { font-family: Arial; background: #fff; }
.container { width: 900px; margin: 20px auto; background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 10px; }
h1 { text-align:center; color: teal; }
.message { padding: 10px; margin: 10px 0; border-radius: 4px; }
.success { background: #e6f7e6; color:#3c763d; }
.error { background: #f2dede; color:#a94442; }
.warning { background: #fcf8e3; color:#8a6d3b; }
</style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>
    <?php if($message): ?><div class="message <?= $messageType ?>"><?= $message ?></div><?php endif; ?>

    <h2>Single File Upload</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type</label>
        <select name="test_type" required>
            <?php foreach(ALLOWED_TEST_TYPES as $type=>$dir): ?>
            <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select><br><br>
        <label>Eye</label>
        <select name="eye" required>
            <option value="OD">Right Eye</option>
            <option value="OS">Left Eye</option>
        </select><br><br>
        <label>Patient ID</label>
        <input type="text" name="patient_id" required><br><br>
        <label>Test Date</label>
        <input type="date" name="test_date" required><br><br>
        <label>Image</label>
        <input type="file" name="image" required><br><br>
        <button type="submit" name="import">Upload File</button>
    </form>

    <h2>Bulk Folder Upload</h2>
    <form method="POST" enctype="multipart/form-data">
        <label>Test Type</label>
        <select name="bulk_test_type" required>
            <?php foreach(ALLOWED_TEST_TYPES as $type=>$dir): ?>
            <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select><br><br>
        <label>Select Folder:</label>
        <input type="file" name="folder[]" webkitdirectory directory multiple required><br><br>
        <button type="submit" name="bulk_folder_import">Upload Folder</button>
    </form>
</div>
</body>
</html>
