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

// Patient search functionality with sanitization
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';

if ($search_patient_id) {
    // Sanitize the search input to prevent SQL injection
    $search_patient_id = (int)$search_patient_id; // Casting ensures it's an integer

    if ($search_patient_id > 0) {
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
    } else {
        // Handle invalid patient ID
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

        /* Logo Styling */
        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 150px;
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

        /* Styling for the static bars */
        .metric-bar-container {
            margin-top: 30px;
            width: 80%;
            max-width: 600px;
        }

        .metric-bar {
            width: 100%;
            background-color: #ddd;
            height: 30px;
            margin: 10px 0;
            border-radius: 5px;
            position: relative;
        }

        .metric-bar .metric-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 16px;
            color: #fff;
        }

        /* Chart container styling */
        .chart-container {
            width: 100%;
            max-width: 600px;
            margin: 20px auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .chart-title {
            text-align: center;
            color: #4CAF50;
            margin-bottom: 15px;
        }

        .stats-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }
    </style>
</head>
<body>

    <!-- Kensington Clinic Logo -->
    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">

    <!-- Front Page Content -->
    <div class="content">
        <h1>Kensington Health Data Portal</h1>

        <!-- Search Patient Form -->
        <div class="search-form">
            <form method="POST" action="index.php">
                <label for="patient_id">Enter Patient ID to Search for Visits:</label><br>
                <input type="number" name="search_patient_id" id="search_patient_id" required>
                <button type="submit">Search</button>
            </form>
        </div>

        <?php
        // Display patient and visit data if search was made
        if ($search_patient_id && isset($result_patient)) {
            if ($result_patient->num_rows > 0) {
                echo "<h3>Visits for Patient ID: $search_patient_id</h3>";
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
                while ($row = $result_patient->fetch_assoc()) {
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
                }
                echo "</table>";
            } else {
                echo "<p>No visits found for Patient ID: $search_patient_id</p>";
            }
        }
        ?>

        <h2><a href="form.php">Add New Patient and Visit</a></h2>
    </div>

    <!-- Statistics Section with Charts -->
    <div class="stats-section">
        <!-- Gender Distribution Chart -->
        <div class="chart-container">
            <h3 class="chart-title">Gender Distribution</h3>
            <canvas id="genderChart"></canvas>
        </div>

        <!-- Location Distribution Chart -->
        <div class="chart-container">
            <h3 class="chart-title">Location Distribution</h3>
            <canvas id="locationChart"></canvas>
        </div>

        <!-- Age Distribution Chart -->
        <div class="chart-container">
            <h3 class="chart-title">Age Distribution</h3>
            <canvas id="ageChart"></canvas>
        </div>
    </div>

    <!-- Patient Metrics Section with Static Bars -->
    <div class="metric-bar-container">
        <div class="metric-bar" style="width: <?php echo min(($total_patients / 100) * 100, 100); ?>%">
            <div class="metric-value"><?php echo $total_patients; ?> Patients</div>
        </div>

        <div class="metric-bar" style="width: <?php echo min(($median / 100) * 100, 100); ?>%">
            <div class="metric-value"><?php echo $median; ?> Median Age</div>
        </div>

        <div class="metric-bar" style="width: <?php echo min(($percentile_25 / 100) * 100, 100); ?>%">
            <div class="metric-value"><?php echo $percentile_25; ?> 25th Percentile Age</div>
        </div>

        <div class="metric-bar" style="width: <?php echo min(($percentile_75 / 100) * 100, 100); ?>%">
            <div class="metric-value"><?php echo $percentile_75; ?> 75th Percentile Age</div>
        </div>
    </div>

    <script>
        // Gender Distribution Chart
        var genderCtx = document.getElementById('genderChart').getContext('2d');
        var genderChart = new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?php echo $gender_data['m'] ?? 0; ?>, <?php echo $gender_data['f'] ?? 0; ?>],
                    backgroundColor: ['#36a2eb', '#ff6384'],
                    borderColor: ['#36a2eb', '#ff6384'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var label = context.label || '';
                                var value = context.raw || 0;
                                var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                var percentage = Math.round((value / total) * 100);
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Location Distribution Chart
        var locationCtx = document.getElementById('locationChart').getContext('2d');
        var locationChart = new Chart(locationCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($location_data)); ?>,
                datasets: [{
                    label: 'Patients by Location',
                    data: <?php echo json_encode(array_values($location_data)); ?>,
                    backgroundColor: '#4CAF50',
                    borderColor: '#4CAF50',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' patients';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Age Distribution Chart
        var ageCtx = document.getElementById('ageChart').getContext('2d');
        var ageChart = new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: ['25th Percentile', 'Median', '75th Percentile'],
                datasets: [{
                    label: 'Age Distribution',
                    data: [<?php echo $percentile_25; ?>, <?php echo $median; ?>, <?php echo $percentile_75; ?>],
                    backgroundColor: ['#ffcd56', '#4CAF50', '#36a2eb'],
                    borderColor: ['#ffcd56', '#4CAF50', '#36a2eb'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Age: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: Math.max(0, <?php echo min($ages); ?> - 5),
                        max: Math.min(100, <?php echo max($ages); ?> + 5)
                    }
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
