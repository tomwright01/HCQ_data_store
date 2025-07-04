<?php
/*
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
*/
?>

