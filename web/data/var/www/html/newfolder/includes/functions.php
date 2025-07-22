<?php
require_once 'config.php';

/**
 * Get patient data by ID with enhanced error handling
 */
function getPatientById($patient_id) {
    global $conn;
    
    try {
        if (!is_string($patient_id) || empty($patient_id)) {
            throw new InvalidArgumentException("Invalid patient ID");
        }

        $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
        if (!$stmt) {
            throw new RuntimeException("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $patient_id);
        
        if (!$stmt->execute()) {
            throw new RuntimeException("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $patientData = $result->fetch_assoc();
        
        if (!$patientData) {
            error_log("Patient not found: " . $patient_id);
            return null;
        }
        
        return $patientData;
    } catch (Exception $e) {
        error_log("getPatientById error: " . $e->getMessage());
        return false;
    }
}

/**
 * Import test image with improved validation and transaction handling
 */
function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;
    
    try {
        // Validate inputs
        if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
            throw new InvalidArgumentException("Invalid test type: " . $testType);
        }
        
        if (!in_array($eye, ['OD', 'OS'])) {
            throw new InvalidArgumentException("Invalid eye value: " . $eye);
        }
        
        if (!is_uploaded_file($tempFilePath)) {
            throw new RuntimeException("Invalid file upload");
        }
        
        // Validate file type and size
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $tempFilePath);
        finfo_close($fileInfo);
        
        if (!array_key_exists($mimeType, ALLOWED_IMAGE_TYPES)) {
            throw new RuntimeException("Invalid file type: " . $mimeType);
        }
        
        if (filesize($tempFilePath) > MAX_FILE_SIZE) {
            throw new RuntimeException("File size exceeds maximum limit");
        }
        
        // Prepare target directory
        $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
        if (!file_exists($targetDir)) {
            if (!mkdir($targetDir, 0777, true)) {
                throw new RuntimeException("Failed to create directory: " . $targetDir);
            }
        }
        
        // Generate secure filename
        $extension = ALLOWED_IMAGE_TYPES[$mimeType];
        $filename = sprintf('%s_%s_%s.%s', 
            preg_replace('/[^a-zA-Z0-9_-]/', '', $patient_id),
            $eye,
            date('Ymd', strtotime($test_date)),
            $extension
        );
        
        $targetFile = $targetDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($tempFilePath, $targetFile)) {
            throw new RuntimeException("Failed to move uploaded file");
        }
        
        // Set up database fields
        $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
        $testDate = date('Y-m-d', strtotime($test_date));
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Check for existing test
            $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
            $stmt->bind_param("sss", $patient_id, $testDate, $eye);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing test
                $test = $result->fetch_assoc();
                $update = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
                $update->bind_param("ss", $filename, $test['test_id']);
                
                if (!$update->execute()) {
                    throw new RuntimeException("Update failed: " . $update->error);
                }
            } else {
                // Create new test
                $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
                $insert = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, eye, $imageField) VALUES (?, ?, ?, ?, ?)");
                $insert->bind_param("sssss", $testId, $patient_id, $testDate, $eye, $filename);
                
                if (!$insert->execute()) {
                    throw new RuntimeException("Insert failed: " . $insert->error);
                }
            }
            
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            // Clean up the file if database operation failed
            if (file_exists($targetFile)) {
                unlink($targetFile);
            }
            throw $e; // Re-throw for outer catch
        }
    } catch (Exception $e) {
        error_log("importTestImage error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for duplicate tests with improved validation
 */
function checkDuplicateTest($patient_id, $test_date, $eye) {
    global $conn;
    
    try {
        if (empty($patient_id) || empty($test_date) || !in_array($eye, ['OD', 'OS'])) {
            throw new InvalidArgumentException("Invalid parameters for duplicate check");
        }
        
        $stmt = $conn->prepare("SELECT 1 FROM tests WHERE patient_id = ? AND date_of_test = ? AND eye = ?");
        if (!$stmt) {
            throw new RuntimeException("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("sss", $patient_id, $test_date, $eye);
        
        if (!$stmt->execute()) {
            throw new RuntimeException("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    } catch (Exception $e) {
        error_log("checkDuplicateTest error: " . $e->getMessage());
        return false;
    }
}

/**
 * Backup database with improved error handling and verification
 */
function backupDatabase() {
    try {
        $backupDir = '/var/www/html/backups/';
        
        // Create backup directory if it doesn't exist
        if (!file_exists($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new RuntimeException("Failed to create backup directory");
            }
        }
        
        // Generate backup filename with timestamp
        $backupFile = $backupDir . 'PatientData_' . date("Y-m-d_His") . '.sql';
        
        // Build mysqldump command with escaped parameters
        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s %s > %s',
            escapeshellarg(DB_USERNAME),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_SERVER),
            escapeshellarg(DB_NAME),
            escapeshellarg($backupFile)
        );
        
        // Execute backup command
        system($command, $output);
        
        // Verify backup was created
        if ($output !== 0 || !file_exists($backupFile)) {
            throw new RuntimeException("Backup creation failed with code: " . $output);
        }
        
        // Verify backup file is not empty
        if (filesize($backupFile) === 0) {
            unlink($backupFile);
            throw new RuntimeException("Backup file is empty");
        }
        
        return $backupFile;
    } catch (Exception $e) {
        error_log("backupDatabase error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get dynamic image path with improved security
 */
function getDynamicImagePath($filename) {
    if (empty($filename)) {
        return null;
    }
    
    // Validate filename doesn't contain directory traversal
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
        error_log("Potential directory traversal attempt: " . $filename);
        return null;
    }
    
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $fullPath = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($fullPath)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    
    return null;
}
