<?php
require_once 'config.php';

/**
 * Generate a unique patient_id from subject_id
 */
function generatePatientId($subject_id) {
    return 'P_' . substr(md5($subject_id), 0, 20);
}

/**
 * Get existing or create a patient record
 */
function getOrCreatePatient($conn, $patient_id, $subject_id, $location, $dob) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows > 0) {
        $stmt->close();
        return $patient_id;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $patient_id, $subject_id, $location, $dob);
    if (!$stmt->execute()) {
        die("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patient_id;
}

/**
 * Insert or update a test record
 */
function insertTest($conn, $test_id, $patient_id, $location, $date_of_test) {
    // Check if the test already exists
    $stmt = $conn->prepare("SELECT * FROM tests WHERE test_id = ?");
    $stmt->bind_param("s", $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        // Insert test if it doesn't already exist
        $stmt = $conn->prepare("
            INSERT INTO tests (test_id, patient_id, location, date_of_test)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
        if (!$stmt->execute()) {
            die("Failed to insert test: " . $stmt->error);
        }
    }
    $stmt->close();
}

/**
 * Insert or update a test_eye record
 */
function insertTestEye(
    $conn, $test_id, $eye, $age, $report_diagnosis, $exclusion, $merci_score,
    $merci_diagnosis, $error_type, $faf_grade, $oct_score, $vf_score,
    $actual_diagnosis, $dosage, 
    $duration_days, $cumulative_dosage, $date_of_continuation
) {
    // Prepare the SQL query
    $stmt = $conn->prepare("
        INSERT INTO test_eyes 
        (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
        faf_grade, oct_score, vf_score, actual_diagnosis, dosage,
        duration_days, cumulative_dosage, date_of_continuation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            dosage = VALUES(dosage),
            duration_days = VALUES(duration_days),
            cumulative_dosage = VALUES(cumulative_dosage),
            date_of_continuation = VALUES(date_of_continuation),
            updated_at = CURRENT_TIMESTAMP
    ");

    // Bind the parameters (data types are matched with schema)
    $stmt->bind_param("iiissiiiiiiddsds", 
        $test_id,   // i: test_id (integer)
        $eye,       // s: eye (string)
        $age,       // i: age (integer)
        $report_diagnosis, // s: report_diagnosis (string)
        $exclusion, // s: exclusion (string)
        $merci_score, // i: merci_score (integer)
        $merci_diagnosis, // s: merci_diagnosis (string)
        $error_type,  // s: error_type (string)
        $faf_grade,  // i: faf_grade (integer)
        $oct_score,  // d: oct_score (decimal)
        $vf_score,   // i: vf_score (integer)
        $actual_diagnosis, // s: actual_diagnosis (string)
        $dosage,     // d: dosage (decimal)
        $duration_days, // i: duration_days (integer)
        $cumulative_dosage, // d: cumulative_dosage (decimal)
        $date_of_continuation // s: date_of_continuation (string)
    );
    
    // Execute the statement
    if (!$stmt->execute()) {
        die("Failed to insert/update test_eye: " . $stmt->error);
    }

    $stmt->close();
}
?>




