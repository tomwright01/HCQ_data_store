<?php
require_once 'config.php';

/**
 * =========
 * LEGACY/CSV HELPERS (kept intact)
 * =========
 */

/** Generate a unique patient_id from subject_id */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Get existing or create a patient record (by provided patient_id from CSV)
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

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth)
                            VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patient_id, $subject_id, $location, $dob);
    if (!$stmt->execute()) {
        die("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patient_id;
}

/**
 * Insert or update a test record (by test_id)
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
 * NOTE: ON DUPLICATE KEY requires a UNIQUE constraint (e.g., UNIQUE(test_id, eye))
 * If you don’t have that unique key, this will always insert new rows.
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
    // Types kept as in your working version:
    $stmt->bind_param("isississiiisisis",
        $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score, $merci_diagnosis, $error_type,
        $faf_grade, $oct_score, $vf_score, $actual_diagnosis, $dosage,
        $duration_days, $cumulative_dosage, $date_of_continuation
    );
    if (!$stmt->execute()) {
        die("Failed to insert/update test_eye: " . $stmt->error);
    }
    $stmt->close();
}

/** Retrieve all patients (for UI cards) */
function getPatientsWithTests($conn) {
    $result = $conn->query("SELECT * FROM patients ORDER BY subject_id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** Retrieve tests for a patient */
function getTestsByPatient($conn, $patient_id) {
    $stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test DESC");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** Retrieve per-eye rows for a test */
function getTestEyes($conn, $test_id) {
    $stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye ASC");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * =========
 * IMAGE FLOW HELPERS (added to support new importer & viewers)
 * =========
 */

/** Get a patient row (simple existence check helper for importer) */
function getPatientById(string $patient_id) : ?array {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Ensure a tests row exists for (patient_id, dateYmd). Returns test_id.
 * dateYmd must be "YYYYMMDD".
 */
function ensureTest(mysqli $conn, string $patient_id, string $dateYmd): string {
    $sqlDate = DateTime::createFromFormat('Ymd', $dateYmd)->format('Y-m-d');
    $q = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
    $q->bind_param("ss", $patient_id, $sqlDate);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $q->close();
    if ($res) return $res['test_id'];

    $testId = $patient_id . '_' . $dateYmd . '_' . substr(md5(uniqid('', true)), 0, 6);
    $ins = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test) VALUES (?, ?, ?)");
    $ins->bind_param("sss", $testId, $patient_id, $sqlDate);
    if (!$ins->execute()) {
        throw new RuntimeException("DB error creating test: " . $conn->error);
    }
    $ins->close();
    return $testId;
}

/** Ensure a test_eyes row exists for (test_id, eye) */
function ensureTestEye(mysqli $conn, string $test_id, string $eye): void {
    $eye = strtoupper($eye) === 'OS' ? 'OS' : 'OD';
    $q = $conn->prepare("SELECT result_id FROM test_eyes WHERE test_id = ? AND eye = ?");
    $q->bind_param("ss", $test_id, $eye);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $q->close();
    if ($res) return;

    $ins = $conn->prepare("INSERT INTO test_eyes (test_id, eye) VALUES (?, ?)");
    $ins->bind_param("ss", $test_id, $eye);
    if (!$ins->execute()) {
        throw new RuntimeException("DB error creating test_eyes: " . $conn->error);
    }
    $ins->close();
}

/** Map modality+eye to the correct reference column in test_eyes */
function refCol(string $testType, string $eye): string {
    $eye = strtoupper($eye) === 'OS' ? 'OS' : 'OD';
    switch (strtoupper($testType)) {
        case 'FAF':   return "faf_reference_{$eye}";
        case 'OCT':   return "oct_reference_{$eye}";
        case 'VF':    return "vf_reference_{$eye}";
        case 'MFERG': return "mferg_reference_{$eye}";
    }
    throw new RuntimeException("Unsupported test type: $testType");
}
