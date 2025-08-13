<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

// CORS for fetch (bulk)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ---------- Small helpers ----------
function json_out($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

function ensure_dir($dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
}

function clean_id($s) {
    // Allow letters, numbers, underscore, hyphen
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$s);
}

function file_ext($name) {
    $p = pathinfo($name);
    return strtolower($p['extension'] ?? '');
}

// map test type -> test_eyes column
function ref_col_for_type($type) {
    $map = [
        'FAF'   => 'faf_reference',
        'OCT'   => 'oct_reference',
        'VF'    => 'vf_reference',
        'MFERG' => 'mferg_reference',
    ];
    $t = strtoupper($type);
    if (!isset($map[$t])) throw new InvalidArgumentException("Invalid test type: $type");
    return $map[$t];
}

// make a friendly test_id (tests.test_id is VARCHAR(25))
function make_test_id(DateTime $dt) {
    // e.g. 20240115_ab12 (<= 25 chars)
    return $dt->format('Ymd') . '_' . substr(md5($dt->format('Ymd') . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 4);
}

// find or create tests row by (patient_id, date_of_test)
function get_or_create_test(mysqli $conn, $patientId, DateTime $date) {
    // already exists?
    $sql = "SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("DB prepare failed: " . $conn->error);
    $ymd = $date->format('Y-m-d');
    $stmt->bind_param("ss", $patientId, $ymd);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row['test_id'];

    // need location; use patient's location if available, else default (KH)
    $patient = getPatientById($patientId);
    $loc = $patient['location'] ?? 'KH';

    $testId = make_test_id($date);
    $sql = "INSERT INTO tests (test_id, patient_id, location, date_of_test) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("DB prepare failed: " . $conn->error);
    $stmt->bind_param("ssss", $testId, $patientId, $loc, $ymd);
    if (!$stmt->execute()) throw new RuntimeException("DB error: " . $stmt->error);
    $stmt->close();
    return $testId;
}

// set the correct image col in test_eyes (update if exists by (test_id, eye) else insert)
function upsert_test_eye_reference(mysqli $conn, $testId, $eye, $col, $filename) {
    // find existing row
    $sql = "SELECT result_id FROM test_eyes WHERE test_id = ? AND eye = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException("DB prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $testId, $eye);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $rid = (int)$row['result_id'];
        $sql = "UPDATE test_eyes SET $col = ?, updated_at = CURRENT_TIMESTAMP WHERE result_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException("DB prepare failed: " . $conn->error);
        $stmt->bind_param("si", $filename, $rid);
        if (!$stmt->execute()) throw new RuntimeException("DB error: " . $stmt->error);
        $stmt->close();
    } else {
        $sql = "INSERT INTO test_eyes (test_id, eye, $col) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException("DB prepare failed: " . $conn->error);
        $stmt->bind_param("sss", $testId, $eye, $filename);
        if (!$stmt->execute()) throw new RuntimeException("DB error: " . $stmt->error);
        $stmt->close();
    }
}

// main worker for one file
function handle_one_file(mysqli $conn, $testType, $tmpPath, $origName, $patientId = null, $testDateStr = null, $eye = null) {
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        return ['success'=>false, 'message'=>'Invalid test type'];
    }

    // extensions: allow png, jpg, jpeg, pdf; and exp only for MFERG
    $ext = file_ext($origName);
    $typeUp = strtoupper($testType);
    $allowed = ['png','jpg','jpeg','pdf'];
    if ($typeUp === 'MFERG') $allowed[] = 'exp';
    if (!in_array($ext, $allowed, true)) {
        return ['success'=>false, 'message'=>"Invalid file extension .{$ext}. Allowed: ".implode(', ', $allowed)];
    }

    // If any of patientId/testDate/eye missing -> parse from filename "PatientID_OD|OS_YYYYMMDD.ext"
    if ($patientId === null || $testDateStr === null || $eye === null) {
        $pattern = ($typeUp === 'MFERG')
            ? '/^([A-Za-z0-9_\-]+)_(OD|OS)_(\d{8})\.(png|jpg|jpeg|pdf|exp)$/i'
            : '/^([A-Za-z0-9_\-]+)_(OD|OS)_(\d{8})\.(png|jpg|jpeg|pdf)$/i';

        if (!preg_match($pattern, $origName, $m)) {
            return ['success'=>false, 'message'=>"Filename must match: PatientID_OD|OS_YYYYMMDD.ext"];
        }
        $patientId   = $m[1];
        $eye         = strtoupper($m[2]);
        $testDateStr = $m[3]; // YYYYMMDD
        $dt = DateTime::createFromFormat('Ymd', $testDateStr);
    } else {
        $eye = strtoupper($eye);
        // allow HTML date (YYYY-MM-DD)
        $dt  = DateTime::createFromFormat('Y-m-d', $testDateStr) ?: DateTime::createFromFormat('Ymd', $testDateStr);
    }

    if (!$dt) return ['success'=>false, 'message'=>'Invalid test date'];
    if (!in_array($eye, ['OD','OS'], true)) return ['success'=>false, 'message'=>'Eye must be OD or OS'];

    // patient must exist (patients.date_of_birth is NOT NULL so we can’t auto-create)
    $patient = getPatientById($patientId);
    if (!$patient) {
        return ['success'=>false, 'message'=>"Patient '{$patientId}' not found. Create them first (CSV/form)."];
    }

    // build final filename (no subfolders; your getDynamicImagePath expects this)
    $safePid = clean_id($patientId);
    $finalName = "{$safePid}_{$eye}_".$dt->format('Ymd').".{$ext}";

    // physical dir by test type (per your config)
    $typeDirName = ALLOWED_TEST_TYPES[$testType];       // e.g. "OCT"
    $destDir     = rtrim(IMAGE_BASE_DIR, '/')."/{$typeDirName}";
    ensure_dir($destDir);
    $destPath    = "{$destDir}/{$finalName}";

    // move file
    // note: we don't call isAllowedImageType() to allow PDFs/EXP reliably
    if (is_uploaded_file($tmpPath)) {
        if (!move_uploaded_file($tmpPath, $destPath)) {
            return ['success'=>false, 'message'=>'Upload failed (cannot move file)'];
        }
    } else {
        if (!@rename($tmpPath, $destPath)) {
            return ['success'=>false, 'message'=>'Upload failed (cannot move file)'];
        }
    }

    // DB: tests row by (patient, date), then test_eyes row by (test_id, eye)
    try {
        $testId = get_or_create_test($conn, $patientId, $dt);
        $col = ref_col_for_type($testType); // e.g. oct_reference
        upsert_test_eye_reference($conn, $testId, $eye, $col, $finalName);
    } catch (Throwable $e) {
        // roll back file if DB failed
        @unlink($destPath);
        return ['success'=>false, 'message'=>$e->getMessage()];
    }

    return [
        'success'=>true,
        'message'=>"$testType saved for {$patientId} {$eye} on ".$dt->format('Y-m-d'),
        'test_id'=>$testId,
        'filename'=>$finalName
    ];
}

