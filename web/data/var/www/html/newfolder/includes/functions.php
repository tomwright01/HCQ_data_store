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

// Function to scan and import images into the database
function scanAndImportImages($directory) {
    global $conn;
    
    $testTypes = ['FAF', 'OCT', 'VF', 'MFERG'];  // Add test types as needed
    $imported = 0;
    
    foreach ($testTypes as $type) {
        $dir = $directory . '/' . $type . '/';  // Correctly set the directory
        
        if (!file_exists($dir)) continue;
        
        $files = scandir($dir);  // Scan the directory
        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) continue;
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'png') continue;  // Only import PNG files
            
            // Extract patient ID and date from the filename (e.g., 12_OD_20250513.png)
            $parts = explode('_', $file);
            if (count($parts) < 3) continue;  // Skip if the filename doesn't match the pattern
            
            $patient_id = (int)$parts[0];  // Patient ID is the first part
            $eye = $parts[1];  // Eye: OD or OS
            $date = str_replace('.png', '', $parts[2]);  // Date from filename
            
            // Ensure patient exists
            $patient = getPatientById($patient_id);
            if (!$patient) {
                $stmt = $conn->prepare("INSERT INTO Patients (patient_id) VALUES (?)");
                $stmt->bind_param("i", $patient_id);
                $stmt->execute();
            }
            
            // Check if visit exists for this patient on this date
            $stmt = $conn->prepare("SELECT visit_id FROM Visits WHERE patient_id = ? AND visit_date = ?");
            $stmt->bind_param("is", $patient_id, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Determine the correct field for storing the image (based on eye and test type)
            $field = strtolower($type) . '_reference_' . $eye;  // e.g., faf_reference_OD or oct_reference_OS
            
            if ($result->num_rows > 0) {
                // Update existing visit with the image
                $visit = $result->fetch_assoc();
                $stmt = $conn->prepare("UPDATE Visits SET $field = ? WHERE visit_id = ?");
                $stmt->bind_param("si", $file, $visit['visit_id']);
            } else {
                // Create a new visit if none exists
                $stmt = $conn->prepare("INSERT INTO Visits (patient_id, visit_date, $field) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $patient_id, $date, $file);
            }
            
            $stmt->execute();
            $imported++;
        }
    }
    
    return $imported;  // Return the number of images imported
}
?>
