<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

// -------------- Helpers --------------
function respond_json($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

function sanitize_patient_id($id) {
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
}

function ensure_dir_exists($dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
}

function unique_filename($dir, $baseName, $ext) {
    $candidate = $baseName . '.' . $ext;
    $i = 1;
    while (file_exists($dir . '/' . $candidate)) {
        $candidate = $baseName . "_$i.$ext";
        $i++;
    }
    return $candidate;
}

/**
 * Find existing test_id for (patient_id, date_of_test) or create a new test.
 * Uses patient's location if available; falls back to 'KH'.
 */
function get_or_create_test_for($conn, $patientId, $dateSql) {
    // Try to find existing test for this patient/date
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? LIMIT 1");
    $stmt->bind_param("ss", $patientId, $dateSql);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $stmt->close();
        return $row['test_id'];
    }
    $stmt->close();

    // Generate compact test_id within 25 chars: TS_<last8pid>_<yyyymmdd>_<4hex>  (<= 3+1+8+1+8+1+4 = 26, but last8pid trims)
    $pidShort = substr($patientId, -8);
    $rand4 = substr(md5(uniqid('', true)), 0, 4);
    $testId = "TS_{$pidShort}_" . str_replace('-', '', $dateSql) . "_$rand4";
    if (strlen($testId) > 25) {
        // Hard fallback
        $testId = 'T' . substr(md5($patientId.$dateSql.microtime(true)), 0, 24);
    }

    // Use patient location if present
    $loc = 'KH';
    $p = getPatientById($patientId);
    if ($p && !empty($p['location'])) $loc = $p['location'];

    insertTest($conn, $testId, $patientId, $loc, $dateSql);
    return $testId;
}

/**
 * Upsert one test_eyes row and set the proper reference column for the given test type.
 * $testType one of: FAF,OCT,VF,MFERG  -> columns: faf_reference, oct_reference, vf_reference, mferg_reference
 */
function upsert_eye_reference($conn, $testId, $eye, $testType, $storedFilename) {
    $col = strtolower($testType) . '_reference';
    if (!in_array($eye, ['OD','OS'], true)) {
        throw new InvalidArgumentException("Eye must be OD or OS");
    }
    if (!preg_match('/^(faf|oct|vf|mferg)_reference$/', $col)) {
        throw new InvalidArgumentException("Invalid column resolved for test type");
    }

    // Insert minimal defaults; rely on table defaults for other fields.
    // Requires UNIQUE KEY (test_id, eye) for ON DUPLICATE KEY to work ideally.
    $sql = "
        INSERT INTO test_eyes (test_id, eye, $col)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE $col = VALUES($col), updated_at = CURRENT_TIMESTAMP
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("Prepare failed: " . $conn->error);
    $stmt->bind_param("sss", $testId, $eye, $storedFilename);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Failed to upsert test_eyes reference: " . $conn->error);
    }
    $stmt->close();
}

/**
 * Core upload handler used by both Single and Bulk flows.
 * If $fieldsProvided is true, we derive filename from the provided fields (patient_id, date, eye).
 * Otherwise we parse patient/date/eye from the original filename pattern: PatientID_OD/OS_YYYYMMDD.ext
 */
