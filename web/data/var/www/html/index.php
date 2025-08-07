<?php
require_once 'config.php';
require_once 'functions.php';

// Enable error reporting (for development)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$search_patient_id = isset($_GET['search_patient_id']) ? trim($_GET['search_patient_id']) : '';
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';
$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_test_eye'])) {
        // Handle test eye updates
        $test_id = $_POST['test_id'];
        $eye = $_POST['eye'];
        
        $update_data = [
            'age' => isset($_POST['age']) ? (int)$_POST['age'] : null,
            'report_diagnosis' => $_POST['report_diagnosis'] ?? 'no input',
            'exclusion' => $_POST['exclusion'] ?? 'none',
            'merci_score' => $_POST['merci_score'] ?? null,
            'merci_diagnosis' => $_POST['merci_diagnosis'] ?? 'no value',
            'error_type' => $_POST['error_type'] ?? 'none',
            'faf_grade' => isset($_POST['faf_grade']) ? (int)$_POST['faf_grade'] : null,
            'oct_score' => isset($_POST['oct_score']) ? (float)$_POST['oct_score'] : null,
            'vf_score' => isset($_POST['vf_score']) ? (float)$_POST['vf_score'] : null,
            'actual_diagnosis' => $_POST['actual_diagnosis'] ?? 'other',
            'medication_name' => $_POST['medication_name'] ?? null,
            'dosage' => isset($_POST['dosage']) ? (float)$_POST['dosage'] : null,
            'dosage_unit' => $_POST['dosage_unit'] ?? 'mg',
            'duration_days' => isset($_POST['duration_days']) ? (int)$_POST['duration_days'] : null,
            'cumulative_dosage' => isset($_POST['cumulative_dosage']) ? (float)$_POST['cumulative_dosage'] : null,
            'date_of_continuation' => $_POST['date_of_continuation'] ?? null,
            'treatment_notes' => $_POST['treatment_notes'] ?? null
        ];
        
        try {
            $stmt = $conn->prepare("
                UPDATE test_eyes SET 
                    age = ?, report_diagnosis = ?, exclusion = ?, merci_score = ?, merci_diagnosis = ?, 
                    error_type = ?, faf_grade = ?, oct_score = ?, vf_score = ?, actual_diagnosis = ?, 
                    medication_name = ?, dosage = ?, dosage_unit = ?, duration_days = ?, 
                    cumulative_dosage = ?, date_of_continuation = ?, treatment_notes = ?
                WHERE test_id = ? AND eye = ?
            ");
            
            $stmt->bind_param(
                "issssssddsssdsssss",
                $update_data['age'],
                $update_data['report_diagnosis'],
                $update_data['exclusion'],
                $update_data['merci_score'],
                $update_data['merci_diagnosis'],
                $update_data['error_type'],
                $update_data['faf_grade'],
                $update_data['oct_score'],
                $update_data['vf_score'],
                $update_data['actual_diagnosis'],
                $update_data['medication_name'],
                $update_data['dosage'],
                $update_data['dosage_unit'],
                $update_data['duration_days'],
                $update_data['cumulative_dosage'],
                $update_data['date_of_continuation'],
                $update_data['treatment_notes'],
                $test_id,
                $eye
            );
            
            if ($stmt->execute()) {
                $message = "Test eye record updated successfully!";
                $message_type = "success";
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $message = "Error updating record: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get all patients (for the main view)
$patients = getPatientsWithTests($conn);

// Get specific patient data if searching
$search_results = [];
if ($search_patient_id) {
    $stmt = $conn->prepare("
        SELECT p.*, t.test_id, t.location AS test_location, t.date_of_test
        FROM patients p
        LEFT JOIN tests t ON p.patient_id = t.patient_id
        WHERE p.patient_id = ? OR p.subject_id = ?
        ORDER BY t.date_of_test DESC
    ");
    $stmt->bind_param("ss", $search_patient_id, $search_patient_id);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Patient Data Viewer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1, h2, h3 {
            color: #2c3e50;
        }
        
        .search-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .search-button {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .patient-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background: white;
        }
        
        .patient-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .test-card {
            border-left: 3px solid #3498db;
            padding: 10px 15px;
            margin: 15px 0;
            background: #f8f9fa;
        }
        
        .eye-card {
            border: 1px dashed #aaa;
            padding: 10px;
            margin: 10px 0;
            background: white;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        
        .data-table th, .data-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .data-table th {
            background: #3498db;
            color: white;
        }
        
        .edit-form input, .edit-form select {
            width: 100%;
            padding: 5px;
            margin: 2px 0;
        }
        
        .action-button {
            padding: 5px 10px;
            margin: 2px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        
        .edit-button {
            background: #f39c12;
            color: white;
        }
        
        .save-button {
            background: #2ecc71;
            color: white;
        }
        
        .cancel-button {
            background: #e74c3c;
            color: white;
        }
        
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .image-links {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .image-link {
            padding: 3px 6px;
            background: #eaf2f8;
            border-radius: 3px;
            font-size: 12px;
            text-decoration: none;
            color: #2980b9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Clinical Patient Records</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <div class="search-box">
            <form method="GET" action="index.php" class="search-form">
                <input type="text" name="search_patient_id" class="search-input" 
                       placeholder="Enter Patient ID or Subject ID" value="<?= htmlspecialchars($search_patient_id) ?>">
                <button type="submit" class="search-button">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if ($search_patient_id): ?>
                    <a href="index.php" class="action-button cancel-button">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php if (!$edit_mode): ?>
                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>&edit=true" 
                           class="action-button edit-button">
                            <i class="fas fa-edit"></i> Edit Mode
                        </a>
                    <?php else: ?>
                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" 
                           class="action-button cancel-button">
                            <i class="fas fa-times"></i> Cancel Edit
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if ($search_patient_id && !empty($search_results)): ?>
            <!-- Display search results -->
            <?php 
            $grouped_results = [];
            foreach ($search_results as $row) {
                $patient_id = $row['patient_id'];
                if (!isset($grouped_results[$patient_id])) {
                    $grouped_results[$patient_id] = [
                        'patient' => $row,
                        'tests' => []
                    ];
                }
                if ($row['test_id']) {
                    $grouped_results[$patient_id]['tests'][] = $row;
                }
            }
            ?>
            
            <?php foreach ($grouped_results as $group): ?>
                <div class="patient-card">
                    <div class="patient-header">
                        <h2>
                            <?= htmlspecialchars($group['patient']['subject_id']) ?> 
                            (<?= htmlspecialchars($group['patient']['patient_id']) ?>)
                        </h2>
                        <div>
                            <strong>Location:</strong> <?= htmlspecialchars($group['patient']['location']) ?> | 
                            <strong>DOB:</strong> <?= htmlspecialchars($group['patient']['date_of_birth']) ?>
                        </div>
                    </div>
                    
                    <?php if (empty($group['tests'])): ?>
                        <p>No tests found for this patient.</p>
                    <?php else: ?>
                        <?php foreach ($group['tests'] as $test): ?>
                            <div class="test-card">
                                <h3>
                                    Test ID: <?= htmlspecialchars($test['test_id']) ?> | 
                                    Date: <?= htmlspecialchars($test['date_of_test']) ?> | 
                                    Location: <?= htmlspecialchars($test['test_location']) ?>
                                </h3>
                                
                                <?php 
                                $eyes = getTestEyes($conn, $test['test_id']);
                                if (empty($eyes)): ?>
                                    <p>No eye data found for this test.</p>
                                <?php else: ?>
                                    <?php foreach ($eyes as $eye): ?>
                                        <div class="eye-card">
                                            <?php if ($edit_mode): ?>
                                                <form method="POST" action="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>&edit=true" class="edit-form">
                                                    <input type="hidden" name="test_id" value="<?= htmlspecialchars($eye['test_id']) ?>">
                                                    <input type="hidden" name="eye" value="<?= htmlspecialchars($eye['eye']) ?>">
                                                    
                                                    <table class="data-table">
                                                        <tr>
                                                            <th>Field</th>
                                                            <th>Value</th>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Eye</strong></td>
                                                            <td><?= htmlspecialchars($eye['eye']) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Age</strong></td>
                                                            <td>
                                                                <input type="number" name="age" value="<?= htmlspecialchars($eye['age'] ?? '') ?>" min="0" max="120">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Report Diagnosis</strong></td>
                                                            <td>
                                                                <select name="report_diagnosis">
                                                                    <option value="normal" <?= $eye['report_diagnosis'] === 'normal' ? 'selected' : '' ?>>normal</option>
                                                                    <option value="abnormal" <?= $eye['report_diagnosis'] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                                                    <option value="exclude" <?= $eye['report_diagnosis'] === 'exclude' ? 'selected' : '' ?>>exclude</option>
                                                                    <option value="no input" <?= $eye['report_diagnosis'] === 'no input' ? 'selected' : '' ?>>no input</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Exclusion</strong></td>
                                                            <td>
                                                                <select name="exclusion">
                                                                    <option value="none" <?= $eye['exclusion'] === 'none' ? 'selected' : '' ?>>none</option>
                                                                    <option value="retinal detachment" <?= $eye['exclusion'] === 'retinal detachment' ? 'selected' : '' ?>>retinal detachment</option>
                                                                    <option value="generalized retinal dysfunction" <?= $eye['exclusion'] === 'generalized retinal dysfunction' ? 'selected' : '' ?>>generalized retinal dysfunction</option>
                                                                    <option value="unilateral testing" <?= $eye['exclusion'] === 'unilateral testing' ? 'selected' : '' ?>>unilateral testing</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>MERCI Score</strong></td>
                                                            <td>
                                                                <input type="text" name="merci_score" value="<?= htmlspecialchars($eye['merci_score'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>MERCI Diagnosis</strong></td>
                                                            <td>
                                                                <select name="merci_diagnosis">
                                                                    <option value="normal" <?= $eye['merci_diagnosis'] === 'normal' ? 'selected' : '' ?>>normal</option>
                                                                    <option value="abnormal" <?= $eye['merci_diagnosis'] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                                                    <option value="no value" <?= $eye['merci_diagnosis'] === 'no value' ? 'selected' : '' ?>>no value</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Error Type</strong></td>
                                                            <td>
                                                                <select name="error_type">
                                                                    <option value="none" <?= $eye['error_type'] === 'none' ? 'selected' : '' ?>>none</option>
                                                                    <option value="TN" <?= $eye['error_type'] === 'TN' ? 'selected' : '' ?>>TN</option>
                                                                    <option value="FP" <?= $eye['error_type'] === 'FP' ? 'selected' : '' ?>>FP</option>
                                                                    <option value="TP" <?= $eye['error_type'] === 'TP' ? 'selected' : '' ?>>TP</option>
                                                                    <option value="FN" <?= $eye['error_type'] === 'FN' ? 'selected' : '' ?>>FN</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>FAF Grade</strong></td>
                                                            <td>
                                                                <input type="number" name="faf_grade" value="<?= htmlspecialchars($eye['faf_grade'] ?? '') ?>" min="1" max="4">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>OCT Score</strong></td>
                                                            <td>
                                                                <input type="number" step="0.01" name="oct_score" value="<?= htmlspecialchars($eye['oct_score'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>VF Score</strong></td>
                                                            <td>
                                                                <input type="number" step="0.01" name="vf_score" value="<?= htmlspecialchars($eye['vf_score'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Actual Diagnosis</strong></td>
                                                            <td>
                                                                <select name="actual_diagnosis">
                                                                    <option value="RA" <?= $eye['actual_diagnosis'] === 'RA' ? 'selected' : '' ?>>RA</option>
                                                                    <option value="SLE" <?= $eye['actual_diagnosis'] === 'SLE' ? 'selected' : '' ?>>SLE</option>
                                                                    <option value="Sjogren" <?= $eye['actual_diagnosis'] === 'Sjogren' ? 'selected' : '' ?>>Sjogren</option>
                                                                    <option value="other" <?= $eye['actual_diagnosis'] === 'other' ? 'selected' : '' ?>>other</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Medication</strong></td>
                                                            <td>
                                                                <input type="text" name="medication_name" value="<?= htmlspecialchars($eye['medication_name'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Dosage</strong></td>
                                                            <td>
                                                                <div style="display: flex; gap: 5px;">
                                                                    <input type="number" step="0.01" name="dosage" value="<?= htmlspecialchars($eye['dosage'] ?? '') ?>" style="flex: 1;">
                                                                    <select name="dosage_unit" style="width: 80px;">
                                                                        <option value="mg" <?= $eye['dosage_unit'] === 'mg' ? 'selected' : '' ?>>mg</option>
                                                                        <option value="g" <?= $eye['dosage_unit'] === 'g' ? 'selected' : '' ?>>g</option>
                                                                    </select>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Duration (days)</strong></td>
                                                            <td>
                                                                <input type="number" name="duration_days" value="<?= htmlspecialchars($eye['duration_days'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Cumulative Dosage</strong></td>
                                                            <td>
                                                                <input type="number" step="0.01" name="cumulative_dosage" value="<?= htmlspecialchars($eye['cumulative_dosage'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Date of Continuation</strong></td>
                                                            <td>
                                                                <input type="date" name="date_of_continuation" value="<?= htmlspecialchars($eye['date_of_continuation'] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Treatment Notes</strong></td>
                                                            <td>
                                                                <textarea name="treatment_notes" style="width: 100%; min-height: 60px;"><?= htmlspecialchars($eye['treatment_notes'] ?? '') ?></textarea>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Images</strong></td>
                                                            <td>
                                                                <div class="image-links">
                                                                    <?php if (!empty($eye['faf_reference'])): ?>
                                                                        <a href="<?= getDynamicImagePath($eye['faf_reference']) ?>" class="image-link" target="_blank">FAF</a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($eye['oct_reference'])): ?>
                                                                        <a href="<?= getDynamicImagePath($eye['oct_reference']) ?>" class="image-link" target="_blank">OCT</a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($eye['vf_reference'])): ?>
                                                                        <a href="<?= getDynamicImagePath($eye['vf_reference']) ?>" class="image-link" target="_blank">VF</a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($eye['mferg_reference'])): ?>
                                                                        <a href="<?= getDynamicImagePath($eye['mferg_reference']) ?>" class="image-link" target="_blank">MFERG</a>
                                                                    <?php endif; ?>
                                                                    <?php if (empty($eye['faf_reference']) && empty($eye['oct_reference']) && empty($eye['vf_reference']) && empty($eye['mferg_reference'])): ?>
                                                                        No images
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <div style="margin-top: 10px;">
                                                        <button type="submit" name="update_test_eye" class="action-button save-button">
                                                            <i class="fas fa-save"></i> Save Changes
                                                        </button>
                                                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" class="action-button cancel-button">
                                                            <i class="fas fa-times"></i> Cancel
                                                        </a>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <h4>Eye: <?= htmlspecialchars($eye['eye']) ?></h4>
                                                <table class="data-table">
                                                    <tr>
                                                        <th>Field</th>
                                                        <th>Value</th>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Age</strong></td>
                                                        <td><?= htmlspecialchars($eye['age'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Report Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['report_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Exclusion</strong></td>
                                                        <td><?= htmlspecialchars($eye['exclusion']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>MERCI Score</strong></td>
                                                        <td><?= htmlspecialchars($eye['merci_score'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>MERCI Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['merci_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Error Type</strong></td>
                                                        <td><?= htmlspecialchars($eye['error_type'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>FAF Grade</strong></td>
                                                        <td><?= htmlspecialchars($eye['faf_grade'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>OCT Score</strong></td>
                                                        <td><?= htmlspecialchars($eye['oct_score'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>VF Score</strong></td>
                                                        <td><?= htmlspecialchars($eye['vf_score'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Actual Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['actual_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Medication</strong></td>
                                                        <td><?= htmlspecialchars($eye['medication_name'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Dosage</strong></td>
                                                        <td>
                                                            <?= htmlspecialchars($eye['dosage'] ?? 'N/A') ?> 
                                                            <?= htmlspecialchars($eye['dosage_unit'] ?? '') ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Duration (days)</strong></td>
                                                        <td><?= htmlspecialchars($eye['duration_days'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Cumulative Dosage</strong></td>
                                                        <td><?= htmlspecialchars($eye['cumulative_dosage'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Date of Continuation</strong></td>
                                                        <td><?= htmlspecialchars($eye['date_of_continuation'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Treatment Notes</strong></td>
                                                        <td><?= htmlspecialchars($eye['treatment_notes'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Images</strong></td>
                                                        <td>
                                                            <div class="image-links">
                                                                <?php if (!empty($eye['faf_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['faf_reference']) ?>" class="image-link" target="_blank">FAF</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['oct_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['oct_reference']) ?>" class="image-link" target="_blank">OCT</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['vf_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['vf_reference']) ?>" class="image-link" target="_blank">VF</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['mferg_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['mferg_reference']) ?>" class="image-link" target="_blank">MFERG</a>
                                                                <?php endif; ?>
                                                                <?php if (empty($eye['faf_reference']) && empty($eye['oct_reference']) && empty($eye['vf_reference']) && empty($eye['mferg_reference'])): ?>
                                                                    No images
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
        <?php elseif ($search_patient_id): ?>
            <p>No patient found with ID: <?= htmlspecialchars($search_patient_id) ?></p>
        <?php else: ?>
            <!-- Display all patients -->
            <?php if (empty($patients)): ?>
                <p>No patient data found.</p>
            <?php else: ?>
                <?php foreach ($patients as $patient): ?>
                    <div class="patient-card">
                        <div class="patient-header">
                            <h2>
                                <?= htmlspecialchars($patient['subject_id']) ?> 
                                (<?= htmlspecialchars($patient['patient_id']) ?>)
                            </h2>
                            <div>
                                <strong>Location:</strong> <?= htmlspecialchars($patient['location']) ?> | 
                                <strong>DOB:</strong> <?= htmlspecialchars($patient['date_of_birth']) ?>
                            </div>
                        </div>
                        
                        <?php 
                        $tests = getTestsByPatient($conn, $patient['patient_id']);
                        if (empty($tests)): ?>
                            <p>No tests found for this patient.</p>
                        <?php else: ?>
                            <?php foreach ($tests as $test): ?>
                                <div class="test-card">
                                    <h3>
                                        Test ID: <?= htmlspecialchars($test['test_id']) ?> | 
                                        Date: <?= htmlspecialchars($test['date_of_test']) ?> | 
                                        Location: <?= htmlspecialchars($test['location']) ?>
                                    </h3>
                                    
                                    <?php 
                                    $eyes = getTestEyes($conn, $test['test_id']);
                                    if (empty($eyes)): ?>
                                        <p>No eye data found for this test.</p>
                                    <?php else: ?>
                                        <?php foreach ($eyes as $eye): ?>
                                            <div class="eye-card">
                                                <h4>Eye: <?= htmlspecialchars($eye['eye']) ?></h4>
                                                <table class="data-table">
                                                    <tr>
                                                        <td><strong>Report Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['report_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>MERCI Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['merci_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Actual Diagnosis</strong></td>
                                                        <td><?= htmlspecialchars($eye['actual_diagnosis']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Images</strong></td>
                                                        <td>
                                                            <div class="image-links">
                                                                <?php if (!empty($eye['faf_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['faf_reference']) ?>" class="image-link" target="_blank">FAF</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['oct_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['oct_reference']) ?>" class="image-link" target="_blank">OCT</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['vf_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['vf_reference']) ?>" class="image-link" target="_blank">VF</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($eye['mferg_reference'])): ?>
                                                                    <a href="<?= getDynamicImagePath($eye['mferg_reference']) ?>" class="image-link" target="_blank">MFERG</a>
                                                                <?php endif; ?>
                                                                <?php if (empty($eye['faf_reference']) && empty($eye['oct_reference']) && empty($eye['vf_reference']) && empty($eye['mferg_reference'])): ?>
                                                                    No images
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <div style="margin-top: 10px;">
                                                    <a href="index.php?search_patient_id=<?= urlencode($patient['patient_id']) ?>" class="action-button">
                                                        <i class="fas fa-search"></i> View Details
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
$conn->close();
?>
