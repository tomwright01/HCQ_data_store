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

// Initialize filter variables
$filter_location = isset($_GET['filter_location']) ? $_GET['filter_location'] : '';
$filter_merci_range = isset($_GET['filter_merci_range']) ? $_GET['filter_merci_range'] : '';
$filter_eye = isset($_GET['filter_eye']) ? $_GET['filter_eye'] : '';
$filter_active = !empty($filter_location) || !empty($filter_merci_range) || !empty($filter_eye);

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

// MERCI Score distribution
$sql_merci = "SELECT 
    CASE 
        WHEN merci_score = 'unable' THEN 'Unable'
        WHEN merci_score IS NULL THEN 'Not Specified'
        WHEN merci_score BETWEEN 0 AND 10 THEN '0-10'
        WHEN merci_score BETWEEN 11 AND 20 THEN '11-20'
        WHEN merci_score BETWEEN 21 AND 30 THEN '21-30'
        WHEN merci_score BETWEEN 31 AND 40 THEN '31-40'
        WHEN merci_score BETWEEN 41 AND 50 THEN '41-50'
        WHEN merci_score BETWEEN 51 AND 60 THEN '51-60'
        WHEN merci_score BETWEEN 61 AND 70 THEN '61-70'
        WHEN merci_score BETWEEN 71 AND 80 THEN '71-80'
        WHEN merci_score BETWEEN 81 AND 90 THEN '81-90'
        WHEN merci_score BETWEEN 91 AND 100 THEN '91-100'
        ELSE 'Other'
    END AS score_range,
    COUNT(*) AS count 
    FROM tests 
    GROUP BY score_range
    ORDER BY 
        CASE 
            WHEN score_range = 'Unable' THEN 0
            WHEN score_range = 'Not Specified' THEN 1
            ELSE CAST(SUBSTRING_INDEX(score_range, '-', 1) AS UNSIGNED)
        END";
$result_merci = $conn->query($sql_merci);
$merci_data = [];
while ($row = $result_merci->fetch_assoc()) {
    $merci_data[$row['score_range']] = $row['count'];
}

// Get patient data if search was performed or filters are active
$result_patient = null;
if ($search_patient_id || $filter_active) {
    $sql_patient_data = "SELECT 
        t.test_id, t.location, t.date_of_test, t.age, t.eye,
        t.report_diagnosis, t.exclusion, t.merci_score, t.merci_diagnosis,
        t.error_type, t.faf_grade, t.oct_score, t.vf_score,
        t.faf_reference_od, t.faf_reference_os, t.oct_reference_od, t.oct_reference_os,
        t.vf_reference_od, t.vf_reference_os, t.mferg_reference_od, t.mferg_reference_os,
        p.patient_id, p.subject_id, p.date_of_birth
        FROM tests t JOIN patients p ON t.patient_id = p.patient_id
        WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($search_patient_id) {
        $sql_patient_data .= " AND p.patient_id = ?";
        $params[] = $search_patient_id;
        $types .= "s";
    }
    
    // Apply filters
    if (!empty($filter_location)) {
        $sql_patient_data .= " AND t.location = ?";
        $params[] = $filter_location;
        $types .= "s";
    }
    
    if (!empty($filter_eye)) {
        $sql_patient_data .= " AND t.eye = ?";
        $params[] = $filter_eye;
        $types .= "s";
    }
    
    if (!empty($filter_merci_range)) {
        switch ($filter_merci_range) {
            case 'unable':
                $sql_patient_data .= " AND t.merci_score = 'unable'";
                break;
            case '0-10':
                $sql_patient_data .= " AND t.merci_score BETWEEN 0 AND 10";
                break;
            case '11-20':
                $sql_patient_data .= " AND t.merci_score BETWEEN 11 AND 20";
                break;
            case '21-30':
                $sql_patient_data .= " AND t.merci_score BETWEEN 21 AND 30";
                break;
            case '31-40':
                $sql_patient_data .= " AND t.merci_score BETWEEN 31 AND 40";
                break;
            case '41-50':
                $sql_patient_data .= " AND t.merci_score BETWEEN 41 AND 50";
                break;
            case '51-60':
                $sql_patient_data .= " AND t.merci_score BETWEEN 51 AND 60";
                break;
            case '61-70':
                $sql_patient_data .= " AND t.merci_score BETWEEN 61 AND 70";
                break;
            case '71-80':
                $sql_patient_data .= " AND t.merci_score BETWEEN 71 AND 80";
                break;
            case '81-90':
                $sql_patient_data .= " AND t.merci_score BETWEEN 81 AND 90";
                break;
            case '91-100':
                $sql_patient_data .= " AND t.merci_score BETWEEN 91 AND 100";
                break;
        }
    }
    
    $stmt = $conn->prepare($sql_patient_data);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result_patient = $stmt->get_result();
}

