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
$sql_total_patients = "SELECT COUNT(*) AS total_patients FROM patients";
$result_total_patients = $conn->query($sql_total_patients);
$row_total_patients = $result_total_patients->fetch_assoc();
$total_patients = $row_total_patients['total_patients'];

// Query to get age statistics from date_of_birth
$sql_age_stats = "SELECT 
    TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS age 
    FROM patients";
$result_age_stats = $conn->query($sql_age_stats);

$ages = [];
while ($row_age = $result_age_stats->fetch_assoc()) {
    $ages[] = $row_age['age'];
}

// Calculate age statistics
sort($ages);
$median = !empty($ages) ? $ages[floor(count($ages)/2)] : 0;
$percentile_25 = !empty($ages) ? $ages[floor(count($ages)*0.25)] : 0;
$percentile_75 = !empty($ages) ? $ages[floor(count($ages)*0.75)] : 0;

// Query to get diagnosis distribution
$sql_diagnosis = "SELECT 
    merci_diagnosis AS diagnosis,
    COUNT(*) AS count 
    FROM tests 
    GROUP BY merci_diagnosis";
$result_diagnosis = $conn->query($sql_diagnosis);
$diagnosis_data = [];
while ($row = $result_diagnosis->fetch_assoc()) {
    $diagnosis_data[$row['diagnosis']] = $row['count'];
}

// Query to get eye distribution
$sql_eye = "SELECT 
    IFNULL(eye, 'Not Specified') AS eye, 
    COUNT(*) AS count 
    FROM tests 
    GROUP BY eye";
$result_eye = $conn->query($sql_eye);
$eye_data = [];
while ($row = $result_eye->fetch_assoc()) {
    $eye_data[$row['eye']] = $row['count'];
}

// Query to get exclusion reasons
$sql_exclusion = "SELECT 
    exclusion, 
    COUNT(*) AS count 
    FROM tests 
    GROUP BY exclusion";
$result_exclusion = $conn->query($sql_exclusion);
$exclusion_data = [];
while ($row = $result_exclusion->fetch_assoc()) {
    $exclusion_data[$row['exclusion']] = $row['count'];
}

// Patient search functionality
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';

