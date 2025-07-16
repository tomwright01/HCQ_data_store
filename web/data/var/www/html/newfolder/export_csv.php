<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to get all patient and test data
$sql = "SELECT 
    p.patient_id,
    p.subject_id,
    p.date_of_birth,
    t.test_id,
    t.date_of_test,
    t.age,
    t.eye,
    t.report_diagnosis,
    t.exclusion,
    t.merci_score,
    t.merci_diagnosis,
    t.error_type,
    t.faf_grade,
    t.oct_score,
    t.vf_score,
    t.faf_reference_od,
    t.faf_reference_os,
    t.oct_reference_od,
    t.oct_reference_os,
    t.vf_reference_od,
    t.vf_reference_os,
    t.mferg_reference_od,
    t.mferg_reference_os
FROM patients p
LEFT JOIN tests t ON p.patient_id = t.patient_id
ORDER BY p.patient_id, t.date_of_test";

$result = $conn->query($sql);

if ($result->num_rows === 0) {
    die("No data found to export");
}

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=patient_data_export_' . date('Y-m-d') . '.csv');

// Create output file pointer
$output = fopen('php://output', 'w');

// Write CSV headers
$headers = [
    'Patient ID',
    'Subject ID',
    'Date of Birth',
    'Test ID',
    'Date of Test',
    'Age',
    'Eye',
    'Report Diagnosis',
    'Exclusion',
    'MERCI Score',
    'MERCI Diagnosis',
    'Error Type',
    'FAF Grade',
    'OCT Score',
    'VF Score',
    'FAF Reference OD',
    'FAF Reference OS',
    'OCT Reference OD',
    'OCT Reference OS',
    'VF Reference OD',
    'VF Reference OS',
    'MFERG Reference OD',
    'MFERG Reference OS'
];
fputcsv($output, $headers);

// Write data rows
while ($row = $result->fetch_assoc()) {
    // Format date fields
    $row['date_of_birth'] = !empty($row['date_of_birth']) ? date('m/d/Y', strtotime($row['date_of_birth'])) : '';
    $row['date_of_test'] = !empty($row['date_of_test']) ? date('m/d/Y', strtotime($row['date_of_test'])) : '';
    
    // Convert NULL values to empty strings
    foreach ($row as &$value) {
        if ($value === null) {
            $value = '';
        }
    }
    
    fputcsv($output, $row);
}

// Close connection
$conn->close();
exit();
?>
