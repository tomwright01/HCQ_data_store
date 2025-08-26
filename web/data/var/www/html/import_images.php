<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

// CORS for progressive upload
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

function json_out($ok, $msg, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $msg], $extra));
    exit;
}

/**
 * Save one file named like:  920_OD_20250112.png
 * - places it in /data/<TYPE>/<filename>
 * - ensures tests & test_eyes
 * - sets the correct *_reference_OD|OS column in test_eyes
 */
function handleOne(string $testType, array $file): array {
    global $conn;

    $name = $file['name'] ?? '';
    $tmp  = $file['tmp_name'] ?? '';
    $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $size = $file['size'] ?? 0;

    if ($err !== UPLOAD_ERR_OK) return ['ok'=>false,'msg'=>"Upload error for $name (code $err)"];
    if ($size > MAX_FILE_SIZE)  return ['ok'=>false,'msg'=>"$name too large"];

    $isMferg = strtoupper($testType) === 'MFERG';
    $pat = $isMferg
        ? '/^([A-Za-z0-9_-]+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
        : '/^([A-Za-z0-9_-]+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

    if (!preg_match($pat, $name, $m)) {
        return ['ok'=>false,'msg'=>"Invalid filename ($name). Expected PatientID_OD|OS_YYYYMMDD.(png|pdf" . ($isMferg?'|exp':'') . ')'];
    }
    $patientId = $m[1];
    $eye       = strtoupper($m[2]);
    $dateYmd   = $m[3];

    // Patient must exist
    if (!getPatientById($patientId)) return ['ok'=>false,'msg'=>"Patient $patientId not found"];

    // Prepare destination (flat folder)
    if (!isset(ALLOWED_TEST_TYPES[$testType])) return ['ok'=>false,'msg'=>'Invalid test type'];
    $subdir = ALLOWED_TEST_TYPES[$testType];
    $destDir = rtrim(IMAGE_BASE_DIR, '/').'/'.$subdir;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        return ['ok'=>false,'msg'=>"Cannot create $destDir"];
    }

    $dest = $destDir . '/' . $name;
    // Avoid accidental overwrite by auto-suffixing
    if (file_exists($dest)) {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $i = 1;
        do {
            $alt = $destDir . '/' . $base . "_$i." . $ext;
            $i++;
        } while (file_exists($alt));
        $dest = $alt;
        $name = basename($dest);
    }

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok'=>false,'msg'=>"Failed to move $file[name]"];
    }

    // DB writes
    $conn->begin_transaction();
    try {
        $testId = ensureTest($conn, $patientId, $dateYmd);
        ensureTestEye($conn, $testId, $eye);

        $col = refCol($testType, $eye); // e.g., faf_reference_OD
        $sql = "UPDATE test_eyes SET $col = ? WHERE test_id = ? AND eye = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $name, $testId, $eye);
        if (!$stmt->execute()) throw new RuntimeException($conn->error);
        $stmt->close();

        $conn->commit();
        return ['ok'=>true,'msg'=>"$name saved", 'test_id'=>$testId, 'eye'=>$eye, 'file'=>$name];
    } catch (Throwable $e) {
        $conn->rollback();
        // rollback file move
        if (is_file($dest)) @unlink($dest);
        return ['ok'=>false,'msg'=>"DB error: ".$e->getMessage()];
    }
}

/* Progressive (AJAX/fetch) bulk upload */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!isset(ALLOWED_TEST_TYPES[$testType])) json_out(false, 'Invalid test type');
    if (!isset($_FILES['image'])) json_out(false, 'No file');

    $res = handleOne($testType, $_FILES['image']);
    $res['ok'] ? json_out(true, $res['msg'], $res) : json_out(false, $res['msg']);
}

