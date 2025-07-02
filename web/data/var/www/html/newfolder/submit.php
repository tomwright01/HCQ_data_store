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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize patient data
    $patient_data = [
        'location' => $_POST['location'],
        'disease_id' => (int)$_POST['disease_id'],
        'year_of_birth' => (int)$_POST['year_of_birth'],
        'gender' => $_POST['gender'],
        'referring_doctor' => htmlspecialchars($_POST['referring_doctor']),
        'rx_OD' => (float)$_POST['rx_OD'],
        'rx_OS' => (float)$_POST['rx_OS'],
        'procedures_done' => htmlspecialchars($_POST['procedures_done']),
        'dosage' => (float)$_POST['dosage'],
        'duration' => (int)$_POST['duration'],
        'cumulative_dosage' => isset($_POST['cumulative_dosage']) ? (float)$_POST['cumulative_dosage'] : null,
        'date_of_discontinuation' => !empty($_POST['date_of_discontinuation']) ? $_POST['date_of_discontinuation'] : null,
        'extra_notes' => htmlspecialchars($_POST['extra_notes'])
    ];

    // Collect and sanitize visit data
    $visit_data = [
        'visit_date' => $_POST['visit_date'],
        'visit_notes' => htmlspecialchars($_POST['visit_notes']),
        'faf_test_id_OD' => !empty($_POST['faf_test_id_OD']) ? (int)$_POST['faf_test_id_OD'] : null,
        'faf_image_number_OD' => !empty($_POST['faf_image_number_OD']) ? (int)$_POST['faf_image_number_OD'] : null,
        'faf_test_id_OS' => !empty($_POST['faf_test_id_OS']) ? (int)$_POST['faf_test_id_OS'] : null,
        'faf_image_number_OS' => !empty($_POST['faf_image_number_OS']) ? (int)$_POST['faf_image_number_OS'] : null,
        'oct_test_id_OD' => !empty($_POST['oct_test_id_OD']) ? (int)$_POST['oct_test_id_OD'] : null,
        'oct_image_number_OD' => !empty($_POST['oct_image_number_OD']) ? (int)$_POST['oct_image_number_OD'] : null,
        'oct_test_id_OS' => !empty($_POST['oct_test_id_OS']) ? (int)$_POST['oct_test_id_OS'] : null,
        'oct_image_number_OS' => !empty($_POST['oct_image_number_OS']) ? (int)$_POST['oct_image_number_OS'] : null,
        'vf_test_id_OD' => !empty($_POST['vf_test_id_OD']) ? (int)$_POST['vf_test_id_OD'] : null,
        'vf_image_number_OD' => !empty($_POST['vf_image_number_OD']) ? (int)$_POST['vf_image_number_OD'] : null,
        'vf_test_id_OS' => !empty($_POST['vf_test_id_OS']) ? (int)$_POST['vf_test_id_OS'] : null,
        'vf_image_number_OS' => !empty($_POST['vf_image_number_OS']) ? (int)$_POST['vf_image_number_OS'] : null,
        'mferg_test_id_OD' => !empty($_POST['mferg_test_id_OD']) ? (int)$_POST['mferg_test_id_OD'] : null,
        'mferg_image_number_OD' => !empty($_POST['mferg_image_number_OD']) ? (int)$_POST['mferg_image_number_OD'] : null,
        'mferg_test_id_OS' => !empty($_POST['mferg_test_id_OS']) ? (int)$_POST['mferg_test_id_OS'] : null,
        'mferg_image_number_OS' => !empty($_POST['mferg_image_number_OS']) ? (int)$_POST['mferg_image_number_OS'] : null,
        'merci_rating_left_eye' => !empty($_POST['merci_rating_left_eye']) ? (int)$_POST['merci_rating_left_eye'] : null,
        'merci_rating_right_eye' => !empty($_POST['merci_rating_right_eye']) ? (int)$_POST['merci_rating_right_eye'] : null
    ];

    // Insert patient
    $stmt = $conn->prepare("INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siissssssisss", 
        $patient_data['location'],
        $patient_data['disease_id'],
        $patient_data['year_of_birth'],
        $patient_data['gender'],
        $patient_data['referring_doctor'],
        $patient_data['rx_OD'],
        $patient_data['rx_OS'],
        $patient_data['procedures_done'],
        $patient_data['dosage'],
        $patient_data['duration'],
        $patient_data['cumulative_dosage'],
        $patient_data['date_of_discontinuation'],
        $patient_data['extra_notes']
    );
    $stmt->execute();
    $patient_id = $conn->insert_id;
    $stmt->close();

    // Insert visit
    $stmt = $conn->prepare("INSERT INTO Visits (patient_id, visit_date, visit_notes, 
                          faf_test_id_OD, faf_image_number_OD, faf_test_id_OS, faf_image_number_OS,
                          oct_test_id_OD, oct_image_number_OD, oct_test_id_OS, oct_image_number_OS,
                          vf_test_id_OD, vf_image_number_OD, vf_test_id_OS, vf_image_number_OS,
                          mferg_test_id_OD, mferg_image_number_OD, mferg_test_id_OS, mferg_image_number_OS,
                          merci_rating_left_eye, merci_rating_right_eye)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issiiiiiiiiiiiiiiiiii",
        $patient_id,
        $visit_data['visit_date'],
        $visit_data['visit_notes'],
        $visit_data['faf_test_id_OD'],
        $visit_data['faf_image_number_OD'],
        $visit_data['faf_test_id_OS'],
        $visit_data['faf_image_number_OS'],
        $visit_data['oct_test_id_OD'],
        $visit_data['oct_image_number_OD'],
        $visit_data['oct_test_id_OS'],
        $visit_data['oct_image_number_OS'],
        $visit_data['vf_test_id_OD'],
        $visit_data['vf_image_number_OD'],
        $visit_data['vf_test_id_OS'],
        $visit_data['vf_image_number_OS'],
        $visit_data['mferg_test_id_OD'],
        $visit_data['mferg_image_number_OD'],
        $visit_data['mferg_test_id_OS'],
        $visit_data['mferg_image_number_OS'],
        $visit_data['merci_rating_left_eye'],
        $visit_data['merci_rating_right_eye']
    );
    $stmt->execute();
    $stmt->close();

    header("Location: view_visits.php?patient_id=$patient_id&success=1");
    exit();
} else {
    header("Location: form.php");
    exit();
}

$conn->close();
?>
