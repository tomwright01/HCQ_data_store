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

// Query to fetch all patients
$sql_patients = "SELECT patient_id, location, disease_id, year_of_birth, gender, referring_doctor FROM Patients";
$result_patients = $conn->query($sql_patients);

// Query to fetch all visits
$sql_visits = "SELECT v.visit_id, v.visit_date, v.visit_notes, p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
               FROM Visits v
               JOIN Patients p ON v.patient_id = p.patient_id";
$result_visits = $conn->query($sql_visits);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients and Visits Overview</title>
</head>
<body>
    <h1>Patients and Visits Overview</h1>

    <h2>Patients Table</h2>
    <table border="1" cellpadding="10">
        <thead>
            <tr>
                <th>Patient ID</th>
                <th>Location</th>
                <th>Disease ID</th>
                <th>Year of Birth</th>
                <th>Gender</th>
                <th>Referring Doctor</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result_patients->num_rows > 0) {
                while ($row = $result_patients->fetch_assoc()) {
                    echo "<tr>
                            <td>" . $row["patient_id"] . "</td>
                            <td>" . $row["location"] . "</td>
                            <td>" . $row["disease_id"] . "</td>
                            <td>" . $row["year_of_birth"] . "</td>
                            <td>" . $row["gender"] . "</td>
                            <td>" . $row["referring_doctor"] . "</td>
                          </tr>";
                }
            } else {
                echo "<tr><td colspan='6'>No patients found</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <h2>Visits Table</h2>
    <table border="1" cellpadding="10">
        <thead>
            <tr>
                <th>Visit ID</th>
                <th>Visit Date</th>
                <th>Visit Notes</th>
                <th>Patient ID</th>
                <th>Location</th>
                <th>Disease ID</th>
                <th>Year of Birth</th>
                <th>Gender</th>
                <th>Referring Doctor</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result_visits->num_rows > 0) {
                while ($row = $result_visits->fetch_assoc()) {
                    echo "<tr>
                            <td>" . $row["visit_id"] . "</td>
                            <td>" . $row["visit_date"] . "</td>
                            <td>" . $row["visit_notes"] . "</td>
                            <td>" . $row["patient_id"] . "</td>
                            <td>" . $row["location"] . "</td>
                            <td>" . $row["disease_id"] . "</td>
                            <td>" . $row["year_of_birth"] . "</td>
                            <td>" . $row["gender"] . "</td>
                            <td>" . $row["referring_doctor"] . "</td>
                          </tr>";
                }
            } else {
                echo "<tr><td colspan='9'>No visits found</td></tr>";
            }
            ?>
        </tbody>
    </table>

</body>
</html>

<?php
// Close connection
$conn->close();
?>
