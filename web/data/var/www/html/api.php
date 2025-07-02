
<?php
/*
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // This is to allow any domain to access the API

// Database connection
$servername = "localhost";
$username = "root";
$password = "notgood";
$dbname = "PatientData"; // Replace with your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get all patients
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['id'])) {
        $patient_id = $_GET['id'];
        $sql = "SELECT * FROM Patients WHERE patient_id = $patient_id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $patient = $result->fetch_assoc();
            echo json_encode($patient);
        } else {
            echo json_encode(["message" => "Patient not found"]);
        }
    } else {
        $sql = "SELECT * FROM Patients";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $patients = [];
            while ($row = $result->fetch_assoc()) {
                $patients[] = $row;
            }
            echo json_encode($patients);
        } else {
            echo json_encode(["message" => "No patients found"]);
        }
    }
}

// Insert a new patient
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $location = $data['location'];
    $disease_id = $data['disease_id'];
    $year_of_birth = $data['year_of_birth'];
    $gender = $data['gender'];
    $referring_doctor = $data['referring_doctor'];
    $rx_OD = $data['rx_OD'];
    $rx_OS = $data['rx_OS'];
    $procedures_done = $data['procedures_done'];
    $dosage = $data['dosage'];
    $duration = $data['duration'];
    $cumulative_dosage = $data['cumulative_dosage'];
    $date_of_discontinuation = $data['date_of_discontinuation'];
    $extra_notes = $data['extra_notes'];

    $sql = "INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes)
            VALUES ('$location', '$disease_id', '$year_of_birth', '$gender', '$referring_doctor', '$rx_OD', '$rx_OS', '$procedures_done', '$dosage', '$duration', '$cumulative_dosage', '$date_of_discontinuation', '$extra_notes')";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["message" => "New patient created successfully"]);
    } else {
        echo json_encode(["message" => "Error: " . $sql . "<br>" . $conn->error]);
    }
}

// Update an existing patient
if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);

    $patient_id = $data['patient_id'];
    $location = $data['location'];
    $disease_id = $data['disease_id'];
    $year_of_birth = $data['year_of_birth'];
    $gender = $data['gender'];
    $referring_doctor = $data['referring_doctor'];
    $rx_OD = $data['rx_OD'];
    $rx_OS = $data['rx_OS'];
    $procedures_done = $data['procedures_done'];
    $dosage = $data['dosage'];
    $duration = $data['duration'];
    $cumulative_dosage = $data['cumulative_dosage'];
    $date_of_discontinuation = $data['date_of_discontinuation'];
    $extra_notes = $data['extra_notes'];

    $sql = "UPDATE Patients SET location = '$location', disease_id = '$disease_id', year_of_birth = '$year_of_birth', gender = '$gender', referring_doctor = '$referring_doctor', rx_OD = '$rx_OD', rx_OS = '$rx_OS', procedures_done = '$procedures_done', dosage = '$dosage', duration = '$duration', cumulative_dosage = '$cumulative_dosage', date_of_discontinuation = '$date_of_discontinuation', extra_notes = '$extra_notes' WHERE patient_id = $patient_id";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["message" => "Patient updated successfully"]);
    } else {
        echo json_encode(["message" => "Error: " . $sql . "<br>" . $conn->error]);
    }
}

// Delete a patient
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $patient_id = $data['patient_id'];

    $sql = "DELETE FROM Patients WHERE patient_id = $patient_id";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["message" => "Patient deleted successfully"]);
    } else {
        echo json_encode(["message" => "Error: " . $sql . "<br>" . $conn->error]);
    }
}

$conn->close();
*/
?>
