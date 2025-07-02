<?php
require_once 'includes/functions.php';

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

    // Add patient and visit to database
    $patient_id = addPatientAndVisit($patient_data, $visit_data);

    if ($patient_id) {
        header("Location: view_visits.php?patient_id=$patient_id&success=1");
        exit();
    } else {
        header("Location: form.php?error=1");
        exit();
    }
} else {
    header("Location: form.php");
    exit();
}
?>
