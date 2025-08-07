<?php
// Basic error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to fetch patient + test + test_eye data
$sql = "
    SELECT 
        p.patient_id, p.subject_id, p.location AS patient_location, p.date_of_birth,
        t.test_id, t.location AS test_location, t.date_of_test,
        te.eye, te.age, te.report_diagnosis, te.exclusion, te.merci_score, te.merci_diagnosis, te.error_type,
        te.faf_grade, te.oct_score, te.vf_score, te.actual_diagnosis,
        te.medication_name, te.dosage, te.dosage_unit, te.duration_days,
        te.cumulative_dosage, te.date_of_continuation, te.treatment_notes
    FROM patients p
    JOIN tests t ON p.patient_id = t.patient_id
    JOIN test_eyes te ON t.test_id = te.test_id
    ORDER BY p.patient_id, t.date_of_test, te.eye
";

$result = $conn->query($sql);

if (!$result) {
    die("Database query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Clinical Data Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #aaa; padding: 8px; text-align: left; }
        th { background-color: #333; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        caption { font-size: 1.5em; margin-bottom: 10px; font-weight: bold; }
    </style>
</head>
<body>

<h1>Clinical Patient & Test Data</h1>

<table>
    <caption>Patient Test Records</caption>
    <thead>
        <tr>
            <th>Patient ID</th>
            <th>Subject ID</th>
            <th>Patient Location</th>
            <th>Date of Birth</th>
            <th>Test ID</th>
            <th>Test Location</th>
            <th>Date of Test</th>
            <th>Eye</th>
            <th>Age</th>
            <th>Report Diagnosis</th>
            <th>Exclusion</th>
            <th>Merci Score</th>
            <th>Merci Diagnosis</th>
            <th>Error Type</th>
            <th>FAF Grade</th>
            <th>OCT Score</th>
            <th>VF Score</th>
            <th>Actual Diagnosis</th>
            <th>Medication Name</th>
            <th>Dosage</th>
            <th>Dosage Unit</th>
            <th>Duration Days</th>
            <th>Cumulative Dosage</th>
            <th>Date of Continuation</th>
            <th>Treatment Notes</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['patient_id']) ?></td>
            <td><?= htmlspecialchars($row['subject_id']) ?></td>
            <td><?= htmlspecialchars($row['patient_location']) ?></td>
            <td><?= htmlspecialchars($row['date_of_birth']) ?></td>
            <td><?= htmlspecialchars($row['test_id']) ?></td>
            <td><?= htmlspecialchars($row['test_location']) ?></td>
            <td><?= htmlspecialchars($row['date_of_test']) ?></td>
            <td><?= htmlspecialchars($row['eye']) ?></td>
            <td><?= htmlspecialchars($row['age']) ?></td>
            <td><?= htmlspecialchars($row['report_diagnosis']) ?></td>
            <td><?= htmlspecialchars($row['exclusion']) ?></td>
            <td><?= htmlspecialchars($row['merci_score']) ?></td>
            <td><?= htmlspecialchars($row['merci_diagnosis']) ?></td>
            <td><?= htmlspecialchars($row['error_type']) ?></td>
            <td><?= htmlspecialchars($row['faf_grade']) ?></td>
            <td><?= htmlspecialchars($row['oct_score']) ?></td>
            <td><?= htmlspecialchars($row['vf_score']) ?></td>
            <td><?= htmlspecialchars($row['actual_diagnosis']) ?></td>
            <td><?= htmlspecialchars($row['medication_name']) ?></td>
            <td><?= htmlspecialchars($row['dosage']) ?></td>
            <td><?= htmlspecialchars($row['dosage_unit']) ?></td>
            <td><?= htmlspecialchars($row['duration_days']) ?></td>
            <td><?= htmlspecialchars($row['cumulative_dosage']) ?></td>
            <td><?= htmlspecialchars($row['date_of_continuation']) ?></td>
            <td><?= nl2br(htmlspecialchars($row['treatment_notes'])) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
