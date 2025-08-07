<?php
require_once 'config.php';

function fetchPatientsWithTests($conn) {
    $sql = "
        SELECT 
            p.patient_id, p.subject_id, p.location AS patient_location, p.date_of_birth,
            t.test_id, t.date_of_test, t.location AS test_location,
            te.eye, te.age, te.report_diagnosis, te.exclusion, te.merci_score, 
            te.merci_diagnosis, te.error_type, te.faf_grade, te.oct_score, te.vf_score, 
            te.actual_diagnosis, te.medication_name, te.dosage, te.dosage_unit,
            te.duration_days, te.cumulative_dosage, te.date_of_continuation, te.treatment_notes
        FROM patients p
        LEFT JOIN tests t ON p.patient_id = t.patient_id
        LEFT JOIN test_eyes te ON t.test_id = te.test_id
        ORDER BY p.patient_id, t.test_id, te.eye
    ";

    $result = $conn->query($sql);
    if (!$result) {
        die("Query failed: " . $conn->error);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $pid = $row['patient_id'];
        $tid = $row['test_id'];
        $eye = $row['eye'];

        if (!isset($data[$pid])) {
            $data[$pid] = [
                'subject_id' => $row['subject_id'],
                'location' => $row['patient_location'],
                'dob' => $row['date_of_birth'],
                'tests' => []
            ];
        }

        if ($tid) {
            if (!isset($data[$pid]['tests'][$tid])) {
                $data[$pid]['tests'][$tid] = [
                    'date_of_test' => $row['date_of_test'],
                    'location' => $row['test_location'],
                    'eyes' => []
                ];
            }

            if ($eye) {
                $data[$pid]['tests'][$tid]['eyes'][$eye] = [
                    'age' => $row['age'],
                    'report_diagnosis' => $row['report_diagnosis'],
                    'exclusion' => $row['exclusion'],
                    'merci_score' => $row['merci_score'],
                    'merci_diagnosis' => $row['merci_diagnosis'],
                    'error_type' => $row['error_type'],
                    'faf_grade' => $row['faf_grade'],
                    'oct_score' => $row['oct_score'],
                    'vf_score' => $row['vf_score'],
                    'actual_diagnosis' => $row['actual_diagnosis'],
                    'medication_name' => $row['medication_name'],
                    'dosage' => $row['dosage'],
                    'dosage_unit' => $row['dosage_unit'],
                    'duration_days' => $row['duration_days'],
                    'cumulative_dosage' => $row['cumulative_dosage'],
                    'date_of_continuation' => $row['date_of_continuation'],
                    'treatment_notes' => $row['treatment_notes']
                ];
            }
        }
    }

    return $data;
}

$patients = fetchPatientsWithTests($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Patient Test Data</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .patient { margin-bottom: 30px; border-bottom: 1px solid #ccc; padding-bottom: 15px; }
        .test { margin-left: 20px; margin-top: 10px; }
        .eye { margin-left: 40px; }
    </style>
</head>
<body>
    <h1>Clinical Data Overview</h1>

    <?php foreach ($patients as $pid => $patient): ?>
        <div class="patient">
            <h2>Patient ID: <?= htmlspecialchars($pid) ?></h2>
            <p>Subject ID: <?= htmlspecialchars($patient['subject_id']) ?> | Location: <?= htmlspecialchars($patient['location']) ?> | DOB: <?= htmlspecialchars($patient['dob']) ?></p>

            <?php if (!empty($patient['tests'])): ?>
                <?php foreach ($patient['tests'] as $tid => $test): ?>
                    <div class="test">
                        <strong>Test ID:</strong> <?= htmlspecialchars($tid) ?> | Date: <?= htmlspecialchars($test['date_of_test']) ?> | Location: <?= htmlspecialchars($test['location']) ?>
                        
                        <?php foreach ($test['eyes'] as $eye => $details): ?>
                            <div class="eye">
                                <h4>Eye: <?= htmlspecialchars($eye) ?></h4>
                                <ul>
                                    <li>Age: <?= htmlspecialchars($details['age']) ?></li>
                                    <li>Report Diagnosis: <?= htmlspecialchars($details['report_diagnosis']) ?></li>
                                    <li>Exclusion: <?= htmlspecialchars($details['exclusion']) ?></li>
                                    <li>Merci Score: <?= htmlspecialchars($details['merci_score']) ?></li>
                                    <li>Merci Diagnosis: <?= htmlspecialchars($details['merci_diagnosis']) ?></li>
                                    <li>Error Type: <?= htmlspecialchars($details['error_type']) ?></li>
                                    <li>FAF Grade: <?= htmlspecialchars($details['faf_grade']) ?></li>
                                    <li>OCT Score: <?= htmlspecialchars($details['oct_score']) ?></li>
                                    <li>VF Score: <?= htmlspecialchars($details['vf_score']) ?></li>
                                    <li>Actual Diagnosis: <?= htmlspecialchars($details['actual_diagnosis']) ?></li>
                                    <li>Medication: <?= htmlspecialchars($details['medication_name']) ?> (<?= htmlspecialchars($details['dosage']) ?> <?= htmlspecialchars($details['dosage_unit']) ?>)</li>
                                    <li>Duration (days): <?= htmlspecialchars($details['duration_days']) ?></li>
                                    <li>Cumulative Dosage: <?= htmlspecialchars($details['cumulative_dosage']) ?></li>
                                    <li>Date of Continuation: <?= htmlspecialchars($details['date_of_continuation']) ?></li>
                                    <li>Treatment Notes: <?= nl2br(htmlspecialchars($details['treatment_notes'])) ?></li>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No tests found for this patient.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

</body>
</html>
