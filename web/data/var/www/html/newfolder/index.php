<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Handle patient search
$search_patient_id = isset($_POST['search_patient_id']) ? $_POST['search_patient_id'] : '';
$search_results = null;

if ($search_patient_id) {
    $search_patient_id = (int)$search_patient_id;
    if ($search_patient_id > 0) {
        $search_results = getVisitsByPatientId($search_patient_id);  // Fetch visits for patient
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Search</title>
</head>
<body>
    <h1>Search for Patient Visits</h1>
    <form method="POST">
        <input type="number" name="search_patient_id" required placeholder="Enter Patient ID">
        <button type="submit">Search</button>
    </form>

    <?php if ($search_results): ?>
        <h2>Visits for Patient ID: <?= $search_patient_id ?></h2>
        <table>
            <tr>
                <th>Visit Date</th>
                <th>FAF (OD)</th>
                <th>OCT (OD)</th>
                <th>VF (OD)</th>
                <th>MFERG (OD)</th>
            </tr>
            <?php foreach ($search_results as $visit): ?>
                <tr>
                    <td><?= $visit['visit_date'] ?></td>
                    <td><a href="<?= getDynamicImagePath($visit['faf_reference_OD']) ?>" target="_blank">View</a></td>
                    <td><a href="<?= getDynamicImagePath($visit['oct_reference_OD']) ?>" target="_blank">View</a></td>
                    <td><a href="<?= getDynamicImagePath($visit['vf_reference_OD']) ?>" target="_blank">View</a></td>
                    <td><a href="<?= getDynamicImagePath($visit['mferg_reference_OD']) ?>" target="_blank">View</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
