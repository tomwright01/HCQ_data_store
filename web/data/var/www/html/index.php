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

</body>
</html>

<?php
// Close connection
$conn->close();
?>
















