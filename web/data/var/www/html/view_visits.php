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

// Get the visit_id from the URL
$visit_id = isset($_GET['visit_id']) ? $_GET['visit_id'] : '';

if ($visit_id) {
    // Query to get the visit data based on the visit_id
    $sql_visit_data = "SELECT v.visit_id, v.visit_date, v.visit_notes, 
                         v.faf_reference_OD, v.faf_reference_OS, 
                         v.oct_reference_OD, v.oct_reference_OS, 
                         v.vf_reference_OD, v.vf_reference_OS, 
                         v.mferg_reference_OD, v.mferg_reference_OS, 
                         v.merci_rating_left_eye, v.merci_rating_right_eye, 
                         p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
                         FROM Visits v
                         LEFT JOIN Patients p ON v.patient_id = p.patient_id
                         WHERE v.visit_id = $visit_id";

    $result_visit = $conn->query($sql_visit_data);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Details</title>
</head>
<body>

<h1>Visit Details</h1>

<?php
if ($visit_id && $result_visit->num_rows > 0) {
    $visit = $result_visit->fetch_assoc();
    echo "<h3>Visit Information</h3>";
    echo "<p>Visit ID: " . $visit['visit_id'] . "</p>";
    echo "<p>Visit Date: " . $visit['visit_date'] . "</p>";
    echo "<p>Visit Notes: " . $visit['visit_notes'] . "</p>";
    echo "<p>FAF Reference (OD): <a href='" . $visit['faf_reference_OD'] . "' target='_blank'>View</a></p>";
    echo "<p>FAF Reference (OS): <a href='" . $visit['faf_reference_OS'] . "' target='_blank'>View</a></p>";
    echo "<p>OCT Reference (OD): <a href='" . $visit['oct_reference_OD'] . "' target='_blank'>View</a></p>";
    echo "<p>OCT Reference (OS): <a href='" . $visit['oct_reference_OS'] . "' target='_blank'>View</a></p>";
    echo "<p>VF Reference (OD): <a href='" . $visit['vf_reference_OD'] . "' target='_blank'>View</a></p>";
    echo "<p>VF Reference (OS): <a href='" . $visit['vf_reference_OS'] . "' target='_blank'>View</a></p>";
    echo "<p>MFERG Reference (OD): <a href='" . $visit['mferg_reference_OD'] . "' target='_blank'>View</a></p>";
    echo "<p>MFERG Reference (OS): <a href='" . $visit['mferg_reference_OS'] . "' target='_blank'>View</a></p>";
    echo "<p>MERCI Rating (Left Eye): " . $visit['merci_rating_left_eye'] . "</p>";
    echo "<p>MERCI Rating (Right Eye): " . $visit['merci_rating_right_eye'] . "</p>";
    echo "<p>Patient ID: " . $visit['patient_id'] . "</p>";
    echo "<p>Location: " . $visit['location'] . "</p>";
    echo "<p>Disease ID: " . $visit['disease_id'] . "</p>";
    echo "<p>Year of Birth: " . $visit['year_of_birth'] . "</p>";
    echo "<p>Gender: " . $visit['gender'] . "</p>";
    echo "<p>Referring Doctor: " . $visit['referring_doctor'] . "</p>";
} else {
    echo "<p>No visit found with ID: $visit_id</p>";
}
?>

</body>
</html>

<?php
// Close connection
$conn->close();
?>

