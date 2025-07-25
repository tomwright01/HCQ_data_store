<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$success_message = '';
$error_message = '';
$search_patient_id = isset($_REQUEST['search_patient_id']) ? $_REQUEST['search_patient_id'] : '';
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_test'])) {
        // Process update
        $test_id = $_POST['test_id'] ?? '';
        $age = isset($_POST['age']) && $_POST['age'] !== '' ? (int)$_POST['age'] : NULL;
        $eye = $_POST['eye'] ?? NULL;
        $report_diagnosis = $_POST['report_diagnosis'] ?? 'no input';
        $exclusion = $_POST['exclusion'] ?? 'none';
        $merci_score = $_POST['merci_score'] ?? NULL;
        $merci_diagnosis = $_POST['merci_diagnosis'] ?? 'no value';
        $error_type = $_POST['error_type'] ?? 'none';
        $faf_grade = isset($_POST['faf_grade']) && $_POST['faf_grade'] !== '' ? (int)$_POST['faf_grade'] : NULL;
        $oct_score = isset($_POST['oct_score']) && $_POST['oct_score'] !== '' ? (float)$_POST['oct_score'] : NULL;
        $vf_score = isset($_POST['vf_score']) && $_POST['vf_score'] !== '' ? (float)$_POST['vf_score'] : NULL;
        
        try {
            $stmt = $conn->prepare("UPDATE tests SET 
                age = ?, 
                eye = ?, 
                report_diagnosis = ?, 
                exclusion = ?, 
                merci_score = ?, 
                merci_diagnosis = ?, 
                error_type = ?, 
                faf_grade = ?, 
                oct_score = ?, 
                vf_score = ? 
                WHERE test_id = ?");
            
            $stmt->bind_param("isssssssdds", 
                $age, $eye, $report_diagnosis, $exclusion, $merci_score, 
                $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score, $test_id);
            
            if ($stmt->execute()) {
                $success_message = "Test record updated successfully!";
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Error updating record: " . $e->getMessage();
        }
    } elseif (isset($_POST['search_patient_id'])) {
        // Process search
        $search_patient_id = $_POST['search_patient_id'];
    }
}

// Get statistics data
$sql_total_patients = "SELECT COUNT(*) AS total_patients FROM patients";
$result_total_patients = $conn->query($sql_total_patients);
$total_patients = $result_total_patients->fetch_assoc()['total_patients'];

// Age statistics
$sql_age_stats = "SELECT TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS age FROM patients";
$result_age_stats = $conn->query($sql_age_stats);
$ages = array_column($result_age_stats->fetch_all(MYSQLI_ASSOC), 'age');
sort($ages);
$median = !empty($ages) ? $ages[floor(count($ages)/2)] : 0;
$percentile_25 = !empty($ages) ? $ages[floor(count($ages)*0.25)] : 0;
$percentile_75 = !empty($ages) ? $ages[floor(count($ages)*0.75)] : 0;

// Diagnosis distribution
$sql_diagnosis = "SELECT merci_diagnosis AS diagnosis, COUNT(*) AS count FROM tests GROUP BY merci_diagnosis";
$result_diagnosis = $conn->query($sql_diagnosis);
$diagnosis_data = [];
while ($row = $result_diagnosis->fetch_assoc()) {
    $diagnosis_data[$row['diagnosis']] = $row['count'];
}

// Eye distribution
$sql_eye = "SELECT IFNULL(eye, 'Not Specified') AS eye, COUNT(*) AS count FROM tests GROUP BY eye";
$result_eye = $conn->query($sql_eye);
$eye_data = [];
while ($row = $result_eye->fetch_assoc()) {
    $eye_data[$row['eye']] = $row['count'];
}

// Exclusion reasons
$sql_exclusion = "SELECT exclusion, COUNT(*) AS count FROM tests GROUP BY exclusion";
$result_exclusion = $conn->query($sql_exclusion);
$exclusion_data = [];
while ($row = $result_exclusion->fetch_assoc()) {
    $exclusion_data[$row['exclusion']] = $row['count'];
}

