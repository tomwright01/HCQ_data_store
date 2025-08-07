<?php
require_once 'config.php';

/**
 * Generate a unique patient_id from subject_id
 */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20); // 20 chars of md5 hash for uniqueness
}

function getOrCreatePatient($conn, $patient_id, $subject_id, $location, $dob) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $stmt->close();
        return $patient_id;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patient_id, $subject_id, $location, $dob);
    if (!$stmt->execute()) {
        die("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patient_id;
}

function insertTest($conn, $test_id, $patient_id, $location, $date_of_test) {
    $stmt = $conn->prepare("
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            location = VALUES(location),
            date_of_test = VALUES(date_of_test),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
    if (!$stmt->execute()) {
        die("Failed to insert/update test: " . $stmt->error);
    }
    $stmt->close();
}

function insertTestEye(
    $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
    $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
    $actual_diagnosis, $medication_name, $dosage, $dosage_unit,
    $duration_days, $cumulative_dosage, $date_of_continuation, $treatment_notes
) {
    $stmt = $conn->prepare("
        INSERT INTO test_eyes 
        (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
        faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit,
        duration_days, cumulative_dosage, date_of_continuation, treatment_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            age = VALUES(age),
            report_diagnosis = VALUES(report_diagnosis),
            exclusion = VALUES(exclusion),
            merci_score = VALUES(merci_score),
            merci_diagnosis = VALUES(merci_diagnosis),
            error_type = VALUES(error_type),
            faf_grade = VALUES(faf_grade),
            oct_score = VALUES(oct_score),
            vf_score = VALUES(vf_score),
            actual_diagnosis = VALUES(actual_diagnosis),
            medication_name = VALUES(medication_name),
            dosage = VALUES(dosage),
            dosage_unit = VALUES(dosage_unit),
            duration_days = VALUES(duration_days),
            cumulative_dosage = VALUES(cumulative_dosage),
            date_of_continuation = VALUES(date_of_continuation),
            treatment_notes = VALUES(treatment_notes),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("ssissssssdddsdsssss",
        $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score, $merci_diagnosis, $error_type,
        $faf_grade, $oct_score, $vf_score, $actual_diagnosis, $medication_name, $dosage, $dosage_unit,
        $duration_days, $cumulative_dosage, $date_of_continuation, $treatment_notes
    );
    if (!$stmt->execute()) {
        die("Failed to insert/update test_eye: " . $stmt->error);
    }
    $stmt->close();
}

// Helper function: normalize date to Y-m-d or null if invalid
function normalizeDate($dateStr) {
    if (!$dateStr) return null;
    // Try Y-m-d format first
    $d = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$d) {
        // Try m/d/Y format next
        $d = DateTime::createFromFormat('m/d/Y', $dateStr);
    }
    return $d ? $d->format('Y-m-d') : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        die("Error uploading file.");
    }

    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, 'r')) === FALSE) {
        die("Failed to open uploaded CSV file.");
    }

    $headers = fgetcsv($handle);
    if ($headers === FALSE) {
        die("Empty CSV file.");
    }

    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== FALSE) {
        $lineNumber++;
        $data = array_combine($headers, $row);

        if (empty($data['subject_id']) || empty($data['test_id'])) {
            // Skip invalid rows
            continue;
        }

        $patient_id = generatePatientId($data['subject_id']);
        $subject_id = $data['subject_id'];
        $location = $data['location'] ?? 'KH';
        $date_of_birth = normalizeDate($data['date_of_birth'] ?? null);
        $test_id = $data['test_id'];
        $date_of_test = normalizeDate($data['date_of_test'] ?? null);

        getOrCreatePatient($conn, $patient_id, $subject_id, $location, $date_of_birth);
        insertTest($conn, $test_id, $patient_id, $location, $date_of_test);

        foreach (['OD', 'OS'] as $eye) {
            $prefix = $eye . '_';

            $age = isset($data[$prefix . 'age']) && $data[$prefix . 'age'] !== '' ? (int)$data[$prefix . 'age'] : null;
            $report_diagnosis = $data[$prefix . 'report_diagnosis'] ?? 'no input';
            $exclusion = $data[$prefix . 'exclusion'] ?? 'none';
            $merci_score = isset($data[$prefix . 'merci_score']) && is_numeric($data[$prefix . 'merci_score']) ? $data[$prefix . 'merci_score'] : null;
            $merci_diagnosis = $data[$prefix . 'merci_diagnosis'] ?? 'no value';
            $error_type = $data[$prefix . 'error_type'] ?? null;
            $faf_grade = isset($data[$prefix . 'faf_grade']) && $data[$prefix . 'faf_grade'] !== '' ? (int)$data[$prefix . 'faf_grade'] : null;
            $oct_score = isset($data[$prefix . 'oct_score']) && is_numeric($data[$prefix . 'oct_score']) ? (float)$data[$prefix . 'oct_score'] : null;
            $vf_score = isset($data[$prefix . 'vf_score']) && is_numeric($data[$prefix . 'vf_score']) ? (float)$data[$prefix . 'vf_score'] : null;
            $actual_diagnosis = $data[$prefix . 'actual_diagnosis'] ?? 'other';
            $medication_name = $data[$prefix . 'medication_name'] ?? null;
            $dosage = isset($data[$prefix . 'dosage']) && is_numeric($data[$prefix . 'dosage']) ? (float)$data[$prefix . 'dosage'] : null;
            $dosage_unit = $data[$prefix . 'dosage_unit'] ?? 'mg';
            $duration_days = isset($data[$prefix . 'duration_days']) && is_numeric($data[$prefix . 'duration_days']) ? (int)$data[$prefix . 'duration_days'] : null;
            $cumulative_dosage = isset($data[$prefix . 'cumulative_dosage']) && is_numeric($data[$prefix . 'cumulative_dosage']) ? (float)$data[$prefix . 'cumulative_dosage'] : null;
            $date_of_continuation = normalizeDate($data[$prefix . 'date_of_continuation'] ?? null);
            $treatment_notes = $data[$prefix . 'treatment_notes'] ?? null;

            insertTestEye(
                $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
                $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
                $actual_diagnosis, $medication_name, $dosage, $dosage_unit,
                $duration_days, $cumulative_dosage, $date_of_continuation, $treatment_notes
            );
        }
    }

    fclose($handle);
    echo "CSV import completed successfully.";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>CSV Import - Clinical Database</title>
<style>
    body { font-family: Arial, sans-serif; margin: 40px; }
    form { margin-bottom: 20px; }
</style>
</head>
<body>
<h1>Import Clinical Data CSV</h1>
<form method="POST" enctype="multipart/form-data">
    <label for="csv_file">Choose CSV file:</label>
    <input type="file" id="csv_file" name="csv_file" accept=".csv" required />
    <button type="submit">Upload & Import</button>
</form>
<p>CSV must include subject_id and test_id columns. Patient ID will be generated automatically.</p>
</body>
</html>