function handle_upload($testType, $tmpPath, $origName, $fieldsProvided = false, $patientIdForm = null, $testDateForm = null, $eyeForm = null) {
    global $conn;

    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        return ['success' => false, 'message' => 'Invalid test type'];
    }

    // Allowed extensions by type
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','pdf'];
    if ($testType === 'MFERG') { $allowed[] = 'exp'; }
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'message' => "Invalid file extension for {$testType}: .$ext"];
    }

    // Resolve patient/eye/date
    $patientId = null; $eye = null; $dateYmd = null;

    if ($fieldsProvided) {
        $patientId = sanitize_patient_id($patientIdForm ?? '');
        $eye = strtoupper(trim($eyeForm ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $testDateForm ?? '');
        if (!$patientId || !in_array($eye, ['OD','OS'], true) || !$dt) {
            return ['success' => false, 'message' => 'Invalid Single Upload fields'];
        }
        $dateYmd = $dt->format('Ymd');
    } else {
        // Parse from filename: PatientID_OD|OS_YYYYMMDD.ext
        if (!preg_match('/^([A-Za-z0-9_\-]+)_(OD|OS)_(\d{8})\.[A-Za-z0-9]+$/i', $origName, $m)) {
            return ['success' => false, 'message' => 'Filename must be PatientID_OD|OS_YYYYMMDD.ext'];
        }
        $patientId = sanitize_patient_id($m[1]);
        $eye = strtoupper($m[2]);
        $dateYmd = $m[3];
        // Validate date in filename
        $dt = DateTime::createFromFormat('Ymd', $dateYmd);
        if (!$dt) return ['success' => false, 'message' => 'Invalid date in filename'];
    }

    // Make sure patient exists (FK on tests)
    $pat = getPatientById($patientId);
    if (!$pat) {
        return ['success' => false, 'message' => "Patient '{$patientId}' does not exist"];
    }
    $dateSql = DateTime::createFromFormat('Ymd', $dateYmd)->format('Y-m-d');

    // Ensure tests row
    $testId = get_or_create_test_for($conn, $patientId, $dateSql);

    // Prepare storage dir + filename
    $dir = rtrim(getTestTypeDirectory($testType), '/');
    ensure_dir_exists($dir);

    // Standardize stored filename
    $baseName = "{$patientId}_{$eye}_{$dateYmd}";
    $storedName = unique_filename($dir, $baseName, $ext);

    // Move upload
    if (!is_uploaded_file($tmpPath)) {
        // For some PHP SAPIs / bulk streams, move_uploaded_file may fail; fallback to rename
        if (!@rename($tmpPath, $dir . '/' . $storedName)) {
            return ['success' => false, 'message' => 'Upload stream error (move failed)'];
        }
    } else {
        if (!move_uploaded_file($tmpPath, $dir . '/' . $storedName)) {
            return ['success' => false, 'message' => 'Failed to store file'];
        }
    }

    // Upsert test_eyes reference column
    try {
        upsert_eye_reference($conn, $testId, $eye, $testType, $storedName);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    return ['success' => true, 'message' => "$testType image saved for $eye on {$dateSql}", 'stored' => $storedName];
}

// -------------- AJAX (bulk) --------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        respond_json(['status' => 'error', 'message' => 'Upload failed']);
    }
    $result = handle_upload($testType, $_FILES['image']['tmp_name'], $_FILES['image']['name'], false);
    respond_json($result['success']
        ? ['status' => 'success', 'message' => $result['message']]
        : ['status' => 'error', 'message' => $result['message']]
    );
}

