<?php
require_once 'config.php';

/**
 * Get patient data by patient_id
 * @param string $patient_id
 * @return array|null Patient data or null if not found
 */
function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get test data with image URLs
 * @param string $test_id
 * @return array Test data with image URLs
 */
function getTestWithImages($test_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM tests WHERE test_id = ?");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $test = $stmt->get_result()->fetch_assoc();
    
    if ($test) {
        // Add image URLs for all test types
        foreach (ALLOWED_TEST_TYPES as $type => $dir) {
            $test[strtolower($type).'_od_url'] = getDynamicImagePath($test[strtolower($type).'_reference_od']);
            $test[strtolower($type).'_os_url'] = getDynamicImagePath($test[strtolower($type).'_reference_os']);
        }
    }
    
    return $test;
}

/**
 * Import an image and update database
 * @param string $testType One of ALLOWED_TEST_TYPES
 * @param string $eye 'OD' or 'OS'
 * @param string $patient_id
 * @param string $test_date (YYYY-MM-DD)
 * @param string $tempFilePath Temporary upload path
 * @return bool True on success
 */
function importTestImage($testType, $eye, $patient_id, $test_date, $tempFilePath) {
    global $conn;
    
    // Validate inputs
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES) || !in_array($eye, ['OD', 'OS'])) {
        return false;
    }
    
    $targetDir = getTestTypeDirectory($testType);
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Generate filename: patientid_eye_YYYYMMDD.png
    $filename = sprintf('%s_%s_%s.png', 
        $patient_id, 
        $eye, 
        date('Ymd', strtotime($test_date))
    );
    
    $targetFile = $targetDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($tempFilePath, $targetFile)) {
        return false;
    }
    
    // Update database
    $fieldName = strtolower($testType) . '_reference_' . strtolower($eye);
    $testDate = date('Y-m-d', strtotime($test_date));
    
    // Check if test exists
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
    $stmt->bind_param("ss", $patient_id, $testDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing test
        $test = $result->fetch_assoc();
        $stmt = $conn->prepare("UPDATE tests SET $fieldName = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $filename, $test['test_id']);
    } else {
        // Create new test
        $testId = date('Ymd', strtotime($testDate)) . '_' . $patient_id . '_' . substr(md5(uniqid()), 0, 4);
        $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test, $fieldName) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $testId, $patient_id, $testDate, $filename);
    }
    
    return $stmt->execute();
}
