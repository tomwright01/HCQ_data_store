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

// Patient search functionality
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';

if ($search_patient_id) {
    $search_patient_id = (int)$search_patient_id;
    if ($search_patient_id > 0) {
        // Modified query to use the new database structure
        $sql_patient_data = "SELECT 
                v.visit_id, 
                v.visit_date, 
                v.visit_notes,
                v.merci_rating_left_eye, 
                v.merci_rating_right_eye,
                p.patient_id, 
                p.location, 
                p.disease_id, 
                p.year_of_birth, 
                p.gender, 
                p.referring_doctor
            FROM Visits v
            LEFT JOIN Patients p ON v.patient_id = p.patient_id
            WHERE p.patient_id = $search_patient_id";

        $result_patient = $conn->query($sql_patient_data);
        
        // Get all test results for the patient
        $sql_test_results = "SELECT 
                tr.result_id,
                tt.test_name,
                es.side_name,
                v.visit_date
            FROM TestResults tr
            JOIN Visits v ON tr.visit_id = v.visit_id
            JOIN TestTypes tt ON tr.test_type_id = tt.test_type_id
            JOIN EyeSides es ON tr.side_id = es.side_id
            WHERE v.patient_id = $search_patient_id
            ORDER BY v.visit_date, tt.test_name, es.side_name";
            
        $result_tests = $conn->query($sql_test_results);
        $test_results = [];
        
        while ($row_test = $result_tests->fetch_assoc()) {
            $test_results[] = $row_test;
        }
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
            min-height: 100vh;
            background-image: url('https://via.placeholder.com/1500x1000');
            background-size: cover;
            background-position: center;
        }

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

        /* Image gallery styles */
        .image-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .image-card {
            width: 200px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .image-card:hover {
            transform: scale(1.05);
        }

        .image-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .image-info {
            padding: 10px;
            background: #f9f9f9;
        }

        .image-info p {
            margin: 5px 0;
            font-size: 14px;
        }

        .test-section {
            margin-top: 30px;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
        }

        .test-title {
            color: #4CAF50;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">

    <div class="content">
        <h1>Kensington Health Data Portal</h1>

        <div class="search-form">
            <form method="POST" action="index.php">
                <label for="search_patient_id">Enter Patient ID to Search for Visits:</label><br>
                <input type="number" name="search_patient_id" id="search_patient_id" required>
                <button type="submit">Search</button>
            </form>
        </div>

        <?php if ($search_patient_id && isset($result_patient)): ?>
            <?php if ($result_patient->num_rows > 0): ?>
                <h3>Visits for Patient ID: <?= $search_patient_id ?></h3>
                <table>
                    <tr>
                        <th>Visit ID</th>
                        <th>Visit Date</th>
                        <th>Visit Notes</th>
                        <th>MERCI Left</th>
                        <th>MERCI Right</th>
                    </tr>
                    <?php while ($row = $result_patient->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row["visit_id"] ?></td>
                            <td><?= $row["visit_date"] ?></td>
                            <td><?= $row["visit_notes"] ?></td>
                            <td><?= $row["merci_rating_left_eye"] ?></td>
                            <td><?= $row["merci_rating_right_eye"] ?></td>
                        </tr>
                    <?php endwhile; ?>
                </table>

                <?php if (!empty($test_results)): ?>
                    <div class="test-section">
                        <h3 class="test-title">Patient Test Results</h3>
                        <div class="image-gallery">
                            <?php foreach ($test_results as $test): 
                                $image_path = "./SAMPLE/{$search_patient_id}_{$test['side_name']}_" . 
                                    date('Ymd', strtotime($test['visit_date'])) . "_" . 
                                    strtolower($test['test_name']) . ".png";
                            ?>
                                <div class="image-card">
                                    <a href="<?= $image_path ?>" target="_blank">
                                        <img src="<?= $image_path ?>" alt="<?= $test['test_name'] ?> Image" 
                                            onerror="this.src='images/no-image.png';this.onerror=null;">
                                    </a>
                                    <div class="image-info">
                                        <p><strong><?= $test['test_name'] ?></strong></p>
                                        <p>Eye: <?= $test['side_name'] ?></p>
                                        <p>Date: <?= date('M j, Y', strtotime($test['visit_date'])) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p>No test results found for this patient.</p>
                <?php endif; ?>
            <?php else: ?>
                <p>No visits found for Patient ID: <?= $search_patient_id ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <h2><a href="form.php">Add New Patient and Visit</a></h2>
    </div>

    <div class="stats-section">
        <div class="chart-container">
            <h3 class="chart-title">Gender Distribution</h3>
            <canvas id="genderChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Location Distribution</h3>
            <canvas id="locationChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Age Distribution</h3>
            <canvas id="ageChart"></canvas>
        </div>
    </div>

    <div class="metric-bar-container">
        <div class="metric-bar" style="width: <?= min(($total_patients / 100) * 100, 100) ?>%">
            <div class="metric-value"><?= $total_patients ?> Patients</div>
        </div>

        <div class="metric-bar" style="width: <?= min(($median / 100) * 100, 100) ?>%">
            <div class="metric-value"><?= $median ?> Median Age</div>
        </div>

        <div class="metric-bar" style="width: <?= min(($percentile_25 / 100) * 100, 100) ?>%">
            <div class="metric-value"><?= $percentile_25 ?> 25th Percentile Age</div>
        </div>

        <div class="metric-bar" style="width: <?= min(($percentile_75 / 100) * 100, 100) ?>%">
            <div class="metric-value"><?= $percentile_75 ?> 75th Percentile Age</div>
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
                    data: [<?= $gender_data['m'] ?? 0 ?>, <?= $gender_data['f'] ?? 0 ?>],
                    backgroundColor: ['#36a2eb', '#ff6384'],
                    borderColor: ['#36a2eb', '#ff6384'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
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
                labels: <?= json_encode(array_keys($location_data)) ?>,
                datasets: [{
                    label: 'Patients by Location',
                    data: <?= json_encode(array_values($location_data)) ?>,
                    backgroundColor: '#4CAF50',
                    borderColor: '#4CAF50',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
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
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        // Age Distribution Histogram
        var ageCtx = document.getElementById('ageChart').getContext('2d');
        
        <?php
        $min_age = min($ages);
        $max_age = max($ages);
        $bin_size = 5;
        $bins = [];
        $labels = [];
        
        for ($i = $min_age - ($min_age % $bin_size); $i <= $max_age; $i += $bin_size) {
            $labels[] = $i . '-' . ($i + $bin_size - 1);
            $bins[] = 0;
        }
        
        foreach ($ages as $age) {
            $bin_index = floor(($age - $min_age) / $bin_size);
            $bins[$bin_index]++;
        }
        ?>
        
        var ageChart = new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                    label: 'Number of Patients',
                    data: <?= json_encode($bins) ?>,
                    backgroundColor: 'rgba(76, 175, 80, 0.6)',
                    borderColor: 'rgba(76, 175, 80, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' patients';
                            }
                        }
                    },
                    annotation: {
                        annotations: {
                            line25: {
                                type: 'line',
                                yMin: 0,
                                yMax: <?= max($bins) ?>,
                                xMin: (<?= $percentile_25 ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                xMax: (<?= $percentile_25 ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                borderColor: '#ff9800',
                                borderWidth: 2,
                                label: {
                                    content: '25th: <?= $percentile_25 ?>',
                                    enabled: true,
                                    position: 'top'
                                }
                            },
                            lineMedian: {
                                type: 'line',
                                yMin: 0,
                                yMax: <?= max($bins) ?>,
                                xMin: (<?= $median ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                xMax: (<?= $median ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                borderColor: '#f44336',
                                borderWidth: 2,
                                label: {
                                    content: 'Median: <?= $median ?>',
                                    enabled: true,
                                    position: 'top'
                                }
                            },
                            line75: {
                                type: 'line',
                                yMin: 0,
                                yMax: <?= max($bins) ?>,
                                xMin: (<?= $percentile_75 ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                xMax: (<?= $percentile_75 ?> - <?= $min_age ?>) / <?= $bin_size ?>,
                                borderColor: '#2196f3',
                                borderWidth: 2,
                                label: {
                                    content: '75th: <?= $percentile_75 ?>',
                                    enabled: true,
                                    position: 'top'
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Age Range'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Patients'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>

