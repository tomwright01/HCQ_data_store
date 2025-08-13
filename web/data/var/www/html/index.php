<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// ----------------------------
// Fast KPIs
// ----------------------------
$totalPatients = 0;
$totalTests = 0;
$totalEyes = 0;

if ($res = $conn->query("SELECT COUNT(*) AS c FROM patients")) { $row = $res->fetch_assoc(); $totalPatients = (int)($row['c'] ?? 0); }
if ($res = $conn->query("SELECT COUNT(*) AS c FROM tests"))    { $row = $res->fetch_assoc(); $totalTests    = (int)($row['c'] ?? 0); }
if ($res = $conn->query("SELECT COUNT(*) AS c FROM test_eyes")){ $row = $res->fetch_assoc(); $totalEyes     = (int)($row['c'] ?? 0); }

// ----------------------------
// Server-side analytics (initial, all data)
// ----------------------------

// Patients by location
$byLocation = ['KH' => 0, 'CHUSJ' => 0, 'IWK' => 0, 'IVEY' => 0];
if ($res = $conn->query("SELECT location, COUNT(*) AS c FROM patients GROUP BY location")) {
    while ($row = $res->fetch_assoc()) {
        if (isset($byLocation[$row['location']])) $byLocation[$row['location']] = (int)$row['c'];
    }
}

// Diagnosis distribution
$diagnoses = ['normal' => 0, 'abnormal' => 0, 'exclude' => 0, 'no input' => 0];
if ($res = $conn->query("SELECT report_diagnosis, COUNT(*) AS c FROM test_eyes GROUP BY report_diagnosis")) {
    while ($row = $res->fetch_assoc()) {
        if (isset($diagnoses[$row['report_diagnosis']])) $diagnoses[$row['report_diagnosis']] = (int)$row['c'];
    }
}

// Avg scores by eye
$avgByEye = ['OD' => ['oct' => null, 'vf' => null], 'OS' => ['oct' => null, 'vf' => null]];
if ($res = $conn->query("SELECT eye, AVG(oct_score) AS oct_avg, AVG(vf_score) AS vf_avg FROM test_eyes GROUP BY eye")) {
    while ($row = $res->fetch_assoc()) {
        $eye = $row['eye'];
        if (isset($avgByEye[$eye])) {
            $avgByEye[$eye]['oct'] = $row['oct_avg'] !== null ? (float)$row['oct_avg'] : null;
            $avgByEye[$eye]['vf']  = $row['vf_avg']  !== null ? (float)$row['vf_avg']  : null;
        }
    }
}