// -------------- Single form submit --------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $testType = $_POST['test_type'] ?? '';
    $patient  = $_POST['patient_id'] ?? '';
    $dateStr  = $_POST['test_date'] ?? '';
    $eye      = $_POST['eye'] ?? '';
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'danger', 'text' => 'Please choose a file to upload.'];
    } else {
        // Derive filename from fields, but pass original to detect extension
        $origName = $_FILES['image']['name'];
        $res = handle_upload($testType, $_FILES['image']['tmp_name'], $origName, true, $patient, $dateStr, $eye);
        $flash = $res['success']
            ? ['type' => 'success', 'text' => $res['message']]
            : ['type' => 'danger',  'text' => $res['message']];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Images • Hydroxychloroquine Data Repository</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{
            --brand:#1a73e8;
            --brand-2:#6ea8fe;
            --bg:#f7f8fb;
            --card:#ffffff;
            --text:#111827;
            --border:rgba(0,0,0,.08);
            --shadow: 0 8px 24px rgba(0,0,0,0.09);
        }
        body.dark{
            --bg:#0f1220;
            --card:#151a2c;
            --text:#e5e7eb;
            --border:rgba(255,255,255,.14);
            --shadow: 0 12px 28px rgba(0,0,0,0.45);
        }
        body { background: var(--bg); color: var(--text); }
        .navbar-blur { backdrop-filter: saturate(180%) blur(8px); background-color: rgba(255,255,255,0.85); border-bottom: 1px solid var(--border); }
        body.dark .navbar-blur { background-color: rgba(21,26,44,0.85); }
        .card { background: var(--card); border: 1px solid var(--border); box-shadow: var(--shadow); }
        .pill { border-radius: 999px; }
        .card-gradient { background: linear-gradient(135deg, var(--brand-2) 0%, var(--brand) 100%); color:#fff; }
        .form-hint { font-size:.875rem; color:#6c757d; }
        .upload-drop {
            border: 2px dashed var(--border);
            border-radius: .75rem; padding: 1rem; text-align: center;
        }
        .upload-drop.dragover { background: rgba(26,115,232,0.06); }
        .list-small { font-size: .95rem; }
    </style>
</head>
<body>

<!-- Nav (theme-matched) -->
<nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <i class="bi bi-capsule-pill me-2"></i>Hydroxychloroquine Data Repository
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php#analytics">Analytics</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php#patients">Patients</a></li>
      </ul>
      <div class="d-flex align-items-center gap-2 gap-lg-3">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="darkToggle">
            <label class="form-check-label" for="darkToggle"><i class="bi bi-moon-stars"></i></label>
        </div>
        <a href="form.php" class="btn btn-outline-primary pill">
            <i class="bi bi-file-earmark-plus"></i> Add via Form
        </a>
        <a href="csv_import.php" class="btn btn-outline-secondary pill">
            <i class="bi bi-upload"></i> Import CSV
        </a>
        <a href="import_images.php" class="btn btn-primary pill">
            <i class="bi bi-images"></i> Import Images
        </a>
      </div>
    </div>
  </div>
</nav>

<div class="container py-4">
    <div class="row mb-3">
        <div class="col">
            <h1 class="h3 d-flex align-items-center gap-2"><i class="bi bi-images"></i> Import Images</h1>
            <p class="text-muted mb-0">Upload per-eye clinical images and link them to tests automatically.</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary pill"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Single Upload -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-arrow-up"></i> Single File Upload</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label class="form-label">Test Type</label>
                            <select name="test_type" class="form-select" required>
                                <option value="">Select Test Type</option>
                                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Choose a test type.</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Patient ID</label>
                                <input type="text" name="patient_id" class="form-control" placeholder="e.g. P_abc123" required>
                                <div class="invalid-feedback">Enter a valid patient ID.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Test Date</label>
                                <input type="date" name="test_date" class="form-control" required>
                                <div class="invalid-feedback">Pick a date.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Eye</label>
                                <select name="eye" class="form-select" required>
                                    <option value="">Select</option>
                                    <option value="OD">Right (OD)</option>
                                    <option value="OS">Left (OS)</option>
                                </select>
                                <div class="invalid-feedback">Select eye.</div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">File</label>
                            <div class="upload-drop" id="singleDrop">
                                <input type="file" name="image" id="singleFile" class="form-control" accept="image/png,image/jpeg,.pdf,.exp" required>
                                <div class="form-hint mt-2">Allowed: PNG, JPG/JPEG, PDF <?php /* add EXP explicitly */ ?><?php /* for MFERG */ ?><?php ?></div>
                            </div>
                            <div class="invalid-feedback d-block" style="display:none;" id="singleFileInvalid">Please choose a file.</div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" name="import" class="btn btn-primary pill">
                                <i class="bi bi-cloud-upload"></i> Upload & Link
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    This will create a test on the selected date if one doesn’t exist, then attach the file to the OD/OS record.
                </div>
            </div>
        </div>

        <!-- Bulk Upload -->
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-folder-symlink"></i> Bulk Folder Upload</h5>
                    <span class="text-muted small">Filenames must follow <code>PatientID_OD|OS_YYYYMMDD.ext</code></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Test Type</label>
                            <select id="test_type" class="form-select">
                                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Select Folder</label>
                            <input type="file" id="bulk_files" webkitdirectory multiple class="form-control">
                            <div class="form-hint mt-1">We’ll process all files in the selected folder (recursively).</div>
                        </div>
                    </div>

                    <div class="mt-3 d-grid d-sm-flex gap-2">
                        <button id="startUpload" class="btn btn-success pill">
                            <i class="bi bi-play-circle"></i> Start Upload
                        </button>
                        <button id="clearList" class="btn btn-outline-secondary pill">
                            <i class="bi bi-eraser"></i> Clear List
                        </button>
                    </div>

                    <div class="mt-3">
                        <div id="progress" class="fw-semibold">No files uploaded yet</div>
                        <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100">
                          <div class="progress-bar" id="progress-bar" style="width: 0%"></div>
                        </div>
                    </div>

                    <ul id="file-list" class="list-group list-group-flush mt-3 list-small" style="max-height: 260px; overflow-y: auto;"></ul>
                </div>
                <div class="card-footer small">
                    <span class="text-muted">For MFERG, <code>.exp</code> is accepted in addition to PNG/JPG/PDF.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tips -->
    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card card-gradient">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-info-circle"></i> Tips</h6>
                    <ul class="mb-0">
                        <li>Patient IDs must already exist (the importer won’t create patients).</li>
                        <li>We’ll create the test for the given date if none exists, then attach the image to the correct eye.</li>
                        <li>Stored filename is normalized to <code>PatientID_OD|OS_YYYYMMDD.ext</code> and saved under <code>/data/&lt;TYPE&gt;/</code>.</li>
                        <li>Make sure your database has a unique key on <code>(test_id, eye)</code> in <code>test_eyes</code> to fully enable upserts.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

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
    });
})();

