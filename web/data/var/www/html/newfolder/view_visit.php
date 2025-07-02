<?php
require_once 'includes/functions.php';

$visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visit = getVisitById($visit_id);

if (!$visit) {
    die("Visit not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Details - <?= $visit['visit_id'] ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <img src="assets/images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">
        <h1>Visit Details - ID: <?= $visit['visit_id'] ?></h1>
    </header>

    <main class="container">
        <section class="visit-info">
            <div class="info-header">
                <h2>Visit on <?= $visit['visit_date'] ?></h2>
                <p><strong>Patient:</strong> ID <?= $visit['patient_id'] ?> | <?= $visit['gender'] == 'm' ? 'Male' : 'Female' ?>, Age <?= date('Y') - $visit['year_of_birth'] ?></p>
            </div>
            
            <div class="info-grid">
                <div>
                    <h3>Visit Notes</h3>
                    <p><?= $visit['visit_notes'] ?: 'No notes available' ?></p>
                </div>
                
                <div>
                    <h3>MERCI Ratings</h3>
                    <p><strong>Left Eye:</strong> <?= $visit['merci_rating_left_eye'] ?: 'Not rated' ?></p>
                    <p><strong>Right Eye:</strong> <?= $visit['merci_rating_right_eye'] ?: 'Not rated' ?></p>
                </div>
            </div>
        </section>

        <section class="test-results">
            <h2>Test Results</h2>
            
            <div class="test-grid">
                <div class="test-card">
                    <h3>FAF Images</h3>
                    <div class="test-images">
                        <?php if ($visit['faf_reference_OD']): ?>
                            <div>
                                <p>OD (Right Eye)</p>
                                <a href="<?= $visit['faf_reference_OD'] ?>" target="_blank">
                                    <img src="<?= $visit['faf_reference_OD'] ?>" alt="FAF OD" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($visit['faf_reference_OS']): ?>
                            <div>
                                <p>OS (Left Eye)</p>
                                <a href="<?= $visit['faf_reference_OS'] ?>" target="_blank">
                                    <img src="<?= $visit['faf_reference_OS'] ?>" alt="FAF OS" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$visit['faf_reference_OD'] && !$visit['faf_reference_OS']): ?>
                            <p>No FAF images available</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="test-card">
                    <h3>OCT Images</h3>
                    <div class="test-images">
                        <?php if ($visit['oct_reference_OD']): ?>
                            <div>
                                <p>OD (Right Eye)</p>
                                <a href="<?= $visit['oct_reference_OD'] ?>" target="_blank">
                                    <img src="<?= $visit['oct_reference_OD'] ?>" alt="OCT OD" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($visit['oct_reference_OS']): ?>
                            <div>
                                <p>OS (Left Eye)</p>
                                <a href="<?= $visit['oct_reference_OS'] ?>" target="_blank">
                                    <img src="<?= $visit['oct_reference_OS'] ?>" alt="OCT OS" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$visit['oct_reference_OD'] && !$visit['oct_reference_OS']): ?>
                            <p>No OCT images available</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="test-card">
                    <h3>VF Images</h3>
                    <div class="test-images">
                        <?php if ($visit['vf_reference_OD']): ?>
                            <div>
                                <p>OD (Right Eye)</p>
                                <a href="<?= $visit['vf_reference_OD'] ?>" target="_blank">
                                    <img src="<?= $visit['vf_reference_OD'] ?>" alt="VF OD" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($visit['vf_reference_OS']): ?>
                            <div>
                                <p>OS (Left Eye)</p>
                                <a href="<?= $visit['vf_reference_OS'] ?>" target="_blank">
                                    <img src="<?= $visit['vf_reference_OS'] ?>" alt="VF OS" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$visit['vf_reference_OD'] && !$visit['vf_reference_OS']): ?>
                            <p>No VF images available</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="test-card">
                    <h3>MFERG Images</h3>
                    <div class="test-images">
                        <?php if ($visit['mferg_reference_OD']): ?>
                            <div>
                                <p>OD (Right Eye)</p>
                                <a href="<?= $visit['mferg_reference_OD'] ?>" target="_blank">
                                    <img src="<?= $visit['mferg_reference_OD'] ?>" alt="MFERG OD" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($visit['mferg_reference_OS']): ?>
                            <div>
                                <p>OS (Left Eye)</p>
                                <a href="<?= $visit['mferg_reference_OS'] ?>" target="_blank">
                                    <img src="<?= $visit['mferg_reference_OS'] ?>" alt="MFERG OS" class="test-thumbnail">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$visit['mferg_reference_OD'] && !$visit['mferg_reference_OS']): ?>
                            <p>No MFERG images available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <a href="view_visits.php?patient_id=<?= $visit['patient_id'] ?>" class="button">Back to Patient Visits</a>
    </footer>
</body>
</html>
