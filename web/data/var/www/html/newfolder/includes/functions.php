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
    return $stmt->get_result()->fetch_assoc(); // Permanent DB storage
}

function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;
    
    // Validate inputs
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD', 'OS'])) {
        return false;
    }
    
    // Verify file type
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $tempFilePath);
    finfo_close($fileInfo);
    
    if (!in_array($mimeType, array_keys(ALLOWED_IMAGE_TYPES))) {
        return false;
    }
    
    // Permanent file storage setup
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true); // Ensure directory exists
    }
    
    // Generate permanent filename
    $extension = ALLOWED_IMAGE_TYPES[$mimeType];
    $filename = sprintf('%s_%s_%s.%s', 
        preg_replace('/[^a-zA-Z0-9_-]/', '', $patient_id),
        $eye,
        date('Ymd', strtotime($test_date)),
        $extension
    );
    
    $targetFile = $targetDir . $filename;
    
    // Permanent file move
    if (!move_uploaded_file($tempFilePath, $targetFile)) {
        return false;
    }
    
    // Permanent database record
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
            // Update existing permanent record
            $test = $result->fetch_assoc();
            $update = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
            $update->bind_param("ss", $filename, $test['test_id']);
            $update->execute();
        } else {
            // Create new permanent record
            $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
            $insert = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, eye, $imageField) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("sssss", $testId, $patient_id, $testDate, $eye, $filename);
            $insert->execute();
        }
        
        $conn->commit(); // Permanent DB write
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        // Cleanup failed upload
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
    return $stmt->get_result()->num_rows > 0; // Checks permanent storage
}

/**
 * Backup function for additional permanence
 */
function backupDatabase() {
    $backupDir = '/var/www/html/backups/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true); // Permanent backup directory
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

/**
 * Helper function to retrieve stored images
 */
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
