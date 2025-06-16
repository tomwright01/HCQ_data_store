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
$sql = "SELECT patient_id, location, disease_id, year_of_birth, gender, referring_doctor FROM Patients";
$result = $conn->query($sql);

// Check if there are results
if ($result->num_rows > 0) {
  // Output data of each row
  echo "<h1>List of Patients</h1>";
  echo "<table border='1' cellpadding='10'>
          <tr>
            <th>Patient ID</th>
            <th>Location</th>
            <th>Disease ID</th>
            <th>Year of Birth</th>
            <th>Gender</th>
            <th>Referring Doctor</th>
            <th>View Visits</th>
          </tr>";

  while($row = $result->fetch_assoc()) {
    echo "<tr>
            <td>" . $row["patient_id"] . "</td>
            <td>" . $row["location"] . "</td>
            <td>" . $row["disease_id"] . "</td>
            <td>" . $row["year_of_birth"] . "</td>
            <td>" . $row["gender"] . "</td>
            <td>" . $row["referring_doctor"] . "</td>
            <td><a href='visits.php?patient_id=" . $row["patient_id"] . "'>View Visits</a></td>
          </tr>";
  }

  echo "</table>";
} else {
  echo "0 results found.";
}

// Close connection
$conn->close();
?>

/*
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
echo "Connected successfully<br>";

// Query to fetch patient and visit data
$sql = "SELECT p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor, p.rx_OD, p.rx_OS, 
        p.procedures_done, p.dosage, p.duration, p.cumulative_dosage, p.date_of_discontinuation, p.extra_notes,
        v.visit_id, v.visit_date, v.visit_notes, v.faf_reference, v.oct_reference, v.vf_reference, v.mferg_reference, 
        v.merci_rating_left_eye, v.merci_rating_right_eye 
        FROM Patients p
        LEFT JOIN Visits v ON p.patient_id = v.patient_id";

$result = $conn->query($sql);

// Check if there are results
if ($result->num_rows > 0) {
  // Output data of each row
  echo "<table border='1' cellpadding='10'><tr><th>Patient ID</th><th>Location</th><th>Disease ID</th><th>Year of Birth</th><th>Gender</th><th>Referring Doctor</th><th>RX OD</th><th>RX OS</th><th>Procedures Done</th><th>Dosage</th><th>Duration</th><th>Cumulative Dosage</th><th>Date of Discontinuation</th><th>Extra Notes</th><th>Visit ID</th><th>Visit Date</th><th>Visit Notes</th><th>FAF Reference</th><th>OCT Reference</th><th>VF Reference</th><th>MFERG Reference</th><th>MERCI Left Eye</th><th>MERCI Right Eye</th></tr>";
  
  while($row = $result->fetch_assoc()) {
    echo "<tr><td>" . $row["patient_id"] . "</td><td>" . $row["location"] . "</td><td>" . $row["disease_id"] . "</td><td>" . $row["year_of_birth"] . "</td><td>" . $row["gender"] . "</td><td>" . $row["referring_doctor"] . "</td><td>" . $row["rx_OD"] . "</td><td>" . $row["rx_OS"] . "</td><td>" . $row["procedures_done"] . "</td><td>" . $row["dosage"] . "</td><td>" . $row["duration"] . "</td><td>" . $row["cumulative_dosage"] . "</td><td>" . $row["date_of_discontinuation"] . "</td><td>" . $row["extra_notes"] . "</td><td>" . $row["visit_id"] . "</td><td>" . $row["visit_date"] . "</td><td>" . $row["visit_notes"] . "</td><td>" . $row["faf_reference"] . "</td><td>" . $row["oct_reference"] . "</td><td>" . $row["vf_reference"] . "</td><td>" . $row["mferg_reference"] . "</td><td>" . $row["merci_rating_left_eye"] . "</td><td>" . $row["merci_rating_right_eye"] . "</td></tr>";
  }
  
  echo "</table>";
} else {
  echo "0 results found.";
}

// Close connection
$conn->close();
?>
<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";

// Create database connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully";

// phpinfo();
?>
*/

