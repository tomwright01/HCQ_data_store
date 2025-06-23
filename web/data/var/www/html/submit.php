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

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and assign form data to variables
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $disease_id = (int) $_POST['disease_id'];
    $year_of_birth = (int) $_POST['year_of_birth'];
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    $referring_doctor = mysqli_real_escape_string($conn, $_POST['referring_doctor']);
    $rx_OD = (float) $_POST['rx_OD'];
    $rx_OS = (float) $_POST['rx_OS'];
    $procedures_done = mysqli_real_escape_string($conn, $_POST['procedures_done']);
    $dosage = (float) $_POST['dosage'];
    $duration = (int) $_POST['duration'];
    $cumulative_dosage = (float) $_POST['cumulative_dosage'];
    $date_of_discontinuation = $_POST['date_of_discontinuation'];
    $extra_notes = mysqli_real_escape_string($conn, $_POST['extra_notes']);

    // Insert patient data into the Patients table
    $insertPatient = "INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes)
                      VALUES ('$location', '$disease_id', '$year_of_birth', '$gender', '$referring_doctor', '$rx_OD', '$rx_OS', '$procedures_done', '$dosage', '$duration', '$cumulative_dosage', '$date_of_discontinuation', '$extra_notes')";

    if ($conn->query($insertPatient) === TRUE) {
        $patient_id = $conn->insert_id; // Get the patient ID for the visits table

        // Visit data
        $visit_date = $_POST['visit_date'];
        $visit_notes = $_POST['visit_notes'];
        $faf_test_id_OD = $_POST['faf_test_id_OD'];
        $faf_image_number_OD = $_POST['faf_image_number_OD'];
        $faf_test_id_OS = $_POST['faf_test_id_OS'];
        $faf_image_number_OS = $_POST['faf_image_number_OS'];
        $oct_test_id_OD = $_POST['oct_test_id_OD'];
        $oct_image_number_OD = $_POST['oct_image_number_OD'];
        $oct_test_id_OS = $_POST['oct_test_id_OS'];
        $oct_image_number_OS = $_POST['oct_image_number_OS'];
        $vf_test_id_OD = $_POST['vf_test_id_OD'];
        $vf_image_number_OD = $_POST['vf_image_number_OD'];
        $vf_test_id_OS = $_POST['vf_test_id_OS'];
        $vf_image_number_OS = $_POST['vf_image_number_OS'];
        $mferg_test_id_OD = $_POST['mferg_test_id_OD'];
        $mferg_image_number_OD = $_POST['mferg_image_number_OD'];
        $mferg_test_id_OS = $_POST['mferg_test_id_OS'];
        $mferg_image_number_OS = $_POST['mferg_image_number_OS'];
        $merci_rating_left_eye = $_POST['merci_rating_left_eye'];
        $merci_rating_right_eye = $_POST['merci_rating_right_eye'];

        // Insert visit data into the Visits table
        $insertVisit = "INSERT INTO Visits (patient_id, visit_date, visit_notes, 
            faf_test_id_OD, faf_image_number_OD, faf_test_id_OS, faf_image_number_OS, 
            oct_test_id_OD, oct_image_number_OD, oct_test_id_OS, oct_image_number_OS, 
            vf_test_id_OD, vf_image_number_OD, vf_test_id_OS, vf_image_number_OS,
            mferg_test_id_OD, mferg_image_number_OD, mferg_test_id_OS, mferg_image_number_OS,
            merci_rating_left_eye, merci_rating_right_eye)
            VALUES ('$patient_id', '$visit_date', '$visit_notes',
            '$faf_test_id_OD', '$faf_image_number_OD', '$faf_test_id_OS', '$faf_image_number_OS',
            '$oct_test_id_OD', '$oct_image_number_OD', '$oct_test_id_OS', '$oct_image_number_OS',
            '$vf_test_id_OD', '$vf_image_number_OD', '$vf_test_id_OS', '$vf_image_number_OS',
            '$mferg_test_id_OD', '$mferg_image_number_OD', '$mferg_test_id_OS', '$mferg_image_number_OS',
            '$merci_rating_left_eye', '$merci_rating_right_eye')";

        if ($conn->query($insertVisit) === TRUE) {
            echo "New visit record created successfully.";
        } else {
            echo "Error: " . $insertVisit . "<br>" . $conn->error;
        }
    } else {
        echo "Error: " . $insertPatient . "<br>" . $conn->error;
    }
}

// Close connection
$conn->close();
?>


