<?php
require_once 'config.php';

/**
 * Fetch a patient by their ID.
 */
function getPatientById(string $patient_id): ?array {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc() ?: null;
}

/**
 * Insert or update a patient.
 */
function upsertPatient(
    string $patient_id,
    string $subject_id,
    string $date_of_birth,
    string $location = 'KH',
    string $actual_diagnosis = 'other'
): void {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO patients
          (patient_id, subject_id, date_of_birth, location, actual_diagnosis)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          subject_id        = VALUES(subject_id),
          date_of_birth     = VALUES(date_of_birth),
          location          = VALUES(location),
          actual_diagnosis  = VALUES(actual_diagnosis)
    ");
    $stmt->bind_param(
        "sssss",
        $patient_id,
        $subject_id,
        $date_of_birth,
        $location,
        $actual_diagnosis
    );
    if (!$stmt->execute()) {
        throw new Exception("Patient upsert failed: " . $stmt->error);
    }
}

/**
 * Insert or update a test record.
 * Expects all keys present in $testData exactly matching column names.
 */
function insertTest(array $testData): void {
    global $conn;
    $stmt = $conn->prepare("
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
            eye                  = VALUES(eye),
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
    ");

    // ensure merci_score is null or 'unable' or numeric string
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
}

/**
 * Move an uploaded test image into permanent storage
 * and update the tests table accordingly.
 */
function importTestImage(
    string $testType,
    string $eye,
    string $patient_id,
    string $test_date,
    string $tmpPath
): bool {
    global $conn;

    if (!isset(ALLOWED_TEST_TYPES[$testType]) || !in_array($eye, ['OD','OS'])) {
        return false;
    }
    $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
    if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
        return false;
    }

    $ext = ALLOWED_IMAGE_TYPES[$mime];
    $dir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $filename = sprintf(
        '%s_%s_%s.%s',
        preg_replace('/[^A-Za-z0-9_-]/','', $patient_id),
        $eye,
        date('Ymd', strtotime($test_date)),
        $ext
    );

    if (!move_uploaded_file($tmpPath, $dir . $filename)) {
        return false;
    }

    $field = strtolower($testType) . "_reference_" . strtolower($eye);

    // update or insert test record
    $stmt = $conn->prepare("
        UPDATE tests
        SET {$field} = ?
        WHERE patient_id = ? AND date_of_test = ? AND eye = ?
    ");
    $date = date('Y-m-d', strtotime($test_date));
    $stmt->bind_param("ssss", $filename, $patient_id, $date, $eye);
    if (!$stmt->execute()) {
        // if no existing row, insert minimal record
        $stmt2 = $conn->prepare("
            INSERT INTO tests (test_id, patient_id, date_of_test, eye, {$field})
            VALUES (?, ?, ?, ?, ?)
        ");
        $newId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()),0,4);
        $stmt2->bind_param("sssss", $newId, $patient_id, $date, $eye, $filename);
        $stmt2->execute();
    }

    return true;
}

/**
 * Check whether a test already exists for this patient/date/eye.
 */
function checkDuplicateTest(string $patient_id, string $date, string $eye): bool {
    global $conn;
    $stmt = $conn->prepare("
        SELECT 1 FROM tests
         WHERE patient_id=? AND date_of_test=? AND eye=?
    ");
    $stmt->bind_param("sss", $patient_id, $date, $eye);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

/**
 * Backup the entire PatientData DB to a .sql file.
 */
function backupDatabase(): string|false {
    $dir  = '/var/www/html/backups/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $file = $dir . 'PatientData_' . date('Ymd_His') . '.sql';
    $cmd  = sprintf(
        'mysqldump -u%s -p%s -h%s %s > %s',
        DB_USERNAME, DB_PASSWORD, DB_SERVER, DB_NAME, $file
    );
    system($cmd, $ret);
    return $ret === 0 ? $file : false;
}

/**
 * Given a stored filename, return its public URL or null.
 */
function getStoredImagePath(string $filename): ?string {
    foreach(ALLOWED_TEST_TYPES as $type=>$d) {
        $path = IMAGE_BASE_DIR . $d . '/' . $filename;
        if (file_exists($path)) {
            return IMAGE_BASE_URL . $d . '/' . rawurlencode($filename);
        }
    }
    return null;
}