if ($search_patient_id) {
    $sql_patient_data = "SELECT 
        t.test_id, 
        t.date_of_test, 
        t.age,
        t.eye,
        t.report_diagnosis,
        t.exclusion,
        t.merci_score,
        t.merci_diagnosis,
        t.error_type,
        t.faf_grade,
        t.oct_score,
        t.vf_score,
        t.faf_reference_od,
        t.faf_reference_os,
        t.oct_reference_od,
        t.oct_reference_os,
        t.vf_reference_od,
        t.vf_reference_os,
        t.mferg_reference_od,
        t.mferg_reference_os,
        p.patient_id, 
        p.subject_id, 
        p.date_of_birth
        FROM tests t
        JOIN patients p ON t.patient_id = p.patient_id
        WHERE p.patient_id = ?";
    
    $stmt = $conn->prepare($sql_patient_data);
    $stmt->bind_param("s", $search_patient_id);
    $stmt->execute();
    $result_patient = $stmt->get_result();
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
            margin-bottom: 20px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-button {
            padding: 12px 25px;
            font-size: 16px;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .import-button {
            background-color: rgb(76, 175, 80);
        }
        
        .import-button:hover {
            background-color: rgb(69, 160, 73);
        }
        
        .image-button {
            background-color: rgb(33, 150, 243);
        }
        
        .image-button:hover {
            background-color: rgb(30, 136, 229);
        }
        
        .form-button {
            background-color: rgb(0, 168, 143);
        }
        
        .form-button:hover {
            background-color: rgb(0, 140, 120);
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

        .data-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .data-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            width: 280px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .data-card h3 {
            color: rgb(0, 168, 143);
            margin-top: 0;
        }

        .data-value {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }

        .image-link {
            color: rgb(0, 168, 143);
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s;
        }

        .image-link:hover {
            color: rgb(0, 140, 120);
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <img src="images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">

    <div class="content">
        <h1>Kensington Health Data Portal</h1>
        
        <div class="action-buttons">
            <a href="form.php" class="action-button form-button">Manual Data Entry</a>
            <a href="csv_import.php" class="action-button import-button">Upload Patient Data (CSV)</a>
            <a href="import_images.php" class="action-button image-button">Import Medical Images</a>
        </div>

        <div class="search-form">
            <form method="POST" action="index.php">
                <label for="search_patient_id">Enter Patient ID to Search for Tests:</label><br>
                <input type="text" name="search_patient_id" id="search_patient_id" required>
                <button type="submit">Search</button>
            </form>
        </div>

        <?php if ($search_patient_id && isset($result_patient)): ?>
            <?php if ($result_patient->num_rows > 0): ?>
                <h3>Tests for Patient ID: <?= htmlspecialchars($search_patient_id) ?></h3>
                <table>
                    <tr>
                        <th>Test ID</th>
                        <th>Date</th>
                        <th>Age</th>
                        <th>Eye</th>
                        <th>Report Diagnosis</th>
                        <th>Exclusion</th>
                        <th>MERCI Score</th>
                        <th>MERCI Diagnosis</th>
                        <th>Error Type</th>
                        <th>FAF Grade</th>
                        <th>OCT Score</th>
                        <th>VF Score</th>
                        <th>Images</th>
                    </tr>
                    <?php while ($row = $result_patient->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row["test_id"]) ?></td>
                            <td><?= htmlspecialchars($row["date_of_test"]) ?></td>
                            <td><?= htmlspecialchars($row["age"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["eye"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["report_diagnosis"]) ?></td>
                            <td><?= htmlspecialchars($row["exclusion"]) ?></td>
                            <td><?= htmlspecialchars($row["merci_score"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["merci_diagnosis"]) ?></td>
                            <td><?= htmlspecialchars($row["error_type"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["faf_grade"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["oct_score"] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row["vf_score"] ?? 'N/A') ?></td>
                            <td>
                                <?php 
                                $imageLinks = [];
                                if (!empty($row['faf_reference_od'])) $imageLinks[] = '<a href="view_faf.php?ref='.htmlspecialchars($row['faf_reference_od']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OD" class="image-link">FAF OD</a>';
                                if (!empty($row['faf_reference_os'])) $imageLinks[] = '<a href="view_faf.php?ref='.htmlspecialchars($row['faf_reference_os']).'&patient_id='.htmlspecialchars($row['patient_id']).'&eye=OS" class="image-link">FAF OS</a>';
                                if (!empty($row['oct_reference_od'])) $imageLinks[] = '<a href="#" class="image-link">OCT OD</a>';
                                if (!empty($row['oct_reference_os'])) $imageLinks[] = '<a href="#" class="image-link">OCT OS</a>';
                                if (!empty($row['vf_reference_od'])) $imageLinks[] = '<a href="#" class="image-link">VF OD</a>';
                                if (!empty($row['vf_reference_os'])) $imageLinks[] = '<a href="#" class="image-link">VF OS</a>';
                                if (!empty($row['mferg_reference_od'])) $imageLinks[] = '<a href="#" class="image-link">MFERG OD</a>';
                                if (!empty($row['mferg_reference_os'])) $imageLinks[] = '<a href="#" class="image-link">MFERG OS</a>';
                                
                                echo $imageLinks ? implode(' | ', $imageLinks) : 'No images';
                                ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p>No tests found for Patient ID: <?= htmlspecialchars($search_patient_id) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="stats-section">
        <div class="chart-container">
            <h3 class="chart-title">Diagnosis Distribution</h3>
            <canvas id="diagnosisChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Eye Distribution</h3>
            <canvas id="eyeChart"></canvas>
        </div>

        <div class="chart-container">
            <h3 class="chart-title">Exclusion Reasons</h3>
            <canvas id="exclusionChart"></canvas>
        </div>
    </div>

    <div class="data-section">
        <div class="data-card">
            <h3>Total Patients</h3>
            <div class="data-value"><?= $total_patients ?></div>
        </div>
        
        <div class="data-card">
            <h3>Median Age</h3>
            <div class="data-value"><?= round($median) ?></div>
        </div>
        
        <div class="data-card">
            <h3>25th Percentile Age</h3>
            <div class="data-value"><?= round($percentile_25) ?></div>
        </div>
        
        <div class="data-card">
            <h3>75th Percentile Age</h3>
            <div class="data-value"><?= round($percentile_75) ?></div>
        </div>
    </div>

    <div class="metric-bar-container">
        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($total_patients / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= $total_patients ?> Patients</div>
        </div>

        <div class="metric-bar">
            <div class="metric-fill" style="width: <?= min(($median / 100) * 100, 100) ?>%"></div>
            <div class="metric-value"><?= round($median) ?> Median Age</div>
        </div>
    </div>

    <script>
        // Diagnosis Distribution Chart
        var diagnosisCtx = document.getElementById('diagnosisChart').getContext('2d');
        var diagnosisChart = new Chart(diagnosisCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($diagnosis_data)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($diagnosis_data)) ?>,
                    backgroundColor: [
                        'rgb(0, 168, 143)',
                        'rgb(0, 100, 80)',
                        'rgb(200, 200, 200)'
                    ],
                    borderColor: '#fff',
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

        // Eye Distribution Chart
        var eyeCtx = document.getElementById('eyeChart').getContext('2d');
        var eyeChart = new Chart(eyeCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($eye_data)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($eye_data)) ?>,
                    backgroundColor: [
                        'rgb(0, 168, 143)',
                        'rgb(0, 100, 80)',
                        'rgb(200, 200, 200)'
                    ],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });

        // Exclusion Reasons Chart
        var exclusionCtx = document.getElementById('exclusionChart').getContext('2d');
        var exclusionChart = new Chart(exclusionCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($exclusion_data)) ?>,
                datasets: [{
                    label: 'Count',
                    data: <?= json_encode(array_values($exclusion_data)) ?>,
                    backgroundColor: 'rgb(0, 168, 143)',
                    borderColor: 'rgb(0, 140, 120)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
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
