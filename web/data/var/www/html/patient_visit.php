<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData"; // Name of your database

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the Patient ID from the URL
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : 0;

// Query to fetch patient and visit details for the selected patient
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

// Bind parameters to the SQL query
$stmt->bind_param("i", $patient_id);

// Execute the statement
$stmt->execute();

// Get the result
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<h1>Visits for Patient ID: $patient_id</h1>";

    while ($row = $result->fetch_assoc()) {
        echo "<h2>Visit ID: " . $row["visit_id"] . "</h2>";
        echo "Visit Date: " . $row["visit_date"] . "<br>";
        echo "Visit Notes: " . $row["visit_notes"] . "<br>";

        // Display FAF, OCT, VF, and MFERG image references
        echo "FAF OD: <a href='" . $row["faf_reference_OD"] . "' target='_blank'>View Image</a><br>";
        echo "FAF OS: <a href='" . $row["faf_reference_OS"] . "' target='_blank'>View Image</a><br>";
        echo "OCT OD: <a href='" . $row["oct_reference_OD"] . "' target='_blank'>View Image</a><br>";
        echo "OCT OS: <a href='" . $row["oct_reference_OS"] . "' target='_blank'>View Image</a><br>";
        echo "VF OD: <a href='" . $row["vf_reference_OD"] . "' target='_blank'>View Image</a><br>";
        echo "VF OS: <a href='" . $row["vf_reference_OS"] . "' target='_blank'>View Image</a><br>";
        echo "MFERG OD: <a href='" . $row["mferg_reference_OD"] . "' target='_blank'>View Image</a><br>";
        echo "MFERG OS: <a href='" . $row["mferg_reference_OS"] . "' target='_blank'>View Image</a><br>";

        echo "MERCI Left Eye: " . $row["merci_rating_left_eye"] . "<br>";
        echo "MERCI Right Eye: " . $row["merci_rating_right_eye"] . "<br>";
    }
} else {
    echo "<p>No visits found for this patient.</p>";
}

// Close connection
$conn->close();
?>


