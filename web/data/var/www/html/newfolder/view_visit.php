<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get visit ID from URL parameter
$visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visit = getVisitById($visit_id);  // Get the visit from the database by ID

if (!$visit) {
    die("Visit not found.");
}

// Function to display test section (for each image type)
function displayTestSection($testType, $odRef, $osRef) {
    echo "<div class='test-card'>";
    echo "<h3>$testType Images</h3>";
    echo "<div class='test-images'>";
    
    // Display the OD (Right Eye) image
    if ($odRef) {
        $odPath = getDynamicImagePath($odRef);  // Get the image path from the reference field
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
    
    // Display the OS (Left Eye) image
    if ($osRef) {
        $osPath = getDynamicImagePath($osRef);  // Get the image path from the reference field
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
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            height: 60px;
            margin-bottom: 20px;
        }
        
        .info-header {
            background: #4CAF50;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .test-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .test-card {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .test-images {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .test-image {
            text-align: center;
        }
        
        .test-thumbnail {
            width: 200px;
            height: 150px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .image-missing {
            color: #d32f2f;
            font-style: italic;
        }
        
        .button {
            display: inline-block;
            padding: 10px 15px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        
        h1, h2, h3 {
            color: #2c3e50;
        }
        
        .info-header h2 {
            color: white;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Visit Details - ID: <?= htmlspecialchars($visit['visit_id']) ?></h1>
        
        <div class="info-header">
            <h2>Visit on <?= date('F j, Y', strtotime($visit['visit_date'])) ?></h2>
            <p><strong>Patient:</strong> ID <?= htmlspecialchars($visit['patient_id']) ?> | 
               <?= $visit['gender'] == 'm' ? 'Male' : 'Female' ?>, 
               Age <?= date('Y') - $visit['year_of_birth'] ?> | 
               <?= htmlspecialchars($visit['disease_name']) ?></p>
        </div>
        
        <div class="info-grid">
            <div>
                <h3>Visit Notes</h3>
                <p><?= $visit['visit_notes'] ? nl2br(htmlspecialchars($visit['visit_notes'])) : 'No notes available' ?></p>
            </div>
            
            <div>
                <h3>MERCI Ratings</h3>
                <p><strong>Left Eye:</strong> <?= $visit['merci_rating_left_eye'] ?: 'Not rated' ?></p>
                <p><strong>Right Eye:</strong> <?= $visit['merci_rating_right_eye'] ?: 'Not rated' ?></p>
            </div>
        </div>

        <h2>Test Results</h2>
        <div class="test-grid">
            <?php
            // Display test results for FAF, OCT, VF, MFERG images
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
