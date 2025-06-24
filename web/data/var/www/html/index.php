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

// Query to fetch the total number of patients
$sql_patient_count = "SELECT COUNT(*) AS total_patients FROM Patients";
$result_patient_count = $conn->query($sql_patient_count);
$total_patients = $result_patient_count->fetch_assoc()['total_patients'];

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Data Overview</title>
</head>
<body>
    <h1>Welcome to the Patient Data Portal</h1>
    
    <h2>Patient Summary</h2>
    <p>Total number of patients: <?php echo $total_patients; ?></p>
    
    <h2>View Patient and Visit Data</h2>
    <p>Click below to view the full list of patients and their visits:</p>
    <a href="patient_visit.php">View Patients and Visits</a>

    <h2>Add New Patient and Visit</h2>
    <p>Click below to add a new patient and visit:</p>
    <a href="form.php">Go to the form</a>

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
                <th>View Visits</th>
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
                            <td><a href='patient_visit.php?patient_id=" . $row["patient_id"] . "'>View Visits</a></td>
                          </tr>";
                }
            } else {
                echo "<tr><td colspan='7'>No patients found</td></tr>";
            }
            ?>
        </tbody>
    </table>

</body>
</html>










