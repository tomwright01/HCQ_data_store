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
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: white;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            min-height: 100vh;
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
            border: 1px solid #ddd;
        }

        h1 {
            font-size: 36px;
            color: rgb(0, 168, 143);
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
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-form button:hover {
            background-color: rgb(0, 140, 120);
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
            background-color: rgb(0, 168, 143);
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        td a {
            color: rgb(0, 168, 143);
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        td a:hover {
            color: rgb(0, 140, 120);
            text-decoration: underline;
        }

        .metric-bar-container {
            margin-top: 30px;
            width: 80%;
            max-width: 600px;
        }

        .metric-bar {
            width: 100%;
            background-color: #eee;
            height: 30px;
            margin: 10px 0;
            border-radius: 5px;
            position: relative;
            overflow: hidden;
            transition: width 1s ease;
            border: 1px solid #ddd;
        }

        .metric-bar .metric-fill {
            height: 100%;
            background-color: rgb(0, 168, 143);
            width: 0;
            transition: width 1s ease;
        }

        .metric-bar .metric-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 16px;
            color: #fff;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.5);
        }

        .chart-container {
            width: 100%;
            max-width: 600px;
            margin: 20px auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: 1px solid #ddd;
        }

        .chart-title {
            text-align: center;
            color: rgb(0, 168, 143);
            margin-bottom: 15px;
        }

        .stats-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }

        .patient-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin: 10px;
            width: 300px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .patient-card h3 {
            color: rgb(0, 168, 143);
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .patient-card p {
            margin: 8px 0;
        }

        .viewer-link {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 15px;
            background: rgb(0, 168, 143);
            color: white;
            border-radius: 4px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .viewer-link:hover {
            background: rgb(0, 140, 120);
        }

        a {
            color: rgb(0, 168, 143);
            text-decoration: none;
            transition: color 0.3s;
        }

        a:hover {
            color: rgb(0, 140, 120);
            text-decoration: underline;
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
                            <td><a href="view_faf.php?ref=<?= urlencode($row["faf_reference_OD"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OD" target="_blank">View</a></td>
                            <td><a href="view_faf.php?ref=<?= urlencode($row["faf_reference_OS"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OS" target="_blank">View</a></td>
                            <td><a href="view_oct.php?ref=<?= urlencode($row["oct_reference_OD"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OD" target="_blank" class="viewer-link">View</a></td>
                            <td><a href="view_oct.php?ref=<?= urlencode($row["oct_reference_OS"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OS" target="_blank" class="viewer-link">View</a></td>
                            <td><a href="view_vf.php?ref=<?= urlencode($row["vf_reference_OD"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OD" target="_blank" class="viewer-link">View</a></td>
                            <td><a href="view_vf.php?ref=<?= urlencode($row["vf_reference_OS"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OS" target="_blank" class="viewer-link">View</a></td>
                            <td><a href="view_mferg.php?ref=<?= urlencode($row["mferg_reference_OD"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OD" target="_blank" class="viewer-link">View</a></td>
                            <td><a href="view_mferg.php?ref=<?= urlencode($row["mferg_reference_OS"]) ?>&patient_id=<?= $row['patient_id'] ?>&eye=OS" target="_blank" class="viewer-link">View</a></td>
                            <td><?= $row["merci_rating_left_eye"] ?></td>
                            <td><?= $row["merci_rating_right_eye"] ?></td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p>No visits found for Patient ID: <?= $search_patient_id ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <h2><a href="form.php" style="color: rgb(0, 168, 143); text-decoration: none; font-weight: bold;">Add New Patient and Visit</a></h2>
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
        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($total_patients / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= $total_patients ?> Patients</div>
        </div>

        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($median / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= $median ?> Median Age</div>
        </div>

        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($percentile_25 / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= $percentile_25 ?> 25th Percentile Age</div>
        </div>

        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($percentile_75 / 100) * 100, 100) ?>%"></div>
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
                    backgroundColor: ['rgb(0, 168, 143)', 'rgb(0, 100, 80)'],
                    borderColor: ['#fff', '#fff'],
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
                    backgroundColor: 'rgb(0, 168, 143)',
                    borderColor: 'rgb(0, 140, 120)',
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
                    backgroundColor: 'rgba(0, 168, 143, 0.6)',
                    borderColor: 'rgba(0, 140, 120, 1)',
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

        // Animate metric bars on page load
        document.addEventListener('DOMContentLoaded', function() {
            const metricBars = document.querySelectorAll('.metric-fill');
            metricBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>
