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

// Query to fetch all patients and their visits
$sql = "SELECT p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor,
               v.visit_id, v.visit_date, v.visit_notes, v.faf_reference_OD, v.faf_reference_OS,
               v.oct_reference_OD, v.oct_reference_OS, v.vf_reference_OD, v.vf_reference_OS,
               v.mferg_reference_OD, v.mferg_reference_OS, v.merci_rating_left_eye, v.merci_rating_right_eye
        FROM Patients p
        LEFT JOIN Visits v ON p.patient_id = v.patient_id";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient and Visit Data</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }

        h1 {
            text-align: center;
            font-size: 36px;
            margin-top: 20px;
            color: #4CAF50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table, th, td {
            border: 1px solid #ddd;
        }

        th, td {
            padding: 12px;
            text-align: left;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        a {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 18px;
            color: #4CAF50;
            text-decoration: none;
        }

        a:hover {
            color: #45a049;
        }
    </style>
</head>
<body>

    <h1>Patient and Visit Data</h1>

    <table>
        <thead>
            <tr>
                <th>Patient ID</th>
                <th>Location</th>
                <th>Disease ID</th>
                <th>Year of Birth</th>
                <th>Gender</th>
                <th>Referring Doctor</th>
                <th>Visit ID</th>
                <th>Visit Date</th>
                <th>Visit Notes</th>
                <th>FAF OD Reference</th>
                <th>FAF OS Reference</th>
                <th>OCT OD Reference</th>
                <th>OCT OS Reference</th>
                <th>VF OD Reference</th>
                <th>VF OS Reference</th>
                <th>MFERG OD Reference</th>
                <th>MFERG OS Reference</th>
                <th>MERCI Left Eye</th>
                <th>MERCI Right Eye</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>
                            <td>" . $row["patient_id"] . "</td>
                            <td>" . $row["location"] . "</td>
                            <td>" . $row["disease_id"] . "</td>
                            <td>" . $row["year_of_birth"] . "</td>
                            <td>" . $row["gender"] . "</td>
                            <td>" . $row["referring_doctor"] . "</td>
                            <td>" . $row["visit_id"] . "</td>
                            <td>" . $row["visit_date"] . "</td>
                            <td>" . $row["visit_notes"] . "</td>
                            <td>" . $row["faf_reference_OD"] . "</td>
                            <td>" . $row["faf_reference_OS"] . "</td>
                            <td>" . $row["oct_reference_OD"] . "</td>
                            <td>" . $row["oct_reference_OS"] . "</td>
                            <td>" . $row["vf_reference_OD"] . "</td>
                            <td>" . $row["vf_reference_OS"] . "</td>
                            <td>" . $row["mferg_reference_OD"] . "</td>
                            <td>" . $row["mferg_reference_OS"] . "</td>
                            <td>" . $row["merci_rating_left_eye"] . "</td>
                            <td>" . $row["merci_rating_right_eye"] . "</td>
                        </tr>";
                }
            } else {
                echo "<tr><td colspan='20'>No patient or visit data found.</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <a href="index.php">Back to Home</a>

</body>
</html>

<?php
// Close connection
$conn->close();
?>