// Location distribution
$sql_location = "SELECT location, COUNT(*) AS count FROM tests GROUP BY location";
$result_location = $conn->query($sql_location);
$location_data = [];
while ($row = $result_location->fetch_assoc()) {
    $location_data[$row['location']] = $row['count'];
}

// Get patient data if search was performed
$result_patient = null;
if ($search_patient_id) {
    $sql_patient_data = "SELECT 
        t.test_id, t.location AS test_location, t.date_of_test, t.age, t.eye,
        t.report_diagnosis, t.exclusion, t.merci_score, t.merci_diagnosis,
        t.error_type, t.faf_grade, t.oct_score, t.vf_score,
        t.faf_reference_od, t.faf_reference_os, t.oct_reference_od, t.oct_reference_os,
        t.vf_reference_od, t.vf_reference_os, t.mferg_reference_od, t.mferg_reference_os,
        p.patient_id, p.subject_id, p.date_of_birth, p.location AS patient_location
        FROM tests t JOIN patients p ON t.patient_id = p.patient_id
        WHERE p.patient_id = ?";
    
    $stmt = $conn->prepare($sql_patient_data);
    $stmt->bind_param("s", $search_patient_id);
    $stmt->execute();
    $result_patient = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Hydroxychloroquine Data Repository </title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: white;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            min-height: 100vh;
        }
        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 150px;
        }
        .content {
            width: 80%;
            max-width: 1200px;
            text-align: center;
            padding: 20px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid #ddd;
        }
        h1 {
            font-size: 36px;
            color: rgb(0, 168, 143);
            margin-bottom: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            justify-content: center;
            flex-wrap: wrap;
        }
        .action-button {
            padding: 12px 25px;
            font-size: 16px;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .import-button { background-color: rgb(102, 194, 164); }
        .import-button:hover { background-color: rgb(92, 174, 154); }
        .image-button { background-color: rgb(178, 226, 226); }
        .image-button:hover { background-color: rgb(158, 206, 206); }
        .form-button { background-color: rgb(44, 162, 95); }
        .form-button:hover { background-color: rgb(0, 140, 120); }
        .export-button { background-color: rgb(0, 109, 44); }
        .export-button:hover { background-color: rgb(0, 89, 34); }
        .search-form {
            margin: 20px;
        }
        .search-form input {
            padding: 10px;
            width: 300px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .search-form button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .search-form button:hover {
            background-color: rgb(0, 140, 120);
        }
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
        }
        th {
            background-color: rgb(0, 168, 143);
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .chart-container {
            width: 100%;
            max-width: 600px;
            margin: 20px auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: 1px solid #ddd;
        }
        .chart-title {
            text-align: center;
            color: rgb(0, 168, 143);
            margin-bottom: 15px;
        }
        .stats-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }
        .metric-bar-container {
            margin-top: 30px;
            width: 80%;
            max-width: 600px;
        }
        .metric-bar {
            width: 100%;
            background-color: #eee;
            height: 30px;
            margin: 10px 0;
            border-radius: 5px;
            position: relative;
            overflow: hidden;
            transition: width 1s ease;
            border: 1px solid #ddd;
        }
        .metric-bar .metric-fill {
            height: 100%;
            background-color: rgb(0, 168, 143);
            width: 0;
            transition: width 1s ease;
        }
        .metric-bar .metric-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 16px;
            color: #fff;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.5);
        }
        .data-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        .data-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            width: 280px;
            text-align: center;
            border: 1px solid #ddd;
        }
        .data-card h3 {
            color: rgb(0, 168, 143);
            margin-top: 0;
        }
        .data-value {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }
        .image-link {
            color: rgb(0, 168, 143);
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }
        .image-link:hover {
            color: rgb(0, 140, 120);
            text-decoration: underline;
        }
        .edit-button {
            background-color: rgb(0, 109, 44);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }
        .edit-button:hover {
            background-color: rgb(0, 89, 34);
        }
        .save-button {
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }
        .save-button:hover {
            background-color: rgb(0, 140, 120);
        }
        .cancel-button {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .cancel-button:hover {
            background-color: #c82333;
        }
        .edit-select, .edit-input {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            text-align: center;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">

    <div class="content">
        <h1>Kensington Health Data Portal</h1>
        
        <div class="action-buttons">
            <a href="form.php" class="action-button form-button">Manual Data Entry</a>
            <a href="csv_import.php" class="action-button import-button">Upload Patient Data (CSV)</a>
            <a href="import_images.php" class="action-button image-button">Import Medical Images</a>
            <a href="export_csv.php" class="action-button export-button">Export to CSV</a>
        </div>

        <div class="search-form">
            <form method="POST" action="index.php">
                <label for="search_patient_id">Enter Patient ID to Search for Tests:</label><br>
                <input type="text" name="search_patient_id" id="search_patient_id" required 
                       value="<?= htmlspecialchars($search_patient_id) ?>">
                <button type="submit">Search</button>
                <?php if ($search_patient_id && isset($result_patient) && $result_patient->num_rows > 0): ?>
                    <?php if ($edit_mode): ?>
                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" class="cancel-button">Cancel Edit</a>
                    <?php else: ?>
                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>&edit=true" class="edit-button">Edit Mode</a>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="message success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="message error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if ($search_patient_id && isset($result_patient)): ?>
            <?php if ($result_patient->num_rows > 0): ?>
                <h3>Tests for Patient ID: <?= htmlspecialchars($search_patient_id) ?></h3>
                <table>
                    <tr>
                        <th>Test ID</th>
                        <th>Test Location</th>
                        <th>Patient Location</th>
                        <th>Date</th>
                        <th>Age</th>
                        <th>Eye</th>
                        <th>Report Diagnosis</th>
                        <th>Exclusion</th>
                        <th>MERCI Score</th>
                        <th>MERCI Diagnosis</th>
                        <th>Error Type</th>
                        <th>FAF Grade</th>
                        <th>OCT Score</th>
                        <th>VF Score</th>
                        <th>Images</th>
                        <?php if ($edit_mode): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                    <?php while ($row = $result_patient->fetch_assoc()): ?>
                        <form method="POST" action="index.php?search_patient_id=<?= urlencode($search_patient_id) ?><?= $edit_mode ? '&edit=true' : '' ?>">
                            <input type="hidden" name="test_id" value="<?= htmlspecialchars($row['test_id']) ?>">
                            <tr>
                                <td><?= htmlspecialchars($row["test_id"]) ?></td>
                                <td><?= htmlspecialchars($row["test_location"] ?? 'KH') ?></td>
                                <td><?= htmlspecialchars($row["patient_location"] ?? 'KH') ?></td>
                                <td><?= htmlspecialchars($row["date_of_test"]) ?></td>
                                
                                <?php if ($edit_mode): ?>
                                    <td><input type="number" name="age" class="edit-input" value="<?= htmlspecialchars($row["age"] ?? '') ?>" min="0" max="100"></td>
                                    <td>
                                        <select name="eye" class="edit-select">
                                            <option value="">Not Specified</option>
                                            <option value="OD" <?= ($row["eye"] ?? '') === 'OD' ? 'selected' : '' ?>>OD</option>
                                            <option value="OS" <?= ($row["eye"] ?? '') === 'OS' ? 'selected' : '' ?>>OS</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="report_diagnosis" class="edit-select">
                                            <option value="normal" <?= $row["report_diagnosis"] === 'normal' ? 'selected' : '' ?>>normal</option>
                                            <option value="abnormal" <?= $row["report_diagnosis"] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                            <option value="no input" <?= $row["report_diagnosis"] === 'no input' ? 'selected' : '' ?>>no input</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="exclusion" class="edit-select">
                                            <option value="none" <?= $row["exclusion"] === 'none' ? 'selected' : '' ?>>none</option>
                                            <option value="retinal detachment" <?= $row["exclusion"] === 'retinal detachment' ? 'selected' : '' ?>>retinal detachment</option>
                                            <option value="generalized retinal dysfunction" <?= $row["exclusion"] === 'generalized retinal dysfunction' ? 'selected' : '' ?>>generalized retinal dysfunction</option>
                                            <option value="unilateral testing" <?= $row["exclusion"] === 'unilateral testing' ? 'selected' : '' ?>>unilateral testing</option>
                                        </select>
                                    </td>
                                    <td><input type="text" name="merci_score" class="edit-input" value="<?= htmlspecialchars($row["merci_score"] ?? '') ?>"></td>
                                    <td>
                                        <select name="merci_diagnosis" class="edit-select">
                                            <option value="normal" <?= $row["merci_diagnosis"] === 'normal' ? 'selected' : '' ?>>normal</option>
                                            <option value="abnormal" <?= $row["merci_diagnosis"] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                            <option value="no value" <?= $row["merci_diagnosis"] === 'no value' ? 'selected' : '' ?>>no value</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="error_type" class="edit-select">
                                            <option value="none" <?= ($row["error_type"] ?? '') === 'none' ? 'selected' : '' ?>>none</option>
                                            <option value="TN" <?= ($row["error_type"] ?? '') === 'TN' ? 'selected' : '' ?>>TN</option>
                                            <option value="FP" <?= ($row["error_type"] ?? '') === 'FP' ? 'selected' : '' ?>>FP</option>
                                            <option value="TP" <?= ($row["error_type"] ?? '') === 'TP' ? 'selected' : '' ?>>TP</option>
                                            <option value="FN" <?= ($row["error_type"] ?? '') === 'FN' ? 'selected' : '' ?>>FN</option>
                                        </select>
                                    </td>
                                    <td><input type="number" name="faf_grade" class="edit-input" value="<?= htmlspecialchars($row["faf_grade"] ?? '') ?>" min="1" max="4"></td>
                                    <td><input type="number" step="0.01" name="oct_score" class="edit-input" value="<?= htmlspecialchars($row["oct_score"] ?? '') ?>"></td>
                                    <td><input type="number" step="0.01" name="vf_score" class="edit-input" value="<?= htmlspecialchars($row["vf_score"] ?? '') ?>"></td>
                                <?php else: ?>
                                    <td><?= htmlspecialchars($row["age"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["eye"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["report_diagnosis"]) ?></td>
                                    <td><?= htmlspecialchars($row["exclusion"]) ?></td>
                                    <td><?= htmlspecialchars($row["merci_score"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["merci_diagnosis"]) ?></td>
                                    <td><?= htmlspecialchars($row["error_type"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["faf_grade"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["oct_score"] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($row["vf_score"] ?? 'N/A') ?></td>
                                <?php endif; ?>
                                
                                <td>
                                    <?php 
                                    $imageLinks = [];
                                    if (!empty($row['faf_reference_od'])) $imageLinks[] = '<a href="view_faf.php?ref='.htmlspecialchars($row['faf_reference_od']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OD" class="image-link">FAF OD</a>';
                                    if (!empty($row['faf_reference_os'])) $imageLinks[] = '<a href="view_faf.php?ref='.htmlspecialchars($row['faf_reference_os']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OS" class="image-link">FAF OS</a>';
                                    if (!empty($row['oct_reference_od'])) $imageLinks[] = '<a href="view_oct.php?ref='.htmlspecialchars($row['oct_reference_od']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OD" class="image-link">OCT OD</a>';
                                    if (!empty($row['oct_reference_os'])) $imageLinks[] = '<a href="view_oct.php?ref='.htmlspecialchars($row['oct_reference_os']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OS" class="image-link">OCT OS</a>';
                                    if (!empty($row['vf_reference_od']))  $imageLinks[] = '<a href="view_vf.php?ref='.htmlspecialchars($row['vf_reference_od']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OD" class="image-link">VF OD</a>';
                                    if (!empty($row['vf_reference_os']))  $imageLinks[] = '<a href="view_vf.php?ref='.htmlspecialchars($row['vf_reference_os']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OS" class="image-link">VF OS</a>';
                                    if (!empty($row['mferg_reference_od'])) $imageLinks[] = '<a href="view_mferg.php?ref='.htmlspecialchars($row['mferg_reference_od']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OD" class="image-link">MFERG OD</a>';
                                    if (!empty($row['mferg_reference_os'])) $imageLinks[] = '<a href="view_mferg.php?ref='.htmlspecialchars($row['mferg_reference_os']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OS" class="image-link">MFERG OS</a>';
                                    echo $imageLinks ? implode(' | ', $imageLinks) : 'No images';
                                    ?>
                                </td>
                                
                                <?php if ($edit_mode): ?>
                                    <td>
                                        <button type="submit" name="update_test" class="save-button">Save</button>
                                        <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" class="cancel-button">Cancel</a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        </form>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p>No tests found for Patient ID: <?= htmlspecialchars($search_patient_id) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="stats-section">
        <div class="chart-container">
            <h3 class="chart-title">Diagnosis Distribution</h3>
            <canvas id="diagnosisChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Eye Distribution</h3>
            <canvas id="eyeChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Location Distribution</h3>
            <canvas id="locationChart"></canvas>
        </div>
    </div>

    <div class="data-section">
        <div class="data-card">
            <h3>Total Patients</h3>
            <div class="data-value"><?= $total_patients ?></div>
        </div>
        
        <div class="data-card">
            <h3>Median Age</h3>
            <div class="data-value"><?= round($median) ?></div>
        </div>
        
        <div class="data-card">
            <h3>25th Percentile Age</h3>
            <div class="data-value"><?= round($percentile_25) ?></div>
        </div>
        
        <div class="data-card">
            <h3>75th Percentile Age</h3>
            <div class="data-value"><?= round($percentile_75) ?></div>
        </div>
    </div>

    <div class="metric-bar-container">
        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($total_patients / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= $total_patients ?> Patients</div>
        </div>

        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($median / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= round($median) ?> Median Age</div>
        </div>
    </div>

    <script>
        // Diagnosis Distribution Chart
        var diagnosisCtx = document.getElementById('diagnosisChart').getContext('2d');
        var diagnosisChart = new Chart(diagnosisCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($diagnosis_data)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($diagnosis_data)) ?>,
                    backgroundColor: [
                        'rgb(0, 168, 143)',
                        'rgb(0, 100, 80)',
                        'rgb(200, 200, 200)'
                    ],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var label = context.label || '';
                                var value = context.raw || 0;
                                var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                var percentage = Math.round((value / total) * 100);
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Eye Distribution Chart
        var eyeCtx = document.getElementById('eyeChart').getContext('2d');
        var eyeChart = new Chart(eyeCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($eye_data)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($eye_data)) ?>,
                    backgroundColor: [
                        'rgb(0, 168, 143)',
                        'rgb(0, 100, 80)',
                        'rgb(200, 200, 200)'
                    ],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });

        // Location Distribution Chart
        var locationCtx = document.getElementById('locationChart').getContext('2d');
        var locationChart = new Chart(locationCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($location_data)) ?>,
                datasets: [{
                    label: 'Tests by Location',
                    data: <?= json_encode(array_values($location_data)) ?>,
                    backgroundColor: [
                        'rgb(0, 168, 143)',
                        'rgb(44, 162, 95)',
                        'rgb(102, 194, 164)',
                        'rgb(178, 226, 226)'
                    ],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Animate metric bars on page load
        document.addEventListener('DOMContentLoaded', function() {
            const metricBars = document.querySelectorAll('.metric-fill');
            metricBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>
