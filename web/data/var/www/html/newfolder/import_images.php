<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    try {
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
        
        // Process the upload
        if (importTestImage($testType, $eye, $patient_id, $test_date, $_FILES['image']['tmp_name'])) {
            $message = "Image uploaded and database updated successfully!";
            $messageType = 'success';
        } else {
            throw new Exception("Failed to process image upload");
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
        /* [Keep all your existing CSS styles] */
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Import Medical Images</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
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
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
