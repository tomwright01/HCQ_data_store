<?php
/*
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
                v.optos_reference_OD,
                v.optos_reference_OS,
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

        h1 {
            font-size: 36px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 28px;
            color: #333;
            margin: 20px 0;
            text-align: left;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 5px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            text-align: left;
            margin: 20px 0;
        }

        .info-item {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .info-item p {
            margin: 8px 0;
        }

        .info-item strong {
            color: #4CAF50;
        }

        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        td a {
            color: #4CAF50;
            text-decoration: none;
            font-weight: bold;
        }

        td a:hover {
            text-decoration: underline;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }

        .back-link:hover {
            background-color: #45a049;
        }

        .test-images {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .test-image {
            text-align: center;
        }

        .test-image img {
            width: 200px;
            height: 150px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .test-image p {
            margin-top: 5px;
            font-weight: bold;
        }

        .success-message {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
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
<!-- FAF Images -->
<h3>FAF Images</h3>
<div class="test-images">
    <?php if ($visit['faf_reference_OD']): ?>
        <div class="test-image">
            <a href="<?= getLocalImagePath('FAF', $visit['faf_reference_OD']) ?>" target="_blank">
                <img src="<?= getLocalImagePath('FAF', $visit['faf_reference_OD']) ?>" 
                     alt="FAF OD" style="max-width: 300px;">
            </a>
            <p>OD (Right Eye)</p>
        </div>
    <?php endif; ?>
    
    <?php if ($visit['faf_reference_OS']): ?>
        <div class="test-image">
            <a href="<?= getLocalImagePath('FAF', $visit['faf_reference_OS']) ?>" target="_blank">
                <img src="<?= getLocalImagePath('FAF', $visit['faf_reference_OS']) ?>" 
                     alt="FAF OS" style="max-width: 300px;">
            </a>
            <p>OS (Left Eye)</p>
        </div>
    <?php endif; ?>
</div>
        <!-- OCT Images -->
        <h3>OCT Images</h3>
        <div class="test-images">
            <?php if ($visit['oct_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['oct_reference_OD'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['oct_reference_OS'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
        </div>

        <!-- VF Images -->
        <h3>VF Images</h3>
        <div class="test-images">
            <?php if ($visit['vf_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['vf_reference_OD'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['vf_reference_OS'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
        </div>

        <!-- MFERG Images -->
        <h3>MFERG Images</h3>
        <div class="test-images">
            <?php if ($visit['mferg_reference_OD']): 
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['mferg_reference_OD'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
                $imagePath = IMAGE_BASE_PATH . FAF_FOLDER . $visit['mferg_reference_OS'];
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . $imagePath)): ?>
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
        </div>

        <a href="index.php" class="back-link">Back to Patient Search</a>
    </div>

</body>
</html>
*/
<?php
$conn->close();
?>
