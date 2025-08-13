<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

function render_page_top($title = 'CSV Import') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
                --border:rgba(0,0,0,.08);
                --shadow: 0 8px 24px rgba(0,0,0,0.09);
            }
            body { background: var(--bg); color: var(--text); }
            .navbar-blur { backdrop-filter: saturate(180%) blur(6px); background: rgba(255,255,255,.9); border-bottom:1px solid var(--border); }
            .card { background: var(--card); border:1px solid var(--border); box-shadow: var(--shadow); }
            .card-gradient { background: linear-gradient(135deg, var(--brand-2) 0%, var(--brand) 100%); color:#fff; }
            .pill { border-radius: 999px; }
            .dropzone {
                border:2px dashed rgba(0,0,0,.15);
                border-radius:12px;
                background:#fff;
                padding:30px;
                text-align:center;
                transition:.2s ease;
            }
            .dropzone.dragover { border-color: var(--brand); background: #f1f7ff; }
            .dz-icon { font-size:42px; color: var(--brand); }
            .muted { color:#6c757d; }
            pre.debug { background:#0f172a; color:#e5e7eb; padding:12px; border-radius:8px; max-height:300px; overflow:auto; }
        </style>
    </head>
    <body>
    <nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
      <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="index.php">
          <i class="bi bi-capsule-pill me-2"></i>Hydroxychloroquine Data Repository
        </a>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm pill"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="form.php" class="btn btn-outline-primary btn-sm pill"><i class="bi bi-file-earmark-plus"></i> Add via Form</a>
        </div>
      </div>
    </nav>
    <div class="container py-4">
    <?php
}

function render_page_bottom() {
    ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dropzone UX
        (function(){
            const dz = document.querySelector('.dropzone');
            if (!dz) return;
            const input = dz.querySelector('input[type="file"]');
            const label = dz.querySelector('[data-file-label]');
            const updateLabel = (name) => label.textContent = name || 'Choose CSV file…';

            dz.addEventListener('dragover', (e)=>{ e.preventDefault(); dz.classList.add('dragover'); });
            dz.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
            dz.addEventListener('drop', (e)=>{
                e.preventDefault(); dz.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    updateLabel(e.dataTransfer.files[0].name);
                }
            });
            input?.addEventListener('change', ()=> updateLabel(input.files[0]?.name || ''));
        })();
    </script>
    </body>
    </html>
    <?php
}

function render_error_page($message, $filename = null) {
    render_page_top('Import Error');
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-danger mb-4">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-x-circle"></i> Import failed
                </div>
                <div class="card-body">
                    <?php if ($filename): ?>
                        <p class="mb-2"><strong>File:</strong> <?= htmlspecialchars($filename) ?></p>
                    <?php endif; ?>
                    <div class="alert alert-danger mb-3"><?= $message ?></div>
                    <a href="csv_import.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Try another file</a>
                    <a href="index.php" class="btn btn-primary"><i class="bi bi-speedometer2"></i> Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_page_bottom();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    header('Content-Type: text/html; charset=utf-8');

    $file = $_FILES['csv_file']['tmp_name'];
    $filename = $_FILES['csv_file']['name'];

    // Upload checks (same logic, prettier output)
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        render_error_page("Error uploading file (code " . (int)$_FILES['csv_file']['error'] . ")", $filename);
    }
    if (!file_exists($file)) {
        render_error_page("File not found on server.", $filename);
    }

    $results = [
        'total_rows' => 0,
        'patients_processed' => 0,
        'tests_processed' => 0,
        'eyes_processed' => 0,
        'errors' => [],
        'warnings' => []
    ];

    // collect debug logs instead of echoing mid-stream
    $debugLogs = [];

    try {
        $conn->begin_transaction();

        if (($handle = fopen($file, 'r')) !== false) {
            $lineNumber = 0;

            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;
                $results['total_rows']++;

                // normalize (unchanged)
                $data = array_map('trim', $data);
                $data = array_map(function ($value) {
                    return in_array(strtolower((string)$value), ['null', 'no value', 'missing', '']) ? null : $value;
                }, $data);

                try {
                    // === Patient === (same logic)
                    $patientId = $data[0] ?? null; // CSV col 0
                    if (empty($patientId)) {
                        throw new Exception("Missing patient ID in CSV on line $lineNumber");
                    }

                    $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                    if (!$dob) {
                        throw new Exception("Invalid date of birth format (MM/DD/YYYY) on line $lineNumber");
                    }
                    $dobFormatted = $dob->format('Y-m-d');

                    // get or create patient (same)
                    $patientId = getOrCreatePatient($conn, $patientId, $patientId, 'KH', $dobFormatted);
                    $results['patients_processed']++;

                    // === Test === (same logic)
                    $testId = $data[4] ?? null;
                    if (empty($testId)) {
                        $testId = 'TEST_' . $patientId . '_' . $dob->format('Ymd') . '_' . bin2hex(random_bytes(2));
                    }

                    $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                    if (!$testDate) {
                        throw new Exception("Invalid test date format (MM/DD/YYYY) on line $lineNumber");
                    }
                    $testDateFormatted = $testDate->format('Y-m-d');

                    // Age (same logic)
                    $age = (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) ? (int)$data[3] : null;
                    if ($age === null && isset($data[1])) {
                        $dob2 = DateTime::createFromFormat('m/d/Y', $data[1]);
                        if ($dob2) {
                            $today = new DateTime();
                            $age = $today->diff($dob2)->y;
                        }
                    }

                    // Insert test (same)
                    insertTest($conn, $testId, $patientId, 'KH', $testDateFormatted);
                    $results['tests_processed']++;

                    // === Eye-related fields (same mapping) ===
                    $eyes = ['OD', 'OS'];
                    $reportDiagnosis   = strtolower($data[6]  ?? 'no input');
                    $exclusion         = strtolower($data[7]  ?? 'none');
                    $merciScore        = isset($data[8]) ? (strtolower($data[8]) === 'unable' ? 'unable' : (is_numeric($data[8]) ? (int)$data[8] : null)) : null;
                    $merciDiagnosis    = strtolower($data[9]  ?? 'no value');
                    $errorType         = strtoupper($data[10] ?? 'none');
                    $fafGrade          = isset($data[11]) && is_numeric($data[11]) ? (int)$data[11] : null;
                    $octScore          = isset($data[12]) && is_numeric($data[12]) ? round((float)$data[12], 2) : null;
                    $vfScore           = isset($data[13]) && is_numeric($data[13]) ? round((float)$data[13], 2) : null;
                    $actualDiagnosis   = strtolower($data[14] ?? 'other');
                    $dosage            = isset($data[15]) && is_numeric($data[15]) ? round((float)$data[15], 2) : null;
                    $durationDays      = isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null;
                    $cumulativeDosage  = isset($data[17]) && is_numeric($data[17]) ? round((float)$data[17], 2) : null;
                    $dateOfContinuation= trim($data[18] ?? '');

                    foreach ($eyes as $eye) {
                        // original debug print -> pretty debug log (same info)
                        ob_start();
                        echo "Preparing to insert test eye data for test_id: $testId, eye: $eye\n";
                        print_r([
                            'test_id' => $testId,
                            'eye' => $eye,
                            'age' => $age,
                            'report_diagnosis' => $reportDiagnosis,
                            'exclusion' => $exclusion,
                            'merci_score' => $merciScore,
                            'merci_diagnosis' => $merciDiagnosis,
                            'error_type' => $errorType,
                            'faf_grade' => $fafGrade,
                            'oct_score' => $octScore,
                            'vf_score' => $vfScore,
                            'actual_diagnosis' => $actualDiagnosis,
                            'dosage' => $dosage,
                            'duration_days' => $durationDays,
                            'cumulative_dosage' => $cumulativeDosage,
                            'date_of_continuation' => $dateOfContinuation
                        ]);
                        $debugLogs[] = htmlspecialchars(ob_get_clean());

                        // Insert eye (same)
                        insertTestEye(
                            $conn,
                            $testId,
                            $eye,
                            $age,
                            $reportDiagnosis,
                            $exclusion,
                            $merciScore,
                            $merciDiagnosis,
                            $errorType,
                            $fafGrade,
                            $octScore,
                            $vfScore,
                            $actualDiagnosis,
                            $dosage,
                            $durationDays,
                            $cumulativeDosage,
                            $dateOfContinuation
                        );
                        $results['eyes_processed']++;
                    }

                } catch (Exception $e) {
                    $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                    continue;
                }
            }

            fclose($handle);
            $conn->commit();

            // Pretty results page
            render_page_top('CSV Import Results');
            ?>

            <div class="row g-3 mb-3">
                <div class="col">
                    <h1 class="h3"><i class="bi bi-upload"></i> CSV Import Results</h1>
                    <p class="text-muted mb-0">File: <strong><?= htmlspecialchars($filename) ?></strong></p>
                </div>
                <div class="col-auto">
                    <a href="csv_import.php" class="btn btn-outline-secondary pill"><i class="bi bi-arrow-repeat"></i> Import another</a>
                    <a href="index.php" class="btn btn-primary pill"><i class="bi bi-speedometer2"></i> Back to Dashboard</a>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card card-gradient h-100">
                        <div class="card-body">
                            <div class="small text-uppercase">Total rows</div>
                            <div class="display-6"><?= number_format($results['total_rows']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-success">
                        <div class="card-body">
                            <div class="small text-uppercase text-success">Patients processed</div>
                            <div class="display-6"><?= number_format($results['patients_processed']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-info">
                        <div class="card-body">
                            <div class="small text-uppercase text-info">Tests processed</div>
                            <div class="display-6"><?= number_format($results['tests_processed']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body">
                            <div class="small text-uppercase text-primary">Eyes processed</div>
                            <div class="display-6"><?= number_format($results['eyes_processed']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($results['warnings'])): ?>
                <div class="card mb-3 border-warning">
                    <div class="card-header bg-warning-subtle">
                        <i class="bi bi-exclamation-triangle"></i> Warnings
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <?php foreach ($results['warnings'] as $w): ?>
                                <li><?= htmlspecialchars($w) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['errors'])): ?>
                <div class="card mb-3 border-danger">
                    <div class="card-header bg-danger text-white">
                        <i class="bi bi-bug"></i> Errors
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <?php foreach ($results['errors'] as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mt-3 text-muted small">Rows with errors were skipped; the rest were committed.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="accordion mb-4" id="debugAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingDebug">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDebug" aria-expanded="false" aria-controls="collapseDebug">
                            <i class="bi bi-terminal me-2"></i> Debug log (per-eye inserts)
                        </button>
                    </h2>
                    <div id="collapseDebug" class="accordion-collapse collapse" aria-labelledby="headingDebug" data-bs-parent="#debugAccordion">
                        <div class="accordion-body">
                            <?php if (empty($debugLogs)): ?>
                                <div class="text-muted">No debug output.</div>
                            <?php else: ?>
                                <?php foreach ($debugLogs as $chunk): ?>
                                    <pre class="debug mb-3"><?= $chunk ?></pre>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Notes:</strong> This importer processes <em>every</em> CSV row as data (no automatic header skipping). Dates must be in <strong>MM/DD/YYYY</strong>. MERCI score accepts a number or the string <code>unable</code>.
            </div>

            <?php
            render_page_bottom();
        } else {
            throw new Exception("Could not open CSV file");
        }

    } catch (Exception $e) {
        $conn->rollback();
        render_error_page("Import failed: " . htmlspecialchars($e->getMessage()), $filename);
    }

} else {
    // Upload form (prettified, same logic when submitting)
    render_page_top('Import Patient Data (CSV)');
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-body">
                    <h1 class="h4 mb-3"><i class="bi bi-filetype-csv"></i> Import Patient Data from CSV</h1>
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="dropzone mb-3">
                            <div class="dz-icon mb-2"><i class="bi bi-cloud-arrow-up-fill"></i></div>
                            <p class="mb-1">Drag & drop your <strong>.csv</strong> here or click to browse.</p>
                            <p class="muted small mb-3">Max size depends on server config.</p>
                            <input class="form-control" type="file" name="csv_file" id="csv_file" accept=".csv" required style="max-width:320px;margin:0 auto;">
                            <div class="form-text mt-2" data-file-label>Choose CSV file…</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary pill"><i class="bi bi-upload"></i> Import Data</button>
                            <a href="index.php" class="btn btn-outline-secondary pill"><i class="bi bi-speedometer2"></i> Back to Dashboard</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0">
                <div class="card-header bg-light">
                    <strong>CSV Format Requirements</strong>
                </div>
                <div class="card-body">
                    <ul class="mb-3">
                        <li><strong>All rows are processed</strong> (no automatic header skipping).</li>
                        <li>Date format: <code>MM/DD/YYYY</code> (DOB and Test Date).</li>
                        <li>Minimum 19 columns expected (indices 0–18 as used below).</li>
                    </ul>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th><th>Field</th><th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>0</td><td><code>patient_id</code></td><td><strong>Required.</strong></td></tr>
                                <tr><td>1</td><td><code>date_of_birth</code></td><td>MM/DD/YYYY</td></tr>
                                <tr><td>2</td><td><code>date_of_test</code></td><td>MM/DD/YYYY</td></tr>
                                <tr><td>3</td><td><code>age</code></td><td>Optional; calculated from DOB if missing</td></tr>
                                <tr><td>4</td><td><code>test_id</code></td><td>Optional; auto-generated if missing</td></tr>
                                <tr><td>6</td><td><code>report_diagnosis</code></td><td>e.g., normal / abnormal / exclude / no input</td></tr>
                                <tr><td>7</td><td><code>exclusion</code></td><td>string</td></tr>
                                <tr><td>8</td><td><code>merci_score</code></td><td>number 0–100 or <code>unable</code></td></tr>
                                <tr><td>9</td><td><code>merci_diagnosis</code></td><td>string</td></tr>
                                <tr><td>10</td><td><code>error_type</code></td><td>TN / FP / TP / FN / none</td></tr>
                                <tr><td>11</td><td><code>faf_grade</code></td><td>integer</td></tr>
                                <tr><td>12</td><td><code>oct_score</code></td><td>float</td></tr>
                                <tr><td>13</td><td><code>vf_score</code></td><td>float</td></tr>
                                <tr><td>14</td><td><code>actual_diagnosis</code></td><td>string (e.g., RA/SLE/...)</td></tr>
                                <tr><td>15</td><td><code>dosage</code></td><td>float</td></tr>
                                <tr><td>16</td><td><code>duration_days</code></td><td>integer</td></tr>
                                <tr><td>17</td><td><code>cumulative_dosage</code></td><td>float</td></tr>
                                <tr><td>18</td><td><code>date_of_continuation</code></td><td>string</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mb-0">Importer inserts data for <strong>both eyes (OD &amp; OS)</strong> per row.</p>
                </div>
            </div>

        </div>
    </div>
    <?php
    render_page_bottom();
}
?>
