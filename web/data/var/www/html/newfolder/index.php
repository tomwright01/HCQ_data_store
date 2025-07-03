<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to get total number of patients
$sql_total_patients = "SELECT COUNT(*) AS total_patients FROM Patients";
$result_total_patients = $conn->query($sql_total_patients);
$row_total_patients = $result_total_patients->fetch_assoc();
$total_patients = $row_total_patients['total_patients'];

// Query to get the ages of all patients
$sql_age = "SELECT YEAR(CURRENT_DATE) - year_of_birth AS age FROM Patients";
$result_age = $conn->query($sql_age);

$ages = [];
while ($row_age = $result_age->fetch_assoc()) {
    $ages[] = $row_age['age'];
}

// Sort the ages in ascending order
sort($ages);

// Calculate the percentiles
$median = calculatePercentile($ages, 50);
$percentile_25 = calculatePercentile($ages, 25);
$percentile_75 = calculatePercentile($ages, 75);

function calculatePercentile($arr, $percentile) {
    $index = (int)floor($percentile / 100 * count($arr));
    return $arr[$index];
}

// Patient search functionality
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';

if ($search_patient_id) {
    $search_patient_id = (int)$search_patient_id;
    if ($search_patient_id > 0) {
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
    } else {
        echo "<p>Invalid Patient ID. Please enter a valid ID.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kensington Health Data Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@1.0.2/dist/chartjs-plugin-annotation.min.js"></script>
    <style>
        /* Your styling here */
    </style>
</head>
<body>
    <h1>Kensington Health Data Portal</h1>
    <form method="POST" action="index.php">
        <label for="search_patient_id">Enter Patient ID to Search for Visits:</label><br>
        <input type="number" name="search_patient_id" id="search_patient_id" required>
        <button type="submit">Search</button>
    </form>

    <?php if ($search_patient_id && isset($result_patient)): ?>
        <?php if ($result_patient->num_rows > 0): ?>
            <h3>Visits for Patient ID: <?= $search_patient_id ?></h3>
            <table>
                <tr>
                    <th>Visit ID</th>
                    <th>Visit Date</th>
                    <th>Visit Notes</th>
                    <th>FAF (OD)</th>
                    <th>FAF (OS)</th>
                    <th>OCT (OD)</th>
                    <th>OCT (OS)</th>
                    <th>VF (OD)</th>
                    <th>VF (OS)</th>
                    <th>MFERG (OD)</th>
                    <th>MFERG (OS)</th>
                    <th>MERCI Left</th>
                    <th>MERCI Right</th>
                </tr>
                <?php while ($row = $result_patient->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row["visit_id"] ?></td>
                        <td><?= $row["visit_date"] ?></td>
                        <td><?= $row["visit_notes"] ?></td>
                        <td><a href="<?= $row["faf_reference_OD"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["faf_reference_OS"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["oct_reference_OD"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["oct_reference_OS"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["vf_reference_OD"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["vf_reference_OS"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["mferg_reference_OD"] ?>" target="_blank">View</a></td>
                        <td><a href="<?= $row["mferg_reference_OS"] ?>" target="_blank">View</a></td>
                        <td><?= $row["merci_rating_left_eye"] ?></td>
                        <td><?= $row["merci_rating_right_eye"] ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p>No visits found for Patient ID: <?= $search_patient_id ?></p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
