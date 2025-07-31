<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $conn->begin_transaction();
        
        // Process Patient Data
        $subjectId = trim($_POST['subject_id']);
        $dob = $_POST['date_of_birth'];
        $location = $_POST['location'] ?? 'KH'; // Default to KH if not specified
        
        if (empty($subjectId) || empty($dob)) {
            throw new Exception("Subject ID and Date of Birth are required");
        }
        
        $patientId = $subjectId;
        
        // Insert or update patient
        $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location) 
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE 
                              subject_id = VALUES(subject_id),
                              date_of_birth = VALUES(date_of_birth),
                              location = VALUES(location)");
        $stmt->bind_param("ssss", $patientId, $subjectId, $dob, $location);
        
        if (!$stmt->execute()) {
            throw new Exception("Error saving patient: " . $stmt->error);
        }
        $stmt->close();
        
        // Process Test Data for each eye
        foreach (['OD', 'OS'] as $eye) {
            if (!isset($_POST["eye_data_$eye"])) continue;
            
            $eyeData = $_POST["eye_data_$eye"];
            
            // Skip if no date (indicates eye not being submitted)
            if (empty($eyeData['date_of_test'])) continue;
            
            $testDate = $eyeData['date_of_test'];
            $age = !empty($eyeData['age']) ? (int)$eyeData['age'] : null;
            $reportDiagnosis = $eyeData['report_diagnosis'];
            $exclusion = $eyeData['exclusion'];
            
            // MERCI score handling
            $merciScore = null;
            if (!empty($eyeData['merci_score'])) {
                if (strtolower($eyeData['merci_score']) === 'unable') {
                    $merciScore = 'unable';
                } elseif (is_numeric($eyeData['merci_score']) && $eyeData['merci_score'] >= 0 && $eyeData['merci_score'] <= 100) {
                    $merciScore = (int)$eyeData['merci_score'];
                }
            }
            
            $merciDiagnosis = $eyeData['merci_diagnosis'];
            $errorType = !empty($eyeData['error_type']) ? $eyeData['error_type'] : null;
            $fafGrade = !empty($eyeData['faf_grade']) ? (int)$eyeData['faf_grade'] : null;
            $octScore = !empty($eyeData['oct_score']) ? round((float)$eyeData['oct_score'], 2) : null;
            $vfScore = !empty($eyeData['vf_score']) ? round((float)$eyeData['vf_score'], 2) : null;
            
            // Generate test_id with timestamp for uniqueness
            $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
            
            // Insert or update test data
            $stmt = $conn->prepare("INSERT INTO tests (
                test_id, patient_id, location, date_of_test, age, eye, report_diagnosis, exclusion,
                merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                age = VALUES(age),
                report_diagnosis = VALUES(report_diagnosis),
                exclusion = VALUES(exclusion),
                merci_score = VALUES(merci_score),
                merci_diagnosis = VALUES(merci_diagnosis),
                error_type = VALUES(error_type),
                faf_grade = VALUES(faf_grade),
                oct_score = VALUES(oct_score),
                vf_score = VALUES(vf_score)");
            
            $stmt->bind_param(
                "ssssisssisiddd",
                $testId,
                $patientId,
                $location,
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
                throw new Exception("Error saving $eye test: " . $stmt->error);
            }
            $stmt->close();
            
            // Process Image Uploads for this eye
            foreach (ALLOWED_TEST_TYPES as $testType => $dir) {
                $fileField = "image_{$testType}_{$eye}";
                
                if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
                    $tempFilePath = $_FILES[$fileField]['tmp_name'];
                    
                    if (!importTestImage($testType, $eye, $patientId, $testDate, $tempFilePath)) {
                        throw new Exception("Failed to import $testType image for $eye");
                    }
                }
            }
        }
        
        $conn->commit();
        // Redirect to avoid resubmission on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit(); // Stop further execution
    } catch (Exception $e) {
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
    <title>Patient Data Entry</title>
    <style>
        :root {
            --primary: #2CA25F;
            --primary-light: #66C2A4;
            --primary-lighter: #B2E2E2;
            --primary-dark: #006D2C;
            --secondary: #6BAED6;
            --gray: #F0F0F0;
            --gray-dark: #E0E0E0;
            --text: #333;
            --text-light: #777;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: var(--text);
            min-height: 100vh;
            box-sizing: border-box;
        }
        
        .form-container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 900px;
            margin: 20px auto;
        }
        
        h1 {
            text-align: center;
            font-size: 28px;
            color: var(--primary-dark);
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #E8F5E9;
            color: #2E7D32;
            border-left: 4px solid #4CAF50;
        }
        
        .alert-error {
            background-color: #FFEBEE;
            color: #C62828;
            border-left: 4px solid #F44336;
        }
        
        .duplicate-warning {
            background-color: #FFF3CD;
            color: #856404;
            border-left: 4px solid #FFC107;
            padding: 10px;
            margin-bottom: 15px;
            display: none;
        }
        
        .eye-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--gray-dark);
        }
        
        .eye-tab {
            flex: 1;
            text-align: center;
            padding: 12px;
            background-color: var(--gray);
            cursor: pointer;
            font-weight: bold;
            color: var(--text-light);
            transition: all 0.3s;
            border: none;
            border-radius: 5px 5px 0 0;
        }
        
        .eye-tab.active {
            background-color: white;
            color: var(--primary-dark);
            border-bottom: 3px solid var(--primary);
            margin-bottom: -2px;
        }
        
        .eye-tab:not(.active):hover {
            background-color: var(--gray-dark);
            color: var(--text);
        }
        
        .eye-content {
            display: none;
        }
        
        .eye-content.active {
            display: block;
        }
        
        label {
            display: block;
            margin: 10px 0 5px;
            font-weight: bold;
            color: var(--text);
        }
        
        input, select, textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        
        .form-section {
            margin-bottom: 25px;
            padding-bottom: 15px;
        }
        
        .form-section h2 {
            color: var(--primary);
            font-size: 20px;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--gray-dark);
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-group {
            flex: 1;
        }
        
        .image-upload-group {
            background-color: var(--gray);
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .submit-btn {
            background-color: var(--primary);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 20px;
            transition: background-color 0.3s;
        }
        
        .submit-btn:hover {
            background-color: var(--primary-dark);
        }
        
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: var(--primary);
            text-decoration: none;
            font-weight: bold;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .eye-tabs {
                flex-direction: column;
            }
            
            .eye-tab {
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h1>Patient Data Entry</h1>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div id="duplicate-warning" class="duplicate-warning">
            Note: This patient already has data for the selected date/eye. Submitting will update the existing record.
        </div>

        <form action="" method="post" enctype="multipart/form-data" id="patient-form">
            <!-- Patient Information -->
            <div class="form-section">
                <h2>Patient Information</h2>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="subject_id">Subject ID:</label>
                        <input type="text" name="subject_id" id="subject_id" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth:</label>
                        <input type="date" name="date_of_birth" id="date_of_birth" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location:</label>
                        <select name="location" id="location" required>
                            <option value="KH">KH</option>
                            <option value="Ivey">Ivey</option>
                            <option value="CHUSJ">CHUSJ</option>
                            <option value="IWK">IWK</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Eye Selection Tabs -->
            <div class="eye-tabs">
                <button type="button" class="eye-tab active" data-eye="od">Right Eye (OD)</button>
                <button type="button" class="eye-tab" data-eye="os">Left Eye (OS)</button>
            </div>

            <!-- OD Eye Content -->
            <div class="eye-content active" id="od-content">
                <div class="form-section">
                    <h2>Right Eye (OD) Test Information</h2>
                    <input type="hidden" name="eye_data_OD[eye]" value="OD">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="od_date_of_test">Test Date:</label>
                            <input type="date" name="eye_data_OD[date_of_test]" id="od_date_of_test" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="od_age">Age at Test:</label>
                            <input type="number" name="eye_data_OD[age]" id="od_age" min="0" max="120">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="od_report_diagnosis">Report Diagnosis:</label>
                        <select name="eye_data_OD[report_diagnosis]" id="od_report_diagnosis" required>
                            <option value="normal">Normal</option>
                            <option value="abnormal">Abnormal</option>
                            <option value="no input">No Input</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="od_exclusion">Exclusion:</label>
                        <select name="eye_data_OD[exclusion]" id="od_exclusion" required>
                            <option value="none">None</option>
                            <option value="retinal detachment">Retinal Detachment</option>
                            <option value="generalized retinal dysfunction">Generalized Retinal Dysfunction</option>
                            <option value="unilateral testing">Unilateral Testing</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="od_merci_score">MERCI Score (0-100 or 'unable'):</label>
                            <input type="text" name="eye_data_OD[merci_score]" id="od_merci_score" placeholder="Enter number or 'unable'">
                        </div>
                        
                        <div class="form-group">
                            <label for="od_merci_diagnosis">MERCI Diagnosis:</label>
                            <select name="eye_data_OD[merci_diagnosis]" id="od_merci_diagnosis">
                                <option value="normal">Normal</option>
                                <option value="abnormal">Abnormal</option>
                                <option value="no value">No Value</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="od_error_type">Error Type:</label>
                        <select name="eye_data_OD[error_type]" id="od_error_type">
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
                            <label for="od_faf_grade">FAF Grade (1-4):</label>
                            <input type="number" name="eye_data_OD[faf_grade]" id="od_faf_grade" min="1" max="4">
                        </div>
                        
                        <div class="form-group">
                            <label for="od_oct_score">OCT Score:</label>
                            <input type="number" step="0.01" name="eye_data_OD[oct_score]" id="od_oct_score">
                        </div>
                        
                        <div class="form-group">
                            <label for="od_vf_score">VF Score:</label>
                            <input type="number" step="0.01" name="eye_data_OD[vf_score]" id="od_vf_score">
                        </div>
                    </div>
                </div>

                <!-- OD Image Upload Section -->
                <div class="form-section">
                    <h2>Right Eye (OD) Image Uploads</h2>
                    
                    <div class="image-upload-group">
                        <h3>FAF Image</h3>
                        <div class="form-group">
                            <label for="image_faf_od">FAF File (PNG):</label>
                            <input type="file" name="image_faf_od" id="image_faf_od" accept="image/png">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>OCT Image</h3>
                        <div class="form-group">
                            <label for="image_oct_od">OCT File (PNG):</label>
                            <input type="file" name="image_oct_od" id="image_oct_od" accept="image/png">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>VF Image</h3>
                        <div class="form-group">
                            <label for="image_vf_od">VF File (PDF):</label>
                            <input type="file" name="image_vf_od" id="image_vf_od" accept=".pdf">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>MFERG Image</h3>
                        <div class="form-group">
                            <label for="image_mferg_od">MFERG File (PNG):</label>
                            <input type="file" name="image_mferg_od" id="image_mferg_od" accept="image/png">
                        </div>
                    </div>
                </div>
            </div>

            <!-- OS Eye Content -->
            <div class="eye-content" id="os-content">
                <div class="form-section">
                    <h2>Left Eye (OS) Test Information</h2>
                    <input type="hidden" name="eye_data_OS[eye]" value="OS">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="os_date_of_test">Test Date:</label>
                            <input type="date" name="eye_data_OS[date_of_test]" id="os_date_of_test" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="os_age">Age at Test:</label>
                            <input type="number" name="eye_data_OS[age]" id="os_age" min="0" max="120">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="os_report_diagnosis">Report Diagnosis:</label>
                        <select name="eye_data_OS[report_diagnosis]" id="os_report_diagnosis" required>
                            <option value="normal">Normal</option>
                            <option value="abnormal">Abnormal</option>
                            <option value="no input">No Input</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="os_exclusion">Exclusion:</label>
                        <select name="eye_data_OS[exclusion]" id="os_exclusion" required>
                            <option value="none">None</option>
                            <option value="retinal detachment">Retinal Detachment</option>
                            <option value="generalized retinal dysfunction">Generalized Retinal Dysfunction</option>
                            <option value="unilateral testing">Unilateral Testing</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="os_merci_score">MERCI Score (0-100 or 'unable'):</label>
                            <input type="text" name="eye_data_OS[merci_score]" id="os_merci_score" placeholder="Enter number or 'unable'">
                        </div>
                        
                        <div class="form-group">
                            <label for="os_merci_diagnosis">MERCI Diagnosis:</label>
                            <select name="eye_data_OS[merci_diagnosis]" id="os_merci_diagnosis">
                                <option value="normal">Normal</option>
                                <option value="abnormal">Abnormal</option>
                                <option value="no value">No Value</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="os_error_type">Error Type:</label>
                        <select name="eye_data_OS[error_type]" id="os_error_type">
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
                            <label for="os_faf_grade">FAF Grade (1-4):</label>
                            <input type="number" name="eye_data_OS[faf_grade]" id="os_faf_grade" min="1" max="4">
                        </div>
                        
                        <div class="form-group">
                            <label for="os_oct_score">OCT Score:</label>
                            <input type="number" step="0.01" name="eye_data_OS[oct_score]" id="os_oct_score">
                        </div>
                        
                        <div class="form-group">
                            <label for="os_vf_score">VF Score:</label>
                            <input type="number" step="0.01" name="eye_data_OS[vf_score]" id="os_vf_score">
                        </div>
                    </div>
                </div>

                <!-- OS Image Upload Section -->
                <div class="form-section">
                    <h2>Left Eye (OS) Image Uploads</h2>
                    
                    <div class="image-upload-group">
                        <h3>FAF Image</h3>
                        <div class="form-group">
                            <label for="image_faf_os">FAF File (PNG):</label>
                            <input type="file" name="image_faf_os" id="image_faf_os" accept="image/png">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>OCT Image</h3>
                        <div class="form-group">
                            <label for="image_oct_os">OCT File (PNG):</label>
                            <input type="file" name="image_oct_os" id="image_oct_os" accept="image/png">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>VF Image</h3>
                        <div class="form-group">
                            <label for="image_vf_os">VF File (PDF):</label>
                            <input type="file" name="image_vf_os" id="image_vf_os" accept=".pdf">
                        </div>
                    </div>
                    
                    <div class="image-upload-group">
                        <h3>MFERG Image</h3>
                        <div class="form-group">
                            <label for="image_mferg_os">MFERG File (PNG):</label>
                            <input type="file" name="image_mferg_os" id="image_mferg_os" accept="image/png">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn">Submit Data</button>
        </form>
        
        <a href="index.php" class="back-link">← Back to Dashboard</a>
    </div>

    <script>
        // Tab switching functionality
        document.querySelectorAll('.eye-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.eye-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.eye-content').forEach(c => c.classList.remove('active'));
                
                tab.classList.add('active');
                const eye = tab.dataset.eye;
                document.getElementById(`${eye}-content`).classList.add('active');
                checkForDuplicates();
            });
        });

        // Duplicate checking function
        async function checkForDuplicates() {
            const patientId = document.getElementById('subject_id').value;
            const activeTab = document.querySelector('.eye-tab.active').dataset.eye;
            const testDate = document.getElementById(`${activeTab}_date_of_test`).value;
            
            if (!patientId || !testDate) return;
            
            try {
                const response = await fetch(`includes/check_duplicate.php?patient=${patientId}&date=${testDate}&eye=${activeTab.toUpperCase()}`);
                const data = await response.json();
                
                document.getElementById('duplicate-warning').style.display = data.exists ? 'block' : 'none';
            } catch (error) {
                console.error('Error checking for duplicates:', error);
            }
        }

        // Event listeners
        document.getElementById('subject_id').addEventListener('blur', checkForDuplicates);
        document.getElementById('od_date_of_test').addEventListener('change', function() {
            document.getElementById('os_date_of_test').value = this.value;
            checkForDuplicates();
        });
        document.getElementById('os_date_of_test').addEventListener('change', function() {
            document.getElementById('od_date_of_test').value = this.value;
            checkForDuplicates();
        });
    </script>
</body>
</html>
