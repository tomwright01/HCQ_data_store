<?php
require_once  'config.php'; // ensure config loads

/**
 * PERMANENT STORAGE FUNCTIONS
 * All data operations are designed for permanent retention
 */

function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Upsert patient including actual_diagnosis
 */
function upsertPatient($patient_id, $subject_id, $date_of_birth, $location = 'KH', $actual_diagnosis = 'other') {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO patients (patient_id, subject_id, date_of_birth, location, actual_diagnosis)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            subject_id = VALUES(subject_id),
            date_of_birth = VALUES(date_of_birth),
            location = VALUES(location),
            actual_diagnosis = VALUES(actual_diagnosis)
    ");
    $stmt->bind_param("sssss", $patient_id, $subject_id, $date_of_birth, $location, $actual_diagnosis);
    if (!$stmt->execute()) {
        throw new Exception("Patient upsert failed: " . $stmt->error);
    }
    return $stmt->affected_rows;
}

/**
 * Get existing patient or create if missing (without overwriting actual_diagnosis unless provided)
 */
function getOrCreatePatient($conn, $patientId, $subjectId, $date_of_birth, $location = 'KH', &$results) {
    // Check existence
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return $patientId;
    }
    // Insert new with default actual_diagnosis 'other'
    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location, actual_diagnosis) VALUES (?, ?, ?, ?, 'other')");
    $stmt->bind_param("ssss", $patientId, $subjectId, $date_of_birth, $location);
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    $results['patients']++;
    return $patientId;
}

/**
 * Insert or update test with full schema support
 */
function insertTest($conn, $testData) {
    $stmt = $conn->prepare("
        INSERT INTO tests (
            test_id, patient_id, location, date_of_test, age, eye,
            report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, medication_name,
            dosage, dosage_unit, duration_days, cumulative_dosage,
            date_of_continuation, treatment_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
    ");

    $merciScoreForDb = ($testData['merci_score'] === 'unable') ? 'unable' :
                      (is_null($testData['merci_score']) ? NULL : $testData['merci_score']);

    // Defaults / optional
    $actualDiagnosis = $testData['actual_diagnosis'] ?? null;
    $medicationName = $testData['medication_name'] ?? null;
    $dosage = $testData['dosage'] ?? null;
    $dosageUnit = $testData['dosage_unit'] ?? 'mg';
    $durationDays = $testData['duration_days'] ?? null;
    $cumulativeDosage = $testData['cumulative_dosage'] ?? null;
    $dateOfContinuation = $testData['date_of_continuation'] ?? null;
    $treatmentNotes = $testData['treatment_notes'] ?? null;

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
        $merciScoreForDb,
        $testData['merci_diagnosis'],
        $testData['error_type'],
        $testData['faf_grade'],
        $testData['oct_score'],
        $testData['vf_score'],
        $actualDiagnosis,
        $medicationName,
        $dosage,
        $dosageUnit,
        $durationDays,
        $cumulativeDosage,
        $dateOfContinuation,
        $treatmentNotes
    );

    if (!$stmt->execute()) {
        throw new Exception("Test insert failed: " . $stmt->error);
    }
}

/**
 * Import image and associate with test (existing logic)
 */
function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;

    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD', 'OS'])) {
        return false;
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $tempFilePath);
    finfo_close($fileInfo);

    if (!in_array($mimeType, array_keys(ALLOWED_IMAGE_TYPES))) {
        return false;
    }

    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $extension = ALLOWED_IMAGE_TYPES[$mimeType];
    $filename = sprintf('%s_%s_%s.%s',
        preg_replace('/[^a-zA-Z0-9_-]/', '', $patient_id),
        $eye,
        date('Ymd', strtotime($test_date)),
        $extension
    );

    $targetFile = $targetDir . $filename;

    if (!move_uploaded_file($tempFilePath, $targetFile)) {
        return false;
    }

    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
    $testDate = date('Y-m-d', strtotime($test_date));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
        $stmt->bind_param("sss", $patient_id, $testDate, $eye);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $test = $result->fetch_assoc();
            $update = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
            $update->bind_param("ss", $filename, $test['test_id']);
            $update->execute();
        } else {
            $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
            $insert = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, eye, $imageField) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("sssss", $testId, $patient_id, $testDate, $eye, $filename);
            $insert->execute();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        if (file_exists($targetFile)) {
            unlink($targetFile);
        }
        error_log("Image import error: " . $e->getMessage());
        return false;
    }
}

function checkDuplicateTest($patient_id, $test_date, $eye) {
    global $conn;
    $stmt = $conn->prepare("SELECT 1 FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
    $stmt->bind_param("sss", $patient_id, $test_date, $eye);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Backup function
 */
function backupDatabase() {
    $backupDir = '/var/www/html/backups/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $backupFile = $backupDir . 'PatientData_' . date("Y-m-d_His") . '.sql';
    $command = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s',
        escapeshellarg(DB_USERNAME),
        escapeshellarg(DB_PASSWORD),
        escapeshellarg(DB_SERVER),
        escapeshellarg(DB_NAME),
        escapeshellarg($backupFile)
    );

    system($command, $output);
    return $output === 0 ? $backupFile : false;
}

function getStoredImagePath($filename) {
    if (empty($filename)) return null;
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $fullPath = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($fullPath)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    return null;
}
?>
