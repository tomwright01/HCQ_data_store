<?php
// import_images.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

// CORS for bulk (fetch)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// ------------- Helpers (DB + Files) -------------

/**
 * Verify patient exists (FK safety).
 */
function patientExists(string $patientId): bool {
    $p = getPatientById($patientId);
    return is_array($p) && !empty($p['patient_id']);
}

/**
 * Ensure a tests row exists for (patient_id, date_of_test). Return test_id.
 * Uses compact ID that fits VARCHAR(25).
 */
function ensureTestId(mysqli $conn, string $patientId, string $dateYmd): array {
    $dObj = DateTime::createFromFormat('Ymd', $dateYmd);
    if (!$dObj) return [null, "Invalid date: $dateYmd"];
    $dateSql = $dObj->format('Y-m-d');

    // Try to find existing
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id=? AND date_of_test=? LIMIT 1");
    $stmt->bind_param("ss", $patientId, $dateSql);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($found && !empty($found['test_id'])) {
        return [$found['test_id'], null];
    }

    // Create new (location defaults to KH in schema, but we’ll set it explicitly)
    $testId = 'T' . substr(strtoupper(md5($patientId.'|'.$dateSql)), 0, 16);
    $location = 'KH';

    $stmt = $conn->prepare("INSERT INTO tests (test_id, patient_id, location, date_of_test) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $testId, $patientId, $location, $dateSql);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return [null, "Failed to create test row: $err"];
    }
    $stmt->close();
    return [$testId, null];
}

/**
 * Insert or update a test_eyes row for (test_id, eye) setting the correct modality column.
 */
function upsertEyeReference(mysqli $conn, string $testId, string $eye, string $testType, string $filename): ?string {
    $col = strtolower($testType) . '_reference'; // faf_reference | oct_reference | vf_reference | mferg_reference

    // Does a row already exist?
    $stmt = $conn->prepare("SELECT result_id FROM test_eyes WHERE test_id=? AND eye=? LIMIT 1");
    $stmt->bind_param("ss", $testId, $eye);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $sql = "UPDATE test_eyes SET $col=?, updated_at=CURRENT_TIMESTAMP WHERE result_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $filename, $row['result_id']);
    } else {
        $sql = "INSERT INTO test_eyes (test_id, eye, $col) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $testId, $eye, $filename);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return "Failed to upsert eye reference: $err";
    }
    $stmt->close();
    return null;
}

/**
 * Move a tmp upload into /data/<TYPE>/ keeping the original filename.
 */
function moveToModalityDir(string $testType, string $tmpPath, string $originalName): array {
    try {
        $destDir = rtrim(getTestTypeDirectory($testType), '/');
    } catch (InvalidArgumentException $e) {
        return [null, "Invalid test type directory: " . $e->getMessage()];
    }

    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0775, true)) {
            return [null, "Failed to create directory: $destDir"];
        }
    }

    $target = $destDir . '/' . $originalName;

    // If file exists, overwrite (you can change this to auto-rename if you prefer)
    if (file_exists($target)) {
        @unlink($target);
    }

    if (is_uploaded_file($tmpPath)) {
        if (!move_uploaded_file($tmpPath, $target)) {
            return [null, "Failed to move uploaded file."];
        }
    } else {
        // Fallback (server-side path)
        if (!rename($tmpPath, $target)) {
            if (!copy($tmpPath, $target)) {
                return [null, "Failed to place file into destination."];
            }
        }
    }

    return [$originalName, null];
}

/**
 * Validate and process one file (bulk or single).
 * Single mode can optionally pass $overrideName to build a clean filename.
 */
