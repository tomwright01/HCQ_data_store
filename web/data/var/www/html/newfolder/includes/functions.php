<?php
require_once 'config.php';

function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

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
?>
