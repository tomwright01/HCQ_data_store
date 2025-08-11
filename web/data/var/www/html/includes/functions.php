<?php

/**
 * Insert a new test record into the tests table
 * @param mysqli $conn Database connection
 * @param string $testId Test ID
 * @param string $patientId Patient ID
 * @param string $location Test location
 * @param string $testDate Date of test
 */
function insertTest($conn, $testId, $patientId, $location, $testDate) {
    $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, location, date_of_test) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $testId, $patientId, $location, $testDate);
    if (!$stmt->execute()) {
        throw new Exception("Error inserting test: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Insert a new test eye result into the test_eyes table
 * @param mysqli $conn Database connection
 * @param string $testId Test ID
 * @param string $eye Eye type (OD/OS)
 * @param int|null $age Age of patient
 * @param string|null $reportDiagnosis Report diagnosis
 * @param string|null $exclusion Exclusion criteria
 * @param string|null $merciScore MERCI score
 * @param string|null $merciDiagnosis MERCI diagnosis
 * @param string|null $errorType Error type (TN, FP, TP, FN)
 * @param int|null $fafGrade FAF grade
 * @param float|null $octScore OCT score
 * @param float|null $vfScore VF score
 * @param string|null $actualDiagnosis Actual diagnosis
 * @param string|null $medicationName Medication name
 * @param float|null $dosage Dosage amount
 * @param string $dosageUnit Dosage unit (e.g., mg)
 * @param int|null $durationDays Duration in days
 * @param float|null $cumulativeDosage Cumulative dosage
 * @param string|null $dateOfContinuation Date of continuation
 * @param string|null $treatmentNotes Treatment notes
 */
function insertTestEye(
    $conn,
    $testId,
    $eye,
    $age,
    $reportDiagnosis,
    $exclusion,
    $merciScore,
    $merciDiagnosis,
    $errorType,
    $fafGrade,
    $octScore,
    $vfScore,
    $actualDiagnosis,
    $medicationName,
    $dosage,
    $dosageUnit,
    $durationDays,
    $cumulativeDosage,
    $dateOfContinuation,
    $treatmentNotes
) {
    $stmt = $conn->prepare("INSERT INTO test_eyes (
        test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
        faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit,
        duration_days, cumulative_dosage, date_of_continuation, treatment_notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "ssssssssdddsdsdsss",
        $testId, $eye, $age, $reportDiagnosis, $exclusion, $merciScore, $merciDiagnosis,
        $errorType, $fafGrade, $octScore, $vfScore, $actualDiagnosis, $medicationName, $dosage,
        $dosageUnit, $durationDays, $cumulativeDosage, $dateOfContinuation, $treatmentNotes
    );

    if (!$stmt->execute()) {
        throw new Exception("Error inserting test eye: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Generate a unique patient ID
 * @param string $subjectId Subject ID from CSV
 * @return string Generated patient ID
 */
function generatePatientId($subjectId) {
    return 'P' . strtoupper(substr(md5($subjectId), 0, 12));
}

/**
 * Get or create a patient record
 * @param mysqli $conn Database connection
 * @param string $patientId Patient ID
 * @param string $subjectId Subject ID
 * @param string $location Location of the patient
 * @param string $dobFormatted Formatted date of birth
 * @return string Patient ID
 */
function getOrCreatePatient($conn, $patientId, $subjectId, $location, $dobFormatted) {
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE subject_id = ?");
    $stmt->bind_param("s", $subjectId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Patient exists
        $row = $result->fetch_assoc();
        return $row['patient_id'];
    } else {
        // Insert new patient
        $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, location, date_of_birth) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $patientId, $subjectId, $location, $dobFormatted);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting patient: " . $stmt->error);
        }
        return $patientId;
    }
}
?>
