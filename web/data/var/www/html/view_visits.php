<?php
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

// Get the Patient ID from the URL
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : 0;

// Query to fetch patient and visit details
$sql = "SELECT v.visit_id, v.visit_date, v.visit_notes, 
        v.faf_reference_OD, v.faf_reference_OS, 
        v.oct_reference_OD, v.oct_reference_OS, 
        v.vf_reference_OD, v.vf_reference_OS, 
        v.mferg_reference_OD, v.mferg_reference_OS, 
        v.merci_rating_left_eye, v.merci_rating_right_eye, 
        p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
        FROM Visits v
        LEFT JOIN Patients p ON v.patient_id = p.patient_id
        WHERE v.patient_id = ?";

// Prepare the SQL statement
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<h1>Visits for Patient ID: $patient_id</h1>";

    while ($row = $result->fetch_assoc()) {
        echo "<h2>Visit ID: " . $row["visit_id"] . "</h2>";
        // Show other details of visit
        echo "Visit Date: " . $row["visit_date"] . "<br>";
        echo "Visit Notes: " . $row["visit_notes"] . "<br>";

        // Show references for FAF, OCT, VF, MFERG images
        echo "FAF OD: <a href='" . $row["faf_reference_OD"] . "' target='_blank'>View Image</a><br>";
        echo "FAF OS: <a href='" . $row["faf_reference_OS"] . "' target='_blank'>View Image</a><br>";

        // Similarly, add for OCT, VF, and MFERG images
        // Add score logic here
    }
} else {
    echo "<p>No visits found for this patient.</p>";
}

$conn->close();
?>
