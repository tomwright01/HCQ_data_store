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

// Get visit ID from URL parameter
$visit_id = isset($_GET['visit_id']) ? $_GET['visit_id'] : 0;

// Query to get the visit data
$sql_visit = "SELECT 
                v.visit_id,
                v.visit_date,
                v.visit_notes,
                v.faf_reference_OD,
                v.faf_reference_OS,
                v.oct_reference_OD,
                v.oct_reference_OS,
                v.vf_reference_OD,
                v.vf_reference_OS,
                v.mferg_reference_OD,
                v.mferg_reference_OS,
                v.merci_rating_left_eye,
                v.merci_rating_right_eye,
                p.patient_id,
                p.location,
                p.disease_id,
                p.year_of_birth,
                p.gender,
                p.referring_doctor,
                p.rx_OD,
                p.rx_OS,
                p.procedures_done,
                p.dosage,
                p.duration,
                p.cumulative_dosage,
                p.date_of_discontinuation,
                p.extra_notes
              FROM Visits v
              JOIN Patients p ON v.patient_id = p.patient_id
              WHERE v.visit_id = $visit_id";

$result_visit = $conn->query($sql_visit);

// If visit data exists, fetch it
$visit = null;
if ($result_visit->num_rows > 0) {
    $visit = $result_visit->fetch_assoc();
} else {
    echo "<p>Visit not found.</p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            height: 100vh;
        }

        .content {
            width: 80%;
            max-width: 1200px;
            text-align: center;
            padding: 20px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        h1 {
            font-size: 36px;
            color: #4CAF50;
        }

        h2 {
            font-size: 28px;
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
    </style>
</head>
<body>

<div class="content">
    <h1>Visit Details for Visit ID: <?php echo $visit['visit_id']; ?></h1>

    <h2>Patient Information</h2>
    <p>Patient ID: <?php echo $visit['patient_id']; ?></p>
    <p>Location: <?php echo $visit['location']; ?></p>
    <p>Disease ID: <?php echo $visit['disease_id']; ?></p>
    <p>Year of Birth: <?php echo $visit['year_of_birth']; ?></p>
    <p>Gender: <?php echo $visit['gender']; ?></p>
    <p>Referring Doctor: <?php echo $visit['referring_doctor']; ?></p>

    <h2>Visit Information</h2>
    <p>Visit Date: <?php echo $visit['visit_date']; ?></p>
    <p>Visit Notes: <?php echo $visit['visit_notes']; ?></p>

    <h2>Test References</h2>
    <table>
        <tr>
            <th>FAF (OD)</th>
            <th>FAF (OS)</th>
            <th>OCT (OD)</th>
            <th>OCT (OS)</th>
            <th>VF (OD)</th>
            <th>VF (OS)</th>
            <th>MFERG (OD)</th>
            <th>MFERG (OS)</th>
        </tr>
        <tr>
            <td><a href="<?php echo $visit['faf_reference_OD']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['faf_reference_OS']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['oct_reference_OD']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['oct_reference_OS']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['vf_reference_OD']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['vf_reference_OS']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['mferg_reference_OD']; ?>" target="_blank">View</a></td>
            <td><a href="<?php echo $visit['mferg_reference_OS']; ?>" target="_blank">View</a></td>
        </tr>
    </table>

    <h2>MERCI Ratings</h2>
    <p>MERCI Left Eye: <?php echo $visit['merci_rating_left_eye']; ?></p>
    <p>MERCI Right Eye: <?php echo $visit['merci_rating_right_eye']; ?></p>

    <h2>Other Information</h2>
    <p>RX OD: <?php echo $visit['rx_OD']; ?></p>
    <p>RX OS: <?php echo $visit['rx_OS']; ?></p>
    <p>Procedures Done: <?php echo $visit['procedures_done']; ?></p>
    <p>Dosage: <?php echo $visit['dosage']; ?></p>
    <p>Duration: <?php echo $visit['duration']; ?> months</p>
    <p>Cumulative Dosage: <?php echo $visit['cumulative_dosage']; ?></p>
    <p>Date of Discontinuation: <?php echo $visit['date_of_discontinuation']; ?></p>
    <p>Extra Notes: <?php echo $visit['extra_notes']; ?></p>

    <a href="index.php">Back to Patient Search</a>
</div>

</body>
</html>

<?php
// Close connection
$conn->close();
?>


