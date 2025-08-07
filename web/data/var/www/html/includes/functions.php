<?php
// includes/functions.php
require_once __DIR__ . '/config.php';

/**
 * Look up an existing patient or create a new one.
 * Returns patient_id (string).
 */
function getOrCreatePatient(
    mysqli $conn,
    string $patientId,
    string $subjectId,
    string $dateOfBirth,
    string $location = 'KH',
    ?array &$results = null
): string {
    // Check if patient_id exists
    $sel = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $sel->bind_param("s", $patientId);
    $sel->execute();
    $res = $sel->get_result();
    if ($res && $res->num_rows > 0) {
        $sel->close();
        return $patientId;
    }
    $sel->close();

    // Insert new patient
    $ins = $conn->prepare(
        "INSERT INTO patients (patient_id, subject_id, date_of_birth, location) VALUES (?, ?, ?, ?)"
    );
    $ins->bind_param("ssss", $patientId, $subjectId, $dateOfBirth, $location);
    if (!$ins->execute()) {
        throw new Exception("Patient insert failed: " . $ins->error);
    }
    $ins->close();

    if (isset($results) && is_array($results)) {
        $results['patients'] = ($results['patients'] ?? 0) + 1;
    }

    return $patientId;
}

/**
 * Insert or update a test record.
 * Uses ON DUPLICATE KEY UPDATE to update existing rows.
 * Expects all fields in $testData array.
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

    // Normalize nullable fields: convert empty strings or 'NULL' to null
    $nullableFields = [
        'age', 'merci_score', 'faf_grade', 'oct_score', 'vf_score',
        'medication_name', 'dosage', 'dosage_unit', 'duration_days',
        'cumulative_dosage', 'date_of_continuation', 'treatment_notes'
    ];
    foreach ($nullableFields as $field) {
        if (!isset($testData[$field]) || $testData[$field] === '' || strtoupper($testData[$field]) === 'NULL') {
            $testData[$field] = null;
        }
    }

    // Handle merci_score: treat all as string or null ('unable' stays as string)
    if ($testData['merci_score'] !== null && is_int($testData['merci_score'])) {
        $testData['merci_score'] = (string)$testData['merci_score'];
    }

    /*
    Types string breakdown:
    s - string
    i - integer
    d - double (float)
    
    Parameters:
    1: test_id (s)
    2: patient_id (s)
    3: location (s)
    4: date_of_test (s)
    5: age (s|null)
    6: eye (s)
    7: report_diagnosis (s)
    8: exclusion (s)
    9: merci_score (s|null)
    10: merci_diagnosis (s)
    11: error_type (s)
    12: faf_grade (i|null)
    13: oct_score (d|null)
    14: vf_score (d|null)
    15: actual_diagnosis (s)
    16: medication_name (s|null)
    17: dosage (d|null)
    18: dosage_unit (s|null)
    19: duration_days (i|null)
    20: cumulative_dosage (d|null)
    21: date_of_continuation (s|null)
    22: treatment_notes (s|null)
    */

    $types = "ssssssssssssddsssssdss";

    $stmt->bind_param(
        $types,
        $testData['test_id'],
        $testData['patient_id'],
        $testData['location'],
        $testData['date_of_test'],
        $testData['age'],
        $testData['eye'],
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

    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
    }

    $stmt->close();
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

    // Check if test already exists
    $check = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
    $check->bind_param("sss", $patient_id, $date, $eye);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing test record
        $row = $result->fetch_assoc();
        $test_id = $row['test_id'];
    
        $upd = $conn->prepare("UPDATE tests SET {$field} = ? WHERE test_id = ?");
        $upd->bind_param("ss", $filename, $test_id);
        $upd->execute();
        $upd->close();
    } else {
        // Insert new test record
        $test_id = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
        
        $ins = $conn->prepare(
            "INSERT INTO tests (test_id, patient_id, date_of_test, eye, {$field}) VALUES (?, ?, ?, ?, ?)"
        );
        $ins->bind_param("sssss", $test_id, $patient_id, $date, $eye, $filename);
        $ins->execute();
        $ins->close();
    }

    $check->close();

    return true;
}

/**
 * Check for an existing test (for duplicates).
 * Returns true if test exists.
 */
function checkDuplicateTest(string $patient_id, string $date, string $eye): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT 1 FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
    $stmt->bind_param("sss", $patient_id, $date, $eye);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Create a mysqldump backup.
 * Returns filepath or false on failure.
 */
function backupDatabase(): string|false {
    $dir  = '/var/www/html/backups/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . 'PatientData_' . date('Ymd_His') . '.sql';
    $cmd  = sprintf('mysqldump -u%s -p%s -h%s %s > %s', DB_USERNAME, DB_PASSWORD, DB_SERVER, DB_NAME, escapeshellarg($file));
    system($cmd, $ret);
    return $ret === 0 ? $file : false;
}

/**
 * Get public URL for a stored image by filename.
 * Returns URL string or null if not found.
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
