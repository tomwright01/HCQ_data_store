<?php
/*
require_once 'includes/config.php';
require_once 'includes/functions.php';

$visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visit = getVisitById($visit_id);

if (!$visit) {
    die("Visit not found.");
}

function displayTestSection($testType, $odRef, $osRef) {
    echo "<div class='test-card'>";
    echo "<h3>$testType Images</h3>";
    echo "<div class='test-images'>";
    
    // OD (Right Eye)
    if ($odRef) {
        $odPath = getDynamicImagePath($odRef);
        if ($odPath) {
            echo "<div class='test-image'>
                    <a href='$odPath' target='_blank'>
                        <img src='$odPath' alt='$testType OD' class='test-thumbnail'>
                    </a>
                    <p>OD (Right Eye)</p>
                  </div>";
        } else {
            echo "<p class='image-missing'>$testType OD image not found in SAMPLE folder</p>";
        }
    }
    
    // OS (Left Eye)
    if ($osRef) {
        $osPath = getDynamicImagePath($osRef);
        if ($osPath) {
            echo "<div class='test-image'>
                    <a href='$osPath' target='_blank'>
                        <img src='$osPath' alt='$testType OS' class='test-thumbnail'>
                    </a>
                    <p>OS (Left Eye)</p>
                  </div>";
        } else {
            echo "<p class='image-missing'>$testType OS image not found in SAMPLE folder</p>";
        }
    }
    
    if (!$odRef && !$osRef) {
        echo "<p>No $testType images recorded for this visit</p>";
    }
    
    echo "</div></div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Details - <?= htmlspecialchars($visit['visit_id']) ?></title>
    <style>
        /* Keep your existing CSS styles */
    </style>
</head>
<body>
    <div class="container">
        <h1>Visit Details - ID: <?= htmlspecialchars($visit['visit_id']) ?></h1>
        
        <div class="info-header">
            <h2>Visit on <?= htmlspecialchars($visit['visit_date']) ?></h2>
            <p><strong>Patient:</strong> ID <?= htmlspecialchars($visit['patient_id']) ?></p>
        </div>

        <h2>Test Results</h2>
        <div class="test-grid">
            <?php
            // Display each test type section dynamically
            displayTestSection('FAF', $visit['faf_reference_OD'], $visit['faf_reference_OS']);
            displayTestSection('OCT', $visit['oct_reference_OD'], $visit['oct_reference_OS']);
            displayTestSection('VF', $visit['vf_reference_OD'], $visit['vf_reference_OS']);
            displayTestSection('MFERG', $visit['mferg_reference_OD'], $visit['mferg_reference_OS']);
            ?>
        </div>
        
        <a href="view_visits.php?patient_id=<?= htmlspecialchars($visit['patient_id']) ?>" class="button">Back to Patient Visits</a>
    </div>
</body>
</html>
