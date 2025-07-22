<?php
require_once 'config.php';

/**
 * Call out to the Python anonymizer.
 * Returns true on success (and writes $outputPath), false on failure.
 */
function anonymizePDF(string $inputPath, string $outputPath): bool {
    // adjust this path to wherever you place your script
    $script = 'web/data/var/www/html/newfolder/anonymize_pdf.py';

    // build & run command
    $cmd = escapeshellcmd("python3 {$script} "
           . escapeshellarg($inputPath) . " "
           . escapeshellarg($outputPath));
    exec($cmd, $out, $code);

    return $code === 0 && file_exists($outputPath);
}

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
 * Import an image or PDF, anonymize if PDF, store file permanently, record in tests table.
 *
 * @param string $testType   One of ALLOWED_TEST_TYPES keys
 * @param string $eye        'OD' or 'OS'
 * @param string $patient_id Patient PK
 * @param string $test_date  Date string parseable by strtotime()
 * @param string $tempFilePath Path to the uploaded temp file
 * @return bool success
 */
function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;

    // 1) Validate
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD','OS'])) {
        return false;
    }

    // 2) Verify MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tempFilePath);
    finfo_close($finfo);

    if (!in_array($mime, array_keys(ALLOWED_IMAGE_TYPES))) {
        return false;
    }

    // 3) Prepare permanent directory
    $dir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }

    // 4) Build filename
    $ext      = ALLOWED_IMAGE_TYPES[$mime];
    $datePart = date('Ymd', strtotime($test_date));
    $cleanId  = preg_replace('/[^a-zA-Z0-9_-]/','',$patient_id);
    $filename = "{$cleanId}_{$eye}_{$datePart}.{$ext}";
    $target   = $dir . $filename;

    // 5) If PDF, anonymize first
    if ($mime === 'application/pdf') {
        $tmpAnon = sys_get_temp_dir() . "/anon_{$filename}";
        if (!anonymizePDF($tempFilePath, $tmpAnon)) {
            error_log("PDF anonymization failed for {$tempFilePath}");
            return false;
        }
        // now point to the anon‐ed file
        $tempFilePath = $tmpAnon;
    }

    // 6) Move into permanent storage
    if (!move_uploaded_file($tempFilePath, $target)) {
        return false;
    }

    // 7) Write DB record
    $field     = strtolower($testType) . '_reference_' . strtolower($eye);
    $testDate  = date('Y-m-d', strtotime($test_date));

    $conn->begin_transaction();
    try {
        // see if a test row exists
        $sel = $conn->prepare(
            "SELECT test_id FROM tests
             WHERE patient_id=? AND date_of_test=? AND eye=?"
        );
        $sel->bind_param("sss", $patient_id, $testDate, $eye);
        $sel->execute();
        $res = $sel->get_result();

        if ($res->num_rows) {
            // update
            $row = $res->fetch_assoc();
            $upd = $conn->prepare(
                "UPDATE tests SET {$field}=? WHERE test_id=?"
            );
            $upd->bind_param("ss", $filename, $row['test_id']);
            $upd->execute();
        } else {
            // insert
            $testId = date('YmdHis') . "_{$eye}_" . substr(md5(uniqid()),0,4);
            $ins = $conn->prepare(
                "INSERT INTO tests
                 (test_id, patient_id, date_of_test, eye, {$field})
                 VALUES (?,?,?,?,?)"
            );
            $ins->bind_param(
                "sssss",
                $testId,
                $patient_id,
                $testDate,
                $eye,
                $filename
            );
            $ins->execute();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        if (file_exists($target)) unlink($target);
        error_log("importTestImage error: " . $e->getMessage());
        return false;
    }
}

function checkDuplicateTest($patient_id, $test_date, $eye) {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT 1 FROM tests WHERE patient_id=? AND date_of_test=? AND eye=?"
    );
    $stmt->bind_param("sss", $patient_id, $test_date, $eye);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function backupDatabase() {
    $backupDir = '/var/www/html/backups/';
    if (!file_exists($backupDir)) mkdir($backupDir, 0755, true);

    $file = $backupDir . 'PatientData_' . date("Y-m-d_His") . '.sql';
    $cmd  = sprintf(
        'mysqldump -u%s -p%s -h%s %s > %s',
        escapeshellarg(DB_USERNAME),
        escapeshellarg(DB_PASSWORD),
        escapeshellarg(DB_SERVER),
        escapeshellarg(DB_NAME),
        escapeshellarg($file)
    );
    system($cmd, $rc);
    return $rc === 0 ? $file : false;
}

function getStoredImagePath($filename) {
    if (empty($filename)) return null;
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $full = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($full)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    return null;
}
