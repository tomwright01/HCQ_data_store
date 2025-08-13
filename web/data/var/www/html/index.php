<<?php
session_start();
if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf'];

require_once 'includes/config.php';
require_once 'includes/functions.php';

/* ----------------------------
   Fast KPIs
----------------------------- */
$totalPatients = 0;
$totalTests = 0;
$totalEyes = 0;

if ($res = $conn->query("SELECT COUNT(*) AS c FROM patients")) { $row = $res->fetch_assoc(); $totalPatients = (int)($row['c'] ?? 0); }
if ($res = $conn->query("SELECT COUNT(*) AS c FROM tests"))    { $row = $res->fetch_assoc(); $totalTests    = (int)($row['c'] ?? 0); }
if ($res = $conn->query("SELECT COUNT(*) AS c FROM test_eyes")){ $row = $res->fetch_assoc(); $totalEyes     = (int)($row['c'] ?? 0); }

/* ----------------------------
   Server-side analytics (static, whole dataset)
----------------------------- */
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

/* ----------------------------
   Data for patient list
----------------------------- */
$patients = getPatientsWithTests($conn);
function safe_get($arr, $key, $default = null) { return is_array($arr) && array_key_exists($key, $arr) ? $arr[$key] : $default; }

/* ----------------------------
   Raw rows (one per eye) for CSV export/per-patient charts
----------------------------- */
$eyeRows = [];
$sqlAll = "
SELECT
  te.result_id, te.test_id, te.eye, te.age, te.report_diagnosis, te.merci_score, te.oct_score, te.vf_score,
  te.faf_grade, te.actual_diagnosis, te.dosage, te.duration_days, te.cumulative_dosage, te.date_of_continuation,
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
            'faf_grade'        => is_null($row['faf_grade']) ? null : (int)$row['faf_grade'],
            'actual_diagnosis' => $row['actual_diagnosis'],
            'dosage'           => is_null($row['dosage']) ? null : (float)$row['dosage'],
            'duration_days'    => is_null($row['duration_days']) ? null : (int)$row['duration_days'],
            'cumulative_dosage'=> is_null($row['cumulative_dosage']) ? null : (float)$row['cumulative_dosage'],
            'date_of_continuation' => $row['date_of_continuation'],
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
<meta name="csrf-token" content="<?= htmlspecialchars($CSRF) ?>">

<!-- Bootstrap & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<!-- Chart.js + plugins -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.5/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.umd.min.js"></script>

<style>
:root{
    --brand:#1a73e8; --brand-2:#6ea8fe; --ok:#198754; --warn:#ffc107; --danger:#dc3545;
    --muted:#6c757d; --bg:#f7f8fb; --card:#ffffff; --text:#111827; --grid:rgba(0,0,0,.06);
    --border:rgba(0,0,0,.08); --shadow: 0 8px 24px rgba(0,0,0,0.09);
    --chart-grad: radial-gradient(1200px 400px at 10% 0%, rgba(26,115,232,.06), transparent 60%);
}
body.dark{ --bg:#0f1220; --card:#151a2c; --text:#e5e7eb; --grid:rgba(255,255,255,.12); --border:rgba(255,255,255,.14);
    --muted:#9aa4b2; --shadow: 0 12px 28px rgba(0,0,0,0.45);
    --chart-grad: radial-gradient(1200px 400px at 10% 0%, rgba(110,168,254,.12), transparent 60%); }
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