// Tests over last 12 months
$monthLabels = [];
$countsByMonth = [];
$now = new DateTimeImmutable('first day of this month');
for ($i = 11; $i >= 0; $i--) {
    $m = $now->sub(new DateInterval("P{$i}M"));
    $k = $m->format('Y-m');
    $monthLabels[] = $m->format('M Y');
    $countsByMonth[$k] = 0;
}
if ($res = $conn->query("
    SELECT DATE_FORMAT(date_of_test, '%Y-%m') AS ym, COUNT(*) AS c
    FROM tests
    WHERE date_of_test >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym
")) {
    while ($row = $res->fetch_assoc()) {
        if (isset($countsByMonth[$row['ym']])) $countsByMonth[$row['ym']] = (int)$row['c'];
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

// ----------------------------
// Raw rows for client-side filtering (one per-eye)
// ----------------------------
$eyeRows = [];
$sqlAll = "
SELECT
  te.result_id, te.test_id, te.eye, te.age, te.report_diagnosis, te.merci_score, te.oct_score, te.vf_score,
  t.date_of_test, t.patient_id,
  p.subject_id, p.location
FROM test_eyes te
JOIN tests t ON te.test_id = t.test_id
JOIN patients p ON t.patient_id = p.patient_id
";
if ($res = $conn->query($sqlAll)) {
    while ($row = $res->fetch_assoc()) {
        $eyeRows[] = [
            'result_id'        => (int)$row['result_id'],
            'test_id'          => $row['test_id'],
            'eye'              => $row['eye'],
            'age'              => is_null($row['age']) ? null : (int)$row['age'],
            'report_diagnosis' => $row['report_diagnosis'],
            'merci_score'      => $row['merci_score'],
            'oct_score'        => is_null($row['oct_score']) ? null : (float)$row['oct_score'],
            'vf_score'         => is_null($row['vf_score']) ? null : (float)$row['vf_score'],
            'date_of_test'     => $row['date_of_test'],
            'patient_id'       => $row['patient_id'],
            'subject_id'       => $row['subject_id'],
            'location'         => $row['location'],
        ];
    }
}

// dataset min/max dates for default pickers
$allDates = array_column($eyeRows, 'date_of_test');
sort($allDates);
$minDate = $allDates ? $allDates[0] : null;
$maxDate = $allDates ? end($allDates) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Hydroxychloroquine Data Repository</title>

<!-- Bootstrap & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<!-- Chart.js + plugins -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.5/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.umd.min.js"></script>

<style>
:root{
    --brand:#1a73e8;
    --brand-2:#6ea8fe;
    --ok:#198754;
    --warn:#ffc107;
    --danger:#dc3545;
    --muted:#6c757d;
    --bg:#f7f8fb;
    --card:#ffffff;
    --text:#111827;
    --grid:rgba(0,0,0,.06);
    --border:rgba(0,0,0,.08);
    --shadow: 0 8px 24px rgba(0,0,0,0.09);
    --chart-grad: radial-gradient(1200px 400px at 10% 0%, rgba(26,115,232,.06), transparent 60%);
}
body.dark{
    --bg:#0f1220;
    --card:#151a2c;
    --text:#e5e7eb;
    --grid:rgba(255,255,255,.12);
    --border:rgba(255,255,255,.14);
    --muted:#9aa4b2;
    --shadow: 0 12px 28px rgba(0,0,0,0.45);
    --chart-grad: radial-gradient(1200px 400px at 10% 0%, rgba(110,168,254,.12), transparent 60%);
}
body { background: var(--bg); color: var(--text); }
.navbar-blur { backdrop-filter: saturate(180%) blur(8px); background-color: rgba(255,255,255,0.85); border-bottom: 1px solid var(--border); }
body.dark .navbar-blur { background-color: rgba(21,26,44,0.85); }

.card { background: var(--card); border: 1px solid var(--border); box-shadow: var(--shadow); }
.patient-card { transition: all 0.3s ease; margin-bottom: 20px; }
.patient-card:hover { transform: translateY(-1px); }
.test-card { border-left: 4px solid var(--brand); margin-bottom: 15px; }
.eye-badge { font-size: 0.8rem; margin-right: 5px; }
.os-badge { background-color: #6f42c1; }
.od-badge { background-color: #20c997; }
.section-title { display:flex; align-items:center; gap:.5rem; }
.card-gradient { background: linear-gradient(135deg, var(--brand-2) 0%, var(--brand) 100%); color:#fff; }
.sticky-actions { position: sticky; top: 80px; z-index: 10; }
.pill { border-radius: 999px; }
.chart-card { background-image: var(--chart-grad); }
.chart-toolbar { display:flex; gap:.5rem; justify-content:flex-end; margin-bottom:.5rem; }
.chart-toolbar .btn { --bs-btn-padding-y: .25rem; --bs-btn-padding-x: .5rem; --bs-btn-font-size: .85rem; }
canvas { max-height: 380px; }

.results-badge { font-weight:600; }
.filter-fab { position: fixed; right: 18px; bottom: 18px; z-index: 1000; display: none; }
.filter-fab .btn { box-shadow: var(--shadow); }

/* Print */
@media print {
  body { background: #fff !important; }
  nav, .sticky-actions, .filter-fab, .btn, .navbar { display: none !important; }
  #printArea { display: block !important; }
  #analytics, #patients { page-break-before: always; }
  .card { box-shadow: none !important; }
}
#printArea { display:none; }
</style>
</head>
<body>

<!-- Nav -->
<nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="#">
      <i class="bi bi-capsule-pill me-2"></i>Hydroxychloroquine Data Repository
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

      <div class="d-flex align-items-center gap-2 gap-lg-3">
        <!-- Dark mode toggle -->
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="darkToggle">
            <label class="form-check-label" for="darkToggle"><i class="bi bi-moon-stars"></i></label>
        </div>

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
        <div class="col-auto">
            <button id="printSummaryBtn" class="btn btn-outline-secondary pill">
                <i class="bi bi-printer"></i> Print summary
            </button>
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

    <!-- Analytics (STATIC — not affected by patient filters) -->
    <div class="row mb-3" id="analytics">
        <div class="col">
            <h2 class="section-title"><i class="bi bi-graph-up-arrow"></i> Analytics</h2>
            <p class="text-muted">Charts summarize the full dataset. Patient filters below won’t change these.</p>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mb-5">
        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <div class="chart-toolbar">
                        <div class="btn-group">
                            <button class="btn btn-outline-secondary btn-sm" data-download="testsOverTime"><i class="bi bi-download"></i> PNG</button>
                            <button class="btn btn-outline-secondary btn-sm" data-csv="testsOverTime"><i class="bi bi-filetype-csv"></i> CSV</button>
                            <button class="btn btn-outline-secondary btn-sm" data-resetzoom="testsOverTime"><i class="bi bi-zoom-out"></i> Reset Zoom</button>
                        </div>
                    </div>
                    <h5 class="card-title mb-3"><i class="bi bi-calendar3"></i> Tests (by month)</h5>
                    <canvas id="testsOverTime"></canvas>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <div class="chart-toolbar">
                        <div class="btn-group">
                            <button class="btn btn-outline-secondary btn-sm" data-download="diagnosisPie"><i class="bi bi-download"></i> PNG</button>
                            <button class="btn btn-outline-secondary btn-sm" data-csv="diagnosisPie"><i class="bi bi-filetype-csv"></i> CSV</button>
                        </div>
                    </div>
                    <h5 class="card-title mb-3"><i class="bi bi-clipboard2-check"></i> Diagnosis Distribution</h5>
                    <canvas id="diagnosisPie"></canvas>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <div class="chart-toolbar">
                        <div class="btn-group">
                            <button class="btn btn-outline-secondary btn-sm" data-download="locationBar"><i class="bi bi-download"></i> PNG</button>
                            <button class="btn btn-outline-secondary btn-sm" data-csv="locationBar"><i class="bi bi-filetype-csv"></i> CSV</button>
                        </div>
                    </div>
                    <h5 class="card-title mb-3"><i class="bi bi-geo-alt"></i> Patients by Location</h5>
                    <canvas id="locationBar"></canvas>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card chart-card h-100">
                <div class="card-body">
                    <div class="chart-toolbar">
                        <div class="btn-group">
                            <button class="btn btn-outline-secondary btn-sm" data-download="avgScoresEye"><i class="bi bi-download"></i> PNG</button>
                            <button class="btn btn-outline-secondary btn-sm" data-csv="avgScoresEye"><i class="bi bi-filetype-csv"></i> CSV</button>
                        </div>
                    </div>
                    <h5 class="card-title mb-3"><i class="bi bi-eye-fill"></i> Avg OCT & VF by Eye</h5>
                    <canvas id="avgScoresEye"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== -->
    <!-- Patient Filters (ONLY) -->
    <!-- ===================== -->
    <div class="card mb-4" id="patientFilters">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-3">
                    <label class="form-label">Patient ID</label>
                    <input type="text" id="patientIdInput" class="form-control" placeholder="e.g. P_abc123">
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label">Test ID</label>
                    <input type="text" id="testIdInput" class="form-control" placeholder="e.g. T_2024...">
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label">Location</label>
                    <select id="locationFilter" class="form-select">
                        <option value="">All</option>
                        <option value="KH">KH</option>
                        <option value="CHUSJ">CHUSJ</option>
                        <option value="IWK">IWK</option>
                        <option value="IVEY">IVEY</option>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label">Diagnosis</label>
                    <select id="diagnosisFilter" class="form-select">
                        <option value="">All</option>
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                        <option value="exclude">Exclude</option>
                        <option value="no input">No Input</option>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label">Eye</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="OD" id="eyeOD" checked>
                            <label class="form-check-label" for="eyeOD">OD</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="OS" id="eyeOS" checked>
                            <label class="form-check-label" for="eyeOS">OS</label>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4">
                    <label class="form-label">Date range</label>
                    <div class="d-flex gap-2">
                        <input type="date" id="dateStart" class="form-control" value="<?= htmlspecialchars($minDate ?? '') ?>">
                        <input type="date" id="dateEnd" class="form-control" value="<?= htmlspecialchars($maxDate ?? '') ?>">
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="30">Last 30d</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="90">Last 90d</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="365">Last 365d</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="all">All time</button>
                    </div>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label">MERCI score</label>
                    <div class="d-flex gap-2">
                        <input type="number" step="0.01" id="merciMin" class="form-control" placeholder="min">
                        <input type="number" step="0.01" id="merciMax" class="form-control" placeholder="max">
                    </div>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label">Age at test</label>
                    <div class="d-flex gap-2">
                        <input type="number" id="ageMin" class="form-control" placeholder="min">
                        <input type="number" id="ageMax" class="form-control" placeholder="max">
                    </div>
                </div>

                <div class="col-12 col-lg-3">
                    <label class="form-label">Sort patients</label>
                    <div class="d-flex gap-2">
                        <select id="sortBy" class="form-select">
                            <option value="subject">Subject</option>
                            <option value="location">Location</option>
                            <option value="tests">Tests</option>
                            <option value="last_test">Last Test</option>
                            <option value="age">Age</option>
                        </select>
                        <button id="sortDir" class="btn btn-outline-secondary" data-dir="desc" title="Toggle asc/desc">
                            <i class="bi bi-sort-down"></i>
                        </button>
                    </div>
                </div>

                <div class="col-12 col-lg-3 d-flex justify-content-end gap-2">
                    <button id="exportFilteredCsv" class="btn btn-outline-success">
                        <i class="bi bi-filetype-csv"></i> Export filtered rows
                    </button>
                    <button id="clearFilters" class="btn btn-outline-secondary">
                        <i class="bi bi-eraser"></i> Clear
                    </button>
                    <a href="#patients" class="btn btn-outline-primary"><i class="bi bi-people"></i> Patients</a>
                </div>
            </div>

            <!-- Results summary -->
            <div class="mt-3">
                <span class="badge bg-light text-dark results-badge">
                    Results: <span id="resPatients">0</span> patients • <span id="resTests">0</span> tests • <span id="resEyes">0</span> eye records
                </span>
            </div>
        </div>
    </div>

    <!-- Patients -->
    <div class="row mb-3" id="patients">
        <div class="col">
            <h2 class="section-title"><i class="bi bi-people"></i> Patients</h2>
        </div>
    </div>

    <!-- Patients List -->
    <div class="row" id="patientContainer">
        <?php foreach ($patients as $patient): ?>
            <?php
            $tests = getTestsByPatient($conn, $patient['patient_id']);
            $dob = new DateTime($patient['date_of_birth']);
            $ageYears = $dob->diff(new DateTime())->y;

            // compute last test date for sorting
            $lastDate = null;
            if ($tests) {
                $dates = array_map(fn($t) => $t['date_of_test'], $tests);
                rsort($dates);
                $lastDate = $dates[0];
            }
            ?>
            <div class="col-lg-6 patient-item"
                 data-location="<?= htmlspecialchars($patient['location']) ?>"
                 data-subject="<?= htmlspecialchars($patient['subject_id']) ?>"
                 data-patient-id="<?= htmlspecialchars($patient['patient_id']) ?>"
                 data-age="<?= (int)$ageYears ?>"
                 data-tests="<?= count($tests) ?>"
                 data-lasttest="<?= htmlspecialchars($lastDate ?? '') ?>">
                <div class="card patient-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars($patient['subject_id']) ?>
                        </h5>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-sm btn-outline-secondary btn-patient-analytics"
                                    data-patient="<?= htmlspecialchars($patient['patient_id']) ?>"
                                    data-subject="<?= htmlspecialchars($patient['subject_id']) ?>">
                                <i class="bi bi-graph-up"></i> Analytics
                            </button>
                            <span class="badge bg-secondary"><?= htmlspecialchars($patient['location']) ?></span>
                        </div>
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
                                <div class="accordion-item test-wrapper"
                                     data-testid="<?= htmlspecialchars($test['test_id']) ?>"
                                     data-test-date="<?= htmlspecialchars($test['date_of_test']) ?>">
                                    <h2 class="accordion-header" id="heading-<?= htmlspecialchars($test['test_id']) ?>">
                                        <button class="accordion-button collapsed" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapse-<?= htmlspecialchars($test['test_id']) ?>"
                                                aria-expanded="false"
                                                aria-controls="collapse-<?= htmlspecialchars($test['test_id']) ?>">
                                            <i class="bi bi-clipboard2-pulse me-2"></i>
                                            <strong><?= $testDate->format('M j, Y') ?></strong>
                                            <span class="ms-2 badge bg-primary"><?= htmlspecialchars($test['test_id']) ?></span>
                                            <span class="ms-2 badge bg-dark"><span class="eye-count"><?= count($testEyes) ?></span> eye records</span>
                                        </button>
                                    </h2>
                                    <div id="collapse-<?= htmlspecialchars($test['test_id']) ?>" class="accordion-collapse collapse"
                                         aria-labelledby="heading-<?= htmlspecialchars($test['test_id']) ?>)"
                                         data-bs-parent="#testsAccordion-<?= htmlspecialchars($patient['patient_id']) ?>">
                                        <div class="accordion-body">
                                            <?php foreach ($testEyes as $eye): ?>
                                                <?php
                                                    $eyeSide = strtoupper($eye['eye']);
                                                    $eyeClass = $eyeSide === 'OS' ? 'os-badge' : 'od-badge';
                                                    $diag = $eye['report_diagnosis'];
                                                    $diagClass = ($diag === 'no input') ? 'no-input' : $diag;
                                                    $medName = safe_get($eye, 'medication_name');
                                                    $dosage = safe_get($eye, 'dosage');
                                                    $dosageUnit = safe_get($eye, 'dosage_unit', 'mg');
                                                    $merciVal = is_numeric($eye['merci_score']) ? (float)$eye['merci_score'] : null;
                                                    $ageAtTest = isset($eye['age']) ? (int)$eye['age'] : null;
                                                ?>
                                                <div class="card test-card mb-3 eye-row"
                                                     data-eye="<?= htmlspecialchars($eyeSide) ?>"
                                                     data-diagnosis="<?= htmlspecialchars($diag) ?>"
                                                     data-merci="<?= is_null($merciVal)? '' : htmlspecialchars($merciVal) ?>"
                                                     data-age="<?= is_null($ageAtTest)? '' : htmlspecialchars($ageAtTest) ?>"
                                                     data-location="<?= htmlspecialchars($patient['location']) ?>"
                                                     data-subject="<?= htmlspecialchars($patient['subject_id']) ?>"
                                                     data-testid="<?= htmlspecialchars($test['test_id']) ?>"
                                                     data-test-date="<?= htmlspecialchars($test['date_of_test']) ?>">
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

<!-- Sticky Filters FAB -->
<div class="filter-fab" id="filterFab">
    <button class="btn btn-primary pill" id="filtersPill"><i class="bi bi-funnel"></i> <span id="filtersCount">0</span> active • Clear</button>
</div>

<!-- Per-Patient Analytics Modal -->
<div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill"></i> Patient Analytics — <span id="pmSubject"></span> (<span id="pmId"></span>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">These per-patient charts respect the patient filters above.</p>
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="card chart-card h-100">
                    <div class="card-body">
                        <div class="chart-toolbar">
                            <div class="btn-group">
                                <button class="btn btn-outline-secondary btn-sm" data-download="pmTests"><i class="bi bi-download"></i> PNG</button>
                                <button class="btn btn-outline-secondary btn-sm" data-csv="pmTests"><i class="bi bi-filetype-csv"></i> CSV</button>
                            </div>
                        </div>
                        <h6 class="mb-2"><i class="bi bi-calendar3"></i> Tests by Month</h6>
                        <canvas id="pmTests"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card chart-card h-100">
                    <div class="card-body">
                        <div class="chart-toolbar">
                            <div class="btn-group">
                                <button class="btn btn-outline-secondary btn-sm" data-download="pmDiag"><i class="bi bi-download"></i> PNG</button>
                                <button class="btn btn-outline-secondary btn-sm" data-csv="pmDiag"><i class="bi bi-filetype-csv"></i> CSV</button>
                            </div>
                        </div>
                        <h6 class="mb-2"><i class="bi bi-clipboard2-check"></i> Diagnosis Distribution</h6>
                        <canvas id="pmDiag"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card chart-card">
                    <div class="card-body">
                        <div class="chart-toolbar">
                            <div class="btn-group">
                                <button class="btn btn-outline-secondary btn-sm" data-download="pmAvg"><i class="bi bi-download"></i> PNG</button>
                                <button class="btn btn-outline-secondary btn-sm" data-csv="pmAvg"><i class="bi bi-filetype-csv"></i> CSV</button>
                            </div>
                        </div>
                        <h6 class="mb-2"><i class="bi bi-eye-fill"></i> Avg OCT & VF by Eye</h6>
                        <canvas id="pmAvg"></canvas>
                    </div>
                </div>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Printable Summary -->
<section id="printArea" class="container-fluid py-4">
  <h2 class="mb-1">Dataset Summary</h2>
  <p class="text-muted">Generated at <span id="printTime"></span></p>
  <hr>
  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <h6>Tests by Month</h6>
      <img id="printImg-tests" alt="Tests by Month" class="img-fluid border rounded">
    </div>
    <div class="col-12 col-lg-6">
      <h6>Diagnosis Distribution</h6>
      <img id="printImg-diag" alt="Diagnosis Distribution" class="img-fluid border rounded">
    </div>
    <div class="col-12 col-lg-6">
      <h6>Patients by Location</h6>
      <img id="printImg-loc" alt="Patients by Location" class="img-fluid border rounded">
    </div>
    <div class="col-12 col-lg-6">
      <h6>Avg OCT & VF by Eye</h6>
      <img id="printImg-avg" alt="Avg Scores by Eye" class="img-fluid border rounded">
    </div>
  </div>
</section>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== Theme (dark mode) =====
(function themeInit(){
    const toggle = document.getElementById('darkToggle');
    const saved = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark':'light');
    if (saved === 'dark') document.body.classList.add('dark');
    toggle.checked = document.body.classList.contains('dark');
    toggle.addEventListener('change', () => {
        document.body.classList.toggle('dark', toggle.checked);
        localStorage.setItem('theme', toggle.checked ? 'dark' : 'light');
        if (window._recolorCharts) window._recolorCharts();
    });
})();
</script>

<script>
// ===== Data for charts (static, full dataset) =====
const EYE_ROWS = <?= json_encode($eyeRows) ?>;
const DATA_MIN = <?= $minDate ? '"'.htmlspecialchars($minDate).'"' : 'null' ?>;
const DATA_MAX = <?= $maxDate ? '"'.htmlspecialchars($maxDate).'"' : 'null' ?>;
const MONTH_LABELS_BASE = <?= json_encode($monthLabels) ?>;
const TESTS_LAST12_BASE  = <?= json_encode($testsLast12Values) ?>;

(function initUI(){
    // Utilities
    const $  = sel => document.querySelector(sel);
    const $$ = sel => Array.from(document.querySelectorAll(sel));
    const fmt = new Intl.NumberFormat();
    const toNum = v => v === null || v === '' || isNaN(v) ? null : Number(v);
    const parseDate = s => s ? new Date(s + 'T00:00:00') : null;
    const fmtISO = d => d ? d.toISOString().slice(0,10) : '';
    const cloneCanvasToImg = (canvas) => canvas.toDataURL('image/png', 1.0);

    // Inputs (PATIENT FILTERS ONLY)
    const patientIdInput = $('#patientIdInput');
    const testIdInput    = $('#testIdInput');
    const locSelect      = $('#locationFilter');
    const diagSelect     = $('#diagnosisFilter');
    const eyeOD          = $('#eyeOD');
    const eyeOS          = $('#eyeOS');
    const dateStartInput = $('#dateStart');
    const dateEndInput   = $('#dateEnd');
    const merciMinInput  = $('#merciMin');
    const merciMaxInput  = $('#merciMax');
    const ageMinInput    = $('#ageMin');
    const ageMaxInput    = $('#ageMax');
    const clearBtn       = $('#clearFilters');
    const exportBtn      = $('#exportFilteredCsv');
    const presetBtns     = $$('[data-preset]');
    const sortBy         = $('#sortBy');
    const sortDirBtn     = $('#sortDir');

    // Results counters
    const resPatients = $('#resPatients');
    const resTests    = $('#resTests');
    const resEyes     = $('#resEyes');

    const defaultState = {
        dateStart: DATA_MIN ? parseDate(DATA_MIN) : null,
        dateEnd:   DATA_MAX ? parseDate(DATA_MAX) : null,
        eyes: new Set(['OD','OS'])
    };

    function getFilterState(){
        const eyes = new Set();
        if (eyeOD.checked) eyes.add('OD');
        if (eyeOS.checked) eyes.add('OS');

        return {
            patientId: (patientIdInput.value || '').trim().toLowerCase(),
            testId:    (testIdInput.value || '').trim().toLowerCase(),
            location:  (locSelect.value || '').trim(),
            diagnosis: (diagSelect.value || '').trim(),
            eyes,
            dateStart: parseDate(dateStartInput.value),
            dateEnd:   parseDate(dateEndInput.value),
            merciMin:  toNum(merciMinInput.value),
            merciMax:  toNum(merciMaxInput.value),
            ageMin:    toNum(ageMinInput.value),
            ageMax:    toNum(ageMaxInput.value),
        };
    }

    // Chart palette
    Chart.register(ChartDataLabels);
    const C = {
        brand: '#1a73e8', brandLight:'#6ea8fe',
        green:'#198754', red:'#dc3545', amber:'#ffc107', gray:'#6c757d',
        teal:'#20c997', purple:'#6f42c1'
    };

    const shadowPlugin = {
        id: 'shadow',
        beforeDatasetsDraw(chart) {
            const {ctx} = chart; ctx.save();
            ctx.shadowColor = document.body.classList.contains('dark') ? 'rgba(0,0,0,0.8)' : 'rgba(0,0,0,0.15)';
            ctx.shadowBlur = 12; ctx.shadowOffsetY = 6;
        },
        afterDatasetsDraw(chart) { chart.ctx.restore(); }
    };
    Chart.register(shadowPlugin);

    function gridColor(){ return getComputedStyle(document.body).getPropertyValue('--grid'); }
    function lineGradientFor(chart){
        const {ctx, chartArea} = chart;
        if (!chartArea) return C.brandLight;
        const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        const top = document.body.classList.contains('dark') ? 'rgba(110,168,254,0.35)' : 'rgba(26,115,232,0.35)';
        const bot = document.body.classList.contains('dark') ? 'rgba(110,168,254,0.05)' : 'rgba(26,115,232,0.05)';
        g.addColorStop(0, top); g.addColorStop(1, bot);
        return g;
    }

    // === Charts (STATIC) ===
    const testsChart = new Chart(document.getElementById('testsOverTime').getContext('2d'), {
        type: 'line',
        data: { labels: MONTH_LABELS_BASE.slice(), datasets: [{
            label: 'Tests',
            data: TESTS_LAST12_BASE.slice(),
            borderColor: C.brand,
            backgroundColor: (ctx)=>lineGradientFor(ctx.chart),
            tension: 0.35, fill: true, borderWidth: 3, pointRadius: 3, pointHoverRadius: 5
        }]},
        options: {
            responsive:true, maintainAspectRatio:false,
            scales:{ x:{ grid:{ color:gridColor() } }, y:{ beginAtZero:true, grid:{ color:gridColor() } } },
            plugins:{
                legend:{ display:false },
                tooltip:{ callbacks:{ label:ctx=>` ${ctx.parsed.y} tests`} },
                zoom:{ zoom:{ wheel:{enabled:true}, pinch:{enabled:true}, mode:'x' }, pan:{enabled:true, mode:'x'} }
            }
        }
    });

    const diagChart = new Chart(document.getElementById('diagnosisPie').getContext('2d'), {
        type:'doughnut',
        data:{
            labels:['Normal','Abnormal','Exclude','No Input'],
            datasets:[{ data:[<?= $diagnoses['normal'] ?>, <?= $diagnoses['abnormal'] ?>, <?= $diagnoses['exclude'] ?>, <?= $diagnoses['no input'] ?>],
                backgroundColor:[C.green,C.red,C.gray,C.amber], borderWidth:2, borderColor:'#fff'}]
        },
        options:{
            responsive:true, maintainAspectRatio:false, cutout:'62%',
            plugins:{
                legend:{ position:'bottom' },
                datalabels:{
                    color:'#111', formatter:(v,ctx)=>{ const t=ctx.dataset.data.reduce((a,b)=>a+b,0); const p=t?(v/t*100):0; return p>=6?`${p.toFixed(0)}%`:''; }
                }
            }
        }
    });

    const locChart = new Chart(document.getElementById('locationBar').getContext('2d'), {
        type:'bar',
        data:{
            labels: <?= json_encode(array_keys($byLocation)) ?>,
            datasets:[{ label:'Patients', data: <?= json_encode(array_values($byLocation)) ?>,
                backgroundColor:C.teal, borderColor:C.teal, borderWidth:1, borderRadius:8, maxBarThickness:44 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() }}} ,
            plugins:{
                legend:{ display:false },
                datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=> (v>=3?fmt.format(v):'') }
            }
        }
    });

    const avgChart = new Chart(document.getElementById('avgScoresEye').getContext('2d'), {
        type:'bar',
        data:{
            labels:['OD','OS'],
            datasets:[
                { label:'Avg OCT', data:[<?= json_encode($avgByEye['OD']['oct']) ?>, <?= json_encode($avgByEye['OS']['oct']) ?>],
                  backgroundColor:C.purple, borderColor:C.purple, borderWidth:1, borderRadius:8, maxBarThickness:36 },
                { label:'Avg VF',  data:[<?= json_encode($avgByEye['OD']['vf']) ?>,  <?= json_encode($avgByEye['OS']['vf']) ?>],
                  backgroundColor:C.brand,  borderColor:C.brand,  borderWidth:1, borderRadius:8, maxBarThickness:36 }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() }}} ,
            plugins:{
                datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v => (v===null||isNaN(v))?'':fmt.format(v) }
            }
        }
    });

    window._recolorCharts = () => {
        [testsChart, diagChart, locChart, avgChart].forEach(ch => {
            if (!ch) return;
            if (ch.config.type === 'line') ch.data.datasets[0].backgroundColor = (ctx)=>lineGradientFor(ch);
            if (ch.options.scales?.x?.grid) ch.options.scales.x.grid.color = gridColor();
            if (ch.options.scales?.y?.grid) ch.options.scales.y.grid.color = gridColor();
            ch.update('none');
        });
    };

    // PNG/CSV/Zoom handlers (analytics)
    function downloadCSV(filename, rows){
        const csv = rows.map(r=>r.map(v=>{
            v = (v===null||v===undefined) ? '' : String(v);
            if (v.includes('"') || v.includes(',') || v.includes('\n')) v = '"' + v.replace(/"/g,'""') + '"';
            return v;
        }).join(',')).join('\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'}); const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 500);
    }
    function csvForAnalytics(chartId){
        if (chartId === 'testsOverTime') {
            const data = [['Month','Tests']];
            MONTH_LABELS_BASE.forEach((lab,i)=> data.push([lab, TESTS_LAST12_BASE[i]]));
            return data;
        }
        if (chartId === 'diagnosisPie') {
            return [['Diagnosis','Count'], ['Normal',<?= $diagnoses['normal'] ?>], ['Abnormal',<?= $diagnoses['abnormal'] ?>], ['Exclude',<?= $diagnoses['exclude'] ?>], ['No Input',<?= $diagnoses['no input'] ?>]];
        }
        if (chartId === 'locationBar') {
            const labs = <?= json_encode(array_keys($byLocation)) ?>;
            const vals = <?= json_encode(array_values($byLocation)) ?>;
            const rows = [['Location','Patients']]; labs.forEach((l,i)=>rows.push([l, vals[i]])); return rows;
        }
        if (chartId === 'avgScoresEye') {
            return [['Eye','Avg OCT','Avg VF'], ['OD', <?= json_encode($avgByEye['OD']['oct']) ?>, <?= json_encode($avgByEye['OD']['vf']) ?>], ['OS', <?= json_encode($avgByEye['OS']['oct']) ?>, <?= json_encode($avgByEye['OS']['vf']) ?>]];
        }
        return [['Info'],['Unsupported']];
    }
    document.querySelectorAll('[data-download]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            const id = btn.getAttribute('data-download');
            const canvas = document.getElementById(id);
            if (!canvas) return;
            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png', 1.0);
            link.download = `${id}.png`;
            link.click();
        });
    });
    document.querySelectorAll('[data-csv]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            const id = btn.getAttribute('data-csv');
            downloadCSV(`${id}.csv`, csvForAnalytics(id));
        });
    });
    document.querySelectorAll('[data-resetzoom="testsOverTime"]').forEach(btn=>{
        btn.addEventListener('click', ()=> testsChart.resetZoom());
    });

    // ===== PATIENT FILTERING (does NOT affect analytics) =====
    const patientContainer = $('#patientContainer');

    function rowMatches(r, f){
        // Eye-level row filtering used to show/hide eye rows and tests
        if (f.location && r.location !== f.location) return false;
        if (f.diagnosis && r.report_diagnosis !== f.diagnosis) return false;
        if (f.eyes.size && !f.eyes.has(r.eye)) return false;

        const d = parseDate(r.date_of_test);
        if (f.dateStart && d < f.dateStart) return false;
        if (f.dateEnd && d > f.dateEnd) return false;

        const m = toNum(r.merci_score);
        if (f.merciMin !== null) { if (m === null || m < f.merciMin) return false; }
        if (f.merciMax !== null) { if (m === null || m > f.merciMax) return false; }

        const a = toNum(r.age);
        if (f.ageMin !== null) { if (a === null || a < f.ageMin) return false; }
        if (f.ageMax !== null) { if (a === null || a > f.ageMax) return false; }

        return true;
    }

    function filterPatientsDOM(){
        const f = getFilterState();
        let visiblePatients = 0, visibleTests = 0, visibleEyes = 0;

        const patientCards = $$('.patient-item');
        patientCards.forEach(patient => {
            const pidAttr = (patient.getAttribute('data-patient-id') || '').toLowerCase();
            const locAttr = (patient.getAttribute('data-location') || '').toLowerCase();

            // First: coarse patient-level checks (patientId)
            if (f.patientId && !pidAttr.includes(f.patientId)) {
                patient.style.display = 'none';
                return;
            }
            if (f.location && locAttr !== f.location.toLowerCase()) {
                patient.style.display = 'none';
                return;
            }

            const tests = patient.querySelectorAll('.test-wrapper');
            let anyTestVisible = false;

            tests.forEach(tw => {
                const testId = (tw.getAttribute('data-testid') || '').toLowerCase();
                const testDate = tw.getAttribute('data-test-date') || '';

                // Test ID filter (if provided, require match at test level)
                if (f.testId && !testId.includes(f.testId)) {
                    tw.style.display = 'none';
                    return;
                }

                const eyeRows = tw.querySelectorAll('.eye-row');
                let eyesShown = 0;

                eyeRows.forEach(row => {
                    // Build a row object
                    const r = {
                        subject_id: row.getAttribute('data-subject') || '',
                        test_id: row.getAttribute('data-testid') || '',
                        report_diagnosis: row.getAttribute('data-diagnosis') || '',
                        location: row.getAttribute('data-location') || '',
                        eye: row.getAttribute('data-eye') || '',
                        date_of_test: row.getAttribute('data-test-date') || testDate,
                        merci_score: row.getAttribute('data-merci'),
                        age: row.getAttribute('data-age')
                    };
                    const ok = rowMatches(r, f);
                    row.style.display = ok ? 'block' : 'none';
                    if (ok) eyesShown++;
                });

                const countEl = tw.querySelector('.eye-count');
                if (countEl) countEl.textContent = eyesShown;

                tw.style.display = eyesShown > 0 ? 'block' : 'none';
                if (eyesShown > 0) {
                    anyTestVisible = true;
                    visibleTests++;
                    visibleEyes += eyesShown;
                }
            });

            patient.style.display = anyTestVisible ? 'block' : 'none';
            if (anyTestVisible) visiblePatients++;
        });

        resPatients.textContent = fmt.format(visiblePatients);
        resTests.textContent    = fmt.format(visibleTests);
        resEyes.textContent     = fmt.format(visibleEyes);
    }

    // Sorting (on visible patients)
    function sortPatients(){
        const container = patientContainer;
        const items = Array.from(container.querySelectorAll('.patient-item')).filter(it => it.style.display !== 'none');
        const key = sortBy.value;
        const dir = sortDirBtn.getAttribute('data-dir') === 'desc' ? -1 : 1;

        function val(item){
            if (key === 'subject') return (item.getAttribute('data-subject')||'').toLowerCase();
            if (key === 'location') return (item.getAttribute('data-location')||'').toLowerCase();
            if (key === 'tests') return Number(item.getAttribute('data-tests')||0);
            if (key === 'age') return Number(item.getAttribute('data-age')||0);
            if (key === 'last_test') {
                const d = item.getAttribute('data-lasttest')||'';
                return d ? new Date(d+'T00:00:00').getTime() : 0;
            }
            return (item.getAttribute('data-subject')||'').toLowerCase();
        }

        items.sort((a,b)=>{
            const va = val(a), vb = val(b);
            if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
            if (va < vb) return -1*dir;
            if (va > vb) return 1*dir;
            return 0;
        });

        // append sorted visible to end in order (hidden elements keep position)
        items.forEach(it => container.appendChild(it));
    }

    // Active filters pill (optional floating clear button)
    const filterFab   = $('#filterFab');
    const filtersPill = $('#filtersPill');
    const filtersCount= $('#filtersCount');

    function countActiveFilters(){
        const f = getFilterState();
        let n = 0;
        if (f.patientId) n++;
        if (f.testId) n++;
        if (f.location) n++;
        if (f.diagnosis) n++;
        if (!(f.eyes.has('OD') && f.eyes.has('OS'))) n++;
        const defStart = defaultState.dateStart ? defaultState.dateStart.getTime() : null;
        const defEnd   = defaultState.dateEnd   ? defaultState.dateEnd.getTime()   : null;
        const curStart = f.dateStart ? f.dateStart.getTime() : null;
        const curEnd   = f.dateEnd   ? f.dateEnd.getTime()   : null;
        if (defStart !== curStart || defEnd !== curEnd) n++;
        if (f.merciMin !== null || f.merciMax !== null) n++;
        if (f.ageMin !== null || f.ageMax !== null) n++;
        return n;
    }
    function updateFiltersPill(){
        const n = countActiveFilters();
        if (n > 0) { filtersCount.textContent = n; filterFab.style.display = 'block'; }
        else { filterFab.style.display = 'none'; }
    }
    filtersPill.addEventListener('click', ()=>{ clearFilters(); window.scrollTo({top: 0, behavior: 'smooth'}); });

    function applyPatientFilters(){
        filterPatientsDOM();
        sortPatients();
        updateFiltersPill();
    }

    // CSV for filtered rows (eye rows currently visible)
    function exportFilteredRowsCSV(){
        const visibleRows = Array.from(document.querySelectorAll('.eye-row'))
            .filter(el => el.style.display !== 'none')
            .map(el => ({
                subject_id: el.getAttribute('data-subject') || '',
                patient_id: el.closest('.patient-item')?.getAttribute('data-patient-id') || '',
                test_id: el.getAttribute('data-testid') || '',
                date_of_test: el.getAttribute('data-test-date') || '',
                eye: el.getAttribute('data-eye') || '',
                age: el.getAttribute('data-age') || '',
                report_diagnosis: el.getAttribute('data-diagnosis') || '',
                merci_score: el.getAttribute('data-merci') || '',
                location: el.getAttribute('data-location') || ''
            }));

        const header = ['subject_id','patient_id','test_id','date_of_test','eye','age','report_diagnosis','merci_score','location'];
        const rows = [header, ...visibleRows.map(r => header.map(h => r[h]))];

        // download
        const csv = rows.map(r=>r.map(v=>{
            v = (v===null||v===undefined) ? '' : String(v);
            if (v.includes('"') || v.includes(',') || v.includes('\n')) v = '"' + v.replace(/"/g,'""') + '"';
            return v;
        }).join(',')).join('\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'}); const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = 'filtered_eye_rows.csv'; a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 500);
    }

    // Wire events
    let debounceTimer;
    [patientIdInput, testIdInput].forEach(inp=>{
        inp.addEventListener('input', ()=>{ clearTimeout(debounceTimer); debounceTimer=setTimeout(applyPatientFilters, 150); });
    });
    [locSelect, diagSelect, eyeOD, eyeOS, dateStartInput, dateEndInput, merciMinInput, merciMaxInput, ageMinInput, ageMaxInput]
        .forEach(el => el.addEventListener('change', applyPatientFilters));

    exportBtn.addEventListener('click', exportFilteredRowsCSV);

    function setPreset(days){
        const max = DATA_MAX ? new Date(DATA_MAX+'T00:00:00') : new Date();
        let start = null, end = max;
        if (days === 'all') start = DATA_MIN ? new Date(DATA_MIN+'T00:00:00') : null;
        else start = new Date(end.getTime() - Number(days)*24*3600*1000);
        dateStartInput.value = fmtISO(start);
        dateEndInput.value   = fmtISO(end);
        applyPatientFilters();
    }
    presetBtns.forEach(btn=>{
        const v = btn.getAttribute('data-preset');
        if (!DATA_MIN || !DATA_MAX) btn.disabled = true;
        btn.addEventListener('click', ()=> setPreset(v === 'all' ? 'all' : Number(v)));
    });

    function clearFilters(){
        patientIdInput.value = '';
        testIdInput.value = '';
        locSelect.value = '';
        diagSelect.value = '';
        eyeOD.checked = true; eyeOS.checked = true;
        dateStartInput.value = fmtISO(defaultState.dateStart);
        dateEndInput.value   = fmtISO(defaultState.dateEnd);
        merciMinInput.value = ''; merciMaxInput.value = '';
        ageMinInput.value = ''; ageMaxInput.value = '';
        sortBy.value = 'subject'; sortDirBtn.setAttribute('data-dir','desc'); sortDirBtn.innerHTML = '<i class="bi bi-sort-down"></i>';
        applyPatientFilters();
    }
    $('#clearFilters').addEventListener('click', clearFilters);

    // Sorting controls
    sortBy.addEventListener('change', sortPatients);
    sortDirBtn.addEventListener('click', ()=>{
        const cur = sortDirBtn.getAttribute('data-dir');
        const next = cur === 'desc' ? 'asc' : 'desc';
        sortDirBtn.setAttribute('data-dir', next);
        sortDirBtn.innerHTML = next === 'desc' ? '<i class="bi bi-sort-down"></i>' : '<i class="bi bi-sort-up"></i>';
        sortPatients();
    });

    // Initial
    dateStartInput.value = fmtISO(defaultState.dateStart);
    dateEndInput.value   = fmtISO(defaultState.dateEnd);
    applyPatientFilters();

    // ===== Print (analytics images only) =====
    const printBtn  = document.getElementById('printSummaryBtn');
    const printArea = document.getElementById('printArea');
    const printTime = document.getElementById('printTime');
    printBtn.addEventListener('click', ()=>{
        printTime.textContent = new Date().toLocaleString();
        document.getElementById('printImg-tests').src = testsChart.canvas.toDataURL('image/png', 1.0);
        document.getElementById('printImg-diag').src  = diagChart.canvas.toDataURL('image/png', 1.0);
        document.getElementById('printImg-loc').src   = locChart.canvas.toDataURL('image/png', 1.0);
        document.getElementById('printImg-avg').src   = avgChart.canvas.toDataURL('image/png', 1.0);
        printArea.style.display = 'block';
        setTimeout(()=>window.print(), 100);
        setTimeout(()=>{ printArea.style.display='none'; }, 400);
    });

    // ===== Per-Patient Analytics Modal (respects patient filters) =====
    const modalEl = document.getElementById('patientModal');
    const patientModal = new bootstrap.Modal(modalEl);
    let pmCharts = { tests:null, diag:null, avg:null };

    function destroyPmCharts(){ Object.values(pmCharts).forEach(ch => ch?.destroy()); pmCharts = {tests:null, diag:null, avg:null}; }
    function parseMonth(ts){ return (ts||'').slice(0,7); }

    function openPatientAnalytics(pid, subject){
        document.getElementById('pmSubject').textContent = subject;
        document.getElementById('pmId').textContent = pid;
        modalEl.setAttribute('data-patient-id', pid);

        // Build from visible (filtered) rows for this patient
        const rows = Array.from(document.querySelectorAll(`.patient-item[data-patient-id="${pid}"] .eye-row`))
            .filter(el => el.style.display !== 'none')
            .map(el => ({
                test_id: el.getAttribute('data-testid'),
                date_of_test: el.getAttribute('data-test-date'),
                report_diagnosis: el.getAttribute('data-diagnosis'),
                eye: el.getAttribute('data-eye'),
                oct_score: Number(el.getAttribute('data-oct')) || null,
                vf_score: Number(el.getAttribute('data-vf')) || null
            }));

        // month agg
        const end = new Date();
        const endMonth = new Date(end.getFullYear(), end.getMonth(), 1);
        const labels = []; const counts = [];
        const perYM = new Map(); // ym -> set(test_id)
        rows.forEach(r => {
            const ym = parseMonth(r.date_of_test);
            if (!perYM.has(ym)) perYM.set(ym, new Set());
            perYM.get(ym).add(r.test_id);
        });
        for (let i=11;i>=0;i--){
            const d = new Date(endMonth); d.setMonth(d.getMonth()-i);
            const ym = d.toISOString().slice(0,7);
            labels.push(d.toLocaleString(undefined,{month:'short', year:'numeric'}));
            counts.push(perYM.has(ym) ? perYM.get(ym).size : 0);
        }

        const diagCounts = {'normal':0,'abnormal':0,'exclude':0,'no input':0};
        rows.forEach(r => { if (diagCounts.hasOwnProperty(r.report_diagnosis)) diagCounts[r.report_diagnosis]++; });

        const eyeScores = { OD:{oct:[],vf:[]}, OS:{oct:[],vf:[]} };
        rows.forEach(r => {
            if (r.eye==='OD'){ if(!isNaN(r.oct_score)&&r.oct_score!==null) eyeScores.OD.oct.push(r.oct_score); if(!isNaN(r.vf_score)&&r.vf_score!==null) eyeScores.OD.vf.push(r.vf_score);}
            if (r.eye==='OS'){ if(!isNaN(r.oct_score)&&r.oct_score!==null) eyeScores.OS.oct.push(r.oct_score); if(!isNaN(r.vf_score)&&r.vf_score!==null) eyeScores.OS.vf.push(r.vf_score);}
        });
        const avg = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : null;
        const avgOD = {oct: avg(eyeScores.OD.oct), vf: avg(eyeScores.OD.vf)};
        const avgOS = {oct: avg(eyeScores.OS.oct), vf: avg(eyeScores.OS.vf)};

        destroyPmCharts();

        pmCharts.tests = new Chart(document.getElementById('pmTests').getContext('2d'), {
            type:'line',
            data:{ labels, datasets:[{ label:'Tests', data: counts, borderColor: C.brand,
                backgroundColor:(ctx)=>{ const ch=ctx.chart; const g=ch.ctx.createLinearGradient(0,0,0,ch.height); g.addColorStop(0,'rgba(26,115,232,.35)'); g.addColorStop(1,'rgba(26,115,232,.05)'); return g; },
                tension:.35, fill:true, borderWidth:3, pointRadius:3 }]},
            options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{color:gridColor()}}, y:{ beginAtZero:true, grid:{color:gridColor()}} } }
        });

        pmCharts.diag = new Chart(document.getElementById('pmDiag').getContext('2d'), {
            type:'doughnut',
            data:{ labels:['Normal','Abnormal','Exclude','No Input'], datasets:[{ data:[diagCounts['normal'],diagCounts['abnormal'],diagCounts['exclude'],diagCounts['no input']], backgroundColor:[C.green,C.red,C.gray,C.amber], borderWidth:2, borderColor:'#fff' }] },
            options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{position:'bottom'}, datalabels:{ color:'#111', formatter:(v,ctx)=>{ const t=ctx.dataset.data.reduce((a,b)=>a+b,0); const p=t?(v/t*100):0; return p>=6?`${p.toFixed(0)}%`:''; } } } }
        });

        pmCharts.avg = new Chart(document.getElementById('pmAvg').getContext('2d'), {
            type:'bar',
            data:{ labels:['OD','OS'], datasets:[
                { label:'Avg OCT', data:[avgOD.oct, avgOS.oct], backgroundColor:C.purple, borderColor:C.purple, borderWidth:1, borderRadius:8, maxBarThickness:36 },
                { label:'Avg VF',  data:[avgOD.vf,  avgOS.vf],  backgroundColor:C.brand,  borderColor:C.brand,  borderWidth:1, borderRadius:8, maxBarThickness:36 }
            ]},
            options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{display:false}}, y:{ beginAtZero:true, grid:{color:gridColor()}} }, plugins:{ datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=>(v===null||isNaN(v))?'':fmt.format(v) } } }
        });

        patientModal.show();
    }

    document.addEventListener('click', (e)=>{
        const btn = e.target.closest('.btn-patient-analytics');
        if (!btn) return;
        openPatientAnalytics(btn.getAttribute('data-patient'), btn.getAttribute('data-subject'));
    });
    document.getElementById('patientModal').addEventListener('hidden.bs.modal', ()=>{ destroyPmCharts(); });

})();
</script>
</body>
</html>
