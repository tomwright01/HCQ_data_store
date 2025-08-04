<?php
// includes/functions.php
require_once __DIR__ . '/config.php';

/**
 * Look up an existing patient or create a new one.
 */
function getOrCreatePatient(
    mysqli $conn,
    string $patientId,
    string $subjectId,
    string $dateOfBirth,
    string $location = 'KH',
    ?array &$results = null
): string {
    $sel = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $sel->bind_param("s", $patientId);
    $sel->execute();
    if ($sel->get_result()->num_rows > 0) {
        return $patientId;
    }

    $ins = $conn->prepare(
        "INSERT INTO patients (patient_id, subject_id, date_of_birth, location) VALUES (?, ?, ?, ?)"
    );
    $ins->bind_param("ssss", $patientId, $subjectId, $dateOfBirth, $location);
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
}

/**
 * Move and register an uploaded test image, then update the tests record.
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
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
        return false;
    }

    $ext = ALLOWED_IMAGE_TYPES[$mime];
    $dir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

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
    $date  = date('Y-m-d', strtotime($test_date));

    $upd = $conn->prepare("UPDATE tests SET {$field} = ? WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
    $upd->bind_param("ssss", $filename, $patient_id, $date, $eye);
    $upd->execute();

    if ($upd->affected_rows < 1) {
        $ins = $conn->prepare(
            "INSERT INTO tests (test_id, patient_id, date_of_test, eye, {$field}) VALUES (?, ?, ?, ?, ?)"
        );
        $newId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()),0,4);
        $ins->bind_param("sssss", $newId, $patient_id, $date, $eye, $filename);
        $ins->execute();
    }

    return true;
}

/**
 * Check for an existing test
 */
function checkDuplicateTest(string $patient_id, string $date, string $eye): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT 1 FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
    $stmt->bind_param("sss", $patient_id, $date, $eye);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

/**
 * Create a mysqldump backup
 */
function backupDatabase(): string|false {
    $dir  = '/var/www/html/backups/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . 'PatientData_' . date('Ymd_His') . '.sql';
    $cmd  = sprintf('mysqldump -u%s -p%s -h%s %s > %s', DB_USERNAME, DB_PASSWORD, DB_SERVER, DB_NAME, $file);
    system($cmd, $ret);
    return $ret === 0 ? $file : false;
}

/**
 * Get public URL for a stored image
 */
function getStoredImagePath(string $filename): ?string {
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $path = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($path)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    return null;
}
