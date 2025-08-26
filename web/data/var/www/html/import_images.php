<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

/* ----------------------------
   Config
----------------------------- */
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024; // 50MB

// Display name => subdir name
$ALLOWED_TEST_TYPES = [
  'FAF'   => 'faf',
  'OCT'   => 'oct',
  'VF'    => 'vf',
  'MFERG' => 'mferg'
];

$ALLOWED_EXTS = [
  'FAF'   => ['png', 'pdf'],
  'OCT'   => ['png', 'pdf'],
  'VF'    => ['png', 'pdf'],
  'MFERG' => ['png', 'pdf', 'exp'], // mfERG also supports .exp
];

/* ----------------------------
   Helpers
----------------------------- */
function respond_json($ok, $msg, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $msg], $extra));
    exit;
}

function sanitize_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return substr($name, 0, 255);
}

/**
 * Ensure there is a tests row for (patient, date). Return test_id.
 */
function get_or_create_test(mysqli $conn, string $patientId, string $dateYmd): string {
    $sqlDate = DateTime::createFromFormat('Ymd', $dateYmd)->format('Y-m-d');

    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
    $stmt->bind_param('ss', $patientId, $sqlDate);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['test_id'];
    }

    $testId = $patientId . '_' . $dateYmd . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    $ins = $conn->prepare("INSERT INTO tests (test_id, patient_id, date_of_test) VALUES (?, ?, ?)");
    $ins->bind_param('sss', $testId, $patientId, $sqlDate);
    if (!$ins->execute()) {
        throw new RuntimeException('DB insert error (tests): ' . $conn->error);
    }
    return $testId;
}

/**
 * Ensure there is a test_eyes row for (test_id, eye).
 */
function ensure_test_eye(mysqli $conn, string $testId, string $eye): void {
    $q = $conn->prepare("SELECT result_id FROM test_eyes WHERE test_id = ? AND eye = ?");
    $q->bind_param('ss', $testId, $eye);
    $q->execute();
    $res = $q->get_result();
    if ($res && $res->fetch_assoc()) return;

    $ins = $conn->prepare("INSERT INTO test_eyes (test_id, eye) VALUES (?, ?)");
    $ins->bind_param('ss', $testId, $eye);
    if (!$ins->execute()) {
        throw new RuntimeException('DB insert error (test_eyes): ' . $conn->error);
    }
}

/**
 * Map modality + eye to the correct column in test_eyes (per your schema).
 * Returns e.g. faf_reference_OD, oct_reference_OS, etc.
 */
function reference_column(string $testType, string $eye): string {
    $eye = strtoupper($eye) === 'OS' ? 'OS' : 'OD';
    switch (strtoupper($testType)) {
        case 'FAF':   return "faf_reference_{$eye}";
        case 'OCT':   return "oct_reference_{$eye}";
        case 'VF':    return "vf_reference_{$eye}";
        case 'MFERG': return "mferg_reference_{$eye}";
        default: throw new RuntimeException('Unsupported test type');
    }
}

/**
 * Save uploaded file to uploads/<type>/<YYYY>/<MM>/<unique>.ext and return [path, stored]
 * We store only the basename (stored) in the DB.
 */
function save_uploaded_file(array $file, string $testType, string $originalName, array $ALLOWED_TEST_TYPES, array $ALLOWED_EXTS): array {
    if ($file['error'] !== UPLOAD_ERR_OK)  throw new RuntimeException('Upload error code: ' . $file['error']);
    if ($file['size'] > MAX_UPLOAD_BYTES)  throw new RuntimeException('File too large');

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXTS[$testType] ?? [], true)) {
        throw new RuntimeException("Unsupported file type for {$testType}. Allowed: " . implode(', ', $ALLOWED_EXTS[$testType] ?? []));
    }

    $dir = 'uploads/' . $ALLOWED_TEST_TYPES[$testType] . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Failed to create upload folders');
    }

    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $safe = sanitize_filename($base) . '.' . $ext;

    // Avoid collisions
    $target = $dir . '/' . $safe;
    $i = 1;
    while (file_exists($target)) {
        $target = $dir . '/' . sanitize_filename($base) . "_{$i}." . $ext;
        $i++;
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Failed to move uploaded file');
    }

    return ['path' => $target, 'stored' => basename($target)];
}

/**
 * Process a single file whose filename encodes patient/eye/date, e.g.:
 *   P_12345_OD_20250131.png   (MFERG also supports .exp)
 */