/* Single form upload (optional UI below uses filename synthesis to reuse same logic) */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    try {
        $testType  = $_POST['test_type'] ?? '';
        $patientId = trim($_POST['patient_id'] ?? '');
        $eye       = strtoupper($_POST['eye'] ?? '');
        $testDate  = $_POST['test_date'] ?? '';

        if (!isset(ALLOWED_TEST_TYPES[$testType])) throw new RuntimeException('Invalid test type');
        if (!$patientId) throw new RuntimeException('Patient ID required');
        if (!in_array($eye, ['OD','OS'], true)) throw new RuntimeException('Eye must be OD or OS');
        if (!$testDate) throw new RuntimeException('Test date required');
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed');

        // synthesize filename to run through same parser
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION)) ?: 'png';
        $ymd = (new DateTime($testDate))->format('Ymd');
        $synthetic = $patientId . '_' . $eye . '_' . $ymd . '.' . $ext;

        // override the upload array's name so our handler reads it
        $file = $_FILES['image'];
        $file['name'] = $synthetic;

        $res = handleOne($testType, $file);
        $flash = $res['ok'] ? ['type'=>'success','text'=>$res['msg']." (Test ID: {$res['test_id']})"]
                            : ['type'=>'danger','text'=>$res['msg']];
    } catch (Throwable $e) {
        $flash = ['type'=>'danger','text'=>$e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Medical Image Importer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto; background:#f7f8fb; margin:0}
.container{max-width:1000px;margin:32px auto;background:#fff;padding:32px;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.06)}
h1{color:#00a88f;margin:0 0 16px}
h2{color:#00a88f;margin:24px 0 8px}
label{display:block;font-weight:600;margin:10px 0 6px}
input,select{width:100%;padding:10px;border:1px solid #d0d7de;border-radius:8px}
button{padding:12px 16px;border:none;border-radius:8px;background:#00a88f;color:#fff;font-weight:700;cursor:pointer}
.row{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.message{padding:10px;border-radius:6px;margin-bottom:12px}
.success{background:#e6f7e6;color:#2d6a4f;border:1px solid #b7e4c7}
.danger{background:#fde2e1;color:#842029;border:1px solid #f5c2c7}
#file-list{list-style:none;padding:0;margin:0;max-height:240px;overflow:auto}
#file-list li{padding:6px 0;border-bottom:1px dashed #eee}
#file-list li .ok{color:#2d6a4f;font-weight:600}
#file-list li .err{color:#842029;font-weight:600}
.progress{height:12px;background:#eee;border-radius:6px;overflow:hidden;margin-top:8px}
.progress>div{height:100%;width:0;background:#00a88f;transition:width .3s}
</style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>

    <?php if ($flash): ?>
      <div class="message <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <div class="row">
      <section>
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data" class="vstack gap-2">
          <label>Test Type</label>
          <select name="test_type" required>
            <option value="">Select…</option>
            <?php foreach (ALLOWED_TEST_TYPES as $t => $d): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>

          <label>Patient ID</label>
          <input type="text" name="patient_id" placeholder="e.g. 920" required>

          <label>Eye</label>
          <select name="eye" required>
            <option value="OD">Right (OD)</option>
            <option value="OS">Left (OS)</option>
          </select>

          <label>Test Date</label>
          <input type="date" name="test_date" required>

          <label>File (PNG/PDF; MFERG also .exp)</label>
          <input type="file" name="image" accept="image/png,.pdf,.exp" required>

          <button type="submit" name="import">Upload</button>
          <p class="small">Bulk upload uses filenames like <code>920_OD_20250112.png</code>.</p>
        </form>
      </section>

      <section>
        <h2>Bulk Folder Upload</h2>
        <label>Test Type</label>
        <select id="test_type">
          <?php foreach (ALLOWED_TEST_TYPES as $t => $d): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>

        <label>Select Folder</label>
        <input type="file" id="bulk_files" webkitdirectory multiple>

        <button id="startUpload" style="margin-top:10px">Start Upload</button>

        <div id="progressText" style="margin-top:10px;color:#6b7280">No files uploaded yet</div>
        <div class="progress"><div id="bar"></div></div>

        <ul id="file-list"></ul>
      </section>
    </div>
</div>

<script>
document.getElementById('startUpload').addEventListener('click', async () => {
  const files = document.getElementById('bulk_files').files;
  if (!files.length) { alert('Please select a folder'); return; }
  const testType = document.getElementById('test_type').value;
  const list = document.getElementById('file-list');
  const bar = document.getElementById('bar');
  const txt = document.getElementById('progressText');

  list.innerHTML = '';
  let ok=0, err=0;

  for (let i=0;i<files.length;i++) {
    const f = files[i];
    const li = document.createElement('li');
    li.textContent = f.webkitRelativePath + ' — ';
    const badge = document.createElement('span');
    li.appendChild(badge);
    list.appendChild(li);

    const fd = new FormData();
    fd.append('bulk_upload','1');
    fd.append('test_type', testType);
    fd.append('image', f, f.name);

    try {
      const resp = await fetch('import_images.php', { method:'POST', body: fd });
      const j = await resp.json();
      if (j.status === 'success') {
        ok++; badge.textContent = 'OK'; badge.className='ok';
      } else {
        err++; badge.textContent = 'ERR: ' + (j.message||'Error'); badge.className='err';
      }
    } catch(e) {
      err++; badge.textContent = 'ERR: network'; badge.className='err';
    }

    const pct = Math.round(((i+1)/files.length)*100);
    bar.style.width = pct+'%';
    txt.textContent = `Processed ${i+1}/${files.length} — ${ok} success, ${err} errors`;
  }
});
</script>
</body>
</html>

