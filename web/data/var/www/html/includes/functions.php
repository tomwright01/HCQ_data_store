<?php
require_once __DIR__ . '/config.php';

/** check if a column exists */
function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $db = DB_NAME; // from config.php
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

/** resolve user input (subject or patient) to canonical patients.patient_id */
function resolve_patient_id(mysqli $conn, string $typedId): ?string {
    if ($typedId === '') return null;

    // Try as patient_id
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param("s", $typedId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row['patient_id'];

    // Try as subject_id
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE subject_id = ? LIMIT 1");
    $stmt->bind_param("s", $typedId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['patient_id'] ?? null;
}

/** fetch the subject_id for a given patient_id */
function get_patient_subject_id(mysqli $conn, string $patient_id): ?string {
    $stmt = $conn->prepare("SELECT subject_id FROM patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['subject_id'] ?? null;
}

/** ---- MEDICATION INSERT (auto-fills medications.subject_id if that column exists) ---- */
function insertMedication(
    mysqli $conn,
    string $patient_id,
    string $medication_name,
    ?string $dosage_per_day = null,
    ?string $duration_days = null,
    ?string $cumulative_dosage = null,
    ?string $start_date = null,
    ?string $end_date = null,
    ?string $notes = null
): int {
    if ($patient_id === '' || $medication_name === '') {
        throw new InvalidArgumentException('patient_id and medication_name are required');
    }

    $hasSubject = has_column($conn, 'medications', 'subject_id');
    $subject_id = $hasSubject ? (get_patient_subject_id($conn, $patient_id) ?? $patient_id) : null;

    if ($hasSubject) {
        $sql = "INSERT INTO medications
                (patient_id, subject_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        // bind all as strings; MySQL will cast numerics/dates appropriately
        $stmt->bind_param(
            "sssssssss",
            $patient_id, $subject_id, $medication_name, $dosage_per_day, $duration_days,
            $cumulative_dosage, $start_date, $end_date, $notes
        );
    } else {
        $sql = "INSERT INTO medications
                (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssss",
            $patient_id, $medication_name, $dosage_per_day, $duration_days,
            $cumulative_dosage, $start_date, $end_date, $notes
        );
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new mysqli_sql_exception("insertMedication failed: $err");
    }
    $newId = $stmt->insert_id ?: $conn->insert_id;
    $stmt->close();
    return (int)$newId;
}

/** ===== the rest of your original helpers (unchanged) ===== */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

function getOrCreatePatient($conn, $patient_id, $subject_id, $location, $dob) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) { $stmt->close(); return $patient_id; }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patient_id, $subject_id, $location, $dob);
    if (!$stmt->execute()) {
        throw new mysqli_sql_exception("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patient_id;
}

function insertTest($conn, $test_id, $patient_id, $location, $date_of_test) {
    $stmt = $conn->prepare("
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
    if (!$stmt->execute()) {
        throw new mysqli_sql_exception("Failed to insert/update test: " . $stmt->error);
    }
    $stmt->close();
}

function insertTestEye(
    $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
    $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
    $actual_diagnosis, $dosage, $duration_days, $cumulative_dosage, $date_of_continuation
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
    $stmt->bind_param(
        "isississiiisisis",
        $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score, $merci_diagnosis, $error_type,
        $faf_grade, $oct_score, $vf_score, $actual_diagnosis, $dosage,
        $duration_days, $cumulative_dosage, $date_of_continuation
    );
    if (!$stmt->execute()) {
        throw new mysqli_sql_exception("Failed to insert/update test_eye: " . $stmt->error);
    }
    $stmt->close();
}

function getPatientsWithTests($conn) {
    $result = $conn->query("SELECT * FROM patients ORDER BY subject_id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getTestsByPatient($conn, $patient_id) {
    $stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test DESC");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getTestEyes($conn, $test_id) {
    $stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye ASC");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
