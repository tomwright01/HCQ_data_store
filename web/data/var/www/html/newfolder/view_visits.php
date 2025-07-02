<?php
require_once 'includes/functions.php';

$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$patient = getPatientById($patient_id);
$visits = getVisitsByPatientId($patient_id);

if (!$patient) {
    die("Patient not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Visits - <?= $patient['patient_id'] ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <img src="assets/images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">
        <h1>Patient Visits - ID: <?= $patient['patient_id'] ?></h1>
    </header>

    <main class="container">
        <section class="patient-info">
            <h2>Patient Information</h2>
            
            <div class="info-grid">
                <div>
                    <p><strong>Location:</strong> <?= $patient['location'] ?></p>
                    <p><strong>Disease:</strong> <?= getDiseaseName($patient['disease_id']) ?></p>
                    <p><strong>Age:</strong> <?= date('Y') - $patient['year_of_birth'] ?></p>
                </div>
                
                <div>
                    <p><strong>Gender:</strong> <?= $patient['gender'] == 'm' ? 'Male' : 'Female' ?></p>
                    <p><strong>Referring Doctor:</strong> <?= $patient['referring_doctor'] ?></p>
                    <p><strong>Prescription:</strong> OD: <?= $patient['rx_OD'] ?> | OS: <?= $patient['rx_OS'] ?></p>
                </div>
                
                <div>
                    <p><strong>Dosage:</strong> <?= $patient['dosage'] ?></p>
                    <p><strong>Duration:</strong> <?= $patient['duration'] ?> months</p>
                    <p><strong>Cumulative Dosage:</strong> <?= $patient['cumulative_dosage'] ?></p>
                </div>
            </div>
            
            <?php if ($patient['extra_notes']): ?>
                <div class="notes">
                    <h3>Extra Notes</h3>
                    <p><?= $patient['extra_notes'] ?></p>
                </div>
            <?php endif; ?>
        </section>

        <section class="visits-section">
            <h2>Patient Visits</h2>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert success">
                    Patient and visit information added successfully!
                </div>
            <?php endif; ?>
            
            <a href="form.php" class="btn-primary">Add New Visit</a>
            
            <?php if (empty($visits)): ?>
                <p>No visits found for this patient.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Visit ID</th>
                            <th>Date</th>
                            <th>Notes</th>
                            <th>FAF</th>
                            <th>OCT</th>
                            <th>VF</th>
                            <th>MFERG</th>
                            <th>MERCI</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visits as $visit): ?>
                            <tr>
                                <td><?= $visit['visit_id'] ?></td>
                                <td><?= $visit['visit_date'] ?></td>
                                <td><?= substr($visit['visit_notes'], 0, 50) . (strlen($visit['visit_notes']) > 50 ? '...' : '') ?></td>
                                <td>
                                    <?php if ($visit['faf_reference_OD'] || $visit['faf_reference_OS']): ?>
                                        <span class="badge">Available</span>
                                    <?php else: ?>
                                        <span class="badge empty">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($visit['oct_reference_OD'] || $visit['oct_reference_OS']): ?>
                                        <span class="badge">Available</span>
                                    <?php else: ?>
                                        <span class="badge empty">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($visit['vf_reference_OD'] || $visit['vf_reference_OS']): ?>
                                        <span class="badge">Available</span>
                                    <?php else: ?>
                                        <span class="badge empty">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($visit['mferg_reference_OD'] || $visit['mferg_reference_OS']): ?>
                                        <span class="badge">Available</span>
                                    <?php else: ?>
                                        <span class="badge empty">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($visit['merci_rating_left_eye'] || $visit['merci_rating_right_eye']): ?>
                                        L: <?= $visit['merci_rating_left_eye'] ?: '-' ?> 
                                        R: <?= $visit['merci_rating_right_eye'] ?: '-' ?>
                                    <?php else: ?>
                                        <span class="badge empty">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="view_visit.php?visit_id=<?= $visit['visit_id'] ?>" class="btn-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <a href="index.php" class="button">Back to Dashboard</a>
    </footer>
</body>
</html>
