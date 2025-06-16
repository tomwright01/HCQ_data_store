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

// Query to get total number of patients
$sql = "SELECT COUNT(*) AS total_patients FROM Patients";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$total_patients = $row['total_patients'];

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

// Query to get the count of males and females
$sql_gender = "SELECT gender, COUNT(*) AS count FROM Patients GROUP BY gender";
$result_gender = $conn->query($sql_gender);
$gender_data = [];
while ($row_gender = $result_gender->fetch_assoc()) {
    $gender_data[$row_gender['gender']] = $row_gender['count'];
}

// Query to get the count of patients by location
$sql_location = "SELECT location, COUNT(*) AS count FROM Patients GROUP BY location";
$result_location = $conn->query($sql_location);
$location_data = [];
while ($row_location = $result_location->fetch_assoc()) {
    $location_data[$row_location['location']] = $row_location['count'];
}

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
    <p>Median age of patients: <?php echo $median; ?> years</p>
    <p>25th percentile age of patients: <?php echo $percentile_25; ?> years</p>
    <p>75th percentile age of patients: <?php echo $percentile_75; ?> years</p>
    <p>Number of males: <?php echo isset($gender_data['m']) ? $gender_data['m'] : 0; ?></p>
    <p>Number of females: <?php echo isset($gender_data['f']) ? $gender_data['f'] : 0; ?></p>

    <h3>Total Patients by Location</h3>
    <ul>
        <?php
        // Display the count of patients by location (e.g., Halifax, Kensington, Montreal)
        foreach ($location_data as $location => $count) {
            echo "<li>$location: $count patients</li>";
        }
        ?>
    </ul>

    <h2>View Patient and Visit Data</h2>
    <p>Click below to view the full list of patients and their visits:</p>
    <a href="patients_visits.php">View Patients and Visits</a>

    <h2>Add New Patient and Visit</h2>
    <p>Click below to add a new patient and visit:</p>
    <a href="form.php">Go to the form</a>

</body>
</html>

<?php
// Close connection
$conn->close();
?>







