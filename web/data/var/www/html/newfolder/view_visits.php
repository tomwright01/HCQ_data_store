<?php
require_once 'config.php';

// Get visit ID from URL parameter
$visit_id = isset($_GET['visit_id']) ? $_GET['visit_id'] : 0;

// Ensure visit_id is numeric to avoid SQL injection
if (!is_numeric($visit_id) || $visit_id <= 0) {
    die("Invalid visit ID.");
}

// Query to get the visit data
$sql_visit = "SELECT 
                v.visit_id,
                v.visit_date,
                v.visit_notes,
                v.faf_reference_OD,
                v.faf_reference_OS,
                v.oct_reference_OD,
                v.oct_reference_OS,
                v.vf_reference_OD,
                v.vf_reference_OS,
                v.mferg_reference_OD,
                v.mferg_reference_OS,
                v.merci_rating_left_eye,
                v.merci_rating_right_eye,
                p.patient_id,
                p.location,
                p.disease_id,
                p.year_of_birth,
                p.gender,
                p.referring_doctor,
                p.rx_OD,
                p.rx_OS,
                p.procedures_done,
                p.dosage,
                p.duration,
                p.cumulative_dosage,
                p.date_of_discontinuation,
                p.extra_notes
              FROM Visits v
              JOIN Patients p ON v.patient_id = p.patient_id
              WHERE v.visit_id = $visit_id LIMIT 1";

$result_visit = $conn->query($sql_visit);

// If visit data exists, fetch it
$visit = null;
if ($result_visit && $result_visit->num_rows > 0) {
    $visit = $result_visit->fetch_assoc();
} else {
    echo "<p>Visit not found or invalid data.</p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Details</title>
    <style>
        /* Your existing CSS styles */
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            min-height: 100vh;
        }

        .content {
            width: 90%;
            max-width: 1200px;
            text-align: center;
            padding: 30px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin: 30px 0;
        }

        /* Rest of your CSS styles */
    </style>
</head>
<body>

    <div class="content">
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">
                Patient and visit information added successfully!
            </div>
        <?php endif; ?>

        <h1>Visit Details for Visit ID: <?= htmlspecialchars($visit['visit_id']) ?></h1>

        <!-- Your existing patient information sections -->

        <h2>Test Results</h2>
        
        <!-- FAF Images -->
        <h3>FAF Images</h3>
        <div class="test-images">
            <?php if ($visit['faf_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . $visit['faf_reference_OD'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="FAF OD">
                        </a>
                        <p>OD (Right Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['faf_reference_OD']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($visit['faf_reference_OS']): 
                $imagePath = IMAGE_BASE_PATH . $visit['faf_reference_OS'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="FAF OS">
                        </a>
                        <p>OS (Left Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['faf_reference_OS']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!$visit['faf_reference_OD'] && !$visit['faf_reference_OS']): ?>
                <p>No FAF images available</p>
            <?php endif; ?>
        </div>

        <!-- OCT Images -->
        <h3>OCT Images</h3>
        <div class="test-images">
            <?php if ($visit['oct_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . $visit['oct_reference_OD'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="OCT OD">
                        </a>
                        <p>OD (Right Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['oct_reference_OD']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($visit['oct_reference_OS']): 
                $imagePath = IMAGE_BASE_PATH . $visit['oct_reference_OS'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="OCT OS">
                        </a>
                        <p>OS (Left Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['oct_reference_OS']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!$visit['oct_reference_OD'] && !$visit['oct_reference_OS']): ?>
                <p>No OCT images available</p>
            <?php endif; ?>
        </div>

        <!-- VF Images -->
        <h3>VF Images</h3>
        <div class="test-images">
            <?php if ($visit['vf_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . $visit['vf_reference_OD'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="VF OD">
                        </a>
                        <p>OD (Right Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['vf_reference_OD']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($visit['vf_reference_OS']): 
                $imagePath = IMAGE_BASE_PATH . $visit['vf_reference_OS'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="VF OS">
                        </a>
                        <p>OS (Left Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['vf_reference_OS']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!$visit['vf_reference_OD'] && !$visit['vf_reference_OS']): ?>
                <p>No VF images available</p>
            <?php endif; ?>
        </div>

        <!-- MFERG Images -->
        <h3>MFERG Images</h3>
        <div class="test-images">
            <?php if ($visit['mferg_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . $visit['mferg_reference_OD'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="MFERG OD">
                        </a>
                        <p>OD (Right Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['mferg_reference_OD']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($visit['mferg_reference_OS']): 
                $imagePath = IMAGE_BASE_PATH . $visit['mferg_reference_OS'];
                if (file_exists($imagePath)): ?>
                    <div class="test-image">
                        <a href="<?= htmlspecialchars($imagePath) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="MFERG OS">
                        </a>
                        <p>OS (Left Eye)</p>
                    </div>
                <?php else: ?>
                    <p>Image not found: <?= htmlspecialchars($visit['mferg_reference_OS']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!$visit['mferg_reference_OD'] && !$visit['mferg_reference_OS']): ?>
                <p>No MFERG images available</p>
            <?php endif; ?>
        </div>

        <a href="index.php" class="back-link">Back to Patient Search</a>
    </div>

</body>
</html>

<?php
$conn->close();
?>

