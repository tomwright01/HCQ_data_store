<?php
require_once 'config.php'
// expects $conn = new mysqli(...)

function generatePatientId(string $subjectId): string {
    // Compact, deterministic ID within 25 chars: P + 12-char hash of subject
    $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $subjectId));
    return 'P' . substr(hash('crc32b', $base), 0, 12);
}

function getOrCreatePatient(mysqli $conn, string $patientId, string $subjectId, string $location, string $dobYmd): string {
    // Try by subject_id first (so repeated imports reuse same patient)
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE subject_id = ? LIMIT 1");
    $stmt->bind_param("s", $subjectId);
    $stmt->execute();
    $stmt->bind_result($existing);
    if ($stmt->fetch()) {
        $stmt->close();
        return $existing;
    }
    $stmt->close();

    // Insert new
    $stmt = $conn->prepare("
        INSERT INTO patients (patient_id, subject_id, location, date_of_birth)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("ssss", $patientId, $subjectId, $location, $dobYmd);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert patient: " . $stmt->error);
    }
    $stmt->close();
    return $patientId;
}

function testExists(mysqli $conn, int $testId): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tests WHERE test_id = ?");
    $stmt->bind_param("i", $testId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

function generateNumericTestId(mysqli $conn, string $subjectId, DateTime $testDate): int {
    // 1) Base: yyyymmddHHMMSS + 4 random digits = up to 18 digits (fits BIGINT)
    // 2) Ensure uniqueness by retrying if collision
    do {
        $base = $testDate->format('Ymd') . date('His');
        $rand = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $id = (int)($base . $rand);
    } while (testExists($conn, $id));
    return $id;
}

function insertTest(mysqli $conn, int $testId, string $patientId, string $location, string $testDateYmd): void {
    $stmt = $conn->prepare("
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $testId, $patientId, $location, $testDateYmd);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert test: " . $stmt->error);
    }
    $stmt->close();
}

function insertTestEye(
    mysqli $conn,
    int $testId,
    string $eye,
    ?int $age,
    string $reportDiagnosis,
    string $exclusion,
    ?int $merciScore,
    string $merciDiagnosis,
    string $errorType,
    ?int $fafGrade,
    ?int $octScore,
    ?int $vfScore,
    string $actualDiagnosis,
    ?string $dateOfContinuationYmd,
    ?string $treatmentNotes,
    ?string $fafRef,
    ?string $octRef,
    ?string $vfRef,
    ?string $mfergRef
): void {
    $stmt = $conn->prepare("
        INSERT INTO test_eyes (
            test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, date_of_continuation,
            treatment_notes, faf_reference, oct_reference, vf_reference, mferg_reference
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
        "isisssssiiiissssss",
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
        $dateOfContinuationYmd,
        $treatmentNotes,
        $fafRef,
        $octRef,
        $vfRef,
        $mfergRef
    );
    if (!$stmt->execute()) {
        // Handle duplicate (test_id, eye) gracefully so importing twice doesn't blow up
        if ($conn->errno === 1062) {
            throw new Exception("Duplicate eye for test_id=$testId ($eye)");
        }
        throw new Exception("Failed to insert test eye: " . $stmt->error);
    }
    $stmt->close();
}
