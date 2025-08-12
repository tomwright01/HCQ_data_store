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
if ($res = $conn->query("SELECT COUNT(*) AS c FROM test_eyes")){ $row = $res->fetch_assoc(); $totalEyes     = (int)$row['c']; }

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
// Raw rows for client-side filtering & charts (one per-eye)
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
<title>Patient Data Dashboard</title>

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
.search-box { position: relative; margin-bottom: 20px; }
.search-box i { position: absolute; top: 10px; left: 10px; color: var(--muted); }
.search-input { padding-left: 35px; }
.diagnosis-badge { font-size: 0.75rem; text-transform: uppercase; }
.normal { background-color: var(--ok); }
.abnormal { background-color: var(--danger); }
.exclude { background-color: var(--muted); }
.no-input { background-color: var(--warn); color: #000; }
.section-title { display:flex; align-items:center; gap:.5rem; }
.card-gradient { background: linear-gradient(135deg, var(--brand-2) 0%, var(--brand) 100%); color:#fff; }
.sticky-actions { position: sticky; top: 80px; z-index: 10; }
.pill { border-radius: 999px; }
.chart-card { background-image: var(--chart-grad); }
.chart-toolbar { display:flex; gap:.5rem; justify-content:flex-end; margin-bottom:.5rem; }
.chart-toolbar .btn { --bs-btn-padding-y: .25rem; --bs-btn-padding-x: .5rem; --bs-btn-font-size: .85rem; }
canvas { max-height: 380px; }

.filter-fab {
    position: fixed; right: 18px; bottom: 18px; z-index: 1000;
    display: none;
}
.filter-fab .btn { box-shadow: var(--shadow); }

/* Print */
@media print {
  body { background: #fff !important; }
  nav, .sticky-actions, .card:has(#filtersArea), .filter-fab, .btn, .navbar { display: none !important; }
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

    <!-- Analytics -->
    <div class="row mb-3" id="analytics">
        <div class="col">
            <h2 class="section-title"><i class="bi bi-graph-up-arrow"></i> Analytics</h2>
            <p class="text-muted">Interactive charts summarizing the dataset. Use the filters to explore.</p>
        </div>
    </div>

    <!-- Global Filters -->
    <div class="card mb-4" id="filtersArea">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-3">
                    <label class="form-label">Search</label>
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" class="form-control search-input" placeholder="Subject ID, Patient ID, Test ID, diagnosis...">
                    </div>
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

                <div class="col-12 col-lg-3">
                    <label class="form-label">Date range</label>
                    <div class="d-flex gap-2">
                        <input type="date" id="dateStart" class="form-control" value="<?= htmlspecialchars($minDate ?? '') ?>">
                        <input type="date" id="dateEnd" class="form-control" value="<?= htmlspecialchars($maxDate ?? '') ?>">
                    </div>
                    <!-- Quick presets -->
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

                <!-- Sorting -->
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
                                         aria-labelledby="heading-<?= htmlspecialchars($test['test_id']) ?>"
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
        <p class="text-muted">Charts respect current global filters.</p>
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
  <h2 class="mb-1">Patient Data Summary</h2>
  <p class="text-muted">Generated at <span id="printTime"></span></p>
  <hr>
  <h5>Active Filters</h5>
  <ul id="printFilters"></ul>
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
  <hr>
  <h5>Summary Tables</h5>
  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead><tr><th>Diagnosis</th><th>Count</th></tr></thead>
          <tbody id="printDiagTbl"></tbody>
        </table>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead><tr><th>Location</th><th>Patients</th></tr></thead>
          <tbody id="printLocTbl"></tbody>
        </table>
      </div>
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
// ===== Data for client filters/charts =====
const EYE_ROWS = <?= json_encode($eyeRows) ?>;
const DATA_MIN = <?= $minDate ? '"'.htmlspecialchars($minDate).'"' : 'null' ?>;
const DATA_MAX = <?= $maxDate ? '"'.htmlspecialchars($maxDate).'"' : 'null' ?>;
const MONTH_LABELS_BASE = <?= json_encode($monthLabels) ?>;
const TESTS_LAST12_BASE  = <?= json_encode($testsLast12Values) ?>;

(function chartsAndFilters(){
    // Utilities
    const $ = sel => document.querySelector(sel);
    const $$ = sel => Array.from(document.querySelectorAll(sel));
    const fmt = new Intl.NumberFormat();
    const toNum = v => v === null || v === '' || isNaN(v) ? null : Number(v);
    const parseDate = s => s ? new Date(s + 'T00:00:00') : null;
    const fmtISO = d => d ? d.toISOString().slice(0,10) : '';
    const cloneCanvasToImg = (canvas) => canvas.toDataURL('image/png', 1.0);

    // Inputs
    const searchInput    = $('#searchInput');
    const locSelect      = $('#locationFilter');
    theDiagSelect      = $('#diagnosisFilter');
    const diagSelect      = $('#diagnosisFilter');
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
    const filterFab      = $('#filterFab');
    const filtersPill    = $('#filtersPill');
    const filtersCount   = $('#filtersCount');
    const presetBtns     = $$('[data-preset]');
    const sortBy         = $('#sortBy');
    const sortDirBtn     = $('#sortDir');

    // Print elems
    const printBtn   = $('#printSummaryBtn');
    const printArea  = $('#printArea');
    const printTime  = $('#printTime');
    const printFilters = $('#printFilters');
    const printDiagTbl = $('#printDiagTbl');
    const printLocTbl  = $('#printLocTbl');

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
            search: (searchInput.value || '').trim().toLowerCase(),
            location: (locSelect.value || '').trim(),
            diagnosis: (diagSelect.value || '').trim(),
            eyes,
            dateStart: parseDate(dateStartInput.value),
            dateEnd: parseDate(dateEndInput.value),
            merciMin: toNum(merciMinInput.value),
            merciMax: toNum(merciMaxInput.value),
            ageMin: toNum(ageMinInput.value),
            ageMax: toNum(ageMaxInput.value),
        };
    }

    function rowMatches(r, f){
        // 🔎 include patient_id in text search
        const t = `${r.subject_id||''} ${r.patient_id||''} ${r.test_id||''} ${r.report_diagnosis||''}`.toLowerCase();
        if (f.search && !t.includes(f.search)) return false;

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

    // ===== CHARTS =====
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
        const top = document.body.classList.contains('dark') ? 'rgba(110,168,254,0.35)' : 'rgba(26,115,232,0.35)';
        const bot = document.body.classList.contains('dark') ? 'rgba(110,168,254,0.05)' : 'rgba(26,115,232,0.05)';
        const g = chart.ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        g.addColorStop(0, top); g.addColorStop(1, bot);
        return g;
    }

    const testsCtx = document.getElementById('testsOverTime').getContext('2d');
    const diagCtx  = document.getElementById('diagnosisPie').getContext('2d');
    const locCtx   = document.getElementById('locationBar').getContext('2d');
    const avgCtx   = document.getElementById('avgScoresEye').getContext('2d');

    const testsChart = new Chart(testsCtx, {
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
                tooltip:{ callbacks:{ label:ctx=>` ${fmt.format(ctx.parsed.y)} tests`} },
                zoom:{ zoom:{ wheel:{enabled:true}, pinch:{enabled:true}, mode:'x' }, pan:{enabled:true, mode:'x'} }
            }
        }
    });

    const diagChart = new Chart(diagCtx, {
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
                datalabels:{ color:'#111', formatter:(v,ctx)=>{ const total=ctx.dataset.data.reduce((a,b)=>a+b,0)||1; const p=v/total*100; return p>=6?`${p.toFixed(0)}%`:''; } }
            }
        }
    });

    const locChart = new Chart(locCtx, {
        type:'bar',
        data:{
            labels: <?= json_encode(array_keys($byLocation)) ?>,
            datasets:[{ label:'Patients', data: <?= json_encode(array_values($byLocation)) ?>,
                backgroundColor:C.teal, borderColor:C.teal, borderWidth:1, borderRadius:8, maxBarThickness:44 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() }}},
            plugins:{
                legend:{ display:false },
                datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=> (v>=3?fmt.format(v):'') }
            }
        }
    });

    const avgChart = new Chart(avgCtx, {
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
            scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() }}},
            plugins:{
                datalabels:{ anchor:'end', align:'end', offset:4, color:'#333',
                    formatter:v => (v===null||isNaN(v))?'':fmt.format(v) }
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

    // ===== Aggregation for filtered rows =====
    function aggregateForCharts(rows){
        const map = new Map(); // ym -> Set(test_id)
        rows.forEach(r => {
            const ym = (r.date_of_test||'').slice(0,7);
            if (!ym) return;
            if (!map.has(ym)) map.set(ym, new Set());
            map.get(ym).add(r.test_id);
        });

        const endDate = getFilterState().dateEnd || new Date();
        const endMonth = new Date(endDate.getFullYear(), endDate.getMonth(), 1);
        const labels = [];
        const values = [];
        for (let i=11;i>=0;i--){
            const d = new Date(endMonth); d.setMonth(d.getMonth()-i);
            const ym = d.toISOString().slice(0,7);
            const label = d.toLocaleString(undefined,{month:'short', year:'numeric'});
            labels.push(label);
            values.push(map.has(ym) ? map.get(ym).size : 0);
        }

        const diagCounts = {'normal':0,'abnormal':0,'exclude':0,'no input':0};
        rows.forEach(r => { if (diagCounts.hasOwnProperty(r.report_diagnosis)) diagCounts[r.report_diagnosis]++; });

        const locMap = {'KH':new Set(),'CHUSJ':new Set(),'IWK':new Set(),'IVEY':new Set()};
        rows.forEach(r => { if (locMap[r.location]) locMap[r.location].add(r.patient_id); });
        const locLabels = Object.keys(locMap);
        const locVals = locLabels.map(k => locMap[k].size);

        const eyeStats = { OD:{oct:[],vf:[]}, OS:{oct:[],vf:[]} };
        rows.forEach(r => {
            if (!eyeStats[r.eye]) return;
            if (r.oct_score !== null) eyeStats[r.eye].oct.push(Number(r.oct_score));
            if (r.vf_score  !== null) eyeStats[r.eye].vf.push(Number(r.vf_score));
        });
        const avg = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : null;
        const avgOD = {oct: avg(eyeStats.OD.oct), vf: avg(eyeStats.OD.vf)};
        const avgOS = {oct: avg(eyeStats.OS.oct), vf: avg(eyeStats.OS.vf)};

        return { monthLabels:labels, monthCounts:values, diagCounts, locLabels, locVals, avgOD, avgOS };
    }

    function filterRows(){
        const f = getFilterState();
        return EYE_ROWS.filter(r => rowMatches(r,f));
    }

    // ===== Update charts according to current filters =====
    function updateCharts(){
        const rows = filterRows();
        const agg = aggregateForCharts(rows);

        testsChart.data.labels = agg.monthLabels;
        testsChart.data.datasets[0].data = agg.monthCounts;
        testsChart.update('none');

        const diagOrder = ['normal','abnormal','exclude','no input'];
        const diagVals = diagOrder.map(k => agg.diagCounts[k] || 0);
        diagChart.data.labels = ['Normal','Abnormal','Exclude','No Input'];
        diagChart.data.datasets[0].data = diagVals;
        diagChart.update('none');

        locChart.data.labels = agg.locLabels;
        locChart.data.datasets[0].data = agg.locVals;
        locChart.update('none');

        avgChart.data.datasets[0].data = [agg.avgOD.oct, agg.avgOS.oct];
        avgChart.data.datasets[1].data = [agg.avgOD.vf,  agg.avgOS.vf];
        avgChart.update('none');
    }

    // ===== CSV export =====
    function downloadCSV(filename, rows){
        const csv = rows.map(r=>r.map(v=>{
            v = (v===null||v===undefined) ? '' : String(v);
            if (v.includes('"') || v.includes(',') || v.includes('\n')) {
                v = '"' + v.replace(/"/g,'""') + '"';
            }
            return v;
        }).join(',')).join('\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 500);
    }

    function csvFor(chartId){
        const rows = filterRows();
        if (chartId === 'testsOverTime') {
            const a = aggregateForCharts(rows);
            const data = [['Month','Tests']];
            a.monthLabels.forEach((lab, i)=> data.push([lab, a.monthCounts[i]]));
            return data;
        }
        if (chartId === 'diagnosisPie') {
            const a = aggregateForCharts(rows);
            const data = [['Diagnosis','Count']];
            [['Normal','normal'],['Abnormal','abnormal'],['Exclude','exclude'],['No Input','no input']].forEach(([lab,key])=>{
                data.push([lab, a.diagCounts[key]||0]);
            });
            return data;
        }
        if (chartId === 'locationBar') {
            const a = aggregateForCharts(rows);
            const data = [['Location','Patients']];
            a.locLabels.forEach((lab, i)=> data.push([lab, a.locVals[i]]));
            return data;
        }
        if (chartId === 'avgScoresEye') {
            const a = aggregateForCharts(rows);
            return [['Eye','Avg OCT','Avg VF'], ['OD', a.avgOD.oct, a.avgOD.vf], ['OS', a.avgOS.oct, a.avgOS.vf]];
        }
        if (chartId.startsWith('pm')) {
            return patientCsvFor(chartId);
        }
        return [['Info'],['Unsupported']];
    }

    function exportFilteredRowsCSV(){
        const rows = filterRows();
        const header = ['subject_id','patient_id','test_id','date_of_test','eye','age','report_diagnosis','merci_score','oct_score','vf_score','location'];
        const data = rows.map(r => [
            r.subject_id, r.patient_id, r.test_id, r.date_of_test, r.eye,
            r.age ?? '', r.report_diagnosis, r.merci_score ?? '', r.oct_score ?? '', r.vf_score ?? '', r.location
        ]);
        downloadCSV('filtered_eye_rows.csv', [header, ...data]);
    }

    // wire toolbar buttons
    function wireDownloads(){
        $$('[data-download]').forEach(btn=>{
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
        $$('[data-csv]').forEach(btn=>{
            btn.addEventListener('click', ()=>{
                const id = btn.getAttribute('data-csv');
                const data = csvFor(id);
                downloadCSV(`${id}.csv`, data);
            });
        });
        $$('[data-resetzoom="testsOverTime"]').forEach(btn=>{
            btn.addEventListener('click', ()=> testsChart.resetZoom());
        });
        exportBtn.addEventListener('click', exportFilteredRowsCSV);
    }
    wireDownloads();

    // ===== Filter the DOM list (patients/tests/eyes) =====
    function filterDOM(){
        const f = getFilterState();
        const patientCards = $$('.patient-item');

        patientCards.forEach(patient => {
            const tests = patient.querySelectorAll('.test-wrapper');
            let anyTestVisible = false;

            tests.forEach(tw => {
                const testDate = tw.getAttribute('data-test-date') || '';
                const testRows = tw.querySelectorAll('.eye-row');
                let anyEyeVisible = false;

                testRows.forEach(row => {
                    // 🔎 include patient_id from closest patient card
                    const patientEl = row.closest('.patient-item');
                    const r = {
                        patient_id: patientEl?.getAttribute('data-patient-id') || '',
                        subject_id: row.getAttribute('data-subject') || '',
                        test_id: row.getAttribute('data-testid') || '',
                        report_diagnosis: row.getAttribute('data-diagnosis') || '',
                        location: row.getAttribute('data-location') || '',
                        eye: row.getAttribute('data-eye') || '',
                        date_of_test: row.getAttribute('data-test-date') || testDate,
                        merci_score: row.getAttribute('data-merci'),
                        age: row.getAttribute('data-age')
                    };
                    const visible = rowMatches(r, f);
                    row.style.display = visible ? 'block':'none';
                    if (visible) anyEyeVisible = true;
                });

                const count = tw.querySelectorAll('.eye-row[style*="display: block"]').length;
                const countEl = tw.querySelector('.eye-count');
                if (countEl) countEl.textContent = count;

                tw.style.display = anyEyeVisible ? 'block':'none';
                if (anyEyeVisible) anyTestVisible = true;
            });

            patient.style.display = anyTestVisible ? 'block':'none';
        });
    }

    // ===== Sorting =====
    function sortPatients(){
        const container = $('#patientContainer');
        const items = Array.from(container.querySelectorAll('.patient-item'));
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

        items.forEach(it => container.appendChild(it));
    }

    sortBy.addEventListener('change', sortPatients);
    sortDirBtn.addEventListener('click', ()=>{
        const cur = sortDirBtn.getAttribute('data-dir');
        const next = cur === 'desc' ? 'asc' : 'desc';
        sortDirBtn.setAttribute('data-dir', next);
        sortDirBtn.innerHTML = next === 'desc' ? '<i class="bi bi-sort-down"></i>' : '<i class="bi bi-sort-up"></i>';
        sortPatients();
    });

    // ===== Filters active pill =====
    function countActiveFilters(){
        const f = getFilterState();
        let n = 0;
        if (f.search) n++;
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
        if (n > 0) {
            filtersCount.textContent = n;
            filterFab.style.display = 'block';
        } else {
            filterFab.style.display = 'none';
        }
    }

    filtersPill.addEventListener('click', ()=>{
        clearFilters();
        window.scrollTo({top: 0, behavior: 'smooth'});
    });

    // ===== Apply filters =====
    function applyFilters(){
        filterDOM();
        updateCharts();
        updateFiltersPill();
        sortPatients();
    }

    // Debounced search
    let searchTimer;
    searchInput.addEventListener('input', ()=>{ clearTimeout(searchTimer); searchTimer=setTimeout(applyFilters, 150); });
    [locSelect, diagSelect, eyeOD, eyeOS, dateStartInput, dateEndInput, merciMinInput, merciMaxInput, ageMinInput, ageMaxInput]
        .forEach(el => el.addEventListener('change', applyFilters));

    // Quick date presets
    function setPreset(days){
        const max = DATA_MAX ? new Date(DATA_MAX+'T00:00:00') : new Date();
        let start = null, end = max;
        if (days === 'all') {
            start = DATA_MIN ? new Date(DATA_MIN+'T00:00:00') : null;
        } else {
            const ms = Number(days) * 24*3600*1000;
            start = new Date(end.getTime() - ms);
        }
        dateStartInput.value = fmtISO(start);
        dateEndInput.value   = fmtISO(end);
        applyFilters();
    }
    presetBtns.forEach(btn=>{
        const v = btn.getAttribute('data-preset');
        if (!DATA_MIN || !DATA_MAX) btn.disabled = true;
        btn.addEventListener('click', ()=> setPreset(v === 'all' ? 'all' : Number(v)));
    });

    function clearFilters(){
        searchInput.value = '';
        locSelect.value = '';
        diagSelect.value = '';
        eyeOD.checked = true; eyeOS.checked = true;
        dateStartInput.value = fmtISO(defaultState.dateStart);
        dateEndInput.value   = fmtISO(defaultState.dateEnd);
        merciMinInput.value = ''; merciMaxInput.value = '';
        ageMinInput.value = ''; ageMaxInput.value = '';
        sortBy.value = 'subject'; sortDirBtn.setAttribute('data-dir','desc'); sortDirBtn.innerHTML = '<i class="bi bi-sort-down"></i>';
        applyFilters();
    }
    clearBtn.addEventListener('click', clearFilters);

    // Initial
    dateStartInput.value = fmtISO(defaultState.dateStart);
    dateEndInput.value   = fmtISO(defaultState.dateEnd);
    applyFilters();

    // ===== Printable Summary =====
    const printDiagTbl = document.getElementById('printDiagTbl');
    const printLocTbl  = document.getElementById('printLocTbl');
    function listActiveFilters(){
        const f = getFilterState();
        const items = [];
        if (f.search) items.push(`Search: "${f.search}"`);
        if (f.location) items.push(`Location: ${f.location}`);
        if (f.diagnosis) items.push(`Diagnosis: ${f.diagnosis}`);
        if (!(f.eyes.has('OD') && f.eyes.has('OS'))) items.push(`Eye: ${Array.from(f.eyes).join('/')}`);
        if (f.dateStart || f.dateEnd) items.push(`Dates: ${f.dateStart?fmtISO(f.dateStart):'…'} to ${f.dateEnd?fmtISO(f.dateEnd):'…'}`);
        if (f.merciMin !== null || f.merciMax !== null) items.push(`MERCI: ${f.merciMin??'…'} to ${f.merciMax??'…'}`);
        if (f.ageMin !== null || f.ageMax !== null) items.push(`Age: ${f.ageMin??'…'} to ${f.ageMax??'…'}`);
        return items;
    }

    function preparePrint(){
        const rows = filterRows();
        const agg = aggregateForCharts(rows);

        document.getElementById('printTime').textContent = new Date().toLocaleString();

        const ul = document.getElementById('printFilters');
        ul.innerHTML = '';
        const liItems = listActiveFilters();
        if (liItems.length === 0) ul.innerHTML = '<li>None</li>';
        else liItems.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });

        document.getElementById('printImg-tests').src = cloneCanvasToImg(document.getElementById('testsOverTime'));
        document.getElementById('printImg-diag').src  = cloneCanvasToImg(document.getElementById('diagnosisPie'));
        document.getElementById('printImg-loc').src   = cloneCanvasToImg(document.getElementById('locationBar'));
        document.getElementById('printImg-avg').src   = cloneCanvasToImg(document.getElementById('avgScoresEye'));

        printDiagTbl.innerHTML = '';
        [['Normal','normal'],['Abnormal','abnormal'],['Exclude','exclude'],['No Input','no input']].forEach(([lab,key])=>{
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${lab}</td><td>${agg.diagCounts[key]||0}</td>`;
            printDiagTbl.appendChild(tr);
        });

        printLocTbl.innerHTML = '';
        agg.locLabels.forEach((lab,i)=>{
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${lab}</td><td>${agg.locVals[i]}</td>`;
            printLocTbl.appendChild(tr);
        });
    }

    document.getElementById('printSummaryBtn').addEventListener('click', ()=>{
        preparePrint();
        document.getElementById('printArea').style.display = 'block';
        setTimeout(()=>window.print(), 100);
        setTimeout(()=>{ document.getElementById('printArea').style.display='none'; }, 400);
    });

    // ===== Per-Patient Analytics Modal =====
    const modalEl = document.getElementById('patientModal');
    const patientModal = new bootstrap.Modal(modalEl);
    let pmCharts = { tests:null, diag:null, avg:null };
    function destroyPmCharts(){
        Object.values(pmCharts).forEach(ch => { if (ch) ch.destroy(); });
        pmCharts = { tests:null, diag:null, avg:null };
    }

    function filterRowsForPatient(patientId){
        return filterRows().filter(r => r.patient_id === patientId);
    }

    function patientAggregate(patientRows){
        return aggregateForCharts(patientRows);
    }

    function patientCsvFor(chartId){
        const pid = modalEl.getAttribute('data-patient-id');
        const rows = filterRowsForPatient(pid);
        const a = patientAggregate(rows);
        if (chartId === 'pmTests') {
            const data = [['Month','Tests']];
            a.monthLabels.forEach((lab, i)=> data.push([lab, a.monthCounts[i]]));
            return data;
        }
        if (chartId === 'pmDiag') {
            const data = [['Diagnosis','Count']];
            [['Normal','normal'],['Abnormal','abnormal'],['Exclude','exclude'],['No Input','no input']].forEach(([lab,key])=>{
                data.push([lab, a.diagCounts[key]||0]);
            });
            return data;
        }
        if (chartId === 'pmAvg') {
            return [['Eye','Avg OCT','Avg VF'], ['OD', a.avgOD.oct, a.avgOD.vf], ['OS', a.avgOS.oct, a.avgOS.vf]];
        }
        return [['Info'],['Unsupported']];
    }
    window.patientCsvFor = patientCsvFor;

    function openPatientAnalytics(pid, subject){
        document.getElementById('pmSubject').textContent = subject;
        document.getElementById('pmId').textContent = pid;
        modalEl.setAttribute('data-patient-id', pid);

        const rows = filterRowsForPatient(pid);
        const agg = patientAggregate(rows);

        destroyPmCharts();

        const pmTestsCtx = document.getElementById('pmTests').getContext('2d');
        pmCharts.tests = new Chart(pmTestsCtx, {
            type:'line',
            data:{ labels: agg.monthLabels, datasets:[{ label:'Tests', data: agg.monthCounts, borderColor: C.brand,
                backgroundColor:(ctx)=> (function(){ const ch=ctx.chart; const {top,bottom}=ch.chartArea||{top:0,bottom:0}; const g=ch.ctx.createLinearGradient(0,top,0,bottom); g.addColorStop(0,'rgba(26,115,232,.35)'); g.addColorStop(1,'rgba(26,115,232,.05)'); return g; })(), tension:.35, fill:true, borderWidth:3, pointRadius:3 }]},
            options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{color:gridColor()}}, y:{ beginAtZero:true, grid:{color:gridColor()}} } }
        });

        const pmDiagCtx = document.getElementById('pmDiag').getContext('2d');
        const diagVals = ['normal','abnormal','exclude','no input'].map(k=>agg.diagCounts[k]||0);
        pmCharts.diag = new Chart(pmDiagCtx,{
            type:'doughnut',
            data:{ labels:['Normal','Abnormal','Exclude','No Input'], datasets:[{ data:diagVals, backgroundColor:[C.green,C.red,C.gray,C.amber], borderWidth:2, borderColor:'#fff' }]},
            options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{position:'bottom'}, datalabels:{ color:'#111', formatter:(v,ctx)=>{ const t=ctx.dataset.data.reduce((a,b)=>a+b,0); const p=t?(v/t*100):0; return p>=6?`${p.toFixed(0)}%`:''; } } } }
        });

        const pmAvgCtx = document.getElementById('pmAvg').getContext('2d');
        pmCharts.avg = new Chart(pmAvgCtx,{
            type:'bar',
            data:{ labels:['OD','OS'], datasets:[
                { label:'Avg OCT', data:[agg.avgOD.oct, agg.avgOS.oct], backgroundColor:C.purple, borderColor:C.purple, borderWidth:1, borderRadius:8, maxBarThickness:36 },
                { label:'Avg VF',  data:[agg.avgOD.vf,  agg.avgOS.vf],  backgroundColor:C.brand,  borderColor:C.brand,  borderWidth:1, borderRadius:8, maxBarThickness:36 }
            ]},
            options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{display:false}}, y:{ beginAtZero:true, grid:{color:gridColor()}} }, plugins:{ datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=>(v===null||isNaN(v))?'':fmt.format(v) } } }
        });

        patientModal.show();
    }

    document.addEventListener('click', (e)=>{
        const btn = e.target.closest('.btn-patient-analytics');
        if (!btn) return;
        const pid = btn.getAttribute('data-patient');
        const subject = btn.getAttribute('data-subject');
        openPatientAnalytics(pid, subject);
    });

    modalEl.addEventListener('hidden.bs.modal', destroyPmCharts);

    // wire CSV/PNG inside modal (reuse global handlers)
    document.querySelectorAll('#patientModal [data-csv], #patientModal [data-download]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            const id = btn.getAttribute('data-csv') || btn.getAttribute('data-download');
            if (btn.hasAttribute('data-download')) {
                const canvas = document.getElementById(id);
                if (!canvas) return;
                const link = document.createElement('a');
                link.href = canvas.toDataURL('image/png', 1.0);
                link.download = `${id}.png`;
                link.click();
            } else {
                const data = csvFor(id);
                downloadCSV(`${id}.csv`, data);
            }
        });
    });
})();
</script>
</body>
</html>
