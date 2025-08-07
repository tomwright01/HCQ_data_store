<?php
// includes/functions.php
require_once __DIR__ . '/config.php';

/**
 * Get existing patient by patient_id or insert a new patient.
 */
function getOrCreatePatient(
    mysqli $conn,
    string $patientId,
    string $patientName,
    string $dob,
    string $location = 'KH',
    ?array &$results = null
): string {
    $sel = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $sel->bind_param("s", $patientId);
    $sel->execute();
    $res = $sel->get_result();
    if ($res && $res->num_rows > 0) {
        return $patientId;
    }

    $ins = $conn->prepare("INSERT INTO patients (patient_id, patient_name, dob, location) VALUES (?, ?, ?, ?)");
    $ins->bind_param("ssss", $patientId, $patientName, $dob, $location);
    if (!$ins->execute()) {
        throw new Exception("Patient insert failed: " . $ins->error);
    }

    if (isset($results) && is_array($results)) {
        $results['patients'] = ($results['patients'] ?? 0) + 1;
    }

    return $patientId;
}

/**
 * Insert or update a test record.
 */
function insertTest(array $testData): void {
    global $conn;

    $sql = "
        INSERT INTO tests (
            test_id, patient_id, location, date_of_test, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis,
            medication_name, dosage, dosage_unit, duration_days,
            cumulative_dosage, date_of_continuation, treatment_notes
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
        ON DUPLICATE KEY UPDATE
            age                  = VALUES(age),
            report_diagnosis     = VALUES(report_diagnosis),
            exclusion            = VALUES(exclusion),
            merci_score          = VALUES(merci_score),
            merci_diagnosis      = VALUES(merci_diagnosis),
            error_type           = VALUES(error_type),
            faf_grade            = VALUES(faf_grade),
            oct_score            = VALUES(oct_score),
            vf_score             = VALUES(vf_score),
            actual_diagnosis     = VALUES(actual_diagnosis),
            medication_name      = VALUES(medication_name),
            dosage               = VALUES(dosage),
            dosage_unit          = VALUES(dosage_unit),
            duration_days        = VALUES(duration_days),
            cumulative_dosage    = VALUES(cumulative_dosage),
            date_of_continuation = VALUES(date_of_continuation),
            treatment_notes      = VALUES(treatment_notes)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $merci = $testData['merci_score'] === 'unable'
        ? 'unable'
        : ($testData['merci_score'] === null ? null : $testData['merci_score']);

    $stmt->bind_param(
        "ssssissssssdddsisdisss",
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test'],
        $testData['age'],
        $testData['eye'],
        $testData['report_diagnosis'],
        $testData['exclusion'],
        $merci,
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

    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
    }

    $stmt->close();
}

