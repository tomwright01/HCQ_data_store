<?php
// If you prefer to reuse your existing connection, use this:
require_once __DIR__ . '/includes/config.php'; // provides $conn (mysqli)

// If you really want standalone credentials instead, comment the line above
// and uncomment below:
// $servername = "mariadb";
// $username   = "root";
// $password   = "notgood";
// $dbname     = "PatientData";
// $conn = new mysqli($servername, $username, $password, $dbname);
// if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$conn->set_charset('utf8mb4');

// Build export as ONE ROW PER EYE (matches your EYE_ROWS + UI)
$sql = "
SELECT
  p.patient_id,
  p.subject_id,
  p.date_of_birth,
  p.location AS patient_location,

  t.test_id,
  t.date_of_test,

  te.eye,
  te.age,
  te.report_diagnosis,
  te.exclusion,
  te.merci_score,
  te.merci_diagnosis,
  te.error_type,
  te.faf_grade,
  te.oct_score,
  te.vf_score,
  te.actual_diagnosis

FROM test_eyes te
JOIN tests t     ON te.test_id   = t.test_id
JOIN patients p  ON t.patient_id = p.patient_id
ORDER BY p.patient_id, t.date_of_test, te.eye
";

$result = $conn->query($sql);

// Always send CSV headers (even if no rows), so downloads don’t break
$filename = "patient_eye_export_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

// Excel-friendly BOM
echo "\xEF\xBB\xBF";

// Stream to output
$out = fopen('php://output', 'w');

// Column headers (fixed order)
$headers = [
    'Patient ID',
    'Subject ID',
    'Date of Birth',
    'Patient Location',

    'Test ID',
    'Date of Test',

    'Eye',
    'Age at Test',
    'Report Diagnosis',
    'Exclusion',
    'MERCI Score',
    'MERCI Diagnosis',
    'Error Type',
    'FAF Grade',
    'OCT Score',
    'VF Score',
    'Actual Diagnosis'
];
fputcsv($out, $headers);

// If there are rows, stream them; else just header row is delivered
if ($result && $result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {
        // Format dates (MM/DD/YYYY)
        $dob  = empty($r['date_of_birth']) ? '' : date('m/d/Y', strtotime($r['date_of_birth']));
        $dot  = empty($r['date_of_test'])  ? '' : date('m/d/Y', strtotime($r['date_of_test']));

        // Normalize nulls → ''
        $vals = array_map(function($v){ return $v === null ? '' : $v; }, $r);

        // Fixed-order row aligned to $headers
        $row = [
            $vals['patient_id'],
            $vals['subject_id'],
            $dob,
            $vals['patient_location'],

            $vals['test_id'],
            $dot,

            $vals['eye'],
            $vals['age'],
            $vals['report_diagnosis'],
            $vals['exclusion'],
            $vals['merci_score'],
            $vals['merci_diagnosis'],
            $vals['error_type'],
            $vals['faf_grade'],
            $vals['oct_score'],
            $vals['vf_score'],
            $vals['actual_diagnosis']
        ];
        fputcsv($out, $row);
    }
}

// Clean up
if ($result instanceof mysqli_result) { $result->free(); }
$conn->close();
fclose($out);
exit;
