<?php
// Enable error reporting (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// DB config
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Constant (adjust as needed)
define('IMAGE_BASE_URL', ''); // e.g., 'https://yourcdn.example.com/images/'

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialization
$success_message = '';
$error_message = '';
$search_patient_id = isset($_REQUEST['search_patient_id']) ? $_REQUEST['search_patient_id'] : '';
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';

$filter_location = isset($_GET['filter_location']) ? $_GET['filter_location'] : '';
$filter_merci_range = isset($_GET['filter_merci_range']) ? $_GET['filter_merci_range'] : '';
$filter_eye = isset($_GET['filter_eye']) ? $_GET['filter_eye'] : '';
$filter_active = !empty($filter_location) || !empty($filter_merci_range) || !empty($filter_eye);

// CSV import placeholders
$import_results = [
    'patients' => 0,
    'tests' => 0,
    'errors' => []
];
$import_message = '';
$import_message_class = '';
$imported_filename = '';

// Handle CSV upload if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csv_import_submit'])) {
    $uploadDir = "/var/www/html/uploads/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $import_message = "Error uploading file: " . ($_FILES['csv_file']['error'] ?? 'No file selected');
        $import_message_class = 'error';
    } else {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $imported_filename = $fileName;
        $fileNameClean = preg_replace("/[^A-Za-z0-9 \.\-_]/", '', $fileName);
        $fileExt = strtolower(pathinfo($fileNameClean, PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $import_message = "Only CSV files are allowed.";
            $import_message_class = 'error';
        } else {
            $newFileName = uniqid('import_', true) . '.' . $fileExt;
            $destPath = $uploadDir . $newFileName;

            if (!move_uploaded_file($fileTmpPath, $destPath)) {
                $import_message = "Failed to move uploaded file.";
                $import_message_class = 'error';
            } else {
                try {
                    if (!file_exists($destPath) || !is_readable($destPath)) {
                        throw new Exception("CSV file not readable at $destPath");
                    }
                    if (($handle = fopen($destPath, "r")) === FALSE) {
                        throw new Exception("Could not open CSV file");
                    }

                    $conn->begin_transaction();
                    // Skip header
                    fgetcsv($handle, 0, ",", '"', "\0");
                    $lineNumber = 1;

                    while (($data = fgetcsv($handle, 0, ",", '"', "\0")) !== FALSE) {
                        $lineNumber++;
                        try {
                            if (count(array_filter($data)) === 0) continue;
                            if (count($data) < 18) {
                                throw new Exception("Row has only " . count($data) . " columns (minimum 18 required)");
                            }
                            // Normalize
                            $data = array_map('trim', $data);
                            $data = array_map(function($v) {
                                $v = trim($v ?? '');
                                $lower = strtolower($v);
                                if ($v === '' || in_array($lower, ['null', 'no value', 'missing'])) return null;
                                return $v;
                            }, $data);

                            // Patient
                            $subjectId = $data[0] ?? '';
                            $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                            if (!$dob) throw new Exception("Invalid date format for DoB: " . ($data[1] ?? 'NULL') . " - Expected MM/DD/YYYY");
                            $dobFormatted = $dob->format('Y-m-d');
                            $location_default = 'KH';
                            $patientId = $subjectId;
                            $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $dobFormatted, $location_default, $import_results);

                            // Test
                            $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                            if (!$testDate) throw new Exception("Invalid date format for test date: " . ($data[2] ?? 'NULL') . " - Expected MM/DD/YYYY");

                            $ageValue = $data[3] ?? null;
                            $age = (isset($ageValue) && is_numeric($ageValue) && $ageValue >= 0 && $ageValue <= 100) ? (int)round($ageValue) : null;

                            $testNumber = $data[4] ?? null;
                            if ($testNumber !== null && !is_numeric($testNumber)) throw new Exception("Invalid TEST_ID: must be a number");

                            $eyeValue = $data[5] ?? null;
                            $eye = ($eyeValue !== null && in_array(strtoupper($eyeValue), ['OD', 'OS'])) ? strtoupper($eyeValue) : null;

                            $testDateFormatted = $testDate->format('Ymd');
                            $testId = $testDateFormatted . ($eye ? $eye : '') . ($testNumber ? $testNumber : '');

                            // report diagnosis
                            $reportDiagnosisValue = $data[6] ?? null;
                            $reportDiagnosis = 'no input';
                            if ($reportDiagnosisValue !== null) {
                                $lowerValue = strtolower($reportDiagnosisValue);
                                if (in_array($lowerValue, ['normal', 'abnormal', 'exclude'])) {
                                    $reportDiagnosis = $lowerValue;
                                }
                            }

                            $exclusionValue = $data[7] ?? null;
                            $exclusion = 'none';
                            if ($exclusionValue !== null) {
                                $lowerValue = strtolower($exclusionValue);
                                if (in_array($lowerValue, ['retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) {
                                    $exclusion = $lowerValue;
                                }
                            }

                            // MERCI score
                            $merciScoreValue = $data[8] ?? null;
                            $merciScore = null;
                            if (isset($merciScoreValue)) {
                                if (strtolower($merciScoreValue) === 'unable') {
                                    $merciScore = 'unable';
                                } elseif (is_numeric($merciScoreValue) && $merciScoreValue >= 0 && $merciScoreValue <= 100) {
                                    $merciScore = (int)$merciScoreValue;
                                }
                            }

                            // MERCI diagnosis
                            $merciDiagnosisValue = $data[9] ?? null;
                            $merciDiagnosis = 'no value';
                            if ($merciDiagnosisValue !== null) {
                                $lowerValue = strtolower($merciDiagnosisValue);
                                if (in_array($lowerValue, ['normal', 'abnormal'])) {
                                    $merciDiagnosis = $lowerValue;
                                }
                            }

                            // error type
                            $errorTypeValue = $data[10] ?? null;
                            $allowedErrorTypes = ['TN', 'FP', 'TP', 'FN', 'none'];
                            $errorType = null;
                            if ($errorTypeValue !== null && $errorTypeValue !== '') {
                                $upperValue = strtoupper(trim($errorTypeValue));
                                if (in_array($upperValue, $allowedErrorTypes)) {
                                    $errorType = ($upperValue === 'NONE') ? 'none' : $upperValue;
                                } else {
                                    $import_results['errors'][] = "Line $lineNumber: Invalid error_type '{$errorTypeValue}' - set to NULL";
                                }
                            }

                            $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                            $octScore = isset($data[12]) && is_numeric($data[12]) ? round(floatval($data[12]), 2) : null;
                            $vfScore = isset($data[13]) && is_numeric($data[13]) ? round(floatval($data[13]), 2) : null;

                            $allowedDiagnosis = ['RA', 'SLE', 'Sjorgens', 'other'];
                            if (!empty($data[14])) {
                                $diag = ucfirst(strtolower(trim($data[14])));
                                $actualDiagnosis = in_array($diag, $allowedDiagnosis) ? $diag : 'other';
                            } else {
                                $actualDiagnosis = null;
                            }

                            $dosage = isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;
                            $durationDays = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;
                            $cumulativeDosage = isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

                            $testData = [
                                'test_id' => $testId,
                                'patient_id' => $patientId,
                                'location' => $location_default,
                                'date_of_test' => $testDate->format('Y-m-d'),
                                'age' => $age,
                                'eye' => $eye,
                                'report_diagnosis' => $reportDiagnosis,
                                'exclusion' => $exclusion,
                                'merci_score' => $merciScore,
                                'merci_diagnosis' => $merciDiagnosis,
                                'error_type' => $errorType,
                                'faf_grade' => $fafGrade,
                                'oct_score' => $octScore,
                                'vf_score' => $vfScore,
                                'actual_diagnosis' => $actualDiagnosis,
                                'dosage' => $dosage,
                                'duration_days' => $durationDays,
                                'cumulative_dosage' => $cumulativeDosage
                            ];

                            insertTest($conn, $testData);
                            $import_results['tests']++;
                        } catch (Exception $e) {
                            $import_results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                        }
                    }

                    fclose($handle);

                    if (empty($import_results['errors'])) {
                        $conn->commit();
                        $import_message = "Successfully processed {$import_results['patients']} patients and {$import_results['tests']} tests."; 
                        $import_message_class = 'success';
                    } else {
                        $conn->rollback();
                        $import_message = "Completed with " . count($import_results['errors']) . " errors. No data was imported."; 
                        $import_message_class = 'error';
                    }
                } catch (Exception $e) {
                    if ($conn && method_exists($conn, 'rollback')) $conn->rollback();
                    $import_message = "Fatal error: " . $e->getMessage();
                    $import_message_class = 'error';
                }
            }
        }
    }
}

// Handle test updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_test'])) {
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
    $medication_name = $_POST['medication_name'] ?? NULL;
    $dosage = isset($_POST['dosage']) && $_POST['dosage'] !== '' ? (float)$_POST['dosage'] : NULL;
    $dosage_unit = $_POST['dosage_unit'] ?? 'mg';
    $duration_days = isset($_POST['duration_days']) && $_POST['duration_days'] !== '' ? (int)$_POST['duration_days'] : NULL;
    $cumulative_dosage = isset($_POST['cumulative_dosage']) && $_POST['cumulative_dosage'] !== '' ? (float)$_POST['cumulative_dosage'] : NULL;
    $date_of_continuation = $_POST['date_of_continuation'] ?? NULL;
    $treatment_notes = $_POST['treatment_notes'] ?? NULL;

    try {
        $stmt = $conn->prepare("UPDATE tests SET 
            age = ?, eye = ?, report_diagnosis = ?, exclusion = ?, merci_score = ?, merci_diagnosis = ?, error_type = ?, 
            faf_grade = ?, oct_score = ?, vf_score = ?, medication_name = ?, dosage = ?, dosage_unit = ?, duration_days = ?, 
            cumulative_dosage = ?, date_of_continuation = ?, treatment_notes = ?
            WHERE test_id = ?");
        $stmt->bind_param("issssssssdssdidsis",
            $age, $eye, $report_diagnosis, $exclusion, $merci_score,
            $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
            $medication_name, $dosage, $dosage_unit, $duration_days,
            $cumulative_dosage, $date_of_continuation, $treatment_notes, $test_id
        );
        if ($stmt->execute()) {
            $success_message = "Test record updated successfully!";
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        $error_message = "Error updating record: " . $e->getMessage();
    }
}

// Build patient/test query
$result_patient = null;
if ($search_patient_id || $filter_active) {
    $sql_patient_data = "
      SELECT
        t.*,
        t.actual_diagnosis    AS patient_actual_diagnosis,
        p.patient_id,
        p.subject_id,
        p.date_of_birth,
        p.location            AS patient_location
      FROM tests t
      JOIN patients p ON t.patient_id = p.patient_id
      WHERE p.patient_id = ?
    ";

    $params = [];
    $types  = '';

    //  filter by the actual patient_id column
    if ($search_patient_id) {
        $sql_patient_data .= " AND p.patient_id = ?";
        $params[] = $search_patient_id;
        $types   .= "s";
    }
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

// Stats for dashboard
$sql_total_patients = "SELECT COUNT(*) AS total_patients FROM patients";
$result_total_patients = $conn->query($sql_total_patients);
$total_patients = $result_total_patients->fetch_assoc()['total_patients'] ?? 0;

$sql_age_stats = "SELECT TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS age FROM patients";
$result_age_stats = $conn->query($sql_age_stats);
$ages = [];
if ($result_age_stats) {
    $ages = array_column($result_age_stats->fetch_all(MYSQLI_ASSOC), 'age');
}
sort($ages);
$median = !empty($ages) ? $ages[floor(count($ages)/2)] : 0;
$percentile_25 = !empty($ages) ? $ages[floor(count($ages)*0.25)] : 0;
$percentile_75 = !empty($ages) ? $ages[floor(count($ages)*0.75)] : 0;

function fetch_group_counts($conn, $sql) {
    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[array_values($row)[0]] = array_values($row)[1];
        }
    }
    return $out;
}

$diagnosis_data = fetch_group_counts($conn, "SELECT merci_diagnosis AS diagnosis, COUNT(*) AS count FROM tests GROUP BY merci_diagnosis");
$eye_data = fetch_group_counts($conn, "SELECT IFNULL(eye,'Not Specified') AS eye, COUNT(*) AS count FROM tests GROUP BY eye");
$location_data = fetch_group_counts($conn, "SELECT location, COUNT(*) AS count FROM tests GROUP BY location");
$merci_data = [];
$res_merci = $conn->query("SELECT 
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
        END");
while ($row = $res_merci->fetch_assoc()) {
    $merci_data[$row['score_range']] = $row['count'];
}

// Helper to remove filter
function remove_filter_url($filter_to_remove) {
    $params = $_GET;
    unset($params[$filter_to_remove]);
    return 'index.php?' . http_build_query($params);
}

// Determine patient-level actual diagnosis to display
$display_actual_diagnosis = '';
if ($result_patient && $result_patient->num_rows > 0) {
    $peek = $result_patient->fetch_assoc();
    $display_actual_diagnosis = $peek['patient_actual_diagnosis'] ?? '';
    $result_patient->data_seek(0);
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
        /* Core layout & typography */
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f1f5fa;
            color: #2f3742;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }
        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 150px;
        }
        .content {
            width: 90%;
            max-width: 1400px;
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin: 40px 0 80px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.08);
            position: relative;
        }
        h1 {
            font-size: 36px;
            color: rgb(0, 168, 143);
            margin-bottom: 10px;
        }
        .top-right-badge {
            position: absolute;
            top: 25px;
            right: 25px;
            background: linear-gradient(135deg, rgb(0,168,143), rgb(0,140,120));
            color: white;
            padding: 14px 20px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            gap: 10px;
            align-items: center;
            box-shadow: 0 12px 35px rgba(0,168,143,0.35);
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        .action-button {
            padding: 12px 25px;
            font-size: 16px;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight:600;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all .3s;
        }
        .form-button { background: linear-gradient(135deg, rgb(44,162,95), rgb(0,140,120)); }
        .import-button { background: linear-gradient(135deg, rgb(102,194,164), rgb(0,168,143)); }
        .image-button { background: linear-gradient(135deg, rgb(178,226,226), rgb(0,168,143)); }
        .export-button { background: linear-gradient(135deg, rgb(0,109,44), rgb(0,89,34)); }
        .action-button:hover { transform: translateY(-2px); filter: brightness(1.05); }

        /* Filter/search panels */
        .filter-panel {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
            margin: 25px 0;
            border: 1px solid #e0e6ed;
            box-shadow: 0 8px 30px rgba(0,0,0,0.03);
        }
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .filter-header h3 { margin:0; display:flex; gap:8px; align-items:center; color: rgb(0,168,143); }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:20px;
            margin-bottom: 15px;
        }
        .filter-card {
            background:white;
            border-radius:10px;
            padding:15px;
            display:flex;
            gap:12px;
            align-items:center;
            border:1px solid #e3eaef;
            box-shadow:0 12px 35px rgba(0,0,0,0.03);
        }
        .filter-icon {
            width:40px;
            height:40px;
            background: rgba(0,168,143,0.1);
            border-radius:8px;
            display:flex;
            align-items:center;
            justify-content:center;
            color: rgb(0,168,143);
            font-size:1.1rem;
        }
        .filter-content label {
            display:block;
            font-weight:600;
            margin-bottom:6px;
            font-size:0.85rem;
            color:#555;
        }
        .filter-select {
            width:100%;
            padding:10px 12px;
            border-radius:8px;
            border:1px solid #ced4da;
            background:white;
            font-size:0.9rem;
        }
        .filter-footer {
            display:flex;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:15px;
            align-items:center;
        }
        .active-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .filter-tag {
            background: linear-gradient(135deg, rgb(0,168,143), rgb(0,140,120));
            color:white;
            padding:6px 14px;
            border-radius:20px;
            font-size:0.75rem;
            display:flex;
            align-items:center;
            gap:6px;
            font-weight:600;
        }
        .filter-tag a { color:white; text-decoration:none; }

        .filter-actions { display:flex; gap:12px; flex-wrap:wrap; }
        .filter-button {
            background: linear-gradient(135deg, rgb(0,168,143), rgb(0,140,120));
            border:none;
            color:white;
            padding:10px 20px;
            border-radius:8px;
            cursor:pointer;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:8px;
            font-size:0.9rem;
        }
        .reset-button {
            background:white;
            color:#6c757d;
            border:1px solid #ced4da;
            padding:10px 20px;
            border-radius:8px;
            text-decoration:none;
            font-size:0.9rem;
        }

        /* Search container */
        .search-container {
            background: #f8f9fa;
            padding:25px;
            border-radius:15px;
            border:1px solid #e0e6ed;
            margin-bottom:25px;
            box-shadow:0 12px 35px rgba(0,0,0,0.03);
            position:relative;
        }
        .search-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
        .search-title { margin:0; font-size:1.4rem; color: rgb(0,168,143); display:flex; align-items:center; gap:10px; }
        .search-form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:8px; }
        .search-input { padding:12px 15px; border-radius:8px; border:1px solid #ced4da; width:100%; min-width:220px; font-size:0.95rem; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill=\"%230009af\" viewBox=\"0 0 16 16\"><path d=\"M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z\"/></svg>');
            background-repeat:no-repeat;
            background-position:15px center;
            background-size:18px;
        }
        .search-button {
            background: linear-gradient(135deg, rgb(0,168,143), rgb(0,140,120));
            border:none;
            color:white;
            padding:12px 20px;
            border-radius:8px;
            cursor:pointer;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .edit-toggle-button, .cancel-edit-button {
            padding:8px 15px;
            border:none;
            border-radius:6px;
            cursor:pointer;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
            color:white;
        }
        .edit-toggle-button { background: linear-gradient(135deg, rgb(0,109,44), rgb(0,89,34)); }
        .cancel-edit-button { background: linear-gradient(135deg, #dc3545, #c82333); }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size:0.78rem;
        }
        th, td {
            padding: 10px;
            text-align:center;
            border:1px solid #e3e8ed;
            vertical-align: middle;
        }
        th {
            background: rgb(0,168,143);
            color: white;
            position: sticky;
            top:0;
            z-index:2;
            font-weight:600;
        }
        tr:nth-child(even) { background:#f9f9f9; }
        tr:hover { background:#eef4fa; }
        .edit-input, .edit-select {
            padding:5px;
            border:1px solid #ced4da;
            border-radius:4px;
            width:100%;
            font-size:0.75rem;
        }
        .save-button {
            background: rgb(0,168,143);
            color:white;
            border:none;
            padding:6px 12px;
            border-radius:4px;
            cursor:pointer;
            font-size:0.75rem;
            font-weight:600;
        }
        .cancel-button {
            background:#dc3545;
            color:white;
            border:none;
            padding:6px 12px;
            border-radius:4px;
            cursor:pointer;
            font-size:0.75rem;
            font-weight:600;
            text-decoration:none;
            display:inline-block;
        }

        .image-link { display:inline-block; margin:2px 0; font-size:0.65rem; color: rgb(0,168,143); text-decoration:none; }
        .image-link:hover { text-decoration:underline; }

        .message { padding:12px; border-radius:6px; margin:10px 0; font-weight:600; }
        .success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

        .data-section {
            display:flex;
            gap:20px;
            flex-wrap:wrap;
            margin-top:30px;
            justify-content:center;
        }
        .data-card {
            background:white;
            border-radius:8px;
            padding:18px;
            box-shadow:0 12px 30px rgba(0,0,0,0.04);
            flex:1;
            min-width:180px;
            text-align:center;
            border:1px solid #e3e8ed;
        }
        .data-card h3 { margin-top:0; color: rgb(0,168,143); font-size:1rem; }
        .data-value { font-size:28px; font-weight:700; margin:8px 0; }

        .metric-bar-container {
            max-width:850px;
            margin:40px auto 0;
        }
        .metric-bar {
            background:#eee;
            border-radius:6px;
            height:38px;
            position:relative;
            margin-bottom:16px;
            overflow:hidden;
            border:1px solid #d9dfe7;
        }
        .metric-fill {
            height:100%;
            background: linear-gradient(135deg, rgb(0,168,143), rgb(0,140,120));
            width:0;
            transition: width 1s ease;
        }
        .metric-value {
            position:absolute;
            top:50%;
            left:50%;
            transform:translate(-50%,-50%);
            color:white;
            font-weight:600;
            font-size:14px;
            text-shadow:1px 1px 3px rgba(0,0,0,0.4);
        }

        .stats-section {
            display:grid;
            gap:20px;
            grid-template-columns: repeat(auto-fit,minmax(260px,1fr));
            margin-top:40px;
        }
        .chart-container {
            background:white;
            border-radius:10px;
            padding:20px;
            box-shadow:0 12px 35px rgba(0,0,0,0.04);
            border:1px solid #e3e8ed;
            position:relative;
        }
        .chart-title {
            margin:0 0 12px;
            color: rgb(0,168,143);
            font-size:1.1rem;
        }

        @media (max-width: 1100px) {
            .data-section { flex-direction: column; }
        }
    </style>
</head>
<body>
    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">
    <div class="content">
        <h1>Hydroxychloroquine Data Repository</h1>

        <?php if ($display_actual_diagnosis): ?>
            <div class="top-right-badge">
                <i class="fas fa-notes-medical"></i>
                Actual Diagnosis: <span style="text-transform:uppercase;"><?= htmlspecialchars($display_actual_diagnosis) ?></span>
            </div>
        <?php endif; ?>

        <div class="action-buttons">
            <a href="form.php" class="action-button form-button"><i class="fas fa-file-medical-alt"></i> Manual Data Entry</a>
            <a href="csv_import.php" class="action-button import-button"><i class="fas fa-file-import"></i> Upload Patient Data (CSV)</a>
            <a href="import_images.php" class="action-button image-button"><i class="fas fa-images"></i> Import Medical Images</a>
            <a href="export_csv.php" class="action-button export-button"><i class="fas fa-file-export"></i> Export to CSV</a>
        </div>

        <!-- CSV Import Summary -->
        <div id="csvImport" style="margin-top:20px; padding:18px; border:1px solid #d9dfe7; border-radius:10px; background:#f7fcfd;">
            <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-file-csv" style="font-size:1.4rem; color:rgb(0,168,143);"></i>
                    <div>
                        <div style="font-weight:600; font-size:1.1rem; margin:0;">CSV Import Summary</div>
                        <div style="font-size:0.85rem; color:#555;">
                            <?= $imported_filename ? "File: " . htmlspecialchars($imported_filename) : "No file imported yet." ?>
                        </div>
                    </div>
                </div>
                <?php if ($import_message): ?>
                    <div style="flex:1; min-width:250px;">
                        <div class="message <?= $import_message_class === 'success' ? 'success' : 'error' ?>">
                            <?php if ($import_message_class === 'success'): ?>
                                <i class="fas fa-check-circle"></i>
                            <?php else: ?>
                                <i class="fas fa-exclamation-circle"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($import_message) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($import_results['patients']) || !empty($import_results['tests'])): ?>
                <div style="display:flex; gap:25px; flex-wrap:wrap; margin-top:12px;">
                    <div class="data-card" style="flex:0 0 180px;">
                        <h3>Patients Processed</h3>
                        <div class="data-value"><?= $import_results['patients'] ?></div>
                    </div>
                    <div class="data-card" style="flex:0 0 180px;">
                        <h3>Tests Imported</h3>
                        <div class="data-value"><?= $import_results['tests'] ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($import_results['errors'])): ?>
                <div style="margin-top:20px;">
                    <div style="font-size:1rem; font-weight:600; color:#b02a37; margin-bottom:8px;">
                        <i class="fas fa-exclamation-triangle"></i> Errors Encountered (<?= count($import_results['errors']) ?>)
                    </div>
                    <div style="max-height:220px; overflow:auto; border:1px solid #e3e8ed; border-radius:8px; background:white;">
                        <?php foreach ($import_results['errors'] as $err): ?>
                            <div style="padding:10px; border-bottom:1px solid #f1f5fa; display:flex; gap:10px; font-size:0.8rem;">
                                <i class="fas fa-times-circle" style="color:#dc3545; margin-top:3px;"></i>
                                <div><?= htmlspecialchars($err) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Patients</h3>
                <?php if ($filter_active && isset($result_patient)): ?>
                    <div class="filter-results-badge" style="background:rgb(0,168,143); color:white; padding:6px 14px; border-radius:20px; font-weight:600;">
                        <?= $result_patient->num_rows ?> results
                    </div>
                <?php endif; ?>
            </div>
            <form method="GET" action="index.php">
                <div class="filter-grid">
                    <div class="filter-card">
                        <div class="filter-icon"><i class="fas fa-map-marker-alt"></i></div>
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

                    <div class="filter-card">
                        <div class="filter-icon"><i class="fas fa-eye"></i></div>
                        <div class="filter-content">
                            <label for="filter_eye">Eye</label>
                            <select name="filter_eye" id="filter_eye" class="filter-select">
                                <option value="">Both Eyes</option>
                                <option value="OD" <?= $filter_eye === 'OD' ? 'selected' : '' ?>>OD (Right Eye)</option>
                                <option value="OS" <?= $filter_eye === 'OS' ? 'selected' : '' ?>>OS (Left Eye)</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-card">
                        <div class="filter-icon"><i class="fas fa-chart-line"></i></div>
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
                            <div style="font-size:0.85rem; font-weight:600; color:#555;">Active filters:</div>
                            <?php if ($filter_location): ?>
                                <div class="filter-tag">
                                    Location: <?= htmlspecialchars($filter_location) ?>
                                    <a href="<?= remove_filter_url('filter_location') ?>"><i class="fas fa-times" style="margin-left:6px;"></i></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($filter_eye): ?>
                                <div class="filter-tag">
                                    Eye: <?= htmlspecialchars($filter_eye) ?>
                                    <a href="<?= remove_filter_url('filter_eye') ?>"><i class="fas fa-times" style="margin-left:6px;"></i></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($filter_merci_range): ?>
                                <div class="filter-tag">
                                    MERCI: <?= htmlspecialchars($filter_merci_range) ?>
                                    <a href="<?= remove_filter_url('filter_merci_range') ?>"><i class="fas fa-times" style="margin-left:6px;"></i></a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="filter-button"><i class="fas fa-filter"></i> Apply Filters</button>
                        <a href="index.php" class="reset-button"><i class="fas fa-redo"></i> Reset All</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Search -->
        <div class="search-container">
            <div class="search-header">
                <h2 class="search-title"><i class="fas fa-search"></i> Search Patient Tests</h2>
                <?php if (($search_patient_id || $filter_active) && isset($result_patient) && $result_patient->num_rows > 0): ?>
                    <div class="filter-results-badge" style="background:rgb(0,168,143); color:white; padding:6px 14px; border-radius:20px; font-weight:600;">
                        <?= $result_patient->num_rows ?> results found
                    </div>
                <?php endif; ?>
            </div>
            <form method="POST" action="index.php" class="search-form">
                <div style="flex:1; min-width:220px;">
                    <input type="text" name="search_patient_id" id="search_patient_id" class="search-input" placeholder="Enter Patient ID..." value="<?= htmlspecialchars($search_patient_id) ?>" required>
                </div>
                <button type="submit" class="search-button"><i class="fas fa-search"></i> Search</button>
                <?php if (($search_patient_id || $filter_active) && isset($result_patient) && $result_patient->num_rows > 0): ?>
                    <div style="margin-left:auto; display:flex; gap:10px;">
                        <?php if ($edit_mode): ?>
                            <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>" class="cancel-edit-button"><i class="fas fa-times"></i> Cancel Edit</a>
                        <?php else: ?>
                            <a href="index.php?search_patient_id=<?= urlencode($search_patient_id) ?>&edit=true" class="edit-toggle-button"><i class="fas fa-edit"></i> Edit Mode</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($success_message): ?>
            <div class="message success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="message error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if (($search_patient_id || $filter_active) && isset($result_patient)): ?>
            <?php if ($result_patient->num_rows > 0): ?>
                <h3 style="margin-top:20px;">
                    <?php if ($search_patient_id): ?>
                        Tests for Patient ID: <strong><?= htmlspecialchars($search_patient_id) ?></strong>
                    <?php else: ?>
                        Filtered Tests (<?= $result_patient->num_rows ?> results)
                    <?php endif; ?>
                </h3>
                <div style="overflow-x:auto;">
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
                            <th>Medication</th>
                            <th>Dosage</th>
                            <th>Duration</th>
                            <th>Cumulative Dosage</th>
                            <th>Date of Continuation</th>
                            <th>Treatment Notes</th>
                            <th>Images</th>
                            <?php if ($edit_mode): ?><th>Actions</th><?php endif; ?>
                        </tr>
                        <?php while ($row = $result_patient->fetch_assoc()): ?>
                            <form method="POST" action="index.php?search_patient_id=<?= urlencode($search_patient_id) ?><?= $edit_mode ? '&edit=true' : '' ?>">
                                <input type="hidden" name="test_id" value="<?= htmlspecialchars($row['test_id']) ?>">
                                <tr>
                                    <td><?= htmlspecialchars($row['test_id']) ?></td>
                                    <td><?= htmlspecialchars($row['location'] ?? 'KH') ?></td>
                                    <td><?= htmlspecialchars($row['date_of_test']) ?></td>
                                    <?php if ($edit_mode): ?>
                                        <td><input type="number" name="age" class="edit-input" value="<?= htmlspecialchars($row['age'] ?? '') ?>" min="0" max="100"></td>
                                        <td>
                                            <select name="eye" class="edit-select">
                                                <option value="">Not Specified</option>
                                                <option value="OD" <?= ($row['eye'] ?? '') === 'OD' ? 'selected' : '' ?>>OD</option>
                                                <option value="OS" <?= ($row['eye'] ?? '') === 'OS' ? 'selected' : '' ?>>OS</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="report_diagnosis" class="edit-select">
                                                <option value="normal" <?= $row['report_diagnosis'] === 'normal' ? 'selected' : '' ?>>normal</option>
                                                <option value="abnormal" <?= $row['report_diagnosis'] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                                <option value="no input" <?= $row['report_diagnosis'] === 'no input' ? 'selected' : '' ?>>no input</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="exclusion" class="edit-select">
                                                <option value="none" <?= $row['exclusion'] === 'none' ? 'selected' : '' ?>>none</option>
                                                <option value="retinal detachment" <?= $row['exclusion'] === 'retinal detachment' ? 'selected' : '' ?>>retinal detachment</option>
                                                <option value="generalized retinal dysfunction" <?= $row['exclusion'] === 'generalized retinal dysfunction' ? 'selected' : '' ?>>generalized retinal dysfunction</option>
                                                <option value="unilateral testing" <?= $row['exclusion'] === 'unilateral testing' ? 'selected' : '' ?>>unilateral testing</option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="merci_score" class="edit-input" value="<?= htmlspecialchars($row['merci_score'] ?? '') ?>"></td>
                                        <td>
                                            <select name="merci_diagnosis" class="edit-select">
                                                <option value="normal" <?= $row['merci_diagnosis'] === 'normal' ? 'selected' : '' ?>>normal</option>
                                                <option value="abnormal" <?= $row['merci_diagnosis'] === 'abnormal' ? 'selected' : '' ?>>abnormal</option>
                                                <option value="no value" <?= $row['merci_diagnosis'] === 'no value' ? 'selected' : '' ?>>no value</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="error_type" class="edit-select">
                                                <option value="none" <?= ($row['error_type'] ?? '') === 'none' ? 'selected' : '' ?>>none</option>
                                                <option value="TN" <?= ($row['error_type'] ?? '') === 'TN' ? 'selected' : '' ?>>TN</option>
                                                <option value="FP" <?= ($row['error_type'] ?? '') === 'FP' ? 'selected' : '' ?>>FP</option>
                                                <option value="TP" <?= ($row['error_type'] ?? '') === 'TP' ? 'selected' : '' ?>>TP</option>
                                                <option value="FN" <?= ($row['error_type'] ?? '') === 'FN' ? 'selected' : '' ?>>FN</option>
                                            </select>
                                        </td>
                                        <td><input type="number" name="faf_grade" class="edit-input" value="<?= htmlspecialchars($row['faf_grade'] ?? '') ?>" min="1" max="4"></td>
                                        <td><input type="number" step="0.01" name="oct_score" class="edit-input" value="<?= htmlspecialchars($row['oct_score'] ?? '') ?>"></td>
                                        <td><input type="number" step="0.01" name="vf_score" class="edit-input" value="<?= htmlspecialchars($row['vf_score'] ?? '') ?>"></td>
                                        <td><input type="text" name="medication_name" class="edit-input" value="<?= htmlspecialchars($row['medication_name'] ?? '') ?>"></td>
                                        <td>
                                            <div style="display:flex; gap:4px;">
                                                <input type="number" step="0.01" name="dosage" class="edit-input" style="width:70px;" value="<?= htmlspecialchars($row['dosage'] ?? '') ?>">
                                                <select name="dosage_unit" class="edit-select" style="width:60px;">
                                                    <option value="mg" <?= ($row['dosage_unit'] ?? '') === 'mg' ? 'selected' : '' ?>>mg</option>
                                                    <option value="g" <?= ($row['dosage_unit'] ?? '') === 'g' ? 'selected' : '' ?>>g</option>
                                                </select>
                                            </div>
                                        </td>
                                        <td><input type="number" name="duration_days" class="edit-input" value="<?= htmlspecialchars($row['duration_days'] ?? '') ?>"></td>
                                        <td><input type="number" step="0.01" name="cumulative_dosage" class="edit-input" value="<?= htmlspecialchars($row['cumulative_dosage'] ?? '') ?>"></td>
                                        <td><input type="date" name="date_of_continuation" class="edit-input" value="<?= htmlspecialchars($row['date_of_continuation'] ?? '') ?>"></td>
                                        <td><input type="text" name="treatment_notes" class="edit-input" value="<?= htmlspecialchars($row['treatment_notes'] ?? '') ?>"></td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($row['age'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['eye'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['report_diagnosis']) ?></td>
                                        <td><?= htmlspecialchars($row['exclusion']) ?></td>
                                        <td><?= htmlspecialchars($row['merci_score'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['merci_diagnosis']) ?></td>
                                        <td><?= htmlspecialchars($row['error_type'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['faf_grade'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['oct_score'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['vf_score'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['medication_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['dosage'] ?? 'N/A') ?> <?= htmlspecialchars($row['dosage_unit'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['duration_days'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['cumulative_dosage'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['date_of_continuation'] ?? 'N/A') ?></td>
                                        <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($row['treatment_notes'] ?? 'N/A') ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php 
                                            $currentEye = $row['eye'] ?? '';
                                            $imageLinks = [];
                                            if (in_array($currentEye, ['OD','OS'])) {
                                                $testTypes = ['faf'=>'FAF','oct'=>'OCT','vf'=>'VF','mferg'=>'MFERG'];
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
                </div>
            <?php else: ?>
                <p style="margin-top:15px;">No tests found matching your criteria</p>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Stats Charts -->
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

        <!-- Summary Cards -->
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

        <!-- Metric Bars -->
        <div class="metric-bar-container">
            <div class="metric-bar">
                <div class="metric-fill" style="width: <?= min(($total_patients/100)*100,100) ?>%;"></div>
                <div class="metric-value"><?= $total_patients ?> Patients</div>
            </div>
            <div class="metric-bar">
                <div class="metric-fill" style="width: <?= min(($median/100)*100,100) ?>%;"></div>
                <div class="metric-value"><?= round($median) ?> Median Age</div>
            </div>
        </div>
    </div>

    <script>
        // Charts
        const diagnosisCtx = document.getElementById('diagnosisChart')?.getContext('2d');
        if (diagnosisCtx) {
            new Chart(diagnosisCtx, {
                type: 'pie',
                data: {
                    labels: <?= json_encode(array_keys($diagnosis_data)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($diagnosis_data)) ?>,
                        backgroundColor: ['rgb(0,168,143)','rgb(0,100,80)','rgb(200,200,200)'],
                        borderColor:'#fff',
                        borderWidth:1
                    }]
                },
                options:{
                    responsive:true,
                    plugins:{
                        legend:{ position:'top' },
                        tooltip:{
                            callbacks:{
                                label:function(ctx){
                                    const label = ctx.label||'';
                                    const val = ctx.raw||0;
                                    const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                    const pct = Math.round((val/total)*100);
                                    return `${label}: ${val} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        const eyeCtx = document.getElementById('eyeChart')?.getContext('2d');
        if (eyeCtx) {
            new Chart(eyeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($eye_data)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($eye_data)) ?>,
                        backgroundColor: ['rgb(0,168,143)','rgb(0,100,80)','rgb(200,200,200)'],
                        borderColor:'#fff',
                        borderWidth:1
                    }]
                },
                options:{ responsive:true, plugins:{ legend:{ position:'top' } } }
            });
        }

        const locationCtx = document.getElementById('locationChart')?.getContext('2d');
        if (locationCtx) {
            new Chart(locationCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($location_data)) ?>,
                    datasets: [{
                        label:'Tests by Location',
                        data: <?= json_encode(array_values($location_data)) ?>,
                        backgroundColor:['rgb(0,168,143)','rgb(44,162,95)','rgb(102,194,164)','rgb(178,226,226)'],
                        borderColor:'#fff',
                        borderWidth:1
                    }]
                },
                options:{
                    responsive:true,
                    plugins:{ legend:{ display:false } },
                    scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } }
                }
            });
        }

        const merciCtx = document.getElementById('merciChart')?.getContext('2d');
        if (merciCtx) {
            new Chart(merciCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($merci_data)) ?>,
                    datasets: [{
                        label:'Number of Tests',
                        data: <?= json_encode(array_values($merci_data)) ?>,
                        backgroundColor: Array(Object.keys(<?= json_encode($merci_data) ?>).length).fill('rgba(0,168,143,0.7)'),
                        borderColor:'rgba(255,255,255,1)',
                        borderWidth:1
                    }]
                },
                options:{
                    responsive:true,
                    plugins:{
                        legend:{ display:false },
                        tooltip:{
                            callbacks:{
                                label:function(ctx){ return ctx.parsed.y + ' tests'; }
                            }
                        }
                    },
                    scales:{
                        y:{ beginAtZero:true, title:{ display:true, text:'Number of Tests' }, ticks:{ stepSize:1 } },
                        x:{ title:{ display:true, text:'MERCI Score Range' } }
                    }
                }
            });
        }

        // Animate metric bars
        document.addEventListener('DOMContentLoaded', function(){
            const bars = document.querySelectorAll('.metric-fill');
            bars.forEach(b => {
                const width = b.style.width;
                b.style.width='0';
                setTimeout(()=>{ b.style.width = width; }, 120);
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();

/**
 * Helpers used above (duplicated here for clarity)
 */
function getOrCreatePatient($conn, $patientId, $subjectId, $dob, $location = 'KH', &$results) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) return $patientId;

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patientId, $subjectId, $dob, $location);
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    $results['patients']++;
    return $patientId;
}

function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, location, date_of_test, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, dosage, duration_days,
            cumulative_dosage
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' :
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);

    $stmt->bind_param(
        "ssssissssssdddsdid",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test'],
        $testData['age'],
        $testData['eye'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $merciScoreForDb,
        $testData['merci_diagnosis'],
        $testData['error_type'],
        $testData['faf_grade'],
        $testData['oct_score'],
        $testData['vf_score'],
        $testData['actual_diagnosis'],
        $testData['dosage'],
        $testData['duration_days'],
        $testData['cumulative_dosage']
    );

    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
    }
}
?>
