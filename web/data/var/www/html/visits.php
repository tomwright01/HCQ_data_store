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

// Query to fetch visit data for the selected patient
$sql = "SELECT v.visit_id, v.visit_date, v.visit_notes, v.faf_reference, v.oct_reference, v.vf_reference, v.mferg_reference,
        v.merci_rating_left_eye, v.merci_rating_right_eye, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
        FROM Visits v
        LEFT JOIN Patients p ON v.patient_id = p.patient_id
        WHERE v.patient_id = $patient_id";

$result = $conn->query($sql);

// Check if there are results
if ($result->num_rows > 0) {
  // Output data of each row
  echo "<h1>Visits for Patient ID: $patient_id</h1>";
  echo "<table border='1' cellpadding='10'>
          <tr>
            <th>Visit ID</th>
            <th>Visit Date</th>
            <th>Visit Notes</th>
            <th>FAF Reference</th>
            <th>OCT Reference</th>
            <th>VF Reference</th>
            <th>MFERG Reference</th>
            <th>MERCI Left Eye</th>
            <th>MERCI Right Eye</th>
            <th>Location</th>
            <th>Disease ID</th>
            <th>Year of Birth</th>
            <th>Gender</th>
            <th>Referring Doctor</th>
          </tr>";

  while($row = $result->fetch_assoc()) {
    echo "<tr>
            <td>" . $row["visit_id"] . "</td>
            <td>" . $row["visit_date"] . "</td>
            <td>" . $row["visit_notes"] . "</td>
            <td>" . $row["faf_reference"] . "</td>
            <td>" . $row["oct_reference"] . "</td>
            <td>" . $row["vf_reference"] . "</td>
            <td>" . $row["mferg_reference"] . "</td>
            <td>" . $row["merci_rating_left_eye"] . "</td>
            <td>" . $row["merci_rating_right_eye"] . "</td>
            <td>" . $row["location"] . "</td>
            <td>" . $row["disease_id"] . "</td>
            <td>" . $row["year_of_birth"] . "</td>
            <td>" . $row["gender"] . "</td>
            <td>" . $row["referring_doctor"] . "</td>
          </tr>";
  }

  echo "</table>";
} else {
  echo "No visits found for this patient.";
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

// Query to fetch visit data along with related patient data
$sql = "SELECT v.visit_id, v.patient_id, v.visit_date, v.visit_notes, v.faf_reference, v.oct_reference, v.vf_reference, v.mferg_reference,
        v.merci_rating_left_eye, v.merci_rating_right_eye, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
        FROM Visits v
        LEFT JOIN Patients p ON v.patient_id = p.patient_id";

$result = $conn->query($sql);

// Check if there are results
if ($result->num_rows > 0) {
  // Output data of each row
  echo "<table border='1' cellpadding='10'><tr><th>Visit ID</th><th>Patient ID</th><th>Visit Date</th><th>Visit Notes</th><th>FAF Reference</th><th>OCT Reference</th><th>VF Reference</th><th>MFERG Reference</th><th>MERCI Left Eye</th><th>MERCI Right Eye</th><th>Location</th><th>Disease ID</th><th>Year of Birth</th><th>Gender</th><th>Referring Doctor</th></tr>";
  
  while($row = $result->fetch_assoc()) {
    echo "<tr><td>" . $row["visit_id"] . "</td><td>" . $row["patient_id"] . "</td><td>" . $row["visit_date"] . "</td><td>" . $row["visit_notes"] . "</td><td>" . $row["faf_reference"] . "</td><td>" . $row["oct_reference"] . "</td><td>" . $row["vf_reference"] . "</td><td>" . $row["mferg_reference"] . "</td><td>" . $row["merci_rating_left_eye"] . "</td><td>" . $row["merci_rating_right_eye"] . "</td><td>" . $row["location"] . "</td><td>" . $row["disease_id"] . "</td><td>" . $row["year_of_birth"] . "</td><td>" . $row["gender"] . "</td><td>" . $row["referring_doctor"] . "</td></tr>";
  }
  
  echo "</table>";
} else {
  echo "0 results found.";
}

// Close connection
$conn->close();
?>
*/
