<?php
require_once 'config.php';

// Function to get patient data by patient_id
function getPatientById($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT p.*, d.disease_name 
                          FROM Patients p
                          LEFT JOIN Diseases d ON p.disease_id = d.disease_id
                          WHERE p.patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get visits by patient_id
function getVisitsByPatientId($patient_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM Visits WHERE patient_id = ? ORDER BY visit_date DESC");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to get visit details by visit_id
function getVisitById($visit_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT v.*, p.*, d.disease_name 
                          FROM Visits v
                          JOIN Patients p ON v.patient_id = p.patient_id
                          LEFT JOIN Diseases d ON p.disease_id = d.disease_id
                          WHERE v.visit_id = ?");
    $stmt->bind_param("i", $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to scan and import images into the database
function scanAndImportImages() {
    global $conn;
    
    $testTypes = ['FAF', 'OCT', 'VF', 'MFERG'];
    $imported = 0;
    
    foreach ($testTypes as $type) {
        $dir = IMAGE_BASE_DIR . $type . '/';
        
        if (!file_exists($dir)) continue;
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) continue;
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'png') continue;
            
            $parts = explode('_', $file);
            if (count($parts) < 3) continue;
            
            $patient_id = (int)$parts[0];
            $eye = $parts[1];
            $date = str_replace('.png', '', $parts[2]);
            
            // Ensure patient exists
            $patient = getPatientById($patient_id);
            if (!$patient) {
                $stmt = $conn->prepare("INSERT INTO Patients (patient_id) VALUES (?)");
                $stmt->bind_param("i", $patient_id);
                $stmt->execute();
            }
            
            // Check for existing visit on this date
            $stmt = $conn->prepare("SELECT visit_id FROM Visits WHERE patient_id = ? AND visit_date = ?");
            $stmt->bind_param("is", $patient_id, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $field = strtolower($type) . '_reference_' . $eye;
            
            if ($result->num_rows > 0) {
                // Update existing visit
                $visit = $result->fetch_assoc();
                $stmt = $conn->prepare("UPDATE Visits SET $field = ? WHERE visit_id = ?");
                $stmt->bind_param("si", $file, $visit['visit_id']);
            } else {
                // Create new visit
                $stmt = $conn->prepare("INSERT INTO Visits (patient_id, visit_date, $field) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $patient_id, $date, $file);
            }
            
            $stmt->execute();
            $imported++;
        }
    }
    
    return $imported;
}

// Function to get patient statistics
function getPatientStatistics() {
    global $conn;
    
    $stats = [];
    
    $result = $conn->query("SELECT COUNT(*) AS total_patients FROM Patients");
    $stats['total_patients'] = $result->fetch_assoc()['total_patients'];
    
    $result = $conn->query("SELECT YEAR(CURRENT_DATE) - year_of_birth AS age FROM Patients");
    $ages = [];
    while ($row = $result->fetch_assoc()) {
        $ages[] = $row['age'];
    }
    sort($ages);
    $stats['median_age'] = calculatePercentile($ages, 50);
    $stats['percentile_25'] = calculatePercentile($ages, 25);
    $stats['percentile_75'] = calculatePercentile($ages, 75);
    
    $stats['gender'] = [];
    $result = $conn->query("SELECT gender, COUNT(*) AS count FROM Patients GROUP BY gender");
    while ($row = $result->fetch_assoc()) {
        $stats['gender'][$row['gender']] = $row['count'];
    }
    
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
?>

