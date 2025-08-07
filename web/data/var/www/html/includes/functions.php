<?php
function generatePatientId($conn, $patient_name, $patient_dob) {
    $patient_id = md5($patient_name . $patient_dob);
    return $patient_id;
}

function getOrCreatePatient($conn, $patient_name, $patient_dob) {
    $patient_id = generatePatientId($conn, $patient_name, $patient_dob);

    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO patients (patient_id, name, dob) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $patient_id, $patient_name, $patient_dob);
        $stmt->execute();
    }

    $stmt->close();
    return $patient_id;
}

function insertOrUpdateTest($conn, $test_id, $patient_id, $location, $date_of_test, $eye_data) {
    // Insert or update main test row
    $stmt = $conn->prepare("
        INSERT INTO tests (test_id, patient_id, location, date_of_test)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE location = VALUES(location), date_of_test = VALUES(date_of_test)
    ");
    $stmt->bind_param("ssss", $test_id, $patient_id, $location, $date_of_test);
    $stmt->execute();
    $stmt->close();

    // Insert or update per-eye data
    $stmt = $conn->prepare("
        INSERT INTO test_eyes (
            test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
            faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit,
            duration_days, cumulative_dosage, date_of_continuation, treatment_notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            medication_name = VALUES(medication_name),
            dosage = VALUES(dosage),
            dosage_unit = VALUES(dosage_unit),
            duration_days = VALUES(duration_days),
            cumulative_dosage = VALUES(cumulative_dosage),
            date_of_continuation = VALUES(date_of_continuation),
            treatment_notes = VALUES(treatment_notes)
    ");

    $stmt->bind_param(
        "ssissssssdddsisdisss",
        $test_id,
        $eye_data['eye'],
        $eye_data['age'],
        $eye_data['report_diagnosis'],
        $eye_data['exclusion'],
        $eye_data['merci_score'],
        $eye_data['merci_diagnosis'],
        $eye_data['error_type'],
        $eye_data['faf_grade'],
        $eye_data['oct_score'],
        $eye_data['vf_score'],
        $eye_data['actual_diagnosis'],
        $eye_data['medication_name'],
        $eye_data['dosage'],
        $eye_data['dosage_unit'],
        $eye_data['duration_days'],
        $eye_data['cumulative_dosage'],
        $eye_data['date_of_continuation'],
        $eye_data['treatment_notes']
    );

    $stmt->execute();
    $stmt->close();
}

function checkDuplicateTest($conn, $patient_id, $date_of_test, $eye) {
    $query = "
        SELECT te.test_id
        FROM tests t
        JOIN test_eyes te ON t.test_id = te.test_id
        WHERE t.patient_id = ? AND t.date_of_test = ? AND te.eye = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $patient_id, $date_of_test, $eye);
    $stmt->execute();
    $stmt->store_result();
    $is_duplicate = $stmt->num_rows > 0;
    $stmt->close();

    return $is_duplicate;
}

function importTestImage($conn, $test_id, $eye, $image_type, $file_path) {
    // Example: image_type = 'oct_reference', file_path = 'uploads/abc.jpg'
    // This will update test_eyes.oct_reference or other image field
    $allowed_fields = ['oct_reference', 'vf_reference', 'faf_reference'];

    if (!in_array($image_type, $allowed_fields)) {
        throw new Exception("Invalid image type: $image_type");
    }

    // Ensure the column exists in your `test_eyes` table
    $sql = "UPDATE test_eyes SET {$image_type} = ? WHERE test_id = ? AND eye = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $file_path, $test_id, $eye);
    $stmt->execute();
    $stmt->close();
}
?>
