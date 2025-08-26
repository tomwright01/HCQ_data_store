<?php
// medications_save.php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// ---- read POST (adjust names to match your form) ----
$id_input         = trim($_POST['patient_or_subject'] ?? $_POST['patient_id'] ?? $_POST['subject_id'] ?? '');
$med_name         = trim($_POST['medication_name'] ?? '');
$dosage_per_day   = $_POST['dosage_per_day']   !== '' ? (float)$_POST['dosage_per_day']   : null;
$duration_days    = $_POST['duration_days']    !== '' ? (int)  $_POST['duration_days']    : null;
$cumulative_dose  = $_POST['cumulative_dosage']!== '' ? (float)$_POST['cumulative_dosage']: null;
$start_date       = trim($_POST['start_date'] ?? '');
$end_date         = trim($_POST['end_date']   ?? '');
$notes            = trim($_POST['notes'] ?? '');

// ---- basic validation ----
if ($med_name === '') {
    http_response_code(400);
    exit('Medication name is required.');
}

$patient_id = resolve_patient_id($conn, $id_input);
if ($patient_id === null) {
    http_response_code(400);
    exit('Patient not found for the ID provided.');
}

// Normalize dates to Y-m-d or null
$start_date = $start_date ? date('Y-m-d', strtotime($start_date)) : null;
$end_date   = $end_date   ? date('Y-m-d', strtotime($end_date))   : null;

// ---- insert ----
$sql = "INSERT INTO medications
        (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'isdidsss',
    $patient_id,
    $med_name,
    $dosage_per_day,      // double or null
    $duration_days,       // int or null
    $cumulative_dose,     // double or null
    $start_date,          // string (Y-m-d) or null
    $end_date,            // string (Y-m-d) or null
    $notes
);
if (!$stmt->execute()) {
    http_response_code(500);
    exit('Failed to save medication: ' . $stmt->error);
}
$stmt->close();

// back to index
header('Location: index.php#patients');
exit;
