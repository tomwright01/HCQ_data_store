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
        
        // Generate unique filename
        $filename = $testType . '_' . $eye . '_' . $patient_id . '_' . date('Ymd', strtotime($test_date)) . '.png';
        $uploadPath = 'uploads/' . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
            throw new Exception("Failed to move uploaded file");
        }
        
        // Update database
        $fieldName = $testType . '_reference_' . strtolower($eye);
        $sql = "UPDATE tests SET $fieldName = ? WHERE patient_id = ? AND date_of_test = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $filename, $patient_id, $test_date);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update database");
        }
        
        $message = "Image uploaded and database updated successfully!";
        $messageType = 'success';
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
            background-color: #f7f7f7;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            color: #333;
        }

        .container {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: 600px;
        }

        h1 {
            color: rgb(0, 168, 143);
            font-size: 36px;
            margin-bottom: 30px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }

        .form-group button {
            padding: 12px 20px;
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            width: 100%;
        }

        .form-group button:hover {
            background-color: rgb(0, 140, 120);
        }

        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            font-size: 16px;
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

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            margin-top: 20px;
            background-color: rgb(0, 168, 143);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .back-button:hover {
            background-color: rgb(0, 140, 120);
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
                    <option value="faf">Fundus Autofluorescence (FAF)</option>
                    <option value="oct">Optical Coherence Tomography (OCT)</option>
                    <option value="vf">Visual Field (VF)</option>
                    <option value="mferg">Multifocal ERG (MFERG)</option>
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
            
            <div class="form-group">
                <button type="submit" name="import">Import Image</button>
            </div>
        </form>
        
        <a href="index.php" class="back-button">← Back to Dashboard</a>
    </div>
</body>
</html>
