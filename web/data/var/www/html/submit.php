<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData"; // Your actual database name, change if necessary

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Patient data
    $location = $_POST['location'];
    $disease_id = $_POST['disease_id'];
    $year_of_birth = $_POST['year_of_birth'];
    $gender = $_POST['gender'];
    $referring_doctor = $_POST['referring_doctor'];
    $rx_OD = $_POST['rx_OD'];
    $rx_OS = $_POST['rx_OS'];
    $procedures_done = $_POST['procedures_done'];
    $dosage = $_POST['dosage'];
    $duration = $_POST['duration'];
    $cumulative_dosage = $_POST['cumulative_dosage'];
    $date_of_discontinuation = $_POST['date_of_discontinuation'];
    $extra_notes = $_POST['extra_notes'];

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
            // Success: Data inserted, now prompt for next action
            echo "<h3>Patient and Visit Record Successfully Added!</h3>";
            echo "<p>Do you want to:</p>";
            echo "<a href='index.php'>Return to Main Page</a><br>";
            echo "<a href='form.php'>Add Another Entry</a>";
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




