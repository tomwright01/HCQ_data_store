<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Get all patients with their test counts
$patients = getPatientsWithTests($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Data Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Custom styles here */
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1><i class="bi bi-people-fill"></i> Patient Data Dashboard</h1>
                <p class="lead">View and manage patient records and test results</p>
            </div>
            <div class="col-auto">
                <a href="csv_import.php" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Import CSV
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-person-vcard"></i> Patients</h5>
                        <p class="card-text display-6"><?= count($patients) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-clipboard2-pulse"></i> Total Tests</h5>
                        <p class="card-text display-6">
                            <?= array_sum(array_map(fn($p) => count(getTestsByPatient($conn, $p['patient_id'])), $patients)) ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-eye"></i> Eye Records</h5>
                        <p class="card-text display-6">
                            <?php
                            $totalEyes = 0;
                            foreach ($patients as $patient) {
                                $tests = getTestsByPatient($conn, $patient['patient_id']);
                                foreach ($tests as $test) {
                                    $totalEyes += count(getTestEyes($conn, $test['test_id']));
                                }
                            }
                            echo $totalEyes;
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-hospital"></i> Locations</h5>
                        <p class="card-text">KH, CHUSJ, IWK, IVEY</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="searchInput" class="form-control search-input" placeholder="Search patients...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select id="locationFilter" class="form-select">
                            <option value="">All Locations</option>
                            <option value="KH">KH</option>
                            <option value="CHUSJ">CHUSJ</option>
                            <option value="IWK">IWK</option>
                            <option value="IVEY">IVEY</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select id="diagnosisFilter" class="form-select">
                            <option value="">All Diagnoses</option>
                            <option value="normal">Normal</option>
                            <option value="abnormal">Abnormal</option>
                            <option value="exclude">Exclude</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Patients List -->
        <div class="row" id="patientContainer">
            <?php foreach ($patients as $patient): ?>
                <?php
                $tests = getTestsByPatient($conn, $patient['patient_id']);
                $dob = new DateTime($patient['date_of_birth']);
                $age = $dob->diff(new DateTime())->y;
                ?>
                <div class="col-lg-6 patient-item" data-location="<?= $patient['location'] ?>">
                    <div class="card patient-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-person-circle"></i> 
                                <?= htmlspecialchars($patient['subject_id']) ?>
                            </h5>
                            <span class="badge bg-secondary"><?= $patient['location'] ?></span>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p><strong>Patient ID:</strong> <?= $patient['patient_id'] ?></p>
                                    <p><strong>Age:</strong> <?= $age ?> years</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>DOB:</strong> <?= $dob->format('M j, Y') ?></p>
                                    <p><strong>Tests:</strong> <?= count($tests) ?></p>
                                </div>
                            </div>

                            <!-- Tests Accordion -->
                            <div class="accordion" id="testsAccordion-<?= $patient['patient_id'] ?>">
                                <?php foreach ($tests as $index => $test): ?>
                                    <?php
                                    $testEyes = getTestEyes($conn, $test['test_id']);
                                    $testDate = new DateTime($test['date_of_test']);
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading-<?= $test['test_id'] ?>">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                                    data-bs-target="#collapse-<?= $test['test_id'] ?>" 
                                                    aria-expanded="false" aria-controls="collapse-<?= $test['test_id'] ?>">
                                                <i class="bi bi-clipboard2-pulse me-2"></i>
                                                <strong><?= $testDate->format('M j, Y') ?></strong>
                                                <span class="ms-2 badge bg-primary"><?= $test['test_id'] ?></span>
                                                <span class="ms-2 badge bg-dark"><?= count($testEyes) ?> eye records</span>
                                            </button>
                                        </h2>
                                        <div id="collapse-<?= $test['test_id'] ?>" class="accordion-collapse collapse" 
                                             aria-labelledby="heading-<?= $test['test_id'] ?>" 
                                             data-bs-parent="#testsAccordion-<?= $patient['patient_id'] ?>">
                                            <div class="accordion-body">
                                                <?php foreach ($testEyes as $eye): ?>
                                                    <div class="card test-card mb-3">
                                                        <div class="card-body">
                                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                                <span class="badge <?= strtolower($eye['eye']) ?>-badge eye-badge">
                                                                    <?= $eye['eye'] ?>
                                                                </span>
                                                                <span class="badge diagnosis-badge <?= $eye['report_diagnosis'] ?>">
                                                                    <?= $eye['report_diagnosis'] ?>
                                                                </span>
                                                            </div>
                                                             <!-- Other content goes here -->
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
