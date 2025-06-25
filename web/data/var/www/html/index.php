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
    <title>Kensington Health Data Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* General Page Styling */
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6; /* Light background for the page */
            color: #333; /* Dark text color */
        }

        h1 {
            text-align: center;
            font-size: 50px;
            color: #4CAF50; /* Green color */
            margin-top: 30px;
        }

        /* Patient Summary */
        .stats-summary {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h2 {
            font-size: 36px;
            font-family: 'Arial', sans-serif;
            margin-top: 30px;
            color: #333;
        }

        h3, h4 {
            text-align: center;
            font-size: 28px;
            font-family: 'Arial', sans-serif;
        }

        /* Chart Styling */
        canvas {
            max-width: 400px;
            max-height: 300px;
            width: 100%;
            height: auto;
            margin: 30px auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Section Styling */
        .section {
            margin: 40px 20px;
        }

        /* Button Styling */
        .btn {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 12px 24px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            border-radius: 8px;
            margin: 20px auto;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #45a049;
        }

        /* Footer Styling */
        footer {
            text-align: center;
            font-size: 16px;
            color: #777;
            margin-top: 50px;
        }
    </style>
</head>
<body>
    <h1>Kensington Health Data Portal</h1>

    <div class="section stats-summary">
        <h2>Patient Summary</h2>
        <p>Total number of patients: <strong><?php echo $total_patients; ?></strong></p>
        <p>Median age of patients: <strong><?php echo $median; ?> years</strong></p>
        <p>25th percentile age of patients: <strong><?php echo $percentile_25; ?> years</strong></p>
        <p>75th percentile age of patients: <strong><?php echo $percentile_75; ?> years</strong></p>
        <p>Number of males: <strong><?php echo isset($gender_data['m']) ? $gender_data['m'] : 0; ?></strong></p>
        <p>Number of females: <strong><?php echo isset($gender_data['f']) ? $gender_data['f'] : 0; ?></strong></p>
    </div>

    <h3>Total Patients by Location</h3>
    <ul style="text-align: center; list-style: none; padding: 0;">
        <?php
        // Display the count of patients by location (e.g., Halifax, Kensington, Montreal)
        foreach ($location_data as $location => $count) {
            echo "<li><strong>$location:</strong> $count patients</li>";
        }
        ?>
    </ul>

    <div class="section">
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
    </div>

    <div class="section">
        <h2>View Patient and Visit Data</h2>
        <p>Click the button below to view the full list of patients and their visits:</p>
        <a href="patient_visit.php" class="btn">View Patients and Visits</a>

        <h2>Add New Patient and Visit</h2>
        <p>Click the button below to add a new patient and visit:</p>
        <a href="form.php" class="btn">Go to the form</a>
    </div>

    <script>
        // Gender Distribution Chart
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
            data: genderData,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        backgroundColor: '#fff',
                        titleColor: '#333',
                        bodyColor: '#333',
                        borderColor: '#ddd',
                        borderWidth: 1
                    }
                }
            }
        });

        // Age Distribution Chart
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
            data: ageData,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        backgroundColor: '#fff',
                        titleColor: '#333',
                        bodyColor: '#333',
                        borderColor: '#ddd',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: { beginAtZero: true }
                }
            }
        });

        // Location Distribution Chart
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
            data: locationData,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        backgroundColor: '#fff',
                        titleColor: '#333',
                        bodyColor: '#333',
                        borderColor: '#ddd',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: { beginAtZero: true }
                }
            }
        });
    </script>
</body>
</html>

<?php
// Close connection
$conn->close();
?>















