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
        $patient_id = $conn->insert_id; // Get the patient ID for the visits

