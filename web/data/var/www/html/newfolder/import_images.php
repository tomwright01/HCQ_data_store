<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process import if requested
if (isset($_POST['import']) && isset($_FILES['image'])) {
    $target_dir = IMAGE_BASE_DIR;
    $test_type = $_POST['test_type']; // FAF, OCT, VF, or MFERG
    $eye = $_POST['eye']; // OD or OS
    $patient_id = $_POST['patient_id'];
    $test_date = $_POST['test_date'];
    
    // Create filename pattern: patientid_eye_YYYYMMDD.png
    $filename = $patient_id . '_' . $eye . '_' . $test_date . '.png';
    $target_file = $target_dir . $test_type . '/' . $filename;
    
    // Check if directory exists, create if not
    if (!file_exists($target_dir . $test_type)) {
        mkdir($target_dir . $test_type, 0777, true);
    }
    
    // Try to upload the file
    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
        // Update database with the image reference
        $field_name = strtolower($test_type) . '_reference_' . strtolower($eye);
        
        // Check if test record exists for this patient and date
        $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
        $stmt->bind_param("ss", $patient_id, $test_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing test record
            $test = $result->fetch_assoc();
            $stmt = $conn->prepare("UPDATE tests SET $field_name = ? WHERE test_id = ?");
            $stmt->bind_param("ss", $filename, $test['test_id']);
        } else {
            // Create new test record
            $test_id = $test_date . '_' . $patient_id . '_' . substr(md5(uniqid()), 0, 4);
            $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $field_name) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $test_id, $patient_id, $test_date, $filename);
        }
        
        if ($stmt->execute()) {
            $message = "Image uploaded and database updated successfully!";
        } else {
            $message = "Error updating database: " . $conn->error;
        }
    } else {
        $message = "Sorry, there was an error uploading your file.";
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
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
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
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        button:hover {
            background-color: rgb(0, 140, 120);
        }
        .message {
            margin-top: 20px;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
        }
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: rgb(0, 168, 143);
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Import Medical Images</h1>
        
        <?php if (isset($message)): ?>
            <div class="message <?= strpos($message, 'error') !== false ? 'error' : 'success' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="test_type">Test Type:</label>
                <select name="test_type" id="test_type" required>
                    <option value="">Select Test Type</option>
                    <option value="FAF">Fundus Autofluorescence (FAF)</option>
                    <option value="OCT">Optical Coherence Tomography (OCT)</option>
                    <option value="VF">Visual Field (VF)</option>
                    <option value="MFERG">Multifocal ERG (MFERG)</option>
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
                <label for="image">Image File (PNG):</label>
                <input type="file" name="image" id="image" accept="image/png" required>
            </div>
            
            <button type="submit" name="import">Import Image</button>
        </form>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
