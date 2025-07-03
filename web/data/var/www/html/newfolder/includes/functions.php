<?php

require_once 'config.php';

function addOrUpdatePatientFromImage($filename, $testType = 'FAF') {
    global $conn;
    
    // Parse filename (format: {patient_id}_{eye}_{date}.png)
    $parts = explode('_', $filename);
    if (count($parts) < 3) return false;
    
    $patient_id = (int)$parts[0];
    $eye = $parts[1];
    $date = str_replace('.png', '', $parts[2]);
    
    // Check if patient exists
    $patient = getPatientById($patient_id);
    
    if (!$patient) {
        // Create basic patient record
        $stmt = $conn->prepare("INSERT INTO Patients (patient_id, location) VALUES (?, 'Unknown')");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
    }
    
    // Check if visit exists for this date
    $stmt = $conn->prepare("SELECT visit_id FROM Visits WHERE patient_id = ? AND visit_date = ?");
    $stmt->bind_param("is", $patient_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing visit
        $visit = $result->fetch_assoc();
        $visit_id = $visit['visit_id'];
        
        $field = strtolower($testType) . '_reference_' . $eye;
        $stmt = $conn->prepare("UPDATE Visits SET $field = ? WHERE visit_id = ?");
        $stmt->bind_param("si", $filename, $visit_id);
        $stmt->execute();
    } else {
        // Create new visit
        $field = strtolower($testType) . '_reference_' . $eye;
        $sql = "INSERT INTO Visits (patient_id, visit_date, $field) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $patient_id, $date, $filename);
        $stmt->execute();
    }
    
    return true;
}

// New function to scan directory and update database
function scanAndImportImages($directory, $testType = 'FAF') {
    if (!file_exists($directory)) {
        return ['success' => false, 'message' => 'Directory not found'];
    }
    
    $files = scandir($directory);
    $imported = 0;
    $errors = 0;
    
    foreach ($files as $file) {
        if (in_array($file, ['.', '..'])) continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'png') continue;
        
        if (addOrUpdatePatientFromImage($file, $testType)) {
            $imported++;
        } else {
            $errors++;
        }
    }
    
    return [
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'total' => count($files) - 2 // subtract . and ..
    ];
}

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
