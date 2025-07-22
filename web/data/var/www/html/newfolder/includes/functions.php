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
            
            // Log the update
            logDatabaseChange('tests', $row['test_id'], 'UPDATE', null, json_encode([$field => $filename]));
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
            
            // Log the insert
            logDatabaseChange('tests', $testId, 'INSERT', null, json_encode([
                'test_id' => $testId,
                'patient_id' => $patient_id,
                'date_of_test' => $testDate,
                'eye' => $eye,
                $field => $filename
            ]));
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

/**
 * Perform a complete database backup with compression
 */
function backupDatabase() {
    $backupDir = '/var/www/html/data/backups/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

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
    
    if ($rc === 0) {
        // Compress the backup
        $compressedFile = $file . '.gz';
        $compressCmd = sprintf('gzip -c %s > %s', escapeshellarg($file), escapeshellarg($compressedFile));
        system($compressCmd);
        
        // Remove uncompressed file
        unlink($file);
        
        // Upload to cloud storage if configured
        if (defined('CLOUD_BACKUP_ENABLED') && CLOUD_BACKUP_ENABLED) {
            uploadBackupToCloud($compressedFile);
        }
        
        // Log the backup
        logDatabaseChange('system', 'backup', 'CREATE', null, json_encode([
            'backup_file' => basename($compressedFile),
            'size' => filesize($compressedFile)
        ]));
        
        return $compressedFile;
    }
    
    return false;
}

/**
 * Upload backup to cloud storage (AWS S3 example)
 */
function uploadBackupToCloud($backupFile) {
    if (!file_exists($backupFile)) {
        error_log("Backup file not found: $backupFile");
        return false;
    }

    try {
        // Requires AWS SDK: composer require aws/aws-sdk-php
        $s3 = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => defined('AWS_REGION') ? AWS_REGION : 'us-east-1',
            'credentials' => [
                'key'    => defined('AWS_ACCESS_KEY') ? AWS_ACCESS_KEY : '',
                'secret' => defined('AWS_SECRET_KEY') ? AWS_SECRET_KEY : '',
            ]
        ]);
        
        $result = $s3->putObject([
            'Bucket' => defined('AWS_BACKUP_BUCKET') ? AWS_BACKUP_BUCKET : 'medical-backups',
            'Key'    => 'database/' . basename($backupFile),
            'Body'   => fopen($backupFile, 'r'),
            'StorageClass' => 'STANDARD_IA' // Lower cost infrequent access
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Cloud backup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log all database changes for audit purposes
 */
function logDatabaseChange($table, $record_id, $action, $old_values = null, $new_values = null) {
    global $conn;
    
    $changed_by = $_SESSION['user_id'] ?? 'system';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt = $conn->prepare(
        "INSERT INTO audit_log 
        (table_name, record_id, action, old_values, new_values, changed_by, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    
    $stmt->bind_param(
        "sssssss",
        $table,
        $record_id,
        $action,
        $old_values,
        $new_values,
        $changed_by,
        $ip_address
    );
    
    return $stmt->execute();
}

/**
 * Restore database from backup file
 */
function restoreDatabase($backupFile) {
    if (!file_exists($backupFile)) {
        throw new Exception("Backup file not found");
    }
    
    // If compressed, decompress first
    if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz') {
        $uncompressedFile = str_replace('.gz', '', $backupFile);
        $cmd = sprintf('gunzip -c %s > %s', escapeshellarg($backupFile), escapeshellarg($uncompressedFile));
        system($cmd, $rc);
        
        if ($rc !== 0) {
            throw new Exception("Failed to decompress backup file");
        }
        
        $backupFile = $uncompressedFile;
    }
    
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD);
    
    // Drop and recreate database
    $conn->query("DROP DATABASE IF EXISTS " . DB_NAME);
    $conn->query("CREATE DATABASE " . DB_NAME);
    $conn->select_db(DB_NAME);
    
    // Restore from backup
    $command = sprintf(
        'mysql -u%s -p%s -h%s %s < %s',
        escapeshellarg(DB_USERNAME),
        escapeshellarg(DB_PASSWORD),
        escapeshellarg(DB_SERVER),
        escapeshellarg(DB_NAME),
        escapeshellarg($backupFile)
    );
    
    system($command, $returnCode);
    
    // Clean up uncompressed file if we created it
    if (isset($uncompressedFile) {
        unlink($uncompressedFile);
    }
    
    return $returnCode === 0;
}

/**
 * Validate patient data before insertion
 */
function validatePatientData($data) {
    $errors = [];
    
    // Validate subject ID format
    if (!preg_match('/^[A-Z0-9]{8}$/', $data['subject_id'])) {
        $errors[] = "Subject ID must be 8 alphanumeric characters";
    }
    
    // Validate date of birth is reasonable (not in future, not before 1900)
    $dob = new DateTime($data['date_of_birth']);
    $now = new DateTime();
    $minDate = new DateTime('1900-01-01');
    
    if ($dob > $now) {
        $errors[] = "Date of birth cannot be in the future";
    }
    
    if ($dob < $minDate) {
        $errors[] = "Date of birth is unrealistically old";
    }
    
    return $errors;
}

/**
 * Get stored image path with fallback
 */
function getStoredImagePath($filename) {
    if (empty($filename)) return null;
    
    foreach (ALLOWED_TEST_TYPES as $type => $dir) {
        $full = IMAGE_BASE_DIR . $dir . '/' . $filename;
        if (file_exists($full)) {
            return IMAGE_BASE_URL . $dir . '/' . rawurlencode($filename);
        }
    }
    
    // Check backup locations if not found in primary
    $backupDirs = [
        '/var/backups/medical_images/',
        '/mnt/secondary_storage/patient_images/'
    ];
    
    foreach ($backupDirs as $backupDir) {
        foreach (ALLOWED_TEST_TYPES as $type => $dir) {
            $full = $backupDir . $dir . '/' . $filename;
            if (file_exists($full)) {
                return str_replace('/var/www/html', '', $backupDir) . $dir . '/' . rawurlencode($filename);
            }
        }
    }
    
    return null;
}

/**
 * Initialize database with audit log table if not exists
 */
function initializeDatabase() {
    global $conn;
    
    $conn->query("
        CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(50) NOT NULL,
            record_id VARCHAR(50) NOT NULL,
            action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
            old_values TEXT,
            new_values TEXT,
            changed_by VARCHAR(100),
            ip_address VARCHAR(45),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (table_name, record_id),
            INDEX (changed_at)
        ) ENGINE=InnoDB
    ");
}

// Initialize database when this file is loaded
initializeDatabase();

// Set up automatic backup on shutdown (runs when script finishes)
register_shutdown_function(function() {
    // Run backup daily at 2 AM
    if (date('H') == '02' && rand(1, 10) == 1) { // 10% chance to run when testing
        backupDatabase();
    }
});
