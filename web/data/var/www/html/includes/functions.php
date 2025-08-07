<?php
require_once 'config.php';

/**
 * Generate a unique patient_id from subject_id
 */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Get existing or create a patient record.
 */
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
