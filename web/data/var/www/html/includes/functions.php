<?php
require_once __DIR__ . '/config.php';

/* ---------- helpers ---------- */

function has_column(mysqli $conn, string $table, string $column): bool {
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

/** Accepts either a patient_id or a subject_id and returns the canonical patients.patient_id */
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

/** Fetch subject_id for a given patient_id */
function get_patient_subject_id(mysqli $conn, string $patient_id): ?string {
    $stmt = $conn->prepare("SELECT subject_id FROM patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['subject_id'] ?? null;
}

/* =========================================================
   MEDICATIONS
   - Fills subject_id if present in schema
   - Fills input_dosage_value / input_dosage_unit if present
   ========================================================= */
function insertMedication(
    mysqli $conn,
    string $patient_or_subject_id,
    string $medication_name,
    ?string $dosage_per_day = null,
    ?string $duration_days = null,
    ?string $cumulative_dosage = null,
    ?string $start_date = null,
    ?string $end_date = null,
    ?string $notes = null,
    ?string $input_dosage_value = null,
    ?string $input_dosage_unit = null
): int {
    if ($medication_name === '') {
        throw new InvalidArgumentException('medication_name is required');
    }

    // Resolve to canonical patient_id (accepts subject or patient)
    $patient_id = resolve_patient_id($conn, $patient_or_subject_id);
    if (!$patient_id) {
        throw new InvalidArgumentException("Unknown patient/subject id: {$patient_or_subject_id}");
    }

    // Optional columns present?
    $hasSubjectCol   = has_column($conn, 'medications', 'subject_id');
    $hasInputValCol  = has_column($conn, 'medications', 'input_dosage_value');
    $hasInputUnitCol = has_column($conn, 'medications', 'input_dosage_unit');

    $subject_id = $hasSubjectCol ? (get_patient_subject_id($conn, $patient_id) ?? $patient_id) : null;

    // If input_* columns exist, ensure we provide safe values even if nulls were passed
    if ($hasInputValCol) {
        if ($input_dosage_value === null || $input_dosage_value === '') {
            // Try to derive from dosage_per_day like "200", "200 mg", "200mg/day"
            $derived = null;
            if ($dosage_per_day !== null && $dosage_per_day !== '') {
                if (is_numeric($dosage_per_day)) {
                    $derived = $dosage_per_day;
                } else {
                    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $dosage_per_day, $m)) {
                        $derived = $m[1];
                    }
                }
            }
            $input_dosage_value = ($derived !== null) ? (string)$derived : '0';
        }
    }
    if ($hasInputUnitCol) {
        if ($input_dosage_unit === null || $input_dosage_unit === '') {
            // Best-effort default
            $input_dosage_unit = 'mg';
        }
    }

    // Build dynamic INSERT to include only columns that exist, but
    // include input_* and subject_id when present to avoid NOT NULL errors.
    $cols = ['patient_id', 'medication_name'];
    $vals = [$patient_id, $medication_name];

    // Subject id if column exists
    if ($hasSubjectCol) {
        $cols[] = 'subject_id';
        $vals[] = $subject_id;
    }

    // Common optional columns (add them if they exist in your schema; harmless if NULL)
    if (has_column($conn, 'medications', 'dosage_per_day')) {
        $cols[] = 'dosage_per_day'; $vals[] = $dosage_per_day;
    }
    if (has_column($conn, 'medications', 'duration_days')) {
        $cols[] = 'duration_days'; $vals[] = $duration_days;
    }
    if (has_column($conn, 'medications', 'cumulative_dosage')) {
        $cols[] = 'cumulative_dosage'; $vals[] = $cumulative_dosage;
    }
    if (has_column($conn, 'medications', 'start_date')) {
        $cols[] = 'start_date'; $vals[] = $start_date;
    }
    if (has_column($conn, 'medications', 'end_date')) {
        $cols[] = 'end_date'; $vals[] = $end_date;
    }
    if (has_column($conn, 'medications', 'notes')) {
        $cols[] = 'notes'; $vals[] = $notes;
    }

    // Input columns (important for NOT NULL schemas)
    if ($hasInputValCol) {
        $cols[] = 'input_dosage_value'; $vals[] = $input_dosage_value;
    }
    if ($hasInputUnitCol) {
        $cols[] = 'input_dosage_unit';  $vals[] = $input_dosage_unit;
    }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO medications (" . implode(',', $cols) . ") VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);

    // Bind all as strings (MySQL casts as needed)
    $types = str_repeat('s', count($vals));
    $stmt->bind_param($types, ...$vals);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new mysqli_sql_exception("insertMedication failed: $err");
    }
    $newId = $stmt->insert_id ?: $conn->insert_id;
    $stmt->close();
    return (int)$newId;
}

/* =========================================================
   OTHER HELPERS (unchanged from your previous version)
   ========================================================= */

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
