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

// Get the patient ID from the URL
$search_patient_id = isset($_GET['search_patient_id']) ? $_GET['search_patient_id'] : '';

// Query to get the patient and visit data based on the patient_id
$sql_patient_data = "SELECT v.visit_id, v.visit_date, v.visit_notes, 
                     v.faf_reference_OD, v.faf_reference_OS, 
                     v.oct_reference_OD, v.oct_reference_OS, 
                     v.vf_reference_OD, v.vf_reference_OS, 
                     v.mferg_reference_OD, v.mferg_reference_OS, 
                     v.merci_rating_left_eye, v.merci_rating_right_eye, 
                     p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
                     FROM Visits v
                     LEFT JOIN Patients p ON v.patient_id = p.patient_id
                     WHERE p.patient_id = $search_patient_id";

$result_patient = $conn->query($sql_patient_data);

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
        }

        .content {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        td a {
            color: #4CAF50;
            text-decoration: none;
        }

        h2 {
            text-align: center;
            font-size: 32px;
            color: #333;
        }
    </style>
</head>
<body>

    <div class="content">
        <h2>Patient Details</h2>

        <?php
        // Display patient and visit data if patient found
        if ($result_patient && $result_patient->num_rows > 0) {
            while ($row = $result_patient->fetch_assoc()) {
                echo "<h3>Patient ID: " . $row["patient_id"] . "</h3>";
                echo "<p><strong>Location:</strong> " . $row["location"] . "</p>";
                echo "<p><strong>Year of Birth:</strong> " . $row["year_of_birth"] . "</p>";
                echo "<p><strong>Gender:</strong> " . $row["gender"] . "</p>";
                echo "<p><strong>Referring Doctor:</strong> " . $row["referring_doctor"] . "</p>";

                echo "<h3>Visits for this Patient</h3>";
                echo "<table>
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
                            <th>MERCI Left Eye</th>
                            <th>MERCI Right Eye</th>
                        </tr>";
                // Output visit data
                echo "<tr>
                        <td>" . $row["visit_id"] . "</td>
                        <td>" . $row["visit_date"] . "</td>
                        <td>" . $row["visit_notes"] . "</td>
                        <td><a href='" . $row["faf_reference_OD"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["faf_reference_OS"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["oct_reference_OD"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["oct_reference_OS"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["vf_reference_OD"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["vf_reference_OS"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["mferg_reference_OD"] . "' target='_blank'>View</a></td>
                        <td><a href='" . $row["mferg_reference_OS"] . "' target='_blank'>View</a></td>
                        <td>" . $row["merci_rating_left_eye"] . "</td>
                        <td>" . $row["merci_rating_right_eye"] . "</td>
                      </tr>";
                echo "</table>";
            }
        } else {
            echo "<p>No visits found for this patient.</p>";
        }
        ?>

        <br><br>
        <a href="index.php" class="btn">Back to Home Page</a>
    </div>

</body>
</html>

?>
