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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Ensure the charts are responsive */
        canvas {
            max-width: 300px; /* You can adjust the width to your needs */
            max-height: 200px; /* Set max height for consistent sizing */
            width: 100%; /* Makes the chart responsive */
            height: auto; /* Adjust height automatically */
        }
    </style>
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

    <h2>Graphs</h2>
    <div>
        <canvas id="genderChart"></canvas>
    </div>

    <div>
        <canvas id="ageChart"></canvas>
    </div>

    <div>
        <canvas id="locationChart"></canvas>
    </div>

    <h2>View Patient and Visit Data</h2>
    <p>Click below to view the full list of patients and their visits:</p>
    <a href="patient_visit.php">View Patients and Visits</a>

    <h2>Add New Patient and Visit</h2>
    <p>Click below to add a new patient and visit:</p>
    <a href="form.php">Go to the form</a>

    <script>
        // Gender Chart
        var genderData = {
            labels: ['Male', 'Female'],
            datasets: [{
                label: 'Gender Distribution',
                data: [<?php echo isset($gender_data['m']) ? $gender_data['m'] : 0; ?>, <?php echo isset($gender_data['f']) ? $gender_data['f'] : 0; ?>],
                backgroundColor: ['#36a2eb', '#ff6384'],
                borderColor: ['#36a2eb', '#ff6384'],
                borderWidth: 1
            }]
        };

        var ctx1 = document.getElementById('genderChart').getContext('2d');
        new Chart(ctx1, {
            type: 'pie',
            data: genderData
        });

        // Age Chart (Median, Percentiles)
        var ageData = {
            labels: ['Median', '25th Percentile', '75th Percentile'],
            datasets: [{
                label: 'Age Distribution',
                data: [<?php echo $median; ?>, <?php echo $percentile_25; ?>, <?php echo $percentile_75; ?>],
                backgroundColor: ['#ffcd56', '#ff9f40', '#ff5733'],
                borderColor: ['#ffcd56', '#ff9f40', '#ff5733'],
                borderWidth: 1
            }]
        };

        var ctx2 = document.getElementById('ageChart').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: ageData
        });

        // Location Chart (Total by Location)
        var locationData = {
            labels: <?php echo json_encode(array_keys($location_data)); ?>,
            datasets: [{
                label: 'Patients by Location',
                data: <?php echo json_encode(array_values($location_data)); ?>,
                backgroundColor: '#36a2eb',
                borderColor: '#36a2eb',
                borderWidth: 1
            }]
        };

        var ctx3 = document.getElementById('locationChart').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: locationData
        });
    </script>
</body>
</html>

<?php
// Close connection
$conn->close();
?>