function process_one_file(mysqli $conn, string $testType, string $filename, array $fileInfo, array $ALLOWED_TEST_TYPES, array $ALLOWED_EXTS): array {
    $isMferg = strtoupper($testType) === 'MFERG';
    $pattern = $isMferg
        ? '/^([A-Za-z0-9_-]+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
        : '/^([A-Za-z0-9_-]+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

    if (!preg_match($pattern, $filename, $m)) {
        return ['ok' => false, 'msg' => 'Filename must be PatientID_OD|OS_YYYYMMDD.ext'];
    }

    $patientId = $m[1];
    $eye       = strtoupper($m[2]); // OD/OS
    $dateYmd   = $m[3];

    if (!in_array($eye, ['OD','OS'], true)) {
        return ['ok' => false, 'msg' => 'Eye must be OD or OS'];
    }

    // 1) Save file
    $saved = save_uploaded_file($fileInfo, $testType, $filename, $ALLOWED_TEST_TYPES, $ALLOWED_EXTS);

    // 2) Ensure tests row
    $testId = get_or_create_test($conn, $patientId, $dateYmd);

    // 3) Ensure test_eyes row for this eye
    ensure_test_eye($conn, $testId, $eye);

    // 4) Update the proper column in test_eyes for this eye
    $col = reference_column($testType, $eye); // e.g., faf_reference_OD
    $sql = "UPDATE test_eyes SET {$col} = ? WHERE test_id = ? AND eye = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $saved['stored'], $testId, $eye);
    if (!$stmt->execute()) {
        return ['ok' => false, 'msg' => 'DB error: ' . $conn->error];
    }

    return ['ok' => true, 'msg' => "{$testType} file saved for {$eye}", 'test_id' => $testId, 'eye' => $eye, 'stored' => $saved['stored']];
}

