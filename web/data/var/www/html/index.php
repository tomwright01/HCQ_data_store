<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// ----------------------------
// Fast KPIs (avoid N+1 queries)
// ----------------------------
$totalPatients = 0;
$totalTests = 0;
$totalEyes = 0;

$res = $conn->query("SELECT COUNT(*) AS c FROM patients");
if ($res) { $row = $res->fetch_assoc(); $totalPatients = (int)($row['c'] ?? 0); }

$res = $conn->query("SELECT COUNT(*) AS c FROM tests");
if ($res) { $row = $res->fetch_assoc(); $totalTests = (int)($row['c'] ?? 0); }

$res = $conn->query("SELECT COUNT(*) AS c FROM test_eyes");
if ($res) { $row = $res->fetch_assoc(); $totalEyes = (int)($row['c'] ?? 0); }

// ----------------------------
// Analytics Data
// ----------------------------

// Patients by location
$byLocation = ['KH' => 0, 'CHUSJ' => 0, 'IWK' => 0, 'IVEY' => 0];
$res = $conn->query("SELECT location, COUNT(*) AS c FROM patients GROUP BY location");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $loc = $row['location'];
        if (isset($byLocation[$loc])) $byLocation[$loc] = (int)$row['c'];
    }
}

// Diagnosis distribution (from test_eyes.report_diagnosis)
$diagnoses = ['normal' => 0, 'abnormal' => 0, 'exclude' => 0, 'no input' => 0];
$res = $conn->query("SELECT report_diagnosis, COUNT(*) AS c FROM test_eyes GROUP BY report_diagnosis");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $d = $row['report_diagnosis'];
        if (isset($diagnoses[$d])) $diagnoses[$d] = (int)$row['c'];
    }
}

// Avg scores by eye
$avgByEye = ['OD' => ['oct' => null, 'vf' => null], 'OS' => ['oct' => null, 'vf' => null]];
$res = $conn->query("
    SELECT eye, AVG(oct_score) AS oct_avg, AVG(vf_score) AS vf_avg
    FROM test_eyes
    GROUP BY eye
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $eye = $row['eye'];
        if (isset($avgByEye[$eye])) {
            $avgByEye[$eye]['oct'] = $row['oct_avg'] !== null ? (float)$row['oct_avg'] : null;
            $avgByEye[$eye]['vf']  = $row['vf_avg']  !== null ? (float)$row['vf_avg']  : null;
        }
    }
}

// Tests over last 12 months
// Build zero-filled months first
$monthLabels = [];
$countsByMonth = [];
$now = new DateTimeImmutable('first day of this month');
for ($i = 11; $i >= 0; $i--) {
    $m = $now->sub(new DateInterval("P{$i}M"));
    $k = $m->format('Y-m');
    $monthLabels[] = $m->format('M Y');
    $countsByMonth[$k] = 0;
}
$res = $conn->query("
    SELECT DATE_FORMAT(date_of_test, '%Y-%m') AS ym, COUNT(*) AS c
    FROM tests
    WHERE date_of_test >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ym = $row['ym'];
        if (isset($countsByMonth[$ym])) $countsByMonth[$ym] = (int)$row['c'];
    }
}
$testsLast12Values = array_values($countsByMonth);

// ----------------------------
// Data for the main list
// ----------------------------
$patients = getPatientsWithTests($conn);