/* Edit mode helpers */
.view-field .edit-input { display:none; }
.editing .view-field .view-val { display:none; }
.editing .view-field .edit-input { display:block; }
.inline-actions { display:none; }
.editing .inline-actions { display:block; }
.editing .view-actions { display:none; }

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
    <div class="row g-4 mb-4">
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
                    <input type="text" id="testIdInput" class="form-control" placeholder="e.g. 20240101_OS_XXXX">
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

                <div class="col-12 col-lg-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="autoExpand" checked>
                        <label class="form-check-label" for="autoExpand">Auto-expand first match</label>
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
                            <span class="patient-subject"><?= htmlspecialchars($patient['subject_id']) ?></span>
                        </h5>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-sm btn-outline-secondary btn-patient-analytics"
                                    data-patient="<?= htmlspecialchars($patient['patient_id']) ?>"
                                    data-subject="<?= htmlspecialchars($patient['subject_id']) ?>">
                                <i class="bi bi-graph-up"></i> Analytics
                            </button>

                            <!-- Edit toggles for this patient -->
                            <div class="view-actions">
                                <button class="btn btn-sm btn-outline-primary btn-edit-patient" title="Edit this patient card">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                            </div>
                            <div class="inline-actions">
                                <button class="btn btn-sm btn-success btn-save-patient"><i class="bi bi-check2"></i> Save</button>
                                <button class="btn btn-sm btn-outline-secondary btn-cancel-edit"><i class="bi bi-x"></i> Cancel</button>
                            </div>

                            <span class="badge bg-secondary"><?= htmlspecialchars($patient['location']) ?></span>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- Patient-level editable fields -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Patient ID:</strong> <?= htmlspecialchars($patient['patient_id']) ?></p>
                                <div class="view-field mb-2">
                                    <label class="form-label mb-0"><strong>Subject:</strong></label>
                                    <div class="view-val"><?= htmlspecialchars($patient['subject_id']) ?></div>
                                    <input class="form-control form-control-sm edit-input edit-patient-field" data-field="subject_id"
                                           value="<?= htmlspecialchars($patient['subject_id']) ?>">
                                </div>
                                <div class="view-field mb-2">
                                    <label class="form-label mb-0"><strong>Location:</strong></label>
                                    <div class="view-val"><?= htmlspecialchars($patient['location']) ?></div>
                                    <select class="form-select form-select-sm edit-input edit-patient-field" data-field="location">
                                        <?php foreach (['KH','CHUSJ','IWK','IVEY'] as $loc): ?>
                                            <option value="<?= $loc ?>" <?= $loc === $patient['location'] ? 'selected':'' ?>><?= $loc ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="view-field mb-2">
                                    <label class="form-label mb-0"><strong>DOB:</strong></label>
                                    <div class="view-val"><?= $dob->format('M j, Y') ?></div>
                                    <input type="date" class="form-control form-control-sm edit-input edit-patient-field" data-field="date_of_birth"
                                           value="<?= htmlspecialchars($dob->format('Y-m-d')) ?>">
                                </div>
                                <p class="mb-0"><strong>Tests:</strong> <?= count($tests) ?></p>
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
                                            <strong class="me-2">Test:</strong>
                                            <span class="badge bg-primary me-2"><?= htmlspecialchars($test['test_id']) ?></span>

                                            <!-- Test date (editable) -->
                                            <span class="view-val me-2"><i class="bi bi-calendar-event"></i> <?= $testDate->format('M j, Y') ?></span>
                                            <input type="date" class="form-control form-control-sm edit-input d-inline-block w-auto me-2 edit-test-date"
                                                   value="<?= htmlspecialchars($testDate->format('Y-m-d')) ?>">

                                            <span class="ms-auto badge bg-dark">
                                                <span class="eye-count"><?= count($testEyes) ?></span> eye records
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="collapse-<?= htmlspecialchars($test['test_id']) ?>" class="accordion-collapse collapse"
                                         aria-labelledby="heading-<?= htmlspecialchars($test['test_id']) ?>"
                                         data-bs-parent="#testsAccordion-<?= htmlspecialchars($patient['patient_id']) ?>">
                                        <div class="accordion-body">
                                            <!-- Test save buttons -->
                                            <div class="text-end mb-2">
                                                <button class="btn btn-sm btn-outline-primary btn-save-test">Save test date</button>
                                            </div>

                                            <?php foreach ($testEyes as $eye): ?>
                                                <?php
                                                    $eyeSide   = strtoupper($eye['eye']);
                                                    $eyeClass  = $eyeSide === 'OS' ? 'os-badge' : 'od-badge';
                                                    $diag      = $eye['report_diagnosis'];
                                                    $diagClass = ($diag === 'no input') ? 'no-input' : $diag;
                                                    $medName   = safe_get($eye, 'medication_name');
                                                    $dosage    = safe_get($eye, 'dosage');
                                                    $dosageUnit= safe_get($eye, 'dosage_unit', 'mg');
                                                    $merciVal  = is_numeric($eye['merci_score']) ? (float)$eye['merci_score'] : (is_string($eye['merci_score']) ? $eye['merci_score'] : null);
                                                    $ageAtTest = isset($eye['age']) ? (int)$eye['age'] : null;
                                                    $resultId  = safe_get($eye,'result_id');
                                                ?>
                                                <div class="card test-card mb-3 eye-row"
                                                     data-result-id="<?= htmlspecialchars($resultId) ?>"
                                                     data-eye="<?= htmlspecialchars($eyeSide) ?>"
                                                     data-diagnosis="<?= htmlspecialchars($diag) ?>"
                                                     data-merci="<?= is_null($merciVal)? '' : htmlspecialchars($merciVal) ?>"
                                                     data-age="<?= is_null($ageAtTest)? '' : htmlspecialchars($ageAtTest) ?>"
                                                     data-location="<?= htmlspecialchars($patient['location']) ?>"
                                                     data-subject="<?= htmlspecialchars($patient['subject_id']) ?>"
                                                     data-patient="<?= htmlspecialchars($patient['patient_id']) ?>"
                                                     data-testid="<?= htmlspecialchars($test['test_id']) ?>"
                                                     data-test-date="<?= htmlspecialchars($test['date_of_test']) ?>"
                                                     data-oct="<?= htmlspecialchars($eye['oct_score'] ?? '') ?>"
                                                     data-vf="<?= htmlspecialchars($eye['vf_score'] ?? '') ?>">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <div>
                                                                <span class="badge <?= $eyeClass ?> eye-badge"><?= htmlspecialchars($eyeSide) ?></span>
                                                                <span class="badge diagnosis-badge <?= htmlspecialchars($diagClass) ?>">
                                                                    <span class="view-val"><?= htmlspecialchars($diag) ?></span>
                                                                </span>
                                                            </div>
                                                            <div class="view-actions">
                                                                <button class="btn btn-sm btn-outline-secondary btn-edit-eye"><i class="bi bi-pencil"></i> Edit</button>
                                                            </div>
                                                            <div class="inline-actions">
                                                                <button class="btn btn-sm btn-success btn-save-eye"><i class="bi bi-check2"></i> Save</button>
                                                                <button class="btn btn-sm btn-outline-secondary btn-cancel-eye"><i class="bi bi-x"></i> Cancel</button>
                                                            </div>
                                                        </div>

                                                        <!-- View mode -->
                                                        <div class="row view-mode">
                                                            <div class="col-md-6">
                                                                <p><strong>Age at Test:</strong> <span class="v-age"><?= htmlspecialchars($eye['age'] ?? 'N/A') ?></span></p>
                                                                <p><strong>MERCI Score:</strong> <span class="v-merci"><?= htmlspecialchars($eye['merci_score'] ?? 'N/A') ?></span></p>
                                                                <p><strong>OCT Score:</strong> <span class="v-oct"><?= htmlspecialchars($eye['oct_score'] ?? 'N/A') ?></span></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <p><strong>VF Score:</strong> <span class="v-vf"><?= htmlspecialchars($eye['vf_score'] ?? 'N/A') ?></span></p>
                                                                <p><strong>FAF Grade:</strong> <span class="v-faf"><?= htmlspecialchars($eye['faf_grade'] ?? 'N/A') ?></span></p>
                                                                <p><strong>Diagnosis:</strong> <span class="v-actual"><?= htmlspecialchars($eye['actual_diagnosis'] ?? 'N/A') ?></span></p>
                                                            </div>
                                                        </div>

                                                        <!-- Edit mode -->
                                                        <div class="row g-2 edit-mode">
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Age at Test</label>
                                                                <input type="number" class="form-control form-control-sm e-age" value="<?= htmlspecialchars($eye['age'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">MERCI Score (or "unable")</label>
                                                                <input type="text" class="form-control form-control-sm e-merci" value="<?= htmlspecialchars($eye['merci_score'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">OCT Score</label>
                                                                <input type="number" step="0.01" class="form-control form-control-sm e-oct" value="<?= htmlspecialchars($eye['oct_score'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">VF Score</label>
                                                                <input type="number" step="0.01" class="form-control form-control-sm e-vf" value="<?= htmlspecialchars($eye['vf_score'] ?? '') ?>">
                                                            </div>

                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">FAF Grade</label>
                                                                <input type="number" class="form-control form-control-sm e-faf" value="<?= htmlspecialchars($eye['faf_grade'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Report Diagnosis</label>
                                                                <select class="form-select form-select-sm e-report">
                                                                    <?php foreach (['normal','abnormal','exclude','no input'] as $opt): ?>
                                                                        <option value="<?= $opt ?>" <?= ($opt === strtolower((string)$eye['report_diagnosis']))?'selected':''; ?>><?= ucfirst($opt) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label form-label-sm">Actual Diagnosis</label>
                                                                <input type="text" class="form-control form-control-sm e-actual" value="<?= htmlspecialchars($eye['actual_diagnosis'] ?? '') ?>">
                                                            </div>

                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Dosage</label>
                                                                <input type="number" step="0.01" class="form-control form-control-sm e-dosage" value="<?= htmlspecialchars($eye['dosage'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Duration (days)</label>
                                                                <input type="number" class="form-control form-control-sm e-duration" value="<?= htmlspecialchars($eye['duration_days'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Cumulative Dosage</label>
                                                                <input type="number" step="0.01" class="form-control form-control-sm e-cumulative" value="<?= htmlspecialchars($eye['cumulative_dosage'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label form-label-sm">Date of Continuation</label>
                                                                <input type="text" class="form-control form-control-sm e-cont" value="<?= htmlspecialchars($eye['date_of_continuation'] ?? '') ?>">
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