// ===== Bootstrap form validation (Single) =====
(() => {
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      const file = document.getElementById('singleFile');
      const invalid = document.getElementById('singleFileInvalid');
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      if (!file.files || file.files.length === 0) {
        invalid.style.display = 'block';
        event.preventDefault();
        event.stopPropagation();
      } else {
        invalid.style.display = 'none';
      }
      form.classList.add('was-validated');
    }, false);
  });
})();

// ===== Bulk uploader =====
const startBtn = document.getElementById('startUpload');
const clearBtn = document.getElementById('clearList');
const fileList = document.getElementById('file-list');
const progress = document.getElementById('progress');
const progressBar = document.getElementById('progress-bar');
const testTypeSel = document.getElementById('test_type');

clearBtn.addEventListener('click', () => {
    fileList.innerHTML = '';
    progress.textContent = 'No files uploaded yet';
    progressBar.style.width = '0%';
});

startBtn.addEventListener('click', async () => {
    const files = document.getElementById('bulk_files').files;
    if (!files || files.length === 0) {
        alert('Please select a folder with files.');
        return;
    }
    const testType = testTypeSel.value;
    fileList.innerHTML = '';
    let successCount = 0, errorCount = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];

        const li = document.createElement('li');
        li.className = 'list-group-item d-flex align-items-center justify-content-between';
        li.innerHTML = `<span class="text-truncate" style="max-width:75%;">${file.webkitRelativePath}</span>
                        <span class="badge rounded-pill bg-secondary">Pending</span>`;
        fileList.appendChild(li);

        const formData = new FormData();
        formData.append('bulk_upload', '1');
        formData.append('test_type', testType);
        formData.append('image', file, file.name);

        try {
            const res = await fetch('import_images.php', { method: 'POST', body: formData });
            const out = await res.json();
            const badge = li.querySelector('.badge');
            if (out.status === 'success') {
                badge.className = 'badge rounded-pill bg-success';
                badge.textContent = 'OK';
                successCount++;
            } else {
                badge.className = 'badge rounded-pill bg-danger';
                badge.textContent = 'Error';
                const msg = document.createElement('div');
                msg.className = 'small text-danger mt-1';
                msg.textContent = out.message || 'error';
                li.appendChild(msg);
                errorCount++;
            }
        } catch (e) {
            const badge = li.querySelector('.badge');
            badge.className = 'badge rounded-pill bg-danger';
            badge.textContent = 'Error';
            const msg = document.createElement('div');
            msg.className = 'small text-danger mt-1';
            msg.textContent = 'Network error';
            li.appendChild(msg);
            errorCount++;
        }

        const pct = Math.round(((i+1)/files.length)*100);
        progress.textContent = `Processed ${i+1}/${files.length} • Success: ${successCount} • Errors: ${errorCount}`;
        progressBar.style.width = pct + '%';
    }

    progress.textContent = `Upload finished! Success: ${successCount}, Errors: ${errorCount}`;
});
</script>
</body>
</html>
