<?php
require_once 'config.php';
require_once 'functions.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinical Patient Data Viewer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .patient-box {
            border: 1px solid #ccc;
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f8f8;
        }

        .test-box {
            border-left: 3px solid #888;
            margin-top: 15px;
            padding-left: 15px;
        }

        .eye-box {
            background-color: #fff;
            padding: 10px;
            margin: 10px 0;
            border: 1px dashed #aaa;
        }

        h1, h2, h3, h4 {
            margin-top: 0;
        }

        ul {
            margin: 0;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <h1>Clinical Patient Records</h1>

    <?php
    $patients = getPatientsWithTests($conn);

    if (empty($patients)) {
        echo "<p>No patient data found.</p>";
    }

    foreach ($patients as $patient):
    ?>
        <div class="patient-box">
            <h2><?= htmlspecialchars($patient['subject_id']) ?> (<?= htmlspecialchars($patient['patient_id']) ?>)</h2>
            <p><strong>Location:</strong> <?= htmlspecialchars($patient['location']) ?><br>
               <strong>Date of Birth:</strong> <?= htmlspecialchars($patient['date_of_birth']) ?></p>

            <?php
            $tests = getTestsByPatient($conn, $patient['patient_id']);

            if (empty($tests)) {
                echo "<p>No tests found for this patient.</p>";
            }

            foreach ($tests as $test):
                $test_id = $test['test_id'];
                $eyes = getTestEyes($conn, $test_id);
            ?>
                <div class="test-box">
                    <h3>Test ID: <?= htmlspecialchars($test_id) ?> | Date: <?= htmlspecialchars($test['date_of_test']) ?> | Location: <?= htmlspecialchars($test['location']) ?></h3>

                    <?php
                    if (empty($eyes)) {
                        echo "<p>No eye data found for this test.</p>";
                    }

                    foreach ($eyes as $eye): ?>
                        <div class="eye-box">
                            <h4>Eye: <?= htmlspecialchars($eye['eye']) ?></h4>
                            <ul>
                                <li><strong>Age:</strong> <?= htmlspecialchars($eye['age']) ?></li>
                                <li><strong>Report Diagnosis:</strong> <?= htmlspecialchars($eye['report_diagnosis']) ?></li>
                                <li><strong>Exclusion:</strong> <?= htmlspecialchars($eye['exclusion']) ?></li>
                                <li><strong>Merci Score:</strong> <?= htmlspecialchars($eye['merci_score']) ?></li>
                                <li><strong>Merci Diagnosis:</strong> <?= htmlspecialchars($eye['merci_diagnosis']) ?></li>
                                <li><strong>Error Type:</strong> <?= htmlspecialchars($eye['error_type']) ?></li>
                                <li><strong>FAF Grade:</strong> <?= htmlspecialchars($eye['faf_grade']) ?></li>
                                <li><strong>OCT Score:</strong> <?= htmlspecialchars($eye['oct_score']) ?></li>
                                <li><strong>VF Score:</strong> <?= htmlspecialchars($eye['vf_score']) ?></li>
                                <li><strong>Actual Diagnosis:</strong> <?= htmlspecialchars($eye['actual_diagnosis']) ?></li>
                                <li><strong>Medication:</strong> <?= htmlspecialchars($eye['medication_name']) ?> (<?= htmlspecialchars($eye['dosage']) ?> <?= htmlspecialchars($eye['dosage_unit']) ?>)</li>
                                <li><strong>Duration:</strong> <?= htmlspecialchars($eye['duration_days']) ?> days</li>
                                <li><strong>Cumulative Dosage:</strong> <?= htmlspecialchars($eye['cumulative_dosage']) ?></li>
                                <li><strong>Date of Continuation:</strong> <?= htmlspecialchars($eye['date_of_continuation']) ?></li>
                                <li><strong>Treatment Notes:</strong> <?= htmlspecialchars($eye['treatment_notes']) ?></li>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