function handleSingleFile(string $testType, string $tmpName, string $originalName, ?string $overrideName = null): array {
    global $conn;

    // extension rules
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','pdf'];
    if ($testType === 'MFERG') $allowed[] = 'exp';
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'message' => "Unsupported file type for $testType: .$ext"];
    }

    // Use override name (single form), else rely on file name pattern (bulk)
    $finalName = $overrideName ?: $originalName;

    // Pattern: PatientID_OD|OS_YYYYMMDD.ext
    $pattern = '/^([A-Za-z0-9_\-]+)_(OD|OS)_(\d{8})\.(png|jpg|jpeg|pdf|exp)$/i';
    if (!preg_match($pattern, $finalName, $m)) {
        return ['success' => false, 'message' => "Filename must be PatientID_OD|OS_YYYYMMDD.ext (got: $finalName)"];
    }
    $patientId = $m[1];
    $eye       = strtoupper($m[2]);
    $dateStr   = $m[3]; // YYYYMMDD

    // FK safety
    if (!patientExists($patientId)) {
        return ['success' => false, 'message' => "Patient '$patientId' does not exist. Please add them first (CSV/Form)."];
    }

    // Make sure we have a tests row
    [$testId, $err] = ensureTestId($conn, $patientId, $dateStr);
    if (!$testId) {
        return ['success' => false, 'message' => $err ?: "Could not ensure test row."];
    }

    // Move file into /data/<TYPE>
    [$storedName, $merr] = moveToModalityDir($testType, $tmpName, $finalName);
    if (!$storedName) {
        return ['success' => false, 'message' => $merr ?: "File move error"];
    }

    // Upsert into test_eyes.<modality>_reference
    $uerr = upsertEyeReference($conn, $testId, $eye, $testType, $storedName);
    if ($uerr) {
        return ['success' => false, 'message' => $uerr];
    }

    return ['success' => true, 'message' => "$testType saved for $patientId $eye ($dateStr)"];
}

// ------------- AJAX: Bulk folder upload -------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid test type']);
        exit;
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Upload failed']);
        exit;
    }

    // Size guard (optional)
    if (defined('MAX_FILE_SIZE') && $_FILES['image']['size'] > MAX_FILE_SIZE) {
        echo json_encode(['status' => 'error', 'message' => 'File too large']);
        exit;
    }

    $result = handleSingleFile($testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    echo json_encode($result['success']
        ? ['status' => 'success', 'message' => $result['message']]
        : ['status' => 'error', 'message' => $result['message']]);
    exit;
}

// ------------- Single form submit -------------

$flashMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import']) && !isset($_POST['bulk_upload'])) {
    $testType   = $_POST['test_type'] ?? '';
    $patientId  = trim($_POST['patient_id'] ?? '');
    $testDate   = $_POST['test_date'] ?? '';
    $eye        = $_POST['eye'] ?? 'OD';

    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        $flashMessage = ['type' => 'error', 'text' => 'Invalid test type.'];
    } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $flashMessage = ['type' => 'error', 'text' => 'Upload failed.'];
    } else {
        // Build canonical filename PatientID_EYE_YYYYMMDD.ext from the form
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        $dt  = DateTime::createFromFormat('Y-m-d', $testDate);
        $dateYmd = $dt ? $dt->format('Ymd') : '';

        $cleanPatient = preg_replace('/[^A-Za-z0-9_\-]/', '_', $patientId);
        $cleanEye     = strtoupper($eye) === 'OS' ? 'OS' : 'OD';

        $overrideName = "{$cleanPatient}_{$cleanEye}_{$dateYmd}.{$ext}";

        $res = handleSingleFile($testType, $_FILES['image']['tmp_name'], $_FILES['image']['name'], $overrideName);
        $flashMessage = ['type' => ($res['success'] ? 'success' : 'error'), 'text' => $res['message']];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Images • Hydroxychloroquine Repository</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root{
  --brand:#1a73e8; --brand-2:#6ea8fe;
  --bg:#f7f8fb; --card:#ffffff; --border:rgba(0,0,0,.08); --shadow:0 8px 24px rgba(0,0,0,0.08);
}
body { background: var(--bg); color:#111827; }
.navbar-blur { backdrop-filter: saturate(180%) blur(8px); background: rgba(255,255,255,0.9); border-bottom:1px solid var(--border); }
.card { border:1px solid var(--border); box-shadow: var(--shadow); background: var(--card); }
.pill { border-radius: 999px; }
/* Make primary buttons match index.php theme (brand blue) */
.btn-primary { background: var(--brand); border-color: var(--brand); }
.btn-primary:hover { background: #1462c8; border-color: #1462c8; }
/* Progress bar in brand color */
.progress-bar { background-color: var(--brand); }
.badge-faf { background:#6f42c1; }
.badge-oct { background:#1a73e8; }
.badge-vf  { background:#20c997; }
.badge-mfg { background:#ffc107; color:#111; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navbar-blur sticky-top py-2">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php"><i class="bi bi-images me-2"></i>Import Images</a>
    <div class="d-flex align-items-center gap-2">
      <a href="index.php" class="btn btn-outline-secondary pill"><i class="bi bi-house"></i> Back to Dashboard</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashMessage['type']==='success'?'success':'danger' ?>"><?= htmlspecialchars($flashMessage['text']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Single Upload -->
    <div class="col-12 col-lg-5">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-file-earmark-arrow-up"></i> Single File Upload</h5>
          <p class="text-muted">Use this when you know the patient, date and eye. We’ll standardize the filename for you.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-2">
            <div class="mb-3">
              <label class="form-label">Test Type</label>
              <select name="test_type" class="form-select" required>
                <option value="">Select</option>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Patient ID</label>
                <input type="text" name="patient_id" class="form-control" placeholder="e.g. P_ABC123" required>
              </div>
              <div class="col-6">
                <label class="form-label">Test Date</label>
                <input type="date" name="test_date" class="form-control" required>
              </div>
              <div class="col-6">
                <label class="form-label">Eye</label>
                <select name="eye" class="form-select" required>
                  <option value="OD">Right (OD)</option>
                  <option value="OS">Left (OS)</option>
                </select>
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label">File (PNG/JPG/PDF, MFERG also .exp)</label>
              <input type="file" name="image" class="form-control" accept="image/png,image/jpeg,application/pdf,.png,.jpg,.jpeg,.pdf,.exp" required>
            </div>

            <button type="submit" name="import" class="btn btn-primary mt-3 w-100">
              <i class="bi bi-upload"></i> Upload
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Bulk Folder Upload -->
    <div class="col-12 col-lg-7">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-folder-symlink"></i> Bulk Folder Upload</h5>
          <p class="text-muted mb-2">
            Select a modality and drag a folder. Files must be named
            <code>PatientID_OD|OS_YYYYMMDD.ext</code>. For MFERG, <code>.exp</code> is also accepted.
          </p>

          <div class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
              <label class="form-label">Test Type</label>
              <select id="test_type" class="form-select">
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-7">
              <label class="form-label">Select Folder</label>
              <input type="file" id="bulk_files" class="form-control" webkitdirectory multiple>
            </div>
          </div>

          <button id="startUpload" class="btn btn-primary mt-3 w-100">
            <i class="bi bi-cloud-upload"></i> Start Upload
          </button>

          <div class="mt-3">
            <div class="progress" style="height: 12px;">
              <div class="progress-bar" id="progress-bar" style="width:0%"></div>
            </div>
            <div id="progress" class="small text-muted mt-2">No files uploaded yet</div>
          </div>

          <ul id="file-list" class="mt-3 list-group small" style="max-height:260px; overflow:auto;"></ul>
        </div>
      </div>
    </div>
  </div>

  <div class="text-center mt-4">
    <a href="index.php" class="btn btn-outline-secondary pill"><i class="bi bi-house"></i> Return Home</a>
  </div>
</div>

<script>
document.getElementById('startUpload').addEventListener('click', async () => {
  const files = document.getElementById('bulk_files').files;
  if (!files || files.length === 0) { alert('Please select a folder with files.'); return; }

  const test_type = document.getElementById('test_type').value;
  const fileList  = document.getElementById('file-list');
  const progress  = document.getElementById('progress');
  const bar       = document.getElementById('progress-bar');

  fileList.innerHTML = '';
  let ok = 0, bad = 0;

  for (let i=0; i<files.length; i++) {
    const f  = files[i];
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';
    li.textContent = f.webkitRelativePath || f.name;
    fileList.appendChild(li);

    const fd = new FormData();
    fd.append('bulk_upload','1');
    fd.append('test_type', test_type);
    fd.append('image', f, f.name);

    try {
      const res = await fetch('import_images.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (json.status === 'success') {
        ok++;
        li.innerHTML = `<span>${li.textContent}</span><span class="badge bg-success">OK</span>`;
      } else {
        bad++;
        li.innerHTML = `<span>${li.textContent}</span><span class="badge bg-danger" title="${json.message||'error'}">ERR</span>`;
      }
    } catch (e) {
      bad++;
      li.innerHTML = `<span>${li.textContent}</span><span class="badge bg-danger" title="network error">ERR</span>`;
    }

    const pct = Math.round(((i+1)/files.length)*100);
    bar.style.width = pct + '%';
    progress.textContent = `Processed ${i+1}/${files.length} • Success: ${ok} • Errors: ${bad}`;
  }

  progress.textContent = `Upload finished • Success: ${ok} • Errors: ${bad}`;
});
</script>
</body>
</html>

