<?php
require_once 'config.php';

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

function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;
    
    // Validate inputs
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD', 'OS'])) {
        return false;
    }
    
    // Get original filename (works for both form uploads and bulk imports)
    $originalName = $_FILES['image']['name'] ?? basename($tempFilePath);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    
    // Special handling for MFERG EXP files
    if ($testType === 'MFERG' && $extension === 'exp') {
        $mimeType = 'application/octet-stream'; // Force correct MIME type
    } else {
        // Normal MIME detection for other files
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $tempFilePath);
        finfo_close($fileInfo);
    }
    
    // Validate file type against allowed types
    if (!array_key_exists($mimeType, ALLOWED_IMAGE_TYPES)) {
        error_log("Rejected file type: $mimeType for test $testType");
        return false;
    }
    
    // Permanent file storage setup
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Generate filename
    $filename = sprintf('%s_%s_%s.%s',
        preg_replace('/[^a-zA-Z0-9_-]/', '', $patient_id),
        $eye,
        date('Ymd', strtotime($test_date)),
        $extension // Use original extension
    );
    
    $targetFile = $targetDir . $filename;
    
    // Move/Store the file
    if (is_uploaded_file($tempFilePath)) {
        $moved = move_uploaded_file($tempFilePath, $targetFile);
    } else {
        $moved = rename($tempFilePath, $targetFile);
    }
    
    if (!$moved) {
        error_log("Failed to move file to $targetFile");
        return false;
    }
    
    // Database operations
    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
    $testDate = date('Y-m-d', strtotime($test_date));
    
    $conn->begin_transaction();
    try {
        // Check for existing test
        $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
        $stmt->bind_param("sss", $patient_id, $testDate, $eye);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing record
            $test = $result->fetch_assoc();
            $update = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
            $update->bind_param("ss", $filename, $test['test_id']);
            $update->execute();
        } else {
            // Create new record
            $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
            $insert = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, eye, $imageField) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("sssss", $testId, $patient_id, $testDate, $eye, $filename);
            $insert->execute();
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        // Cleanup failed upload
        if (file_exists($targetFile)) {
            unlink($targetFile);
        }
        error_log("Database error during image import: " . $e->getMessage());
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

function getStoredImagePath($filename) {
    if (empty($filename)) return null;
    
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $fullPath = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($fullPath)) {
            return [
                'url' => IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename),
                'force_download' => ($extension === 'exp') // Special handling for EXP files
            ];
        }
    }
    return null;
}

/**
 * Helper function to get test type directory
 */
function getTestTypeDirectory($testType) {
    return IMAGE_BASE_DIR . (ALLOWED_TEST_TYPES[$testType] ?? '');
}
