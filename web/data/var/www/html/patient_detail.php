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

// Get patient ID from search form
$patient_id = isset($_GET['search_patient_id']) ? $_GET['search_patient_id'] : 0;

// Query to get patient details
$sql_patient = "SELECT * FROM Patients WHERE patient_id = $patient_id";
$result_patient = $conn->query($sql_patient);

if ($result_patient->num_rows > 0) {
    // Fetch the patient details
    $patient_data = $result_patient->fetch_assoc();
    echo "<h1>Patient Information (ID: $patient_id)</h1>";
    echo "<p><strong>Location:</strong> " . $patient_data['location'] . "</p>";
    echo "<p><strong>Disease ID:</strong> " . $patient_data['disease_id'] . "</p>";
    echo "<p><strong>Year of Birth:</strong> " . $patient_data['year_of_birth'] . "</p>";
    echo "<p><strong>Gender:</strong> " . $patient_data['gender'] . "</p>";
    echo "<p><strong>Referring Doctor:</strong> " . $patient_data['referring_doctor'] . "</p>";
    
    // Query to get visit details
    $sql_visits = "SELECT * FROM Visits WHERE patient_id = $patient_id";
    $result_visits = $conn->query($sql_visits);
    
    if ($result_visits->num_rows > 0) {
        echo "<h2>Visit Data</h2>";
        echo "<table border='1' cellpadding='10'>
                <thead>
                    <tr>
                        <th>Visit ID</th>
                        <th>Visit Date</th>
                        <th>Visit Notes</th>
                        <th>FAF Reference (OD)</th>
                        <th>FAF Reference (OS)</th>
                        <th>OCT Reference (OD)</th>
                        <th>OCT Reference (OS)</th>
                        <th>VF Reference (OD)</th>
                        <th>VF Reference (OS)</th>
                        <th>MFERG Reference (OD)</th>
                        <th>MFERG Reference (OS)</th>
                    </tr>
                </thead>
                <tbody>";
        while ($row_visits = $result_visits->fetch_assoc()) {
            echo "<tr>
                    <td>" . $row_visits["visit_id"] . "</td>
                    <td>" . $row_visits["visit_date"] . "</td>
                    <td>" . $row_visits["visit_notes"] . "</td>
                    <td><a href='" . $row_visits["faf_reference_OD"] . "'>FAF OD</a></td>
                    <td><a href='" . $row_visits["faf_reference_OS"] . "'>FAF OS</a></td>
                    <td><a href='" . $row_visits["oct_reference_OD"] . "'>OCT OD</a></td>
                    <td><a href='" . $row_visits["oct_reference_OS"] . "'>OCT OS</a></td>
                    <td><a href='" . $row_visits["vf_reference_OD"] . "'>VF OD</a></td>
                    <td><a href='" . $row_visits["vf_reference_OS"] . "'>VF OS</a></td>
                    <td><a href='" . $row_visits["mferg_reference_OD"] . "'>MFERG OD</a></td>
                    <td><a href='" . $row_visits["mferg_reference_OS"] . "'>MFERG OS</a></td>
                </tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p>No visits found for this patient.</p>";
    }
} else {
    echo "<p>Patient not found.</p>";
}

// Close the connection
$conn->close();
?>