// Utility to safely read possibly-missing keys (for optional columns)
function safe_get($arr, $key, $default = null) {
    return is_array($arr) && array_key_exists($key, $arr) ? $arr[$key] : $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Patient Data Dashboard</title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.5/dist/chart.umd.min.js"></script>

    <style>
        body { background: #f7f8fb; }
        .navbar-blur {
            backdrop-filter: saturate(180%) blur(8px);
            background-color: rgba(255,255,255,0.85);
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .patient-card { transition: all 0.3s ease; margin-bottom: 20px; border: 1px solid rgba(0,0,0,.05); }
        .patient-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.09); transform: translateY(-1px); }
        .test-card { border-left: 4px solid #0d6efd; margin-bottom: 15px; }
        .eye-badge { font-size: 0.8rem; margin-right: 5px; }
        .os-badge { background-color: #6f42c1; }
        .od-badge { background-color: #20c997; }
        .search-box { position: relative; margin-bottom: 20px; }
        .search-box i { position: absolute; top: 10px; left: 10px; color: #6c757d; }
        .search-input { padding-left: 35px; }
        .diagnosis-badge { font-size: 0.75rem; text-transform: uppercase; }
        .normal { background-color: #198754; }
        .abnormal { background-color: #dc3545; }
        .exclude { background-color: #6c757d; }
        .no-input { background-color: #ffc107; color: #000; }
        .section-title { display:flex; align-items:center; gap:.5rem; }
        .section-title i { opacity:.8; }
        .card-gradient { background: linear-gradient(135deg, #6ea8fe 0%, #1a73e8 100%); }
        .card-gradient .card-title, .card-gradient .display-6 { color: #fff; }
        .sticky-actions { position: sticky; top: 80px; z-index: 10; }
        .pill { border-radius: 999px; }
        .chart-card { border: 1px solid rgba(0,0,0,.05); }
    </style>
</head>
<body>

<!-- Nav -->
<nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="#">
      <i class="bi bi-people-fill me-2"></i>Patient Data Dashboard
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link active" href="#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" href="#analytics">Analytics</a></li>
        <li class="nav-item"><a class="nav-link" href="#patients">Patients</a></li>
      </ul>
      <div class="d-flex gap-2">
        <a href="form.php" class="btn btn-outline-primary pill">
            <i class="bi bi-file-earmark-plus"></i> Add via Form
        </a>
        <a href="csv_import.php" class="btn btn-primary pill">
            <i class="bi bi-upload"></i> Import CSV
        </a>
      </div>
    </div>
  </div>
</nav>

<div class="container-fluid py-4" id="overview">
    <div class="row mb-3">
        <div class="col">
            <h1 class="section-title"><i class="bi bi-speedometer2"></i> Overview</h1>
            <p class="text-muted mb-0">Quick stats and data entry.</p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-gradient h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-person-vcard"></i> Patients</h5>
                    <p class="card-text display-6 mb-0"><?= number_format($totalPatients) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-clipboard2-pulse"></i> Total Tests</h5>
                    <p class="card-text display-6 mb-0"><?= number_format($totalTests) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-eye"></i> Eye Records</h5>
                    <p class="card-text display-6 mb-0"><?= number_format($totalEyes) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-hospital"></i> Locations</h5>
                    <p class="card-text mb-0">KH, CHUSJ, IWK, IVEY</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="row mb-5">
        <div class="col-auto sticky-actions">
            <div class="btn-group">
                <a href="#analytics" class="btn btn-outline-secondary pill"><i class="bi bi-graph-up"></i> View Analytics</a>
                <a href="#patients" class="btn btn-outline-secondary pill"><i class="bi bi-list-task"></i> View Patients</a>
            </div>
        </div>
    </div>

    <!-- Analytics -->
    <div class="row mb-3" id="analytics">
        <div class="col">
            <h2 class="section-title"><i class="bi bi-graph-up-arrow"></i> Analytics</h2>
            <p class="text-muted">Interactive charts summarizing the dataset.</p>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-calendar3"></i> Tests (Last 12 Months)</h5>
                    <canvas id="testsOverTime"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-clipboard2-check"></i> Diagnosis Distribution</h5>
                    <canvas id="diagnosisPie"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-geo-alt"></i> Patients by Location</h5>
                    <canvas id="locationBar"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-eye-fill"></i> Avg OCT & VF by Eye</h5>
                    <canvas id="avgScoresEye"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Search + Filters -->
    <div class="row mb-3" id="patients">
        <div class="col">
            <h2 class="section-title"><i class="bi bi-people"></i> Patients</h2>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" class="form-control search-input" placeholder="Search patients, test IDs, diagnoses...">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location</label>
                    <select id="locationFilter" class="form-select">
                        <option value="">All Locations</option>
                        <option value="KH">KH</option>
                        <option value="CHUSJ">CHUSJ</option>
                        <option value="IWK">IWK</option>
                        <option value="IVEY">IVEY</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Diagnosis</label>
                    <select id="diagnosisFilter" class="form-select">
                        <option value="">All Diagnoses</option>
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                        <option value="exclude">Exclude</option>
                        <option value="no input">No Input</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Patient List -->
    <div class="row" id="patientContainer">
        <?php foreach ($patients as $patient): ?>
            <?php
            $tests = getTestsByPatient($conn, $patient['patient_id']);
            $dob = new DateTime($patient['date_of_birth']);
            $ageYears = $dob->diff(new DateTime())->y;
            ?>
            <div class="col-lg-6 patient-item" data-location="<?= htmlspecialchars($patient['location']) ?>">
                <div class="card patient-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars($patient['subject_id']) ?>
                        </h5>
                        <span class="badge bg-secondary"><?= htmlspecialchars($patient['location']) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p><strong>Patient ID:</strong> <?= htmlspecialchars($patient['patient_id']) ?></p>
                                <p><strong>Age:</strong> <?= (int)$ageYears ?> years</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>DOB:</strong> <?= $dob->format('M j, Y') ?></p>
                                <p><strong>Tests:</strong> <?= count($tests) ?></p>
                            </div>
                        </div>

                        <div class="accordion" id="testsAccordion-<?= htmlspecialchars($patient['patient_id']) ?>">
                            <?php foreach ($tests as $test): ?>
                                <?php
                                $testEyes = getTestEyes($conn, $test['test_id']);
                                $testDate = new DateTime($test['date_of_test']);
                                ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading-<?= htmlspecialchars($test['test_id']) ?>">
                                        <button class="accordion-button collapsed" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapse-<?= htmlspecialchars($test['test_id']) ?>"
                                                aria-expanded="false"
                                                aria-controls="collapse-<?= htmlspecialchars($test['test_id']) ?>">
                                            <i class="bi bi-clipboard2-pulse me-2"></i>
                                            <strong><?= $testDate->format('M j, Y') ?></strong>
                                            <span class="ms-2 badge bg-primary"><?= htmlspecialchars($test['test_id']) ?></span>
                                            <span class="ms-2 badge bg-dark"><?= count($testEyes) ?> eye records</span>
                                        </button>
                                    </h2>
                                    <div id="collapse-<?= htmlspecialchars($test['test_id']) ?>" class="accordion-collapse collapse"
                                         aria-labelledby="heading-<?= htmlspecialchars($test['test_id']) ?>"
                                         data-bs-parent="#testsAccordion-<?= htmlspecialchars($patient['patient_id']) ?>">
                                        <div class="accordion-body">
                                            <?php foreach ($testEyes as $eye): ?>
                                                <?php
                                                    $eyeSide = strtoupper($eye['eye']);
                                                    $eyeClass = $eyeSide === 'OS' ? 'os-badge' : 'od-badge';
                                                    $diag = $eye['report_diagnosis'];
                                                    $diagClass = ($diag === 'no input') ? 'no-input' : $diag; // map 'no input' -> 'no-input'
                                                    $medName = safe_get($eye, 'medication_name'); // may not exist
                                                    $dosage = safe_get($eye, 'dosage');
                                                    $dosageUnit = safe_get($eye, 'dosage_unit', 'mg'); // default if exists
                                                ?>
                                                <div class="card test-card mb-3">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <span class="badge <?= $eyeClass ?> eye-badge"><?= htmlspecialchars($eyeSide) ?></span>
                                                            <span class="badge diagnosis-badge <?= htmlspecialchars($diagClass) ?>">
                                                                <?= htmlspecialchars($diag) ?>
                                                            </span>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <p><strong>Age at Test:</strong> <?= htmlspecialchars($eye['age'] ?? 'N/A') ?></p>
                                                                <p><strong>MERCI Score:</strong> <?= htmlspecialchars($eye['merci_score'] ?? 'N/A') ?></p>
                                                                <p><strong>OCT Score:</strong> <?= htmlspecialchars($eye['oct_score'] ?? 'N/A') ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <p><strong>VF Score:</strong> <?= htmlspecialchars($eye['vf_score'] ?? 'N/A') ?></p>
                                                                <p><strong>FAF Grade:</strong> <?= htmlspecialchars($eye['faf_grade'] ?? 'N/A') ?></p>
                                                                <p><strong>Diagnosis:</strong> <?= htmlspecialchars($eye['actual_diagnosis']) ?></p>
                                                            </div>
                                                        </div>

                                                        <?php if (!empty($medName) || !empty($dosage)): ?>
                                                            <div class="alert alert-info mt-2 p-2">
                                                                <strong>Medication:</strong>
                                                                <?= $medName ? htmlspecialchars($medName) : 'N/A' ?>
                                                                <?php if (!empty($dosage)): ?>
                                                                    (<?= htmlspecialchars($dosage) ?><?= $dosageUnit ? ' '.htmlspecialchars($dosageUnit) : '' ?>)
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php
                                                        // Optional references if present (safe_get handles absence)
                                                        $refs = [
                                                            'FAF Ref' => safe_get($eye, 'faf_reference'),
                                                            'OCT Ref' => safe_get($eye, 'oct_reference'),
                                                            'VF Ref'  => safe_get($eye, 'vf_reference'),
                                                            'mfERG Ref' => safe_get($eye, 'mferg_reference')
                                                        ];
                                                        $hasRef = array_filter($refs);
                                                        ?>
                                                        <?php if (!empty($hasRef)): ?>
                                                            <div class="mt-2">
                                                                <span class="text-muted small d-block mb-1">References</span>
                                                                <?php foreach ($refs as $label => $val): ?>
                                                                    <?php if (!empty($val)): ?>
                                                                        <span class="badge bg-light text-dark border me-1 mb-1"><?= htmlspecialchars($label) ?>: <?= htmlspecialchars($val) ?></span>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
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

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    // --------------------------
    // Charts
    // --------------------------
    const monthLabels = <?= json_encode($monthLabels) ?>;
    const testsOverTime = <?= json_encode($testsLast12Values) ?>;

    const diagLabels = <?= json_encode(array_keys($diagnoses)) ?>;
    const diagValues = <?= json_encode(array_values($diagnoses)) ?>;

    const locLabels = <?= json_encode(array_keys($byLocation)) ?>;
    const locValues = <?= json_encode(array_values($byLocation)) ?>;

    const avgOD = {
        oct: <?= json_encode($avgByEye['OD']['oct']) ?>,
        vf:  <?= json_encode($avgByEye['OD']['vf']) ?>
    };
    const avgOS = {
        oct: <?= json_encode($avgByEye['OS']['oct']) ?>,
        vf:  <?= json_encode($avgByEye['OS']['vf']) ?>
    };

    // Chart helpers: unified options
    const gridColor = 'rgba(0,0,0,0.06)';

    // Tests over time (line)
    new Chart(document.getElementById('testsOverTime'), {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Tests',
                data: testsOverTime,
                tension: 0.25,
                fill: true,
                borderWidth: 2,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor } },
                x: { grid: { color: gridColor } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });

    // Diagnosis distribution (doughnut)
    new Chart(document.getElementById('diagnosisPie'), {
        type: 'doughnut',
        data: {
            labels: diagLabels.map(l => (l === 'no input' ? 'No Input' : l[0].toUpperCase()+l.slice(1))),
            datasets: [{
                data: diagValues
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Patients by location (bar)
    new Chart(document.getElementById('locationBar'), {
        type: 'bar',
        data: {
            labels: locLabels,
            datasets: [{
                label: 'Patients',
                data: locValues
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor } },
                x: { grid: { color: gridColor } }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // Avg OCT & VF by eye (grouped bar)
    new Chart(document.getElementById('avgScoresEye'), {
        type: 'bar',
        data: {
            labels: ['OD', 'OS'],
            datasets: [
                { label: 'Avg OCT', data: [avgOD.oct ?? null, avgOS.oct ?? null] },
                { label: 'Avg VF',  data: [avgOD.vf ?? null,  avgOS.vf ?? null] }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor } },
                x: { grid: { color: gridColor } }
            }
        }
    });

    // --------------------------
    // Search & Filters
    // --------------------------
    const searchInput = document.getElementById('searchInput');
    const locationFilter = document.getElementById('locationFilter');
    const diagnosisFilter = document.getElementById('diagnosisFilter');
    const patientItems = document.querySelectorAll('.patient-item');

    function matchDiagnosis(container, value) {
        if (!value) return true;
        const badges = container.querySelectorAll('.diagnosis-badge');
        const val = value.toLowerCase();
        return Array.from(badges).some(b => b.textContent.trim().toLowerCase().includes(val));
    }

    function filterPatients() {
        const searchTerm = (searchInput.value || '').toLowerCase().trim();
        const locVal = (locationFilter.value || '');
        const diagVal = (diagnosisFilter.value || '');

        patientItems.forEach(item => {
            const patientText = item.textContent.toLowerCase();
            const patientLocation = item.getAttribute('data-location') || '';
            const matchesSearch = !searchTerm || patientText.includes(searchTerm);
            const matchesLocation = !locVal || patientLocation === locVal;
            const matchesDiag = matchDiagnosis(item, diagVal);

            item.style.display = (matchesSearch && matchesLocation && matchesDiag) ? 'block' : 'none';
        });
    }

    // Debounce search for smoother UX
    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(filterPatients, 150);
    });
    locationFilter.addEventListener('change', filterPatients);
    diagnosisFilter.addEventListener('change', filterPatients);

    // Initial filter (no-op)
    filterPatients();
})();
</script>
</body>
</html>

