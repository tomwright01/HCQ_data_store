<?php
require_once 'includes/functions.php';

$stats = getPatientStatistics();

// Handle patient search
$search_results = [];
if (isset($_GET['search'])) {
    $search_term = $_GET['search'];
    $stmt = $conn->prepare("SELECT * FROM Patients WHERE patient_id = ? OR referring_doctor LIKE ?");
    $search_param = "%$search_term%";
    $stmt->bind_param("is", $search_term, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $search_results = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kensington Health Data Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <img src="assets/images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">
        <h1>Kensington Health Data Portal</h1>
    </header>

    <main class="container">
        <section class="search-section">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Search by Patient ID or Doctor..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                <button type="submit">Search</button>
            </form>
            
            <?php if (!empty($search_results)): ?>
                <div class="search-results">
                    <h3>Search Results</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Patient ID</th>
                                <th>Location</th>
                                <th>Disease</th>
                                <th>Year of Birth</th>
                                <th>Gender</th>
                                <th>Referring Doctor</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $patient): ?>
                                <tr>
                                    <td><?= $patient['patient_id'] ?></td>
                                    <td><?= $patient['location'] ?></td>
                                    <td><?= getDiseaseName($patient['disease_id']) ?></td>
                                    <td><?= $patient['year_of_birth'] ?></td>
                                    <td><?= $patient['gender'] == 'm' ? 'Male' : 'Female' ?></td>
                                    <td><?= $patient['referring_doctor'] ?></td>
                                    <td>
                                        <a href="view_visits.php?patient_id=<?= $patient['patient_id'] ?>">View Visits</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="stats-section">
            <h2>Patient Statistics</h2>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Patients</h3>
                    <div class="stat-value"><?= $stats['total_patients'] ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Median Age</h3>
                    <div class="stat-value"><?= $stats['median_age'] ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Gender Distribution</h3>
                    <div class="chart-container">
                        <canvas id="genderChart"></canvas>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Location Distribution</h3>
                    <div class="chart-container">
                        <canvas id="locationChart"></canvas>
                    </div>
                </div>
            </div>
        </section>

        <section class="recent-patients">
            <h2>Recent Patients</h2>
            <table>
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Location</th>
                        <th>Disease</th>
                        <th>Age</th>
                        <th>Gender</th>
                        <th>Referring Doctor</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $recent_patients = getAllPatients();
                    foreach (array_slice($recent_patients, 0, 5) as $patient): 
                    ?>
                        <tr>
                            <td><?= $patient['patient_id'] ?></td>
                            <td><?= $patient['location'] ?></td>
                            <td><?= getDiseaseName($patient['disease_id']) ?></td>
                            <td><?= date('Y') - $patient['year_of_birth'] ?></td>
                            <td><?= $patient['gender'] == 'm' ? 'Male' : 'Female' ?></td>
                            <td><?= $patient['referring_doctor'] ?></td>
                            <td>
                                <a href="view_visits.php?patient_id=<?= $patient['patient_id'] ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>

    <footer>
        <a href="form.php" class="button">Add New Patient</a>
    </footer>

    <script>
        // Gender Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        const genderChart = new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= $stats['gender']['m'] ?? 0 ?>, <?= $stats['gender']['f'] ?? 0 ?>],
                    backgroundColor: ['#36a2eb', '#ff6384'],
                }]
            }
        });

        // Location Chart
        const locationCtx = document.getElementById('locationChart').getContext('2d');
        const locationChart = new Chart(locationCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($stats['location'])) ?>,
                datasets: [{
                    label: 'Patients',
                    data: <?= json_encode(array_values($stats['location'])) ?>,
                    backgroundColor: '#4CAF50',
                }]
            }
        });
    </script>
</body>
</html>
