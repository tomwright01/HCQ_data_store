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

// Query to get average age of patients
$sql_age = "SELECT AVG(YEAR(CURRENT_DATE) - year_of_birth) AS average_age FROM Patients";
$result_age = $conn->query($sql_age);
$row_age = $result_age->fetch_assoc();
$average_age = $row_age['average_age'];

// Query to get the count of males and females
$sql_gender = "SELECT gender, COUNT(*) AS count FROM Patients GROUP BY gender";
$result_gender = $conn->query($sql_gender);
$gender_data = [];
while ($row_gender = $result_gender->fetch_assoc()) {
    $gender_data[$row_gender['gender']] = $row_gender['count'];
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
    <p>Average age of patients: <?php echo round($average_age, 2); ?> years</p>
    <p>Number of males: <?php echo isset($gender_data['m']) ? $gender_data['m'] : 0; ?></p>
    <p>Number of females: <?php echo isset($gender_data['f']) ? $gender_data['f'] : 0; ?></p>
    
    <h2>Add New Patient and Visit</h2>
    <p>Click below to add a new patient and visit:</p>
    <a href="form.php">Go to the form</a>

</body>
</html>

<?php
// Close connection
$conn->close();
?>






