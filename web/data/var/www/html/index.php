<?php
require_once 'config.php';  // Provides $conn and helper functions
ini_set('display_errors', 1);
error_reporting(E_ALL);


// Fetch all patients, ordered by subject_id
$patients_sql = "SELECT * FROM patients ORDER BY subject_id";
$patients_result = $conn->query($patients_sql);
if (!$patients_result) {
    die("Failed to fetch patients: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Clinical Data Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { border-bottom: 2px solid #333; padding-bottom: 4px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #aaa; padding: 6px 8px; text-align: left; }
        th { background-color: #333; color: white; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .test-block { margin-left: 20px; margin-bottom: 20px; }
        .eye-block { margin-left: 40px; margin-bottom: 10px; }
    </style>
</head>
<body>

<h1>Clinical Data Viewer</h1>

<?php while ($patient = $patients_result->fetch_assoc()): ?>
    <section>
        <h2>Patient: <?= htmlspecialchars($patient['subject_id']) ?> (ID: <?= htmlspecialchars($patient['patient_id']) ?>)</h2>
        <p><strong>Location:</strong> <?= htmlspecialchars($patient['location']) ?> | <strong>DOB:</strong> <?= htmlspecialchars($patient['date_of_birth']) ?></p>

        <?php
        // Fetch tests for this patient
        $test_stmt = $conn->prepare("SELECT * FROM tests WHERE patient_id = ? ORDER BY date_of_test");
        $test_stmt->bind_param("s", $patient['patient_id']);
        $test_stmt->execute();
        $tests_result = $test_stmt->get_result();
        ?>

        <?php if ($tests_result->num_rows === 0): ?>
            <p><em>No tests found for this patient.</em></p>
        <?php else: ?>
            <?php while ($test = $tests_result->fetch_assoc()): ?>
                <div class="test-block">
                    <h3>Test ID: <?= htmlspecialchars($test['test_id']) ?> (<?= htmlspecialchars($test['date_of_test']) ?>)</h3>
                    <p><strong>Test Location:</strong> <?= htmlspecialchars($test['location']) ?></p>

                    <?php
                    // Fetch eye data for this test
                    $eye_stmt = $conn->prepare("SELECT * FROM test_eyes WHERE test_id = ? ORDER BY eye");
                    $eye_stmt->bind_param("s", $test['test_id']);
                    $eye_stmt->execute();
                    $eyes_result = $eye_stmt->get_result();
                    ?>

                    <?php if ($eyes_result->num_rows === 0): ?>
                        <p><em>No eye data for this test.</em></p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Eye</th>
                                    <th>Age</th>
                                    <th>Report Diagnosis</th>
                                    <th>Exclusion</th>
                                    <th>Merci Score</th>
                                    <th>Merci Diagnosis</th>
                                    <th>Error Type</th>
                                    <th>FAF Grade</th>
                                    <th>OCT Score</th>
                                    <th>VF Score</th>
                                    <th>Actual Diagnosis</th>
                                    <th>Medication Name</th>
                                    <th>Dosage</th>
                                    <th>Dosage Unit</th>
                                    <th>Duration Days</th>
                                    <th>Cumulative Dosage</th>
                                    <th>Date of Continuation</th>
                                    <th>Treatment Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($eye = $eyes_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($eye['eye']) ?></td>
                                    <td><?= htmlspecialchars($eye['age']) ?></td>
                                    <td><?= htmlspecialchars($eye['report_diagnosis']) ?></td>
                                    <td><?= htmlspecialchars($eye['exclusion']) ?></td>
                                    <td><?= htmlspecialchars($eye['merci_score']) ?></td>
                                    <td><?= htmlspecialchars($eye['merci_diagnosis']) ?></td>
                                    <td><?= htmlspecialchars($eye['error_type']) ?></td>
                                    <td><?= htmlspecialchars($eye['faf_grade']) ?></td>
                                    <td><?= htmlspecialchars($eye['oct_score']) ?></td>
                                    <td><?= htmlspecialchars($eye['vf_score']) ?></td>
                                    <td><?= htmlspecialchars($eye['actual_diagnosis']) ?></td>
                                    <td><?= htmlspecialchars($eye['medication_name']) ?></td>
                                    <td><?= htmlspecialchars($eye['dosage']) ?></td>
                                    <td><?= htmlspecialchars($eye['dosage_unit']) ?></td>
                                    <td><?= htmlspecialchars($eye['duration_days']) ?></td>
                                    <td><?= htmlspecialchars($eye['cumulative_dosage']) ?></td>
                                    <td><?= htmlspecialchars($eye['date_of_continuation']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($eye['treatment_notes'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>

        <?php 
        $test_stmt->close();
        ?>
    </section>
<?php endwhile; ?>

<?php
$patients_result->free();
$conn->close();
?>

</body>
</html>

