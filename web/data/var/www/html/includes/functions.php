<?php
require_once 'config.php';
// includes/functions.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Generate a (legacy) pseudo patient_id from a subject_id.
 * Only used in older CSV flows—your live app should prefer numeric patient_id already in DB.
 */
function generatePatientId(string $subject_id): string {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Return the canonical patients.patient_id (string) when the user types either:
 *   - a Patient ID (e.g., 920), OR
 *   - a Subject ID (e.g., SUBJ001)
 *
 * If not found, returns null.
 */
function resolve_patient_id(mysqli $conn, ?string $input): ?string {
    $id = trim((string)$input);
    if ($id === '') return null;

    // Try exact patient_id first
    $sql = "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row['patient_id'])) {
        return (string)$row['patient_id'];
    }

    // Then try subject_id
    $sql = "SELECT patient_id FROM patients WHERE subject_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['patient_id'] : null;
}

/**
 * Quick existence checks
 */
function patientExistsByPatientId(mysqli $conn, string $patient_id): bool {
    $stmt = $conn->prepare("SELECT 1 FROM patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function patientExistsBySubjectId(mysqli $conn, string $subject_id): bool {
    $stmt = $conn->prepare("SELECT 1 FROM patients WHERE subject_id = ? LIMIT 1");
    $stmt->bind_param("s", $subject_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

/**
 * (Optional) find single patient row by subject_id
 */
function findPatientBySubjectId(mysqli $conn, string $subject_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE subject_id = ? LIMIT 1");
    $stmt->bind_param("s", $subject_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ?: null;
}

/**
 * Create patient if missing (keeps your previous behavior).
 * $patient_id here is whatever your CSV provided; we assume your schema can store it as string.
 */
function getOrCreatePatient(mysqli $conn, string $patient_id, string $subject_id, string $location, string $dob): string {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
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
 * Insert or touch a test row.
 * (We bind everything as strings for NULL-safety; MySQL will coerce numeric/date types.)
 */
function insertTest(mysqli $conn, string $test_id, string $patient_id, string $location, string $date_of_test): void {
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
 * Insert or update a test_eyes row (legacy fields included for compatibility).
 * If you have a UNIQUE index on (test_id, eye) this will upsert cleanly.
 */
function insertTestEye(
    mysqli $conn,
    string $test_id,
    string $eye,
    ?int $age,
    ?string $report_diagnosis,
    ?string $exclusion,
    ?float $merci_score,
    ?string $merci_diagnosis,
    ?string $error_type,
    ?string $faf_grade,
    ?float $oct_score,
    ?float $vf_score,
    ?string $actual_diagnosis,
    ?float $dosage,
    ?int $duration_days,
    ?float $cumulative_dosage,
    ?string $date_of_continuation
): void {

    $sql = "
        INSERT INTO test_eyes
            (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
             faf_grade, oct_score, vf_score, actual_diagnosis, dosage, duration_days, cumulative_dosage, date_of_continuation)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            age                 = VALUES(age),
            report_diagnosis    = VALUES(report_diagnosis),
            exclusion           = VALUES(exclusion),
            merci_score         = VALUES(merci_score),
            merci_diagnosis     = VALUES(merci_diagnosis),
            error_type          = VALUES(error_type),
            faf_grade           = VALUES(faf_grade),
            oct_score           = VALUES(oct_score),
            vf_score            = VALUES(vf_score),
            actual_diagnosis    = VALUES(actual_diagnosis),
            dosage              = VALUES(dosage),
            duration_days       = VALUES(duration_days),
            cumulative_dosage   = VALUES(cumulative_dosage),
            date_of_continuation= VALUES(date_of_continuation),
            updated_at          = CURRENT_TIMESTAMP
    ";

    // Bind all as strings to preserve NULL when values are null.
    $age_s                = is_null($age) ? null : (string)$age;
    $merci_score_s        = is_null($merci_score) ? null : (string)$merci_score;
    $oct_score_s          = is_null($oct_score) ? null : (string)$oct_score;
    $vf_score_s           = is_null($vf_score) ? null : (string)$vf_score;
    $dosage_s             = is_null($dosage) ? null : (string)$dosage;
    $duration_days_s      = is_null($duration_days) ? null : (string)$duration_days;
    $cumulative_dosage_s  = is_null($cumulative_dosage) ? null : (string)$cumulative_dosage;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssssssssssssss",
        $test_id,
        $eye,
        $age_s,
        $report_diagnosis,
        $exclusion,
        $merci_score_s,
        $merci_diagnosis,
        $error_type,
        $faf_grade,
        $oct_score_s,
        $vf_score_s,
        $actual_diagnosis,
        $dosage_s,
        $duration_days_s,
        $cumulative_dosage_s,
        $date_of_continuation
    );
    if (!$stmt->execute()) {
        die("Failed to insert/update test_eye: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Patients list for index (kept exactly as your previous version expects)
 */
function getPatientsWithTests(mysqli $conn): array {
    $result = $conn->query("SELECT * FROM patients ORDER BY subject_id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Tests for a patient (index.php expects all columns: file-ref fallbacks etc.)
 */
function getTestsByPatient(mysqli $conn, string $patient_id): array {
    $stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test DESC");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Eye rows for a test (index.php reads various optional columns, so return *)
 */
function getTestEyes(mysqli $conn, string $test_id): array {
    $stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye ASC");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * (Optional) Medications by patient (handy in case you want to move that query out of index.php)
 */
function getMedicationsByPatient(mysqli $conn, string $patient_id): array {
    $stmt = $conn->prepare("
        SELECT med_id, patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage,
               start_date, end_date, notes
        FROM medications
        WHERE patient_id = ?
        ORDER BY start_date DESC, med_id DESC
    ");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Utility: check if a column exists (useful for mixed schemas and importers)
 */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    if (!defined('DB_NAME')) return false;
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $db = DB_NAME;
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}