/* ----------------------------
   API: Bulk (folder) via fetch
----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!isset($ALLOWED_TEST_TYPES[$testType])) respond_json(false, 'Invalid test type');
    if (!isset($_FILES['image'])) respond_json(false, 'No file provided');

    try {
        $result = process_one_file($conn, $testType, $_FILES['image']['name'], $_FILES['image'], $ALLOWED_TEST_TYPES, $ALLOWED_EXTS);
        if ($result['ok']) {
            respond_json(true, $result['msg'], ['test_id' => $result['test_id'], 'eye' => $result['eye'], 'file' => $result['stored']]);
        } else {
            respond_json(false, $result['msg']);
        }
    } catch (Throwable $e) {
        respond_json(false, $e->getMessage());
    }
}

/* ----------------------------
   Single upload (form POST)
----------------------------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $testType   = $_POST['test_type'] ?? '';
    $patientId  = trim($_POST['patient_id'] ?? '');
    $testDate   = $_POST['test_date'] ?? '';
    $eye        = strtoupper($_POST['eye'] ?? 'OD');

    try {
        if (!isset($ALLOWED_TEST_TYPES[$testType])) throw new RuntimeException('Invalid test type');
        if (!$patientId) throw new RuntimeException('Patient ID required');
        if (!$testDate)  throw new RuntimeException('Test date required');
        if (!in_array($eye, ['OD','OS'], true)) throw new RuntimeException('Eye must be OD or OS');
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed');
        }

        // Build a synthetic filename so the same parser can be used:
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if ($ext === '') $ext = 'png';
        if (!in_array($ext, $ALLOWED_EXTS[$testType] ?? [], true)) {
            throw new RuntimeException("Unsupported file type for {$testType}. Allowed: " . implode(', ', $ALLOWED_EXTS[$testType] ?? []));
        }
        $synthetic = $patientId . '_' . $eye . '_' . str_replace('-', '', $testDate) . '.' . $ext;

        $res = process_one_file($conn, $testType, $synthetic, $_FILES['image'], $ALLOWED_TEST_TYPES, $ALLOWED_EXTS);
        $flash = $res['ok']
          ? ['type' => 'success', 'text' => $res['msg'] . " (Test ID: {$res['test_id']})"]
          : ['type' => 'danger',  'text' => $res['msg']];
    } catch (Throwable $e) {
        $flash = ['type' => 'danger', 'text' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Import Medical Images</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f7f8fb}
.card{box-shadow:0 12px 28px rgba(0,0,0,0.08); border:1px solid rgba(0,0,0,.06)}
.card-header{background:linear-gradient(135deg,#6ea8fe 0%,#1a73e8 100%); color:#fff}
.progress-sm{height:12px}
.list-scroll{max-height:260px; overflow:auto}
.badge-eye{font-size:.8rem}
</style>
</head>
<body>
<nav class="navbar navbar-light bg-white border-bottom sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><i class="bi bi-capsule-pill me-2"></i>Hydroxychloroquine Data Repository</a>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-house"></i> Home</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Single Upload -->
    <div class="col-12 col-lg-5">
      <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-upload"></i> Single File Upload</h5></div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data" class="vstack gap-2">
            <div>
              <label class="form-label">Test Type</label>
              <select name="test_type" class="form-select" required>
                <option value="">Select Test Type</option>
                <?php foreach ($ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Patient ID</label>
              <input type="text" name="patient_id" class="form-control" placeholder="e.g. P_12345" required>
            </div>
            <div>
              <label class="form-label">Test Date</label>
              <input type="date" name="test_date" class="form-control" required>
            </div>
            <div>
              <label class="form-label">Eye</label>
              <select name="eye" class="form-select" required>
                <option value="OD">Right (OD)</option>
                <option value="OS">Left (OS)</option>
              </select>
            </div>
            <div>
              <label class="form-label">File (PNG/PDF; mfERG also .exp)</label>
              <input type="file" name="image" class="form-control" accept="image/png,.pdf,.exp" required>
            </div>
            <button class="btn btn-primary mt-2" type="submit" name="import"><i class="bi bi-cloud-upload"></i> Upload</button>
            <div class="form-text">For bulk, ensure filenames follow <code>PatientID_OD|OS_YYYYMMDD.ext</code>.</div>
          </form>
        </div>
      </div>
    </div>

    <!-- Bulk Upload -->
    <div class="col-12 col-lg-7">
      <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-folder2-open"></i> Bulk Folder Upload</h5></div>
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-6">
              <label class="form-label">Test Type</label>
              <select id="test_type" class="form-select">
                <?php foreach ($ALLOWED_TEST_TYPES as $type => $dir): ?>
                  <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Select Folder</label>
              <input type="file" id="bulk_files" class="form-control" webkitdirectory multiple>
            </div>
            <div class="col-12">
              <button id="startUpload" class="btn btn-success"><i class="bi bi-play-fill"></i> Start Upload</button>
            </div>
          </div>

          <div class="mt-3">
            <div class="d-flex justify-content-between">
              <div id="progressText" class="small text-muted">No files uploaded yet</div>
              <div><span class="badge bg-secondary" id="successCount">0</span> success • <span class="badge bg-danger" id="errorCount">0</span> errors</div>
            </div>
            <div class="progress progress-sm mt-1">
              <div class="progress-bar" id="progressBar" role="progressbar" style="width:0%"></div>
            </div>
          </div>

          <div class="mt-3 list-scroll">
            <ul class="list-group" id="fileList"></ul>
          </div>

          <div class="mt-3">
            <div class="alert alert-info mb-0">
              Filename rule: <code>PatientID_OD|OS_YYYYMMDD.(png|pdf)</code> — MFERG also allows <code>.exp</code>.
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
document.getElementById('startUpload').addEventListener('click', async () => {
  const files = document.getElementById('bulk_files').files;
  if (!files.length) { alert('Please select a folder'); return; }
  const testType = document.getElementById('test_type').value;
  const fileList = document.getElementById('fileList');
  const progressText = document.getElementById('progressText');
  const progressBar = document.getElementById('progressBar');
  const successCount = document.getElementById('successCount');
  const errorCount = document.getElementById('errorCount');

  fileList.innerHTML = '';
  let ok = 0, err = 0;

  for (let i = 0; i < files.length; i++) {
    const f = files[i];
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';
    li.textContent = f.webkitRelativePath;
    const badge = document.createElement('span');
    badge.className = 'badge rounded-pill';
    li.appendChild(badge);
    fileList.appendChild(li);

    const fd = new FormData();
    fd.append('bulk_upload', '1');
    fd.append('test_type', testType);
    fd.append('image', f, f.name);

    try {
      const resp = await fetch('import_images.php', { method: 'POST', body: fd });
      const json = await resp.json();
      if (json.status === 'success') {
        ok++; badge.classList.add('bg-success'); badge.textContent = 'OK';
      } else {
        err++; badge.classList.add('bg-danger'); badge.textContent = 'ERR';
        const small = document.createElement('div');
        small.className = 'small text-danger mt-1';
        small.textContent = json.message || 'Error';
        li.appendChild(small);
      }
    } catch (e) {
      err++; badge.classList.add('bg-danger'); badge.textContent = 'ERR';
      const small = document.createElement('div');
      small.className = 'small text-danger mt-1';
      small.textContent = 'Network error';
      li.appendChild(small);
    }

    const pct = Math.round(((i+1)/files.length)*100);
    progressBar.style.width = pct + '%';
    progressText.textContent = `Processed ${i+1}/${files.length}`;
    successCount.textContent = ok;
    errorCount.textContent = err;
  }
});
</script>
</body>
</html>

