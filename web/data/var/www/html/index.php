<?php
require_once __DIR__ . '/includes/functions.php';

try {
    // Fetch all patients with tests, ordered by patient and date
    $sql = "
        SELECT 
            p.patient_id, p.subject_id, p.date_of_birth, p.location AS patient_location,
            t.test_id, t.location AS test_location, t.date_of_test, t.age, t.eye,
            t.report_diagnosis, t.exclusion, t.merci_score, t.merci_diagnosis, t.error_type,
            t.faf_grade, t.oct_score, t.vf_score, t.actual_diagnosis,
            t.medication_name, t.dosage, t.dosage_unit, t.duration_days,
            t.cumulative_dosage, t.date_of_continuation, t.treatment_notes
        FROM patients p
        LEFT JOIN tests t ON p.patient_id = t.patient_id
        ORDER BY p.patient_id, t.date_of_test DESC, t.eye
    ";

    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception("DB query failed: " . $conn->error);
    }

    // Organize data by patient
    $patients = [];
    while ($row = $result->fetch_assoc()) {
        $pid = $row['patient_id'];
        if (!isset($patients[$pid])) {
            $patients[$pid] = [
                'patient_id' => $row['patient_id'],
                'subject_id' => $row['subject_id'],
                'date_of_birth' => $row['date_of_birth'],
                'location' => $row['patient_location'],
                'tests' => []
            ];
        }
        if ($row['test_id'] !== null) {
            $patients[$pid]['tests'][] = [
                'test_id' => $row['test_id'],
                'location' => $row['test_location'],
                'date_of_test' => $row['date_of_test'],
                'age' => $row['age'],
                'eye' => $row['eye'],
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
                'treatment_notes' => $row['treatment_notes'],
            ];
        }
    }

} catch (Exception $e) {
    die("Error: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Patients and Tests</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 40px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    h2 { margin-top: 40px; }
    .patient-info { margin-bottom: 10px; }
    .no-tests { font-style: italic; color: #777; }
</style>
</head>
<body>

<h1>Patients and Their Tests</h1>

<?php if (empty($patients)): ?>
    <p>No patients found.</p>
<?php else: ?>

    <?php foreach ($patients as $patient): ?>
        <section>
            <h2>Patient: <?= htmlspecialchars($patient['patient_id']) ?> (Subject ID: <?= htmlspecialchars($patient['subject_id']) ?>)</h2>
            <div class="patient-info">
                <strong>DOB:</strong> <?= htmlspecialchars($patient['date_of_birth']) ?> |
                <strong>Location:</strong> <?= htmlspecialchars($patient['location']) ?>
            </div>

            <?php if (empty($patient['tests'])): ?>
                <p class="no-tests">No tests recorded for this patient.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Test ID</th>
                            <th>Location</th>
                            <th>Date of Test</th>
                            <th>Age</th>
                            <th>Eye</th>
                            <th>Report Diagnosis</th>
                            <th>Exclusion</th>
                            <th>Merci Score</th>
                            <th>Merci Diagnosis</th>
                            <th>Error Type</th>
                            <th>FAF Grade</th>
                            <th>OCT Score</th>
                            <th>VF Score</th>
                            <th>Actual Diagnosis</th>
                            <th>Medication</th>
                            <th>Dosage</th>
                            <th>Dosage Unit</th>
                            <th>Duration (Days)</th>
                            <th>Cumulative Dosage</th>
                            <th>Date of Continuation</th>
                            <th>Treatment Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patient['tests'] as $test): ?>
                            <tr>
                                <td><?= htmlspecialchars($test['test_id']) ?></td>
                                <td><?= htmlspecialchars($test['location']) ?></td>
                                <td><?= htmlspecialchars($test['date_of_test']) ?></td>
                                <td><?= htmlspecialchars($test['age'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['eye']) ?></td>
                                <td><?= htmlspecialchars($test['report_diagnosis']) ?></td>
                                <td><?= htmlspecialchars($test['exclusion']) ?></td>
                                <td><?= htmlspecialchars($test['merci_score']) ?></td>
                                <td><?= htmlspecialchars($test['merci_diagnosis']) ?></td>
                                <td><?= htmlspecialchars($test['error_type']) ?></td>
                                <td><?= htmlspecialchars($test['faf_grade'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['oct_score'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['vf_score'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['actual_diagnosis']) ?></td>
                                <td><?= htmlspecialchars($test['medication_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['dosage'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['dosage_unit'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['duration_days'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['cumulative_dosage'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['date_of_continuation'] ?? '') ?></td>
                                <td><?= htmlspecialchars($test['treatment_notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

</body>
</html>
