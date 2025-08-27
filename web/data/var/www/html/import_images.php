<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

/* =========================
   helpers (UNCHANGED LOGIC)
   ========================= */

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $db = DB_NAME; // set in includes/config.php
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function patientExists(mysqli $conn, string $patientId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param("s", $patientId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

/** Ensure a tests row exists; return test_id */
function ensureTest(mysqli $conn, string $patientId, string $dateYmd): string {
    $dateSql = DateTime::createFromFormat('Ymd', $dateYmd)->format('Y-m-d');
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? LIMIT 1");
    $stmt->bind_param("ss", $patientId, $dateSql);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row['test_id'])) return $row['test_id'];

    // Create deterministic-ish unique test id
    $testId = $patientId . '_' . $dateYmd . '_' . substr(md5(uniqid('', true)), 0, 6);

    $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $testId, $patientId, $dateSql);
    $stmt->execute();
    $stmt->close();
    return $testId;
}

/** Ensure a test_eyes row exists for (test_id, eye) */
function ensureTestEye(mysqli $conn, string $testId, string $eye): void {
    $stmt = $conn->prepare("SELECT 1 FROM test_eyes WHERE test_id = ? AND eye = ? LIMIT 1");
    $stmt->bind_param("ss", $testId, $eye);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($exists) return;

    $stmt = $conn->prepare("INSERT INTO test_eyes (test_id, eye) VALUES (?, ?)");
    $stmt->bind_param("ss", $testId, $eye);
    $stmt->execute();
    $stmt->close();
}

/**
 * Save file and write refs to DB:
 * - Saves to IMAGE_BASE_DIR/<ModalityDir>/<PatientID>_<EYE>_<YYYYMMDD>.<ext>
 * - Writes to test_eyes.<modality>_reference_OD|OS (primary)
 * - Optionally backfills tests.<modality>_reference_od|os if columns exist
 */
