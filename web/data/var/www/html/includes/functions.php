<?php
require_once 'config.php';

/**
 * Generate a unique patient_id from subject_id
 */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Get existing or create a patient record
 */
function getOrCreatePatient($conn, $patient_id, $subject_id, $location, $dob) {
    // Check if the patient already exists in the database using the CSV's patient_id
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows > 0) {
        $stmt->close();
        return $patient_id; // Patient exists, return the patient_id
    }
    $stmt->close();

    // If the patient does not exist, create a new one
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patient_id, $subject_id, $location, $dob);
    if (!$stmt->execute()) {
        die("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patient_id; // Return the newly created patient_id
}

/**
 * Insert or update a test record
 */
function insertTest($conn, $test_id, $patient_id, $location, $date_of_test) {
    $stmt = $conn->prepare("
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
    if (!$stmt->execute()) {
        die("Failed to insert/update test: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Insert or update a test_eye record
 */
function insertTestEye(
    $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
    $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
    $actual_diagnosis, $dosage, 
    $duration_days, $cumulative_dosage, $date_of_continuation
) {
    $stmt = $conn->prepare("
        INSERT INTO test_eyes 
        (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
        faf_grade, oct_score, vf_score, actual_diagnosis, dosage,
        duration_days, cumulative_dosage, date_of_continuation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            dosage = VALUES(dosage),
            duration_days = VALUES(duration_days),
            cumulative_dosage = VALUES(cumulative_dosage),
            date_of_continuation = VALUES(date_of_continuation),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("isississiiiddsds",
        $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score, $merci_diagnosis, $error_type,
        $faf_grade, $oct_score, $vf_score, $actual_diagnosis, $dosage,
        $duration_days, $cumulative_dosage, $date_of_continuation
    );
    if (!$stmt->execute()) {
        die("Failed to insert/update test_eye: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Retrieve all patients
 */
function getPatientsWithTests($conn) {
    $result = $conn->query("SELECT * FROM patients ORDER BY subject_id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Retrieve all tests for a given patient
 */
function getTestsByPatient($conn, $patient_id) {
    $stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test DESC");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Retrieve all test_eye records for a given test
 */
function getTestEyes($conn, $test_id) {
    $stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye ASC");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
?>