// ---------- Bulk endpoint (AJAX; one file per request) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_out(['status'=>'error','message'=>'Upload failed']);
    }
    $res = handle_one_file($conn, $testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    json_out($res['success']
        ? ['status'=>'success','message'=>$res['message'],'test_id'=>$res['test_id'],'filename'=>$res['filename']]
        : ['status'=>'error','message'=>$res['message']]);
}

// ---------- Single upload (non-AJAX form) ----------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $testType  = $_POST['test_type']  ?? '';
    $patientId = $_POST['patient_id'] ?? '';
    $testDate  = $_POST['test_date']  ?? '';
    $eye       = $_POST['eye']        ?? '';
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type'=>'error','text'=>'Please choose a file to upload.'];
    } else {
        $res = handle_one_file($conn, $testType, $_FILES['image']['tmp_name'], $_FILES['image']['name'], $patientId, $testDate, $eye);
        $flash = $res['success'] ? ['type'=>'success','text'=>$res['message']] : ['type'=>'error','text'=>$res['message']];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Medical Image Importer</title>
<style>
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; margin: 0; background: #f0f4f8; color:#0f172a; }
    .container { max-width: 1100px; margin: 40px auto; background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 36px rgba(15,23,42,0.12); }
    h1 { text-align:center; color:#0f766e; margin-bottom: 24px; font-size: 32px; letter-spacing:.2px; }
    h2 { color:#0f766e; margin: 0 0 10px; font-size: 22px; letter-spacing:.2px; }
    .muted { color:#64748b; }
    .section { margin-bottom: 44px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
    label { display:block; font-weight:600; margin: 12px 0 6px; font-size:14px; }
    select, input[type="text"], input[type="date"], input[type="file"] {
        width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:10px; font-size:16px; margin-bottom:12px; transition:.2s; background:#fff;
    }
    select:focus, input:focus { outline:none; border-color:#0ea5e9; box-shadow: 0 0 0 4px rgba(14,165,233,.12); }
    button { width:100%; padding:14px; background:#0ea5e9; color:#fff; border:none; border-radius:10px; font-size:16px; font-weight:700; cursor:pointer; transition: transform .15s, box-shadow .15s, background .2s; }
    button:hover { background:#0284c7; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(2,132,199,.25); }
    .btn-secondary { background:#0f766e; } .btn-secondary:hover { background:#115e59; }
    .row { display:grid; grid-template-columns: repeat(12,1fr); gap:18px; }
    .col-4{grid-column:span 4} .col-6{grid-column:span 6} .col-8{grid-column:span 8} .col-12{grid-column:span 12}
    @media (max-width: 900px){ .col-4,.col-6,.col-8{grid-column:span 12;} }
    .message { text-align:center; padding:12px 14px; border-radius:10px; margin-bottom:20px; font-size:15px; }
    .success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .helper { background:#f1f5f9; border:1px solid #e2e8f0; padding:12px; border-radius:10px; font-size:13px; color:#475569; }
    ul#file-list { list-style:none; padding:0; font-size:14px; max-height:260px; overflow-y:auto; margin:0; }
    #file-list li { padding:6px 8px; border-bottom:1px dashed #e2e8f0; }
    #file-list li.success { color:#16a34a; } #file-list li.error{ color:#dc2626; }
    .chips .chip { display:inline-block; padding:2px 8px; border-radius:9999px; background:#e2e8f0; font-size:12px; color:#334155; margin-left:6px; }
    .back { display:flex; gap:10px; align-items:center; text-decoration:none; color:#0f766e; font-weight:700; margin-top: 28px; }
    .back:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>

    <?php if ($flash): ?>
        <div class="message <?= $flash['type']==='success'?'success':'error' ?>">
            <?= htmlspecialchars($flash['text']) ?>
        </div>
    <?php endif; ?>

    <!-- Single File Upload -->
    <div class="section">
        <h2>Single File Upload</h2>
        <p class="muted">Use when you know the patient/date and want us to rename & file it nicely.</p>
        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col-4">
                    <label>Test Type</label>
                    <select name="test_type" required>
                        <option value="">Select Test Type</option>
                        <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4">
                    <label>Patient ID</label>
                    <input type="text" name="patient_id" placeholder="e.g. P_abc123" required>
                </div>
                <div class="col-4">
                    <label>Test Date</label>
                    <input type="date" name="test_date" required>
                </div>
                <div class="col-4">
                    <label>Eye</label>
                    <select name="eye" required>
                        <option value="OD">Right Eye (OD)</option>
                        <option value="OS">Left Eye (OS)</option>
                    </select>
                </div>
                <div class="col-8">
                    <label>File <span class="chips"><span class="chip">PNG</span><span class="chip">JPG/JPEG</span><span class="chip">PDF</span><span class="chip">EXP (MFERG)</span></span></label>
                    <input type="file" name="image" accept="image/png,image/jpeg,.pdf,.exp" required>
                </div>
                <div class="col-12">
                    <button type="submit" name="import" class="btn-secondary">Upload File</button>
                </div>
            </div>
        </form>
        <div class="helper" style="margin-top:10px;">
            Files are stored under <strong><?= htmlspecialchars(IMAGE_BASE_DIR) ?><em>&lt;TYPE&gt;</em>/</strong>
            and the filename is saved into <em>test_eyes</em> as the appropriate <code>*_reference</code> column.
            We look up or create the <em>tests</em> row by <strong>(patient_id, date_of_test)</strong>.
        </div>
    </div>

    <!-- Bulk / Folder Upload -->
    <div class="section">
        <h2>Bulk Folder Upload (Progressive)</h2>
        <p class="muted">Place many files in a folder and upload them at once. Filenames must be <code>PatientID_OD|OS_YYYYMMDD.ext</code>.</p>

        <label>Test Type</label>
        <select id="test_type">
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Select Folder</label>
        <input type="file" id="bulk_files" webkitdirectory multiple>
        <button id="startUpload">Start Upload</button>

        <div id="progress" class="message" style="display:block;background:#f8fafc;border:1px solid #e2e8f0;">No files uploaded yet</div>
        <ul id="file-list"></ul>

        <div class="helper" style="margin-top:12px;">
            Examples: <code>P_001_OD_20240115.png</code>, <code>P_001_OS_20240115.pdf</code>, <code>P_001_OD_20240115.exp</code> (MFERG only).
        </div>
    </div>

    <a href="index.php" class="back">← Return Home</a>
</div>

<script>
const startBtn   = document.getElementById('startUpload');
const fileInput  = document.getElementById('bulk_files');
const typeSelect = document.getElementById('test_type');
const fileList   = document.getElementById('file-list');
const progressEl = document.getElementById('progress');

startBtn.addEventListener('click', async () => {
    const files = fileInput.files;
    if (!files || files.length === 0) { alert('Please select a folder'); return; }

    const test_type = typeSelect.value;
    fileList.innerHTML = '';
    let ok = 0, bad = 0;

    for (let i = 0; i < files.length; i++) {
        const f = files[i];
        if (!f || !f.name) continue;

        const li = document.createElement('li');
        li.textContent = f.webkitRelativePath || f.name;
        fileList.appendChild(li);

        const fd = new FormData();
        fd.append('bulk_upload', '1');
        fd.append('test_type', test_type);
        fd.append('image', f, f.name);

        try {
            const r = await fetch('import_images.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.status === 'success') {
                li.classList.add('success');
                ok++;
            } else {
                li.classList.add('error');
                li.textContent += ' — ' + (j.message || 'error');
                bad++;
            }
        } catch (e) {
            li.classList.add('error');
            li.textContent += ' — network error';
            bad++;
        }

        const pct = Math.round(((i + 1) / files.length) * 100);
        progressEl.textContent = `Processed ${i+1}/${files.length} • Success: ${ok}, Errors: ${bad} • ${pct}%`;
    }

    progressEl.textContent = `Upload finished • Success: ${ok}, Errors: ${bad}`;
});
</script>
</body>
</html>