// Helper function to generate URL with one filter removed
function remove_filter_url($filter_to_remove) {
    $params = $_GET;
    unset($params[$filter_to_remove]);
    return 'index.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hydroxychloroquine Data Repository</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 30px;
            width: 80%;
            max-width: 1200px;
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
        .filter-panel {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 25px;
            border-radius: 12px;
            margin: 25px 0;
            border: 1px solid #e0e6ed;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.03);
        }
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e6ed;
        }
        .filter-header h3 {
            color: rgb(0, 168, 143);
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-results-badge {
            background-color: rgb(0, 168, 143);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid #e0e6ed;
        }
        .filter-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .filter-icon {
            width: 40px;
            height: 40px;
            background: rgba(0, 168, 143, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgb(0, 168, 143);
            font-size: 1.1rem;
        }
        .filter-content {
            flex: 1;
        }
        .filter-content label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }
        .filter-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            background-color: white;
            font-size: 0.95rem;
            color: #495057;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .filter-select:focus {
            border-color: rgb(0, 168, 143);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 168, 143, 0.2);
        }
        .filter-footer {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        .active-filters-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        .filter-tag {
            background: linear-gradient(135deg, rgb(0, 168, 143) 0%, rgb(0, 140, 120) 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .filter-tag-remove {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            margin-left: 5px;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        .filter-tag-remove:hover {
            opacity: 1;
        }
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .filter-button, .reset-button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-button {
            background: linear-gradient(135deg, rgb(0, 168, 143) 0%, rgb(0, 140, 120) 100%);
            color: white;
            box-shadow: 0 2px 10px rgba(0, 168, 143, 0.3);
        }
        .filter-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 168, 143, 0.4);
        }
        .reset-button {
            background: white;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        .reset-button:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }
        /* Enhanced Search Container */
        .search-container {
            width: 100%;
            max-width: 700px;
            margin: 30px auto;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e6ed;
            position: relative;
            overflow: hidden;
        }
        
        .search-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, rgb(0, 168, 143), rgb(0, 140, 120));
        }
        
        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .search-title {
            font-size: 1.5rem;
            color: rgb(0, 168, 143);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .search-title i {
            font-size: 1.8rem;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .search-input-container {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 15px 20px 15px 50px;
            font-size: 1rem;
            border: 1px solid #ced4da;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%23000a8f" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>');
            background-repeat: no-repeat;
            background-position: 20px center;
            background-size: 20px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: rgb(0, 168, 143);
            box-shadow: 0 0 0 3px rgba(0, 168, 143, 0.2);
        }
        
        .search-button {
            padding: 15px 30px;
            font-size: 1rem;
            font-weight: 600;
            background: linear-gradient(135deg, rgb(0, 168, 143) 0%, rgb(0, 140, 120) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 168, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 168, 143, 0.4);
        }
        
        .search-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .edit-toggle-button {
            padding: 10px 20px;
            font-size: 0.9rem;
            background: linear-gradient(135deg, rgb(0, 109, 44) 0%, rgb(0, 89, 34) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .edit-toggle-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 109, 44, 0.3);
        }
        
        .cancel-edit-button {
            padding: 10px 20px;
            font-size: 0.9rem;
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .cancel-edit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
            color: white;
        }
    </style>
</head>
<body>
    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">

    <div class="content">
        <h1>Hydroxychloroquine Data Repository</h1>
        
        <div class="action-buttons">
            <a href="form.php" class="action-button form-button">Manual Data Entry</a>
            <a href="csv_import.php" class="action-button import-button">Upload Patient Data (CSV)</a>
            <a href="import_images.php" class="action-button image-button">Import Medical Images</a>
            <a href="export_csv.php" class="action-button export-button">Export to CSV</a>
        </div>

        <!-- Enhanced Filter Panel -->
        <div class="filter-panel">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Patients</h3>
                <?php if ($filter_active && isset($result_patient)): ?>
                    <div class="filter-results-badge">
                        <?= $result_patient->num_rows ?> results
                    </div>
                <?php endif; ?>
            </div>
            
            <form method="GET" action="index.php" class="filter-form">
                <div class="filter-grid">
                    <!-- Location Filter -->
                    <div class="filter-card">
                        <div class="filter-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="filter-content">
                            <label for="filter_location">Location</label>
                            <select name="filter_location" id="filter_location" class="filter-select">
                                <option value="">All Locations</option>
                                <option value="KH" <?= $filter_location === 'KH' ? 'selected' : '' ?>>Kensington</option>
                                <option value="CHUSJ" <?= $filter_location === 'CHUSJ' ? 'selected' : '' ?>>CHUSJ</option>
                                <option value="IWK" <?= $filter_location === 'IWK' ? 'selected' : '' ?>>IWK</option>
                                <option value="IVEY" <?= $filter_location === 'IVEY' ? 'selected' : '' ?>>Ivey</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Eye Filter -->
                    <div class="filter-card">
                        <div class="filter-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="filter-content">
                            <label for="filter_eye">Eye</label>
                            <select name="filter_eye" id="filter_eye" class="filter-select">
                                <option value="">Both Eyes</option>
                                <option value="OD" <?= $filter_eye === 'OD' ? 'selected' : '' ?>>OD (Right Eye)</option>
                                <option value="OS" <?= $filter_eye === 'OS' ? 'selected' : '' ?>>OS (Left Eye)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- MERCI Score Filter -->
                    <div class="filter-card">
                        <div class="filter-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="filter-content">
                            <label for="filter_merci_range">MERCI Score</label>
                            <select name="filter_merci_range" id="filter_merci_range" class="filter-select">
                                <option value="">All Scores</option>
                                <option value="unable" <?= $filter_merci_range === 'unable' ? 'selected' : '' ?>>Unable</option>
                                <option value="0-10" <?= $filter_merci_range === '0-10' ? 'selected' : '' ?>>0-10</option>
                                <option value="11-20" <?= $filter_merci_range === '11-20' ? 'selected' : '' ?>>11-20</option>
                                <option value="21-30" <?= $filter_merci_range === '21-30' ? 'selected' : '' ?>>21-30</option>
                                <option value="31-40" <?= $filter_merci_range === '31-40' ? 'selected' : '' ?>>31-40</option>
                                <option value="41-50" <?= $filter_merci_range === '41-50' ? 'selected' : '' ?>>41-50</option>
                                <option value="51-60" <?= $filter_merci_range === '51-60' ? 'selected' : '' ?>>51-60</option>
                                <option value="61-70" <?= $filter_merci_range === '61-70' ? 'selected' : '' ?>>61-70</option>
                                <option value="71-80" <?= $filter_merci_range === '71-80' ? 'selected' : '' ?>>71-80</option>
                                <option value="81-90" <?= $filter_merci_range === '81-90' ? 'selected' : '' ?>>81-90</option>
                                <option value="91-100" <?= $filter_merci_range === '91-100' ? 'selected' : '' ?>>91-100</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="filter-footer">
                    <div class="active-filters">
                        <?php if ($filter_active): ?>
                            <div class="active-filters-label">Active filters:</div>
                            <?php if ($filter_location): ?>
                                <div class="filter-tag">
                                    <span>Location: <?= htmlspecialchars($filter_location) ?></span>
                                    <a href="<?= remove_filter_url('filter_location') ?>" class="filter-tag-remove">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($filter_eye): ?>
                                <div class="filter-tag">
                                    <span>Eye: <?= htmlspecialchars($filter_eye) ?></span>
                                    <a href="<?= remove_filter_url('filter_eye') ?>" class="filter-tag-remove">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($filter_merci_range): ?>
                                <div class="filter-tag">
                                    <span>MERCI: <?= htmlspecialchars($filter_merci_range) ?></span>
                                    <a href="<?= remove_filter_url('filter_merci_range') ?>" class="filter-tag-remove">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="filter-button">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="index.php" class="reset-button">
                            <i class="fas fa-redo"></i> Reset All
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Enhanced Search Container -->
        <div class="search-container">
            <div class="search-header">
                <h2 class="search-title"><i class="fas fa-search"></i> Search Patient Tests</h2>
                <?php if (($search_patient_id || $filter_active) && isset($result_patient) && $result_patient->num_rows > 0): ?>
                    <div class="filter-results-badge">
                        <?= $result_patient->num_rows ?> results found
                    </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="index.php" class="search-form">
                <div class="search-input-container">
                    <input type="text" name="search_patient_id" id="search_patient_id" 
                           class="search-input" placeholder="Enter Patient ID..." 
                           value="<?= htmlspecialchars($search_patient_id) ?>" required>
                </div>
                <button type="submit" class="search-button">
                    <i class="fas fa-search"></i> Search
                </button>
                
                <?php if (($search_patient_id || $filter_active) && isset($result_patient) && $result_patient->num_rows > 0): ?>
                    <div class="search-actions">
                        <?php if ($edit_mode): ?>
                            <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" class="cancel-edit-button">
                                <i class="fas fa-times"></i> Cancel Edit
                            </a>
                        <?php else: ?>
                            <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>&edit=true" class="edit-toggle-button">
                                <i class="fas fa-edit"></i> Edit Mode
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="message success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="message error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if (($search_patient_id || $filter_active) && isset($result_patient)): ?>
            <?php if ($result_patient->num_rows > 0): ?>
                <h3>
                    <?php if ($search_patient_id): ?>
                        Tests for Patient ID: <?= htmlspecialchars($search_patient_id) ?>
                    <?php else: ?>
                        Filtered Tests (<?= $result_patient->num_rows ?> results)
                    <?php endif; ?>
                </h3>
                <table>
                    <tr>
                        <th>Test ID</th>
                        <th>Location</th>
                        <th>Date</th>
                        <th>Age</th>
                        <th>Eye</th>
                        <th>Impression</th>
                        <th>Exclusion/Control</th>
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
                                <td><?= htmlspecialchars($row["location"] ?? 'KH') ?></td>
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
                                    $currentEye = $row['eye'] ?? '';
                                    $imageLinks = [];
                                    
                                    if (in_array($currentEye, ['OD', 'OS'])) {
                                        $testTypes = [
                                            'faf' => 'FAF',
                                            'oct' => 'OCT',
                                            'vf' => 'VF',
                                            'mferg' => 'MFERG'
                                        ];
                                        
                                        foreach ($testTypes as $prefix => $label) {
                                            $columnName = $prefix . '_reference_' . strtolower($currentEye);
                                            if (!empty($row[$columnName])) {
                                                $imageLinks[] = sprintf(
                                                    '<a href="view_%s.php?ref=%s&patient_id=%s&eye=%s" class="image-link">%s %s</a>',
                                                    $prefix,
                                                    htmlspecialchars($row[$columnName]),
                                                    htmlspecialchars($row['patient_id']),
                                                    $currentEye,
                                                    $label,
                                                    $currentEye
                                                );
                                                
                                                if ($prefix === 'mferg') {
                                                    $imageLinks[] = sprintf(
                                                        '<a href="%sMFERG/%s" class="image-link" download>Download %s %s</a>',
                                                        IMAGE_BASE_URL,
                                                        rawurlencode($row[$columnName]),
                                                        $label,
                                                        $currentEye
                                                    );
                                                }
                                            }
                                        }
                                    }
                                    
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
                <p>No tests found matching your criteria</p>
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

        <div class="chart-container">
            <h3 class="chart-title">MERCI Score Distribution</h3>
            <canvas id="merciChart"></canvas>
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

        // MERCI Score Distribution Chart
        var merciCtx = document.getElementById('merciChart').getContext('2d');
        var merciChart = new Chart(merciCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($merci_data)) ?>,
                datasets: [{
                    label: 'Number of Tests',
                    data: <?= json_encode(array_values($merci_data)) ?>,
                    backgroundColor: [
                        'rgba(0, 168, 143, 0.7)',
                        'rgba(44, 162, 95, 0.7)',
                        'rgba(102, 194, 164, 0.7)',
                        'rgba(178, 226, 226, 0.7)',
                        'rgba(0, 109, 44, 0.7)',
                        'rgba(0, 168, 143, 0.7)',
                        'rgba(44, 162, 95, 0.7)',
                        'rgba(102, 194, 164, 0.7)',
                        'rgba(178, 226, 226, 0.7)',
                        'rgba(0, 109, 44, 0.7)',
                        'rgba(200, 200, 200, 0.7)'
                    ],
                    borderColor: 'rgba(255, 255, 255, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' tests';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Tests'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'MERCI Score Range'
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
