<?php
require_once __DIR__ . '/config.php';

/**
 * Generate patient_id from subject_id
 */
function generatePatientId(string $subject_id): string {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Get existing patient by subject_id or insert new patient.
 */
function getOrCreatePatient(mysqli $conn, string $subject_id, string $dob, string $location = 'KH'): string {
    $patient_id = generatePatientId($subject_id);

    $sel = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $sel->bind_param("s", $patient_id);
    $sel->execute();
    $res = $sel->get_result();

    if ($res && $res->num_rows > 0) {
        return $patient_id;
    }

    $ins = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location) VALUES (?, ?, ?, ?)");
    $ins->bind_param("ssss", $patient_id, $subject_id, $dob, $location);

    if (!$ins->execute()) {
        throw new Exception("Patient insert failed: " . $ins->error);
    }

    return $patient_id;
}

/**
 * Insert or update a test record.
 */
function insertOrUpdateTest(mysqli $conn, array $testData): void {
    // Insert or update tests table (without eyes)
    $sql = "
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            location = VALUES(location),
            date_of_test = VALUES(date_of_test)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed (tests): " . $conn->error);
    }

    $stmt->bind_param(
        "ssss",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test']
    );

    if (!$stmt->execute()) {
        throw new Exception("Test insert/update failed: " . $stmt->error);
    }
    $stmt->close();

    // Insert or update test_eyes table (detailed per eye)
    $sql_eye = "
        INSERT INTO test_eyes (
            test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit,
            duration_days, cumulative_dosage, date_of_continuation, treatment_notes
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
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
            treatment_notes = VALUES(treatment_notes)
    ";

    $stmt_eye = $conn->prepare($sql_eye);
    if (!$stmt_eye) {
        throw new Exception("Prepare failed (test_eyes): " . $conn->error);
    }

    // Bind all parameters, handling possible nulls gracefully
    $stmt_eye->bind_param(
        "ssissssssdddsisdisss",
        $testData['test_id'],
        $testData['eye'],
        $testData['age'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $testData['merci_score'],
        $testData['merci_diagnosis'],
        $testData['error_type'],
        $testData['faf_grade'],
        $testData['oct_score'],
        $testData['vf_score'],
        $testData['actual_diagnosis'],
        $testData['medication_name'],
        $testData['dosage'],
        $testData['dosage_unit'],
        $testData['duration_days'],
        $testData['cumulative_dosage'],
        $testData['date_of_continuation'],
        $testData['treatment_notes']
    );

    if (!$stmt_eye->execute()) {
        throw new Exception("Test eye insert/update failed: " . $stmt_eye->error);
    }
    $stmt_eye->close();
}