<!-- Sticky Filters FAB (mobile helper) -->
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

<!-- Printable Summary (static analytics) -->
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
    const toNum = v => (v===null || v==='' || isNaN(v)) ? null : Number(v);
    const parseDate = s => s ? new Date(s + 'T00:00:00') : null;
    const fmtISO = d => d ? d.toISOString().slice(0,10) : '';
    const cloneCanvasToImg = (canvas) => canvas.toDataURL('image/png', 1.0);
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Quick toast
    function flash(msg, type='info'){
        const el = document.createElement('div');
        el.className = `alert alert-${type} position-fixed top-0 start-50 translate-middle-x mt-3 shadow`;
        el.style.zIndex = 3000;
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(()=>{ el.remove(); }, 2200);
    }

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
    const autoExpand     = $('#autoExpand');

    // Results counters + filters badge
    const resPatients = $('#resPatients');
    const resTests    = $('#resTests');
    const resEyes     = $('#resEyes');
    const filterFab   = $('#filterFab');
    const filtersPill = $('#filtersPill');
    const filtersCount= $('#filtersCount');

    // Print elems (for static analytics)
    const printBtn   = $('#printSummaryBtn');
    const printArea  = $('#printArea');
    const printTime  = $('#printTime');

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

    // ===== Charts (STATIC analytics) =====
    Chart.register(ChartDataLabels);
    const C = { brand:'#1a73e8', brandLight:'#6ea8fe', green:'#198754', red:'#dc3545', amber:'#ffc107', gray:'#6c757d', teal:'#20c997', purple:'#6f42c1' };
    const shadowPlugin = { id: 'shadow', beforeDatasetsDraw(ch){ const {ctx}=ch; ctx.save(); ctx.shadowColor = document.body.classList.contains('dark') ? 'rgba(0,0,0,0.8)' : 'rgba(0,0,0,0.15)'; ctx.shadowBlur=12; ctx.shadowOffsetY=6; }, afterDatasetsDraw(ch){ ch.ctx.restore(); } };
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

    const testsChart = new Chart(document.getElementById('testsOverTime').getContext('2d'), {
        type: 'line',
        data: { labels: MONTH_LABELS_BASE.slice(), datasets: [{ label:'Tests', data: TESTS_LAST12_BASE.slice(), borderColor: C.brand, backgroundColor:(ctx)=>lineGradientFor(ctx.chart), tension:.35, fill:true, borderWidth:3, pointRadius:3 }]},
        options: { responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{ color:gridColor() } }, y:{ beginAtZero:true, grid:{ color:gridColor() } } }, plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:ctx=>` ${ctx.parsed.y} tests`} }, zoom:{ zoom:{ wheel:{enabled:true}, pinch:{enabled:true}, mode:'x' }, pan:{enabled:true, mode:'x'} } } }
    });

    const diagChart = new Chart(document.getElementById('diagnosisPie').getContext('2d'), {
        type:'doughnut',
        data:{ labels:['Normal','Abnormal','Exclude','No Input'], datasets:[{ data:[<?= $diagnoses['normal'] ?>, <?= $diagnoses['abnormal'] ?>, <?= $diagnoses['exclude'] ?>, <?= $diagnoses['no input'] ?>], backgroundColor:[C.green,C.red,C.gray,C.amber], borderWidth:2, borderColor:'#fff'}] },
        options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{ position:'bottom' }, datalabels:{ color:'#111', formatter:(v,ctx)=>{ const t=ctx.dataset.data.reduce((a,b)=>a+b,0); const p=t?(v/t*100):0; return p>=6?`${p.toFixed(0)}%`:''; } } } }
    });

    const locationBar = new Chart(document.getElementById('locationBar').getContext('2d'), {
        type:'bar',
        data:{ labels: <?= json_encode(array_keys($byLocation)) ?>, datasets:[{ label:'Patients', data: <?= json_encode(array_values($byLocation)) ?>, backgroundColor:C.teal, borderColor:C.teal, borderWidth:1, borderRadius:8, maxBarThickness:44 }]},
        options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() } }}, plugins:{ legend:{ display:false }, datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=> (v>=3?fmt.format(v):'') } } }
    });

    const avgScoresEye = new Chart(document.getElementById('avgScoresEye').getContext('2d'), {
        type:'bar',
        data:{ labels:['OD','OS'], datasets:[
            { label:'Avg OCT', data:[<?= json_encode($avgByEye['OD']['oct']) ?>, <?= json_encode($avgByEye['OS']['oct']) ?>], backgroundColor:C.purple, borderColor:C.purple, borderWidth:1, borderRadius:8, maxBarThickness:36 },
            { label:'Avg VF',  data:[<?= json_encode($avgByEye['OD']['vf']) ?>,  <?= json_encode($avgByEye['OS']['vf']) ?>], backgroundColor:C.brand,  borderColor:C.brand,  borderWidth:1, borderRadius:8, maxBarThickness:36 }
        ]},
        options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, grid:{ color:gridColor() }}}, plugins:{ datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v => (v===null||isNaN(v))?'':fmt.format(v) } } }
    });

    window._recolorCharts = () => {
        [testsChart, diagChart, locationBar, avgScoresEye].forEach(ch => {
            if (!ch) return;
            if (ch.config.type === 'line') ch.data.datasets[0].backgroundColor = (ctx)=>lineGradientFor(ch);
            if (ch.options.scales?.x?.grid) ch.options.scales.x.grid.color = gridColor();
            if (ch.options.scales?.y?.grid) ch.options.scales.y.grid.color = gridColor();
            ch.update('none');
        });
    };

    // ===== Helpers for filtering dataset (for CSV/per-patient modal) =====
    function rowMatchesData(r, f){
        if (f.patientId && !(r.patient_id||'').toLowerCase().includes(f.patientId)) return false;
        if (f.testId    && !(r.test_id||'').toLowerCase().includes(f.testId)) return false;
        if (f.location && r.location !== f.location) return false;
        if (f.diagnosis && r.report_diagnosis !== f.diagnosis) return false;
        if (f.eyes.size && !f.eyes.has(r.eye)) return false;
        const d = r.date_of_test ? new Date(r.date_of_test+'T00:00:00') : null;
        if (f.dateStart && d && d < f.dateStart) return false;
        if (f.dateEnd   && d && d > f.dateEnd)   return false;
        const m = toNum(r.merci_score);
        if (f.merciMin !== null) { if (m === null || m < f.merciMin) return false; }
        if (f.merciMax !== null) { if (m === null || m > f.merciMax) return false; }
        const a = toNum(r.age);
        if (f.ageMin !== null) { if (a === null || a < f.ageMin) return false; }
        if (f.ageMax !== null) { if (a === null || a > f.ageMax) return false; }
        return true;
    }
    function filterRows(){ const f = getFilterState(); return EYE_ROWS.filter(r => rowMatchesData(r, f)); }

    // ===== Export filtered rows to CSV =====
    function downloadCSV(filename, rows){
        const csv = rows.map(r=>r.map(v=>{
            v = (v===null||v===undefined) ? '' : String(v);
            if (v.includes('"') || v.includes(',') || v.includes('\n')) v = '"' + v.replace(/"/g,'""') + '"';
            return v;
        }).join(',')).join('\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'}); const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = filename; a.click(); setTimeout(()=>URL.revokeObjectURL(url), 500);
    }
    function exportFilteredRowsCSV(){
        const rows = filterRows();
        const header = ['subject_id','patient_id','test_id','date_of_test','eye','age','report_diagnosis','merci_score','oct_score','vf_score','location'];
        const data = rows.map(r => [ r.subject_id, r.patient_id, r.test_id, r.date_of_test, r.eye, r.age ?? '', r.report_diagnosis, r.merci_score ?? '', r.oct_score ?? '', r.vf_score ?? '', r.location ]);
        downloadCSV('filtered_eye_rows.csv', [header, ...data]);
    }
    exportBtn.addEventListener('click', exportFilteredRowsCSV);

    // ===== Filter & sort DOM list of patients/tests/eyes =====
    function filterDOM(autoExpandAfter=true){
        const f = getFilterState();
        const patientCards = $$('#patientContainer .patient-item');
        let firstVisiblePatient = null, firstVisibleTestBtn = null;
        let visPatients = 0, visTests = 0, visEyes = 0;

        patientCards.forEach(patient => {
            const patientId = (patient.getAttribute('data-patient-id')||'').toLowerCase();
            const patientLocation = patient.getAttribute('data-location') || '';
            const tests = patient.querySelectorAll('.test-wrapper');
            let anyTestVisible = false; let firstTestBtnInThisPatient = null;

            tests.forEach(tw => {
                const testId = (tw.getAttribute('data-testid')||'').toLowerCase();
                const testDate = tw.getAttribute('data-test-date') || '';
                const testRows = tw.querySelectorAll('.eye-row');
                let anyEyeVisible = false;

                if (f.testId && !testId.includes(f.testId)) {
                    testRows.forEach(row=> row.style.display='none');
                    tw.style.display = 'none';
                    return;
                }

                testRows.forEach(row => {
                    const r = {
                        patient_id: patientId, test_id: testId, subject_id: row.getAttribute('data-subject')||'',
                        report_diagnosis: row.getAttribute('data-diagnosis')||'', location: patientLocation,
                        eye: row.getAttribute('data-eye')||'', date_of_test: row.getAttribute('data-test-date')||testDate,
                        merci_score: row.getAttribute('data-merci'), age: row.getAttribute('data-age')
                    };
                    const visible = rowMatchesData(r, f);
                    row.style.display = visible ? 'block':'none';
                    if (visible) { anyEyeVisible = true; visEyes++; }
                });

                const count = tw.querySelectorAll('.eye-row[style*="display: block"]').length;
                const countEl = tw.querySelector('.eye-count'); if (countEl) countEl.textContent = count;

                tw.style.display = anyEyeVisible ? 'block':'none';
                if (anyEyeVisible) { anyTestVisible = true; visTests++; if (!firstTestBtnInThisPatient) firstTestBtnInThisPatient = tw.querySelector('.accordion-button'); }
            });

            let patientIdPass = true;
            if (f.patientId) patientIdPass = patientId.includes(f.patientId);

            const showPatient = anyTestVisible && patientIdPass;
            patient.style.display = showPatient ? 'block':'none';
            if (showPatient) { if (!firstVisiblePatient) firstVisiblePatient = patient; if (!firstVisibleTestBtn && firstTestBtnInThisPatient) firstVisibleTestBtn = firstTestBtnInThisPatient; visPatients++; }
        });

        // counters + active filters
        resPatients.textContent = visPatients; resTests.textContent = visTests; resEyes.textContent = visEyes;
        let n = 0; const f0 = getFilterState();
        if (f0.patientId) n++; if (f0.testId) n++; if (f0.location) n++; if (f0.diagnosis) n++;
        if (!(f0.eyes.has('OD') && f0.eyes.has('OS'))) n++;
        const defStart = defaultState.dateStart ? defaultState.dateStart.getTime() : null;
        const defEnd   = defaultState.dateEnd   ? defaultState.dateEnd.getTime()   : null;
        const curStart = f0.dateStart ? f0.dateStart.getTime() : null;
        const curEnd   = f0.dateEnd   ? f0.dateEnd.getTime()   : null;
        if (defStart !== curStart || defEnd !== curEnd) n++;
        if (f0.merciMin !== null || f0.merciMax !== null) n++;
        if (f0.ageMin !== null || f0.ageMax !== null) n++;
        filtersCount.textContent = n; filterFab.style.display = n ? 'block' : 'none';

        if (autoExpand.checked && firstVisiblePatient && firstVisibleTestBtn && typeof bootstrap !== 'undefined') {
            const collapseId = firstVisibleTestBtn.getAttribute('data-bs-target');
            const collapseEl = document.querySelector(collapseId);
            if (collapseEl) new bootstrap.Collapse(collapseEl, {toggle:true});
            firstVisiblePatient.scrollIntoView({behavior:'smooth', block:'start'});
        }
    }

    function sortPatients(){
        const container = $('#patientContainer');
        const items = Array.from(container.querySelectorAll('.patient-item'));
        const key = $('#sortBy').value;
        const dir = sortDirBtn.getAttribute('data-dir') === 'desc' ? -1 : 1;
        function val(item){
            if (key === 'subject') return (item.getAttribute('data-subject')||'').toLowerCase();
            if (key === 'location') return (item.getAttribute('data-location')||'').toLowerCase();
            if (key === 'tests') return Number(item.getAttribute('data-tests')||0);
            if (key === 'age') return Number(item.getAttribute('data-age')||0);
            if (key === 'last_test') { const d = item.getAttribute('data-lasttest')||''; return d ? new Date(d+'T00:00:00').getTime() : 0; }
            return (item.getAttribute('data-subject')||'').toLowerCase();
        }
        items.sort((a,b)=>{ const va=val(a), vb=val(b); if (typeof va==='number' && typeof vb==='number') return (va-vb)*dir; if (va<vb) return -1*dir; if (va>vb) return 1*dir; return 0; });
        items.forEach(it => container.appendChild(it));
    }

    sortBy.addEventListener('change', sortPatients);
    sortDirBtn.addEventListener('click', ()=>{ const cur = sortDirBtn.getAttribute('data-dir'); const next = cur === 'desc' ? 'asc' : 'desc'; sortDirBtn.setAttribute('data-dir', next); sortDirBtn.innerHTML = next === 'desc' ? '<i class="bi bi-sort-down"></i>' : '<i class="bi bi-sort-up"></i>'; sortPatients(); });

    function setPreset(days){
        const max = DATA_MAX ? new Date(DATA_MAX+'T00:00:00') : new Date();
        let start = null, end = max;
        if (days === 'all') { start = DATA_MIN ? new Date(DATA_MIN+'T00:00:00') : null; }
        else { const ms = Number(days) * 24*3600*1000; start = new Date(end.getTime() - ms); }
        dateStartInput.value = fmtISO(start); dateEndInput.value = fmtISO(end);
        applyFilters(false);
    }
    presetBtns.forEach(btn=>{ const v=btn.getAttribute('data-preset'); if (!DATA_MIN || !DATA_MAX) btn.disabled = true; btn.addEventListener('click', ()=> setPreset(v === 'all' ? 'all' : Number(v))); });

    function clearFilters(){
        patientIdInput.value = ''; testIdInput.value = ''; locSelect.value = ''; diagSelect.value = '';
        eyeOD.checked = true; eyeOS.checked = true;
        dateStartInput.value = fmtISO(defaultState.dateStart); dateEndInput.value = fmtISO(defaultState.dateEnd);
        merciMinInput.value = ''; merciMaxInput.value = ''; ageMinInput.value = ''; ageMaxInput.value = '';
        sortBy.value = 'subject'; sortDirBtn.setAttribute('data-dir','desc'); sortDirBtn.innerHTML = '<i class="bi bi-sort-down"></i>';
        applyFilters(true);
    }
    clearBtn.addEventListener('click', clearFilters);
    filtersPill.addEventListener('click', ()=>{ clearFilters(); window.scrollTo({top: 0, behavior: 'smooth'}); });

    function applyFilters(doAutoExpand=true){ sortPatients(); filterDOM(doAutoExpand); }

    // Debounced text inputs
    let t1, t2;
    patientIdInput.addEventListener('input', ()=>{ clearTimeout(t1); t1=setTimeout(()=>applyFilters(true), 150); });
    testIdInput.addEventListener('input',    ()=>{ clearTimeout(t2); t2=setTimeout(()=>applyFilters(true), 150); });

    [locSelect, diagSelect, eyeOD, eyeOS, dateStartInput, dateEndInput, merciMinInput, merciMaxInput, ageMinInput, ageMaxInput]
        .forEach(el => el.addEventListener('change', ()=>applyFilters(true)));

    // Initial
    dateStartInput.value = fmtISO(defaultState.dateStart);
    dateEndInput.value   = fmtISO(defaultState.dateEnd);
    applyFilters(true);

    // ===== Print static analytics =====
    const printBtnEl = printBtn;
    function preparePrint(){
        printTime.textContent = new Date().toLocaleString();
        $('#printImg-tests').src = $('#testsOverTime').toDataURL('image/png', 1.0);
        $('#printImg-diag').src  = $('#diagnosisPie').toDataURL('image/png', 1.0);
        $('#printImg-loc').src   = $('#locationBar').toDataURL('image/png', 1.0);
        $('#printImg-avg').src   = $('#avgScoresEye').toDataURL('image/png', 1.0);
    }
    printBtnEl.addEventListener('click', ()=>{ preparePrint(); printArea.style.display='block'; setTimeout(()=>window.print(),100); setTimeout(()=>{ printArea.style.display='none'; }, 400); });

    // ===== Per-Patient Analytics Modal (respects filters) =====
    const modalEl = document.getElementById('patientModal');
    const patientModal = new bootstrap.Modal(modalEl);
    let pmCharts = { tests:null, diag:null, avg:null };
    function destroyPmCharts(){ Object.values(pmCharts).forEach(ch=>{ if(ch) ch.destroy(); }); pmCharts = {tests:null, diag:null, avg:null}; }

    function patientAggregate(patientRows){
        const map = new Map();
        patientRows.forEach(r=>{ const ym=(r.date_of_test||'').slice(0,7); if(!ym) return; if(!map.has(ym)) map.set(ym,new Set()); map.get(ym).add(r.test_id); });
        const end=new Date(), endMonth=new Date(end.getFullYear(),end.getMonth(),1);
        const labels=[], values=[]; for(let i=11;i>=0;i--){ const d=new Date(endMonth); d.setMonth(d.getMonth()-i); const ym=d.toISOString().slice(0,7); labels.push(d.toLocaleString(undefined,{month:'short',year:'numeric'})); values.push(map.has(ym)?map.get(ym).size:0); }
        const diagCounts={'normal':0,'abnormal':0,'exclude':0,'no input':0}; patientRows.forEach(r=>{ if(diagCounts.hasOwnProperty(r.report_diagnosis)) diagCounts[r.report_diagnosis]++; });
        const eyeStats={ OD:{oct:[],vf:[]}, OS:{oct:[],vf:[]} }; patientRows.forEach(r=>{ if(eyeStats[r.eye]){ if(r.oct_score!==null) eyeStats[r.eye].oct.push(Number(r.oct_score)); if(r.vf_score!==null) eyeStats[r.eye].vf.push(Number(r.vf_score)); }});
        const avg=a=>a.length?a.reduce((x,y)=>x+y,0)/a.length:null; const avgOD={oct:avg(eyeStats.OD.oct), vf:avg(eyeStats.OD.vf)}; const avgOS={oct:avg(eyeStats.OS.oct), vf:avg(eyeStats.OS.vf)};
        return {labels, values, diagCounts, avgOD, avgOS};
    }

    function patientCsvFor(chartId, agg){
        if (chartId === 'pmTests') { const data=[['Month','Tests']]; agg.labels.forEach((lab,i)=>data.push([lab,agg.values[i]])); return data; }
        if (chartId === 'pmDiag')  { const data=[['Diagnosis','Count']]; [['Normal','normal'],['Abnormal','abnormal'],['Exclude','exclude'],['No Input','no input']].forEach(([lab,key])=>data.push([lab, agg.diagCounts[key]||0])); return data; }
        if (chartId === 'pmAvg')   { return [['Eye','Avg OCT','Avg VF'], ['OD',agg.avgOD.oct,agg.avgOD.vf], ['OS',agg.avgOS.oct,agg.avgOS.vf]]; }
        return [['Info'],['Unsupported']];
    }

    function openPatientAnalytics(pid, subject){
        $('#pmSubject').textContent = subject; $('#pmId').textContent = pid; modalEl.setAttribute('data-patient-id', pid);
        const rows = filterRows().filter(r => (r.patient_id||'').toLowerCase() === pid.toLowerCase());
        const agg = patientAggregate(rows);
        destroyPmCharts();
        const Cc = C;
        pmCharts.tests = new Chart(document.getElementById('pmTests').getContext('2d'), {
            type:'line',
            data:{ labels: agg.labels, datasets:[{ label:'Tests', data: agg.values, borderColor: Cc.brand,
                backgroundColor:(ctx)=>{ const ch=ctx.chart; const g=ch.ctx.createLinearGradient(0,ch.chartArea.top,0,ch.chartArea.bottom); g.addColorStop(0,'rgba(26,115,232,0.35)'); g.addColorStop(1,'rgba(26,115,232,0.05)'); return g; },
                tension:.35, fill:true, borderWidth:3, pointRadius:3 }]},
            options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:{color:getComputedStyle(document.body).getPropertyValue('--grid')}}, y:{ beginAtZero:true, grid:{color:getComputedStyle(document.body).getPropertyValue('--grid')}} } }
        });
        const diagVals = ['normal','abnormal','exclude','no input'].map(k=>agg.diagCounts[k]||0);
        pmCharts.diag = new Chart(document.getElementById('pmDiag').getContext('2d'),{
            type:'doughnut',
            data:{ labels:['Normal','Abnormal','Exclude','No Input'], datasets:[{ data:diagVals, backgroundColor:[Cc.green,Cc.red,Cc.gray,Cc.amber], borderWidth:2, borderColor:'#fff' }]},
            options:{ responsive:true, maintainAspectRatio:false, cutout:'62%', plugins:{ legend:{position:'bottom'}, datalabels:{ color:'#111', formatter:(v,ctx)=>{ const t=ctx.dataset.data.reduce((a,b)=>a+b,0); const p=t?(v/t*100):0; return p>=6?`${p.toFixed(0)}%`:''; } } } }
        });
        pmCharts.avg = new Chart(document.getElementById('pmAvg').getContext('2d'),{
            type:'bar',
            data:{ labels:['OD','OS'], datasets:[
                { label:'Avg OCT', data:[agg.avgOD.oct, agg.avgOS.oct], backgroundColor:Cc.purple, borderColor:Cc.purple, borderWidth:1, borderRadius:8, maxBarThickness:36 },
                { label:'Avg VF',  data:[agg.avgOD.vf,  agg.avgOS.vf],  backgroundColor:Cc.brand,  borderColor:Cc.brand,  borderWidth:1, borderRadius:8, maxBarThickness:36 }
            ]},
            options:{ responsive:true, maintainAspectRatio:false, scales:{ x:{ grid:{display:false}}, y:{ beginAtZero:true, grid:{color:getComputedStyle(document.body).getPropertyValue('--grid')}} }, plugins:{ datalabels:{ anchor:'end', align:'end', offset:4, color:'#333', formatter:v=>(v===null||isNaN(v))?'':v.toFixed(2) } } }
        });
        modalEl.querySelectorAll('[data-csv], [data-download]').forEach(btn=>{
            btn.onclick = ()=>{
                const id = btn.getAttribute('data-csv') || btn.getAttribute('data-download');
                if (btn.hasAttribute('data-download')) {
                    const canvas = document.getElementById(id); if (!canvas) return;
                    const link = document.createElement('a'); link.href = canvas.toDataURL('image/png', 1.0); link.download = `${id}.png`; link.click();
                } else { const data = patientCsvFor(id, agg); downloadCSV(`${id}.csv`, data); }
            };
        });
        patientModal.show();
    }
    document.addEventListener('click', (e)=>{ const btn=e.target.closest('.btn-patient-analytics'); if (!btn) return; openPatientAnalytics(btn.getAttribute('data-patient'), btn.getAttribute('data-subject')); });
    document.getElementById('patientModal').addEventListener('hidden.bs.modal', ()=>destroyPmCharts());

    /* ============
       EDIT MODE
    ============ */

    // Toggle edit for an entire patient card (patient fields + show per-row edit)
    document.addEventListener('click', (e)=>{
        if (e.target.closest('.btn-edit-patient')) {
            const card = e.target.closest('.patient-card'); card.classList.add('editing'); flash('Editing enabled', 'info');
        }
        if (e.target.closest('.btn-cancel-edit')) {
            const card = e.target.closest('.patient-card'); card.classList.remove('editing');
        }
    });

    // Save patient fields (subject_id, date_of_birth, location)
    document.addEventListener('click', async (e)=>{
        if (!e.target.closest('.btn-save-patient')) return;
        const card = e.target.closest('.patient-card');
        const wrap = card.closest('.patient-item');
        const patientId = wrap.getAttribute('data-patient-id');
        const payload = {
            type: 'patient',
            id: patientId,
            data: {}
        };
        card.querySelectorAll('.edit-patient-field').forEach(inp=>{
            payload.data[inp.getAttribute('data-field')] = inp.value;
        });

        try {
            const res = await fetch('update_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf: CSRF, ...payload })
            });
            const json = await res.json();
            if (json.ok) {
                // update view
                const subj = payload.data.subject_id ?? '';
                const loc  = payload.data.location ?? '';
                const dob  = payload.data.date_of_birth ?? '';
                if (subj) { card.querySelector('.patient-subject').textContent = subj; wrap.setAttribute('data-subject', subj); card.querySelectorAll('.view-field .view-val')[0].textContent = subj; }
                if (loc)  { wrap.setAttribute('data-location', loc); card.querySelector('.badge.bg-secondary').textContent = loc; card.querySelectorAll('.view-field .view-val')[1].textContent = loc; }
                if (dob)  { const d = new Date(dob+'T00:00:00'); card.querySelectorAll('.view-field .view-val')[2].textContent = d.toLocaleDateString(); }
                card.classList.remove('editing');
                flash('Patient saved', 'success');
            } else {
                flash(json.error || 'Save failed', 'danger');
            }
        } catch(err){ console.error(err); flash('Network error saving patient', 'danger'); }
    });

    // Save test date
    document.addEventListener('click', async (e)=>{
        if (!e.target.closest('.btn-save-test')) return;
        const acc = e.target.closest('.test-wrapper');
        const testId = acc.getAttribute('data-testid');
        const dateInput = acc.querySelector('.edit-test-date');
        const payload = { type:'test', id:testId, data:{ date_of_test: dateInput.value } };
        try {
            const res = await fetch('update_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ csrf: CSRF, ...payload }) });
            const json = await res.json();
            if (json.ok) {
                // update view label + data attr
                const d = new Date(dateInput.value+'T00:00:00');
                acc.querySelector('.view-val').innerHTML = `<i class="bi bi-calendar-event"></i> ${d.toLocaleDateString()}`;
                acc.setAttribute('data-test-date', dateInput.value);
                // also update each eye-row data-test-date
                acc.querySelectorAll('.eye-row').forEach(r=> r.setAttribute('data-test-date', dateInput.value));
                flash('Test saved', 'success');
            } else {
                flash(json.error || 'Save failed', 'danger');
            }
        } catch(err){ console.error(err); flash('Network error saving test', 'danger'); }
    });

    // Toggle edit/save for an eye row
    document.addEventListener('click', (e)=>{
        if (e.target.closest('.btn-edit-eye')) {
            const row = e.target.closest('.eye-row');
            row.classList.add('editing');
        }
        if (e.target.closest('.btn-cancel-eye')) {
            const row = e.target.closest('.eye-row');
            row.classList.remove('editing');
        }
    });

    document.addEventListener('click', async (e)=>{
        if (!e.target.closest('.btn-save-eye')) return;
        const row = e.target.closest('.eye-row');
        const resultId = row.getAttribute('data-result-id');
        if (!resultId) { flash('Cannot save: missing result_id', 'danger'); return; }

        const data = {
            age: row.querySelector('.e-age').value || null,
            merci_score: row.querySelector('.e-merci').value || null,
            oct_score: row.querySelector('.e-oct').value || null,
            vf_score: row.querySelector('.e-vf').value || null,
            faf_grade: row.querySelector('.e-faf').value || null,
            report_diagnosis: (row.querySelector('.e-report').value || '').toLowerCase(),
            actual_diagnosis: row.querySelector('.e-actual').value || null,
            dosage: row.querySelector('.e-dosage').value || null,
            duration_days: row.querySelector('.e-duration').value || null,
            cumulative_dosage: row.querySelector('.e-cumulative').value || null,
            date_of_continuation: row.querySelector('.e-cont').value || null
        };

        const payload = { type:'eye', id: resultId, data };
        try {
            const res = await fetch('update_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ csrf: CSRF, ...payload }) });
            const json = await res.json();
            if (json.ok) {
                // Update view texts + data-attrs
                row.querySelector('.v-age').textContent   = data.age ?? 'N/A';
                row.querySelector('.v-merci').textContent = data.merci_score ?? 'N/A';
                row.querySelector('.v-oct').textContent   = data.oct_score ?? 'N/A';
                row.querySelector('.v-vf').textContent    = data.vf_score ?? 'N/A';
                row.querySelector('.v-faf').textContent   = data.faf_grade ?? 'N/A';
                row.querySelector('.v-actual').textContent= data.actual_diagnosis ?? 'N/A';

                row.setAttribute('data-age', data.age ?? '');
                row.setAttribute('data-merci', data.merci_score ?? '');
                row.setAttribute('data-oct', data.oct_score ?? '');
                row.setAttribute('data-vf', data.vf_score ?? '');
                row.setAttribute('data-diagnosis', data.report_diagnosis ?? '');

                // Update diagnosis badge class/text
                const badge = row.querySelector('.diagnosis-badge');
                const diagClass = (data.report_diagnosis === 'no input') ? 'no-input' : (data.report_diagnosis || 'no input');
                badge.className = `badge diagnosis-badge ${diagClass}`;
                badge.querySelector('.view-val').textContent = (data.report_diagnosis || 'no input');

                row.classList.remove('editing');
                flash('Eye saved', 'success');
            } else {
                flash(json.error || 'Save failed', 'danger');
            }
        } catch(err){ console.error(err); flash('Network error saving eye', 'danger'); }
    });

})();
</script>
</body>
</html>
