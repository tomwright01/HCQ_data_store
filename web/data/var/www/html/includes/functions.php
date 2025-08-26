<?php
require_once 'includes/config.php';

/* ---------------------------
   Small helpers
---------------------------- */
function _stmt_close($stmt){ if ($stmt) { $stmt->close(); } }

/* ---------------------------
   Patient lookup & resolve
---------------------------- */
function find_patient_by_patient_id($conn, $patient_id){
    $sql = "SELECT patient_id, subject_id FROM patients WHERE patient_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return null; }
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    _stmt_close($stmt);
    return $row ?: null;
}

function find_patient_by_subject($conn, $subject_id){
    $sql = "SELECT patient_id, subject_id FROM patients WHERE subject_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return null; }
    $stmt->bind_param("s", $subject_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    _stmt_close($stmt);
    return $row ?: null;
}

/** Accepts either a Subject ID or a Patient ID and returns canonical patients.patient_id */
function resolve_patient_id($conn, $typedId){
    if ($typedId === '' || $typedId === null) return null;
    // Try as patient_id
    $row = find_patient_by_patient_id($conn, $typedId);
    if ($row && !empty($row['patient_id'])) return $row['patient_id'];
    // Try as subject_id
    $row = find_patient_by_subject($conn, $typedId);
    if ($row && !empty($row['patient_id'])) return $row['patient_id'];
    return null;
}

/* ---------------------------
   Medications
---------------------------- */
function insertMedication($conn, $patient_id, $name,
                          $dosage_per_day=null, $duration_days=null, $cumulative_dosage=null,
                          $start_date=null, $end_date=null, $notes=null){
    $sql = "INSERT INTO medications
              (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
            VALUES (?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { die('prepare failed: '.$conn->error); }
    // Bind as strings so NULLs can be passed cleanly
    $stmt->bind_param("ssssssss",
        $patient_id, $name, $dosage_per_day, $duration_days, $cumulative_dosage, $start_date, $end_date, $notes
    );
    if (!$stmt->execute()) { die("Failed to insert medication: ".$stmt->error); }
    _stmt_close($stmt);
    return $conn->insert_id;
}

/* ---------------------------
   Legacy helpers (CSV/import)
---------------------------- */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5((string)$subject_id), 0, 20);
}

function getOrCreatePatient($conn, $patient_id, $subject_id, $location, $dob) {
    // If CSV gave a patient_id and it exists, reuse it
    if ($patient_id) {
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $patient_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) { _stmt_close($stmt); return $patient_id; }
            _stmt_close($stmt);
        }
    }

    // Else try subject_id
    $existing = find_patient_by_subject($conn, $subject_id);
    if ($existing) return $existing['patient_id'];

    // Create new patient
    $pid = $patient_id ?: generatePatientId($subject_id);
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
    if (!$stmt) { die('prepare failed: '.$conn->error); }
    $stmt->bind_param("ssss", $pid, $subject_id, $location, $dob);
    if (!$stmt->execute()) { die("Failed to insert patient: ".$stmt->error); }
    _stmt_close($stmt);
    return $pid;
}

/* ---------------------------
   Tests & test_eyes
---------------------------- */
function insertTest($conn, $test_id, $patient_id, $location, $date_of_test) {
    $sql = "INSERT INTO tests (test_id, patient_id, location, date_of_test)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              location = VALUES(location),
              date_of_test = VALUES(date_of_test),
              updated_at = CURRENT_TIMESTAMP";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { die('prepare failed: '.$conn->error); }
    $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
    if (!$stmt->execute()) { die("Failed to insert/update test: ".$stmt->error); }
    _stmt_close($stmt);
}

function insertTestEye(
    $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
    $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
    $actual_diagnosis, $dosage, $duration_days, $cumulative_dosage, $date_of_continuation
) {
    // Bind as strings for maximum compatibility
    $sql = "INSERT INTO test_eyes
              (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
               faf_grade, oct_score, vf_score, actual_diagnosis, dosage, duration_days, cumulative_dosage, date_of_continuation)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
              updated_at = CURRENT_TIMESTAMP";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { die('prepare failed: '.$conn->error); }
    $stmt->bind_param("ssssssssssssssss",
        $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score, $merci_diagnosis, $error_type,
        $faf_grade, $oct_score, $vf_score, $actual_diagnosis, $dosage, $duration_days, $cumulative_dosage, $date_of_continuation
    );
    if (!$stmt->execute()) { die("Failed to insert/update test_eye: ".$stmt->error); }
    _stmt_close($stmt);
}

/* ---------------------------
   Reads used by index.php
---------------------------- */
function getPatientsWithTests($conn) {
    $res = $conn->query("SELECT * FROM patients ORDER BY subject_id ASC");
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC);
}

function getTestsByPatient($conn, $patient_id) {
    $stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test DESC");
    if (!$stmt) return [];
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    _stmt_close($stmt);
    return $rows;
}

function getTestEyes($conn, $test_id) {
    $stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye ASC");
    if (!$stmt) return [];
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    _stmt_close($stmt);
    return $rows;
}
