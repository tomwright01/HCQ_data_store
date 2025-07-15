<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // 1. Process Patient Data
        $subjectId = trim($_POST['subject_id']);
        $dob = $_POST['date_of_birth'];
        
        // Validate required fields
        if (empty($subjectId) || empty($dob)) {
            throw new Exception("Subject ID and Date of Birth are required");
        }
        
        // Use subject ID as patient ID
        $patientId = $subjectId;
        
        // Insert Patient
        $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $patientId, $subjectId, $dob);
        
        if (!$stmt->execute()) {
            throw new Exception("Error saving patient: " . $stmt->error);
        }
        $stmt->close();
        
        // 2. Process Test Data
        $testDate = $_POST['date_of_test'];
        $age = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $eye = !empty($_POST['eye']) ? $_POST['eye'] : null;
        $reportDiagnosis = $_POST['report_diagnosis'];
        $exclusion = $_POST['exclusion'];
        
        // Handle MERCI score (can be number or 'unable')
        $merciScore = null;
        if (!empty($_POST['merci_score'])) {
            if (strtolower($_POST['merci_score']) === 'unable') {
                $merciScore = 'unable';
            } elseif (is_numeric($_POST['merci_score']) && $_POST['merci_score'] >= 0 && $_POST['merci_score'] <= 100) {
                $merciScore = (int)$_POST['merci_score'];
            }
        }
        
        $merciDiagnosis = $_POST['merci_diagnosis'];
        $errorType = !empty($_POST['error_type']) ? $_POST['error_type'] : null;
        $fafGrade = !empty($_POST['faf_grade']) ? (int)$_POST['faf_grade'] : null;
        $octScore = !empty($_POST['oct_score']) ? round((float)$_POST['oct_score'], 2) : null;
        $vfScore = !empty($_POST['vf_score']) ? round((float)$_POST['vf_score'], 2) : null;
        
        // Generate test_id (date + eye + random suffix)
        $testDateFormatted = date('Ymd', strtotime($testDate));
        $eyeCode = $eye ? $eye : '';
        $randomSuffix = substr(md5(uniqid()), 0, 2);
        $testId = $testDateFormatted . $eyeCode . $randomSuffix;
        
        // Insert Test
        $stmt = $conn->prepare("INSERT INTO tests (
            test_id, patient_id, date_of_test, age, eye, report_diagnosis, exclusion,
            merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param(
            "sssisssisiddd",
            $testId,
            $patientId,
            $testDate,
            $age,
            $eye,
            $reportDiagnosis,
            $exclusion,
            $merciScore,
            $merciDiagnosis,
            $errorType,
            $fafGrade,
            $octScore,
            $vfScore
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Error saving test: " . $stmt->error);
        }
        $stmt->close();
        
        // 3. Process Image Uploads if provided
        foreach (ALLOWED_TEST_TYPES as $testType => $dir) {
            $eyeField = 'image_' . strtolower($testType) . '_eye';
            $fileField = 'image_' . strtolower($testType);
            
            if (isset($_POST[$eyeField]) && isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
                $eyeValue = $_POST[$eyeField];
                $tempFilePath = $_FILES[$fileField]['tmp_name'];
                
                if (!importTestImage($testType, $eyeValue, $patientId, $testDate, $tempFilePath)) {
                    throw new Exception("Failed to import $testType image for eye $eyeValue");
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success message
        $successMessage = "Patient and test information successfully saved!";
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errorMessage = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Patient and Test Information</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .form-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
        }
        h1 {
            text-align: center;
            font-size: 28px;
            color: rgb(0, 168, 143);
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #dff0d8;
            color: #3c763d;
        }
        .alert-error {
            background-color: #f2dede;
            color: #a94442;
        }
        label {
            display: block;
            margin: 10px 0 5px;
            font-weight: bold;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .form-section {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .form-section h2 {
            color: rgb(0, 168, 143);
            font-size: 20px;
            margin-bottom: 15px;
        }
        .form-row {
            display: flex;
            gap: 15px;
        }
        .form-group {
            flex: 1;
        }
        .image-upload-group {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px dashed #ccc;
        }
        .submit-btn {
            background-color: rgb(0, 168, 143);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        .submit-btn:hover {
            background-color: rgb(0, 140, 120);
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

    <div class="form-container">
        <h1>Add New Patient and Test Information</h1>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <!-- Patient Information -->
            <div class="form-section">
                <h2>Patient Information</h2>
                
                <div class="form-group">
                    <label for="subject_id">Subject ID:</label>
                    <input type="text" name="subject_id" required>
                </div>
                
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth:</label>
                    <input type="date" name="date_of_birth" required>
                </div>
            </div>

            <!-- Test Information -->
            <div class="form-section">
                <h2>Test Information</h2>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_test">Test Date:</label>
                        <input type="date" name="date_of_test" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="age">Age at Test:</label>
                        <input type="number" name="age" min="0" max="120">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="eye">Eye:</label>
                    <select name="eye">
                        <option value="">Select Eye</option>
                        <option value="OD">OD (Right Eye)</option>
                        <option value="OS">OS (Left Eye)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="report_diagnosis">Report Diagnosis:</label>
                    <select name="report_diagnosis" required>
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                        <option value="no input">No Input</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="exclusion">Exclusion:</label>
                    <select name="exclusion" required>
                        <option value="none">None</option>
                        <option value="retinal detachment">Retinal Detachment</option>
                        <option value="generalized retinal dysfunction">Generalized Retinal Dysfunction</option>
                        <option value="unilateral testing">Unilateral Testing</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="merci_score">MERCI Score (0-100 or 'unable'):</label>
                        <input type="text" name="merci_score" placeholder="Enter number or 'unable'">
                    </div>
                    
                    <div class="form-group">
                        <label for="merci_diagnosis">MERCI Diagnosis:</label>
                        <select name="merci_diagnosis">
                            <option value="normal">Normal</option>
                            <option value="abnormal">Abnormal</option>
                            <option value="no value">No Value</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="error_type">Error Type:</label>
                    <select name="error_type">
                        <option value="">Select Error Type</option>
                        <option value="TN">TN</option>
                        <option value="FP">FP</option>
                        <option value="TP">TP</option>
                        <option value="FN">FN</option>
                        <option value="none">None</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="faf_grade">FAF Grade (1-4):</label>
                        <input type="number" name="faf_grade" min="1" max="4">
                    </div>
                    
                    <div class="form-group">
                        <label for="oct_score">OCT Score:</label>
                        <input type="number" step="0.01" name="oct_score">
                    </div>
                    
                    <div class="form-group">
                        <label for="vf_score">VF Score:</label>
                        <input type="number" step="0.01" name="vf_score">
                    </div>
                </div>
            </div>

            <!-- Image Upload Section -->
            <div class="form-section">
                <h2>Image Uploads</h2>
                
                <?php foreach (ALLOWED_TEST_TYPES as $testType => $dir): ?>
                    <div class="image-upload-group">
                        <h3><?= $testType ?> Image</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="image_<?= strtolower($testType) ?>_eye">Eye:</label>
                                <select name="image_<?= strtolower($testType) ?>_eye">
                                    <option value="">Select Eye</option>
                                    <option value="OD">OD (Right Eye)</option>
                                    <option value="OS">OS (Left Eye)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="image_<?= strtolower($testType) ?>">Image File (PNG):</label>
                                <input type="file" name="image_<?= strtolower($testType) ?>" accept="image/png">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="submit-btn">Submit Data</button>
        </form>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>

</body>
</html>
<?php
$conn->close();
?>