function processImage(mysqli $conn, string $testType, string $finalName, string $patientId, string $eye, string $dateYmd): array {
    $modality = strtoupper($testType);
    if (!defined('ALLOWED_TEST_TYPES') || !is_array(ALLOWED_TEST_TYPES) || !array_key_exists($modality, ALLOWED_TEST_TYPES)) {
        return ['success' => false, 'message' => "Invalid test type"];
    }

    // 1) Save the file
    $dirName   = ALLOWED_TEST_TYPES[$modality];
    $targetDir = rtrim(IMAGE_BASE_DIR, '/')."/{$dirName}/";
    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }

    $tmp = $_FILES['image']['tmp_name'] ?? null;
    if (!$tmp || !is_uploaded_file($tmp)) {
        return ['success' => false, 'message' => "Upload failed (tmp not found)"];
    }
    $targetFile = $targetDir . $finalName;
    if (is_file($targetFile)) { @unlink($targetFile); }
    if (!move_uploaded_file($tmp, $targetFile)) {
        return ['success' => false, 'message' => "Failed to move file"];
    }
    if ($modality =="VF"){
      $cmd = "/usr/local/bin/anonymiseHVF.sh ".$targetFile." ".$targetDir;
      exec($cmd);
    }
    // 2) Ensure parent rows
    $testId = ensureTest($conn, $patientId, $dateYmd);
    ensureTestEye($conn, $testId, $eye);

    // 3) Write DB references
    $modLower = strtolower($modality);           // faf | oct | vf | mferg
    $eyeLower = strtolower($eye);                // od | os
    $eyeUpper = ($eye === 'OS') ? 'OS' : 'OD';   // schema uses UPPERCASE suffix

    // Preferred: test_eyes.<modality>_reference_OD|OS
    $tePerEye = "{$modLower}_reference_{$eyeUpper}";
    if (hasColumn($conn, 'test_eyes', $tePerEye)) {
        $stmt = $conn->prepare("UPDATE test_eyes SET $tePerEye = ? WHERE test_id = ? AND eye = ?");
        $stmt->bind_param("sss", $finalName, $testId, $eye);
        $stmt->execute();
        $stmt->close();
    } else {
        // Extremely old installs might only have a single column
        $teSingle = "{$modLower}_reference";
        if (hasColumn($conn, 'test_eyes', $teSingle)) {
            $stmt = $conn->prepare("UPDATE test_eyes SET $teSingle = ? WHERE test_id = ? AND eye = ?");
            $stmt->bind_param("sss", $finalName, $testId, $eye);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Back-compat: tests.<modality>_reference_od|os if those columns exist
    $tPerEye = "{$modLower}_reference_{$eyeLower}";
    if (hasColumn($conn, 'tests', $tPerEye)) {
        $stmt = $conn->prepare("UPDATE tests SET $tPerEye = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $finalName, $testId);
        $stmt->execute();
        $stmt->close();
    }

    return ['success' => true, 'message' => "$finalName processed", 'test_id' => $testId];
}

/** Validate bulk filename and route to processImage */
function handleSingleFile(mysqli $conn, string $testType, string $tmpName, string $originalName): array {
    // Pattern: 920_OD_20250112.(png|pdf|exp) (exp only allowed for MFERG)
    $allowExp = (strtoupper($testType) === 'MFERG');
    $extPat   = $allowExp ? '(png|pdf|exp)' : '(png|pdf)';
    $pattern  = '/^(\d+)_(OD|OS)_(\d{8})\.' . $extPat . '$/i';

    if (!preg_match($pattern, $originalName, $m)) {
        return ['success' => false, 'message' => "Invalid filename: use PatientID_OD|OS_YYYYMMDD.(png|pdf" . ($allowExp? '|exp':'') . ')'];
    }

    $patientId = $m[1];
    $eye       = strtoupper($m[2]);
    $dateYmd   = $m[3];
    $ext       = strtolower($m[4]);

    if (!patientExists($conn, $patientId)) {
        return ['success' => false, 'message' => "Patient $patientId not found"];
    }

    $_FILES['image']['tmp_name'] = $tmpName; // for processImage

    return processImage($conn, $testType, "{$patientId}_{$eye}_{$dateYmd}.{$ext}", $patientId, $eye, $dateYmd);
}

/** Handle the single-file HTML form */
function handleSingleForm(mysqli $conn): array {
    $testType  = $_POST['test_type']  ?? '';
    $patientId = trim($_POST['patient_id'] ?? '');
    $eye       = strtoupper(trim($_POST['eye'] ?? ''));
    $dateIso   = $_POST['test_date']  ?? '';
    $tmpName   = $_FILES['image']['tmp_name'] ?? null;
    $origName  = $_FILES['image']['name']     ?? null;

    if (!$testType || !$patientId || !in_array($eye, ['OD','OS'], true) || !$dateIso || !$tmpName || !$origName) {
        return ['success' => false, 'message' => 'Missing required fields'];
    }
    if (!patientExists($conn, $patientId)) {
        return ['success' => false, 'message' => "Patient $patientId not found"];
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowExp = (strtoupper($testType) === 'MFERG');
    $okExts = $allowExp ? ['png','pdf','exp'] : ['png','pdf'];
    if (!in_array($ext, $okExts, true)) {
        return ['success' => false, 'message' => "Invalid extension .$ext for $testType"];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $dateIso);
    if (!$dt) return ['success' => false, 'message' => "Invalid date"];
    $dateYmd = $dt->format('Ymd');

    $_FILES['image']['tmp_name'] = $tmpName; // for processImage

    return processImage($conn, $testType, "{$patientId}_{$eye}_{$dateYmd}.{$ext}", $patientId, $eye, $dateYmd);
}

/* =========================
   routes (UNCHANGED LOGIC)
   ========================= */

// AJAX bulk upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!defined('ALLOWED_TEST_TYPES') || !array_key_exists(strtoupper($testType), ALLOWED_TEST_TYPES)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid test type']);
        exit;
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file']);
        exit;
    }

    $res = handleSingleFile($conn, $testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    echo json_encode($res['success'] ? ['status' => 'success', 'message' => $res['message']]
                                     : ['status' => 'error',   'message' => $res['message']]);
    exit;
}

// Single file HTML form submit
$formMessage = null;
$formClass   = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $formMessage = "Upload failed";
        $formClass = "error";
    } else {
        $res = handleSingleForm($conn);
        $formMessage = $res['message'];
        $formClass   = $res['success'] ? "success" : "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Import Images · Hydroxychloroquine Data Repository</title>

    <!-- Bootstrap & Icons (match index.php theme) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --brand:#1a73e8; /* same brand as index.php */
            --brand-2:#6ea8fe;
            --ok:#198754; --warn:#ffc107; --danger:#dc3545; --muted:#6c757d;
            --bg:#f7f8fb; --card:#ffffff; --text:#111827; --border:rgba(0,0,0,.08);
            --shadow:0 8px 24px rgba(0,0,0,0.08);
            --grad: radial-gradient(1200px 400px at 10% 0%, rgba(26,115,232,.06), transparent 60%);
        }
        body{ background:var(--bg); color:var(--text); }
        .navbar-blur{ backdrop-filter:saturate(180%) blur(8px); background-color:rgba(255,255,255,0.85); border-bottom:1px solid var(--border); }
        .card{ border:1px solid var(--border); box-shadow:var(--shadow); }
        .card-hero{ background:linear-gradient(135deg, var(--brand-2), var(--brand)); color:#fff; }
        .pill{ border-radius:999px; }
        .form-help{ color:var(--muted); font-size:0.92rem; }
        .drop{ border:2px dashed var(--border); background:#fff; border-radius:14px; padding:18px; }
        .progress-sm{ height:14px; }
        .list-min{ max-height:260px; overflow:auto; }
        .badge-soft{ background: rgba(26,115,232,.08); color:#0b3d91; border:1px solid rgba(26,115,232,.15); }
        .message.success{ background:#e6f7e6; color:#2e7d32; border:1px solid #c8e6c9; }
        .message.error{ background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }
        .message{ border-radius:10px; padding:10px 14px; }
        .file-ok{ color:#198754; }
        .file-err{ color:#dc3545; }
        code{ background:#f1f3f5; border-radius:6px; padding:2px 6px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php"><i class="bi bi-capsule-pill me-2"></i>Hydroxychloroquine Data Repository</a>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary btn-sm pill"><i class="bi bi-house"></i> Home</a>
      <a href="csv_import.php" class="btn btn-outline-primary btn-sm pill"><i class="bi bi-upload"></i> CSV Import</a>
    </div>
  </div>
</nav>

<main class="container py-4">
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card card-hero">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between">
          <div>
            <h1 class="h3 mb-1"><i class="bi bi-image"></i> Import Images</h1>
            <p class="mb-0">Upload single images or bulk folders. Filenames must be <code>PatientID_OD|OS_YYYYMMDD.ext</code>. <span class="badge badge-soft pill">mfERG allows .exp</span></p>
          </div>
          <div class="mt-3 mt-md-0 d-flex gap-2">
            <a href="#single" class="btn btn-light pill"><i class="bi bi-file-earmark-plus"></i> Single Upload</a>
            <a href="#bulk" class="btn btn-outline-light pill"><i class="bi bi-folder2-open"></i> Bulk Upload</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($formMessage): ?>
  <div class="row mb-3"><div class="col-12">
    <div class="message <?= htmlspecialchars($formClass) ?>"><?= htmlspecialchars($formMessage) ?></div>
  </div></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Single File Upload -->
    <div class="col-12 col-lg-5" id="single">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0"><i class="bi bi-file-earmark-arrow-up"></i> Single File Upload</h2>
            <span class="badge bg-light text-dark">PNG / PDF <span class="text-muted">(+ EXP for mfERG)</span></span>
          </div>
          <form method="POST" enctype="multipart/form-data" class="mt-2">
            <div class="mb-3">
              <label class="form-label">Test Type</label>
              <select name="test_type" class="form-select" required>
                <option value="">Select Test Type</option>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Patient ID</label>
              <input type="text" name="patient_id" class="form-control" placeholder="e.g. 920" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Test Date</label>
              <input type="date" name="test_date" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Eye</label>
              <select name="eye" class="form-select" required>
                <option value="OD">Right Eye (OD)</option>
                <option value="OS">Left Eye (OS)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">File</label>
              <input type="file" name="image" class="form-control" accept=".png,.pdf,.exp" required>
              <div class="form-text">Use <code>.exp</code> only for <strong>mfERG</strong>.</div>
            </div>
            <div class="d-grid">
              <button type="submit" name="import" class="btn btn-primary pill"><i class="bi bi-cloud-arrow-up"></i> Upload File</button>
            </div>
          </form>
          <p class="form-help mt-3 mb-0"><i class="bi bi-info-circle"></i> Bulk mode expects filenames like <code>PatientID_OD_YYYYMMDD.png</code>.</p>
        </div>
      </div>
    </div>

    <!-- Bulk Upload -->
    <div class="col-12 col-lg-7" id="bulk">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0"><i class="bi bi-folder-symlink"></i> Bulk Folder Upload</h2>
            <span class="badge bg-light text-dark">Progressive</span>
          </div>
          <div class="row g-3 align-items-end">
            <div class="col-sm-6">
              <label class="form-label">Test Type</label>
              <select id="test_type" class="form-select">
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Select Folder</label>
              <input type="file" id="bulk_files" class="form-control" webkitdirectory multiple>
            </div>
          </div>

          <div class="d-grid mt-3">
            <button id="startUpload" class="btn btn-outline-primary pill"><i class="bi bi-play-circle"></i> Start Upload</button>
          </div>

          <div class="mt-3">
            <div id="progress" class="small text-muted">No files uploaded yet</div>
            <div class="progress progress-sm mt-2">
              <div class="progress-bar" id="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
          </div>

          <div class="mt-3">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge bg-secondary"><i class="bi bi-list-check"></i> File Log</span>
              <span class="text-muted small">(successes in green, errors in red)</span>
            </div>
            <ul id="file-list" class="list-group list-min"></ul>
          </div>

        </div>
      </div>
    </div>
  </div>

</main>

<footer class="container py-4 text-center text-muted small">
  <span>Import Images · HCR</span>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== Bulk upload JS (UNCHANGED LOGIC) =====
document.getElementById('startUpload').addEventListener('click', async () => {
  const files = document.getElementById('bulk_files').files;
  if (!files || files.length === 0) { alert('Please select a folder'); return; }

  const test_type = document.getElementById('test_type').value;
  const fileList = document.getElementById('file-list');
  const progress = document.getElementById('progress');
  const progressBar = document.getElementById('progress-bar');

  fileList.innerHTML = '';
  let successCount = 0, errorCount = 0;

  // helper to make li with bootstrap styles
  function addItem(path, ok, msg){
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';
    li.innerHTML = `<span class="text-truncate" style="max-width:70%">${path}</span>`+
                   `<span class="ms-2 ${ok? 'file-ok':'file-err'} small">${ok? 'OK':'ERROR'}${msg? ': '+msg:''}</span>`;
    fileList.appendChild(li);
  }

  for (let i = 0; i < files.length; i++) {
    const file = files[i];

    const formData = new FormData();
    formData.append('bulk_upload', '1');
    formData.append('test_type', test_type);
    formData.append('image', file, file.name);

    try {
      const response = await fetch('import_images.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (result.status === 'success') {
        addItem(file.webkitRelativePath || file.name, true);
        successCount++;
      } else {
        addItem(file.webkitRelativePath || file.name, false, result.message || '');
        errorCount++;
      }
    } catch (err) {
      addItem(file.webkitRelativePath || file.name, false, 'network error');
      errorCount++;
    }

    const percentage = Math.round(((i+1)/files.length)*100);
    progress.innerText = `Processed ${i+1}/${files.length} | Success: ${successCount}, Errors: ${errorCount}`;
    progressBar.style.width = percentage + '%';
  }

  progress.innerText = `Upload finished! Success: ${successCount}, Errors: ${errorCount}`;
});
</script>
</body>
</html>
