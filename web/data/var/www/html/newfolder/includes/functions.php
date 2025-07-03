<?php
require_once 'config.php';

function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM Patients WHERE patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getVisitsByPatientId($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM Visits WHERE patient_id = ? ORDER BY visit_date DESC");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getVisitById($visit_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT v.*, p.*, d.disease_name 
                           FROM Visits v 
                           JOIN Patients p ON v.patient_id = p.patient_id
                           JOIN Diseases d ON p.disease_id = d.disease_id
                           WHERE v.visit_id = ?");
    $stmt->bind_param("i", $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getAllPatients() {
    global $conn;
    $result = $conn->query("SELECT p.*, d.disease_name 
                           FROM Patients p
                           JOIN Diseases d ON p.disease_id = d.disease_id
                           ORDER BY p.patient_id DESC");
    return $result->fetch_all(MYSQLI_ASSOC);
}


function getPatientStatistics() {
    global $conn;
    
    $stats = [];
    
    // Total patients
    $result = $conn->query("SELECT COUNT(*) AS total_patients FROM Patients");
    $stats['total_patients'] = $result->fetch_assoc()['total_patients'];
    
    // Age statistics
    $result = $conn->query("SELECT YEAR(CURRENT_DATE) - year_of_birth AS age FROM Patients");
    $ages = [];
    while ($row = $result->fetch_assoc()) {
        $ages[] = $row['age'];
    }
    sort($ages);
    $stats['median_age'] = calculatePercentile($ages, 50);
    $stats['percentile_25'] = calculatePercentile($ages, 25);
    $stats['percentile_75'] = calculatePercentile($ages, 75);
    
    // Gender distribution
    $stats['gender'] = [];
    $result = $conn->query("SELECT gender, COUNT(*) AS count FROM Patients GROUP BY gender");
    while ($row = $result->fetch_assoc()) {
        $stats['gender'][$row['gender']] = $row['count'];
    }
    
    // Location distribution
    $stats['location'] = [];
    $result = $conn->query("SELECT location, COUNT(*) AS count FROM Patients GROUP BY location");
    while ($row = $result->fetch_assoc()) {
        $stats['location'][$row['location']] = $row['count'];
    }
    
    return $stats;
}

function calculatePercentile($arr, $percentile) {
    $index = (int)floor($percentile / 100 * count($arr));
    return $arr[$index];
}

function addPatientAndVisit($patient_data, $visit_data) {
    global $conn;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert patient
        $stmt = $conn->prepare("INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siissssssisss", 
            $patient_data['location'],
            $patient_data['disease_id'],
            $patient_data['year_of_birth'],
            $patient_data['gender'],
            $patient_data['referring_doctor'],
            $patient_data['rx_OD'],
            $patient_data['rx_OS'],
            $patient_data['procedures_done'],
            $patient_data['dosage'],
            $patient_data['duration'],
            $patient_data['cumulative_dosage'],
            $patient_data['date_of_discontinuation'],
            $patient_data['extra_notes']
        );
        $stmt->execute();
        $patient_id = $conn->insert_id;
        
        // Insert visit
        $stmt = $conn->prepare("INSERT INTO Visits (patient_id, visit_date, visit_notes, 
                              faf_test_id_OD, faf_image_number_OD, faf_test_id_OS, faf_image_number_OS,
                              oct_test_id_OD, oct_image_number_OD, oct_test_id_OS, oct_image_number_OS,
                              vf_test_id_OD, vf_image_number_OD, vf_test_id_OS, vf_image_number_OS,
                              mferg_test_id_OD, mferg_image_number_OD, mferg_test_id_OS, mferg_image_number_OS,
                              merci_rating_left_eye, merci_rating_right_eye)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issiiiiiiiiiiiiiiiiii",
            $patient_id,
            $visit_data['visit_date'],
            $visit_data['visit_notes'],
            $visit_data['faf_test_id_OD'],
            $visit_data['faf_image_number_OD'],
            $visit_data['faf_test_id_OS'],
            $visit_data['faf_image_number_OS'],
            $visit_data['oct_test_id_OD'],
            $visit_data['oct_image_number_OD'],
            $visit_data['oct_test_id_OS'],
            $visit_data['oct_image_number_OS'],
            $visit_data['vf_test_id_OD'],
            $visit_data['vf_image_number_OD'],
            $visit_data['vf_test_id_OS'],
            $visit_data['vf_image_number_OS'],
            $visit_data['mferg_test_id_OD'],
            $visit_data['mferg_image_number_OD'],
            $visit_data['mferg_test_id_OS'],
            $visit_data['mferg_image_number_OS'],
            $visit_data['merci_rating_left_eye'],
            $visit_data['merci_rating_right_eye']
        );
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        return $patient_id;
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        return false;
    }
}
?>
