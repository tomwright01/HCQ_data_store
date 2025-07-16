<?php
require_once 'config.php';

/**
 * Get patient data by ID
 */
function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Import an image and update database
 */
function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;
    
    // Validate inputs
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD', 'OS'])) {
        return false;
    }
    
    // Validate file
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $tempFilePath);
    finfo_close($fileInfo);
    
    $allowedTypes = $testType === 'VF' ? ['application/pdf'] : ['image/png'];
    if (!in_array($mimeType, array_keys(ALLOWED_IMAGE_TYPES))) {
        return false;
    }
    
    if (filesize($tempFilePath) > MAX_FILE_SIZE) {
        return false;
    }
    
    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Generate filename
    $extension = ALLOWED_IMAGE_TYPES[$mimeType];
    $filename = sprintf('%s_%s_%s.%s', 
        $patient_id, 
        $eye, 
        date('Ymd', strtotime($test_date)),
        $extension
    );
    
    $targetFile = $targetDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($tempFilePath, $targetFile)) {
        return false;
    }
    
    // Update database
    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
    $testDate = date('Y-m-d', strtotime($test_date));
    
    // Check for existing test
    $stmt = $conn->prepare("SELECT test_id FROM tests 
                          WHERE patient_id = ? 
                          AND date_of_test = ? 
                          AND eye = ?");
    $stmt->bind_param("sss", $patient_id, $testDate, $eye);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing test
        $test = $result->fetch_assoc();
        $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $filename, $test['test_id']);
    } else {
        // Create new test
        $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid()), 0, 4);
        $stmt = $conn->prepare("INSERT INTO tests 
                              (test_id, patient_id, date_of_test, eye, $imageField) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $testId, $patient_id, $testDate, $eye, $filename);
    }
    
    return $stmt->execute();
}

/**
 * Check for duplicate tests
 */
function checkDuplicateTest($patient_id, $test_date, $eye) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT 1 FROM tests 
                          WHERE patient_id = ? 
                          AND date_of_test = ? 
                          AND eye = ?");
    $stmt->bind_param("sss", $patient_id, $test_date, $eye);
    $stmt->execute();
    
    return $stmt->get_result()->num_rows > 0;
}
?>
