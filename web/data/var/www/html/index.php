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

// Patient search functionality
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';

if ($search_patient_id) {
    // Query to get the patient data based on patient_id
    $sql_patient_data = "SELECT p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor, 
                         p.rx_OD, p.rx_OS, p.procedures_done, p.dosage, p.duration, p.cumulative_dosage, 
                         p.date_of_discontinuation, p.extra_notes, d.disease_name
                         FROM Patients p
                         LEFT JOIN Diseases d ON p.disease_id = d.disease_id
                         WHERE p.patient_id = $search_patient_id";

    $result_patient = $conn->query($sql_patient_data);
}

// Query to get visits related to the patient
$sql_visits = "SELECT visit_id, visit_date, visit_notes FROM Visits WHERE patient_id = $search_patient_id";
$result_visits = $conn->query($sql_visits);
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
            background-color: #f4f7f6;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            height: 100vh;
            background-image: url('https://via.placeholder.com/1500x1000');
            background-size: cover;
            background-position: center;
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

        .search-form {
            margin: 20px;
        }

        .search-form input {
            padding: 10px;
            width: 300px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .search-form button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .search-form button:hover {
            background-color: #45a049;
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

    <!-- Front Page Content -->
    <div class="content">
        <h1>Kensington Health Data Portal</h1>

        <!-- Search Patient Form -->
        <div class="search-form">
            <form method="POST" action="index.php">
                <label for="search_patient_id">Enter Patient ID to Search:</label><br>
                <input type="number" name="search_patient_id" id="search_patient_id" required>
                <button type="submit">Search</button>
            </form>
        </div>

        <?php
        // If the patient is found, display their information
        if ($search_patient_id && $result_patient->num_rows > 0) {
            $patient = $result_patient->fetch_assoc();
            echo "<h3>Patient Information</h3>";
            echo "<p>Patient ID: " . $patient['patient_id'] . "</p>";
            echo "<p>Location: " . $patient['location'] . "</p>";
            echo "<p>Disease: " . $patient['disease_name'] . "</p>";
            echo "<p>Year of Birth: " . $patient['year_of_birth'] . "</p>";
            echo "<p>Gender: " . $patient['gender'] . "</p>";
            echo "<p>Referring Doctor: " . $patient['referring_doctor'] . "</p>";
            echo "<p>RX OD: " . $patient['rx_OD'] . "</p>";
            echo "<p>RX OS: " . $patient['rx_OS'] . "</p>";

            // If visits exist, display them with links to view details
            if ($result_visits->num_rows > 0) {
                echo "<h3>Patient Visits</h3><table>";
                echo "<tr><th>Visit ID</th><th>Visit Date</th><th>Visit Notes</th><th>Actions</th></tr>";
                while ($visit = $result_visits->fetch_assoc()) {
                    echo "<tr>
                            <td>" . $visit['visit_id'] . "</td>
                            <td>" . $visit['visit_date'] . "</td>
                            <td>" . $visit['visit_notes'] . "</td>
                            <td><a href='view_visits.php?visit_id=" . $visit['visit_id'] . "'>View Visit Details</a></td>
                        </tr>";
                }
                echo "</table>";
            } else {
                echo "<p>No visits found for this patient.</p>";
            }
        }
        ?>

        <h2><a href="form.php">Add New Patient and Visit</a></h2>
    </div>

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
        <h2>Kensington Health Patient Metrics</h2>
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













