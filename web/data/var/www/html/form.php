<?php
// form.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// ---------- Small helpers ----------
function respond_json($arr) {
    header('Content-Type: application/json'); echo json_encode($arr); exit;
}
function val($arr, $key, $default=null) {
    return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default;
}
function normalize_actual_dx($v) {
    if ($v === null) return 'other';
    $v = strtolower(trim($v));
    if (in_array($v, ['ra','sle','sjogren','other'], true)) return $v;
    // map UI variants:
    if ($v === 'sjögren' || $v === 'sjogren\'s') return 'sjogren';
    return 'other';
}
function normalize_merci_score($v) {
    if ($v === null || $v === '') return null; // null in DB (VARCHAR(10))
    $t = strtolower(trim($v));
    if ($t === 'unable') return 'unable';
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n >= 0 && $n <= 100) return (string)(int)$n; // store as string
    }
    return null;
}
function compute_test_id($subject_id, $date_of_test) {
    // Stable, human-safe test_id (same for both eyes if same date + subject)
    return 'T_' . substr(md5($subject_id . '|' . $date_of_test), 0, 20);
}
function safe_num($v, $decimals=2) {
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v)) return null;
    return round((float)$v, $decimals);
}
function safe_int($v, $min=null, $max=null) {
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v)) return null;
    $n = (int)$v;
    if ($min !== null && $n < $min) return $min;
    if ($max !== null && $n > $max) return $max;
    return $n;
}
function ensure_dir($path) {
    if (!is_dir($path)) { @mkdir($path, 0775, true); }
}
function save_upload($field, $destDir, $prefix, $allow) {
    // $allow = ['ext'=>['png','jpg','jpeg','pdf'], 'mime'=>['image/png','image/jpeg','application/pdf']]
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $name = $_FILES[$field]['name'];
    $tmp  = $_FILES[$field]['tmp_name'];
    $type = mime_content_type($tmp);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allow['ext'], true)) return null;
    if (!in_array($type, $allow['mime'], true)) return null;

    ensure_dir($destDir);
    $fname = $prefix . '_' . uniqid() . '.' . $ext;
    $dest  = rtrim($destDir, '/').'/'.$fname;

    if (move_uploaded_file($tmp, $dest)) {
        return $dest; // store relative path
    }
    return null;
}

// ---------- Inline JSON endpoint for duplicate warning ----------
// Checks: does a row exist for (patient derived from subject_id) + date_of_test + eye?
if (isset($_GET['check']) && $_GET['check'] === '1') {
    $subject = trim($_GET['subject'] ?? '');
    $date    = trim($_GET['date'] ?? '');
    $eye     = strtoupper(trim($_GET['eye'] ?? ''));

    if ($subject === '' || $date === '' || !in_array($eye, ['OD','OS'], true)) {
        respond_json(['ok'=>false, 'exists'=>false, 'err'=>'bad params']);
    }

    $patientId = function_exists('generatePatientId')
        ? generatePatientId($subject)
        : ('P_' . substr(md5($subject), 0, 20));

    $sql = "
        SELECT te.result_id
        FROM test_eyes te
        JOIN tests t ON te.test_id = t.test_id
        WHERE t.patient_id = ? AND t.date_of_test = ? AND te.eye = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $patientId, $date, $eye);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    respond_json(['ok'=>true, 'exists'=>$exists]);
}

// ---------- Handle form POST ----------
$successMessage = '';
$errorMessage   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic required fields
    $subject_id     = trim($_POST['subject_id'] ?? '');
    $date_of_birth  = trim($_POST['date_of_birth'] ?? '');
    $location       = $_POST['location'] ?? 'KH';
    $actual_dx_ui   = $_POST['actual_diagnosis'] ?? 'other';

    try {
        if ($subject_id === '' || $date_of_birth === '') {
            throw new Exception('Subject ID and Date of Birth are required.');
        }
        if (!in_array($location, ['KH','CHUSJ','IWK','IVEY'], true)) {
            throw new Exception('Invalid location.');
        }

        $actual_dx = normalize_actual_dx($actual_dx_ui);

        // Patient ID policy (consistent with your codebase)
        $patient_id = function_exists('generatePatientId')
            ? generatePatientId($subject_id)
            : ('P_' . substr(md5($subject_id), 0, 20));

        $conn->begin_transaction();

        // Upsert patient (matches your schema exactly)
        $stmt = $conn->prepare("
            INSERT INTO patients (patient_id, subject_id, location, date_of_birth)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                subject_id = VALUES(subject_id),
                location   = VALUES(location),
                date_of_birth = VALUES(date_of_birth)
        ");
        $stmt->bind_param('ssss', $patient_id, $subject_id, $location, $date_of_birth);
        if (!$stmt->execute()) {
            throw new Exception('Failed saving patient: '.$stmt->error);
        }
        $stmt->close();

        // Collect eye blocks (OD/OS)
        $EYES = ['OD','OS'];
        $eyePayloads = [];
        foreach ($EYES as $EYE) {
            $key = "eye_data_{$EYE}";
            if (!isset($_POST[$key]) || !is_array($_POST[$key])) continue;

            $block = $_POST[$key];
            $date_of_test = trim($block['date_of_test'] ?? '');
            if ($date_of_test === '') continue; // skip empty eye

            $age               = safe_int(val($block, 'age'), 0, 120);
            $report_diagnosis  = val($block, 'report_diagnosis', 'no input');
            if (!in_array($report_diagnosis, ['normal','abnormal','exclude','no input'], true)) $report_diagnosis = 'no input';

            $exclusion         = val($block, 'exclusion', 'none');
            $merci_score       = normalize_merci_score(val($block, 'merci_score'));
            $merci_diagnosis   = val($block, 'merci_diagnosis', 'no value');
            if (!in_array($merci_diagnosis, ['normal','abnormal','no value'], true)) $merci_diagnosis = 'no value';

            $error_type        = val($block, 'error_type');
            if ($error_type !== null && !in_array($error_type, ['TN','FP','TP','FN','none'], true)) $error_type = null;

            $faf_grade         = safe_int(val($block, 'faf_grade'), 1, 4);
            $oct_score         = safe_num(val($block, 'oct_score'));
            $vf_score          = safe_num(val($block, 'vf_score'));

            $medication_name   = val($block, 'medication_name');
            $dosage            = safe_num(val($block, 'dosage'));
            $dosage_unit       = val($block, 'dosage_unit', 'mg');
            $duration_days     = safe_int(val($block, 'duration_days'), 0, 32767);
            $cumulative_dosage = safe_num(val($block, 'cumulative_dosage'));
            $date_of_cont      = val($block, 'date_of_continuation');
            $treatment_notes   = val($block, 'treatment_notes');

            $override_test_id  = trim(val($block, 'test_id'));
            $test_id           = $override_test_id !== '' ? $override_test_id : compute_test_id($subject_id, $date_of_test);

            $eyePayloads[] = [
                'eye' => $EYE,
                'test_id' => $test_id,
                'date_of_test' => $date_of_test,
                'age' => $age,
                'report_diagnosis' => $report_diagnosis,
                'exclusion' => $exclusion,
                'merci_score' => $merci_score,
                'merci_diagnosis' => $merci_diagnosis,
                'error_type' => $error_type,
                'faf_grade' => $faf_grade,
                'oct_score' => $oct_score,
                'vf_score'  => $vf_score,
                'actual_diagnosis' => $actual_dx,
                'medication_name' => $medication_name,
                'dosage' => $dosage,
                'dosage_unit' => $dosage_unit,
                'duration_days' => $duration_days,
                'cumulative_dosage' => $cumulative_dosage,
                'date_of_continuation' => $date_of_cont,
                'treatment_notes' => $treatment_notes,
            ];
        }

        if (empty($eyePayloads)) {
            throw new Exception('Please enter at least one eye with a Test Date.');
        }

        // Insert each unique test (test_id, patient, date)
        $seenTests = [];
        foreach ($eyePayloads as $p) {
            $tid = $p['test_id'];
            if (isset($seenTests[$tid])) continue;
            // use your helper if you like: insertTest($conn,...)
            $stmt = $conn->prepare("
                INSERT INTO tests (test_id, patient_id, location, date_of_test)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    location = VALUES(location),
                    date_of_test = VALUES(date_of_test),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param('ssss', $tid, $patient_id, $location, $p['date_of_test']);
            if (!$stmt->execute()) throw new Exception('Failed saving test ('.$tid.'): '.$stmt->error);
            $stmt->close();
            $seenTests[$tid] = true;
        }

        // Allowed uploads
        $ALLOW_IMG = ['ext'=>['png','jpg','jpeg'], 'mime'=>['image/png','image/jpeg']];
        $ALLOW_PDF = ['ext'=>['pdf'], 'mime'=>['application/pdf']];

        // Upsert test_eyes per eye
        foreach ($eyePayloads as $p) {
            $test_id = $p['test_id'];
            $eye     = $p['eye'];

            // Prepare upload folder
            $upDir = 'uploads/'.$patient_id.'/'.$test_id;
            // Save any new files (null when not provided)
            $faf_ref   = save_upload("image_faf_".strtolower($eye),   $upDir, "FAF_{$eye}",   $ALLOW_IMG);
            $oct_ref   = save_upload("image_oct_".strtolower($eye),   $upDir, "OCT_{$eye}",   $ALLOW_IMG);
            $vf_ref    = save_upload("image_vf_".strtolower($eye),    $upDir, "VF_{$eye}",    $ALLOW_PDF);
            $mferg_ref = save_upload("image_mferg_".strtolower($eye), $upDir, "MFERG_{$eye}", $ALLOW_IMG);

            // Does a row already exist for (test_id, eye)?
            $existing_id = null;
            $stmt = $conn->prepare("SELECT result_id FROM test_eyes WHERE test_id = ? AND eye = ? LIMIT 1");
            $stmt->bind_param('ss', $test_id, $eye);
            $stmt->execute();
            $stmt->bind_result($rid);
            if ($stmt->fetch()) $existing_id = (int)$rid;
            $stmt->close();

            if ($existing_id) {
                // UPDATE (COALESCE files so we don't blank them when not re-uploaded)
                $stmt = $conn->prepare("
                    UPDATE test_eyes SET
                        age=?, report_diagnosis=?, exclusion=?, merci_score=?, merci_diagnosis=?, error_type=?,
                        faf_grade=?, oct_score=?, vf_score=?, actual_diagnosis=?, medication_name=?, dosage=?, dosage_unit=?,
                        duration_days=?, cumulative_dosage=?, date_of_continuation=?, treatment_notes=?,
                        faf_reference = COALESCE(?, faf_reference),
                        oct_reference = COALESCE(?, oct_reference),
                        vf_reference  = COALESCE(?, vf_reference),
                        mferg_reference = COALESCE(?, mferg_reference),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE result_id = ?
                ");
                $stmt->bind_param(
                    'isssssiddssssiidsssssii',
                    $p['age'],
                    $p['report_diagnosis'],
                    $p['exclusion'],
                    $p['merci_score'],
                    $p['merci_diagnosis'],
                    $p['error_type'],
                    $p['faf_grade'],
                    $p['oct_score'],
                    $p['vf_score'],
                    $p['actual_diagnosis'],
                    $p['medication_name'],
                    $p['dosage'],
                    $p['dosage_unit'],
                    $p['duration_days'],
                    $p['cumulative_dosage'],
                    $p['date_of_continuation'],
                    $p['treatment_notes'],
                    $faf_ref,
                    $oct_ref,
                    $vf_ref,
                    $mferg_ref,
                    $existing_id
                );
                if (!$stmt->execute()) throw new Exception('Failed updating '.$eye.' eye: '.$stmt->error);
                $stmt->close();
            } else {
                // INSERT
                $stmt = $conn->prepare("
                    INSERT INTO test_eyes
                    (test_id, eye, age, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type,
                     faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit,
                     duration_days, cumulative_dosage, date_of_continuation, treatment_notes,
                     faf_reference, oct_reference, vf_reference, mferg_reference)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->bind_param(
                    'ssisssssiddssssiidssss',
                    $test_id,
                    $eye,
                    $p['age'],
                    $p['report_diagnosis'],
                    $p['exclusion'],
                    $p['merci_score'],
                    $p['merci_diagnosis'],
                    $p['error_type'],
                    $p['faf_grade'],
                    $p['oct_score'],
                    $p['vf_score'],
                    $p['actual_diagnosis'],
                    $p['medication_name'],
                    $p['dosage'],
                    $p['dosage_unit'],
                    $p['duration_days'],
                    $p['cumulative_dosage'],
                    $p['date_of_continuation'],
                    $p['treatment_notes'],
                    $faf_ref,
                    $oct_ref,
                    $vf_ref,
                    $mferg_ref
                );
                if (!$stmt->execute()) throw new Exception('Failed inserting '.$eye.' eye: '.$stmt->error);
                $stmt->close();
            }
        }

        $conn->commit();
        header('Location: form.php?success=1');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = $e->getMessage();
    }
}

// success flash
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMessage = 'Saved successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Data Entry</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
  --brand:#1a73e8; --brand2:#6ea8fe; --ok:#198754; --warn:#f59f00; --danger:#dc3545;
  --bg:#f6f8fb; --card:#ffffff; --border:#e7eaf0; --muted:#6c757d; --shadow:0 10px 28px rgba(16,24,40,.08);
}
body { background:var(--bg); }
.card { border:1px solid var(--border); box-shadow:var(--shadow); }
.badge-bg { background: linear-gradient(135deg, var(--brand2), var(--brand)); }
.help { color: var(--muted); font-size:.875rem; }
input[type=file]::file-selector-button{ border:1px solid var(--border); border-radius:.5rem; padding:.375rem .75rem; margin-right:.75rem; background:#f8f9fb; }
.dropzone { border:1px dashed var(--border); border-radius:.75rem; padding:1rem; text-align:center; background:#fafbff;}
.dropzone.dragover { background:#eef3ff; }
.form-floating>.form-control:focus~label{ color:#333; }
.section-title { display:flex; align-items:center; gap:.5rem; }
.nav-pills .nav-link.active{ background:var(--brand); }
.toast-container{ z-index:1080; }
</style>
</head>
<body>
<nav class="navbar navbar-light bg-white border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php"><i class="bi bi-people"></i> Patient Dashboard</a>
    <div class="d-flex gap-2">
      <a href="csv_import.php" class="btn btn-primary"><i class="bi bi-upload"></i> Import CSV</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-4">
    <div class="col-12">
      <div class="card p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h1 class="h4 m-0 section-title"><span class="badge badge-bg text-white">New / Edit</span> Patient Data Entry</h1>
          <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
        <?php if ($successMessage): ?>
          <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
        <?php elseif ($errorMessage): ?>
          <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div id="dup-alert" class="alert alert-warning d-none"><i class="bi bi-exclamation-circle"></i>
          A record for this <strong>patient + date + eye</strong> already exists. Submitting will update it.</div>

        <form method="post" enctype="multipart/form-data" id="dataForm" novalidate>
          <!-- Patient -->
          <div class="card mb-4">
            <div class="card-body">
              <h5 class="mb-3"><i class="bi bi-person-lines-fill"></i> Patient</h5>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Subject ID <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="subject_id" id="subject_id" required>
                  <div class="help">We auto-generate a <code>patient_id</code> from this.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="date_of_birth" id="date_of_birth" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Location</label>
                  <select class="form-select" name="location" id="location">
                    <option value="KH">KH</option>
                    <option value="CHUSJ">CHUSJ</option>
                    <option value="IWK">IWK</option>
                    <option value="IVEY">IVEY</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Actual Diagnosis</label>
                  <select class="form-select" name="actual_diagnosis" id="actual_diagnosis">
                    <option value="ra">RA</option>
                    <option value="sle">SLE</option>
                    <option value="sjogren">Sjogren</option>
                    <option value="other" selected>Other</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Test/eyes -->
          <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="od-tab" data-bs-toggle="pill" data-bs-target="#od-pane" type="button" role="tab">Right Eye (OD)</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="os-tab" data-bs-toggle="pill" data-bs-target="#os-pane" type="button" role="tab">Left Eye (OS)</button>
            </li>
          </ul>
          <div class="tab-content" id="pills-tabContent">
            <!-- OD -->
            <div class="tab-pane fade show active" id="od-pane" role="tabpanel">
              <?php echo eye_block_html('OD'); ?>
            </div>
            <!-- OS -->
            <div class="tab-pane fade" id="os-pane" role="tabpanel">
              <?php echo eye_block_html('OS'); ?>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="syncDates" checked>
              <label class="form-check-label" for="syncDates">Keep OS test date in sync with OD</label>
            </div>
            <button class="btn btn-lg btn-primary"><i class="bi bi-save"></i> Save</button>
          </div>
        </form>

      </div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="toastOk" class="toast align-items-center text-bg-success border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body"><i class="bi bi-check2-circle me-2"></i>Looks good!</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastWarn" class="toast align-items-center text-bg-warning border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body"><i class="bi bi-exclamation-triangle me-2"></i>Please complete required fields.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script>
// Small utilities
const $ = (s, r=document)=> r.querySelector(s);
const $$ = (s, r=document)=> Array.from(r.querySelectorAll(s));
const toast = id => new bootstrap.Toast(document.getElementById(id));

// Live shared test-id hint
function makeTestId(subject, dateStr){
  if(!subject || !dateStr) return '';
  // Keep this in sync with PHP compute_test_id
  return 'T_' + md5(subject+'|'+dateStr).slice(0,20);
}
// Tiny md5 for predictability (not crypto; UI only)
function md5(str){ return CryptoJS.MD5(str).toString(); }

function wireEye(eye){
  const root = document.getElementById(eye.toLowerCase()+'-block');
  const date = $('[data-role="date"]', root);
  const tip  = $('[data-role="testid-tip"]', root);
  const sub  = $('#subject_id');

  function updateTip(){
    const t = makeTestId(sub.value.trim(), date.value);
    tip.textContent = t ? `Suggested Test ID: ${t}` : 'Suggested Test ID will appear here.';
  }
  date.addEventListener('change', updateTip);
  sub.addEventListener('input', updateTip);
  updateTip();

  // Dropzones
  $$('.dropzone', root).forEach(dz=>{
    const inp = dz.nextElementSibling; // file input after dropzone
    dz.addEventListener('click', ()=> inp.click());
    dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
    dz.addEventListener('drop', e=>{
      e.preventDefault(); dz.classList.remove('dragover');
      if (e.dataTransfer.files?.length) { inp.files = e.dataTransfer.files; }
    });
  });
}
document.addEventListener('DOMContentLoaded', ()=>{
  wireEye('OD'); wireEye('OS');

  // Sync dates (OD -> OS)
  const sync = $('#syncDates');
  const odDate = $('#od_date_of_test');
  const osDate = $('#os_date_of_test');
  function syncNow(){
    if (sync.checked && odDate.value) osDate.value = odDate.value;
  }
  sync.addEventListener('change', syncNow);
  odDate.addEventListener('change', syncNow);

  // Duplicate warning (patient + date + eye)
  async function checkDup(eye){
    const subject = $('#subject_id').value.trim();
    const date = eye==='OD' ? $('#od_date_of_test').value : $('#os_date_of_test').value;
    if (!subject || !date) return false;
    const params = new URLSearchParams({check:'1', subject, date, eye});
    const resp = await fetch(`form.php?${params.toString()}`);
    const j = await resp.json();
    return j.ok && j.exists;
  }
  async function updateDupBanner(){
    const a = await checkDup('OD');
    const b = await checkDup('OS');
    $('#dup-alert').classList.toggle('d-none', !(a || b));
  }
  $('#subject_id').addEventListener('blur', updateDupBanner);
  $('#od_date_of_test').addEventListener('change', updateDupBanner);
  $('#os_date_of_test').addEventListener('change', updateDupBanner);

  // Client-side check of requireds
  $('#dataForm').addEventListener('submit', (e)=>{
    const req = ['#subject_id','#date_of_birth'];
    let ok = true;
    req.forEach(sel => { if (!$(sel).value) ok=false; });
    // require at least one eye date
    if (!$('#od_date_of_test').value && !$('#os_date_of_test').value) ok=false;

    if (!ok) {
      e.preventDefault();
      toast('toastWarn').show();
    } else {
      toast('toastOk').show();
    }
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/crypto-js@4.2.0/crypto-js.js"></script>
</body>
</html>
<?php
// --------- Eye block HTML (server-side function) ----------
function eye_block_html($eye) {
    $lower = strtolower($eye);
    ob_start(); ?>
<div class="card mb-4" id="<?php echo $lower; ?>-block">
  <div class="card-body">
    <h5 class="mb-3"><i class="bi bi-eye<?php echo $eye==='OS' ? '-slash' : ''; ?>"></i> <?php echo $eye==='OD'?'Right':'Left'; ?> Eye (<?php echo $eye; ?>)</h5>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Test Date</label>
        <input type="date" class="form-control" name="eye_data_<?php echo $eye; ?>[date_of_test]" id="<?php echo $lower; ?>_date_of_test" data-role="date">
      </div>
      <div class="col-md-4">
        <label class="form-label">Age at Test</label>
        <input type="number" min="0" max="120" class="form-control" name="eye_data_<?php echo $eye; ?>[age]" placeholder="e.g., 52">
      </div>
      <div class="col-md-4">
        <label class="form-label">Test ID (optional override)</label>
        <input type="text" class="form-control" name="eye_data_<?php echo $eye; ?>[test_id]" placeholder="Leave blank to auto-generate">
        <div class="help mt-1" data-role="testid-tip">Suggested Test ID will appear here.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Report Diagnosis</label>
        <select class="form-select" name="eye_data_<?php echo $eye; ?>[report_diagnosis]">
          <option value="normal">Normal</option>
          <option value="abnormal">Abnormal</option>
          <option value="exclude">Exclude</option>
          <option value="no input" selected>No Input</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Exclusion</label>
        <select class="form-select" name="eye_data_<?php echo $eye; ?>[exclusion]">
          <option value="none" selected>None</option>
          <option value="retinal detachment">Retinal Detachment</option>
          <option value="generalized retinal dysfunction">Generalized Retinal Dysfunction</option>
          <option value="unilateral testing">Unilateral Testing</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Error Type</label>
        <select class="form-select" name="eye_data_<?php echo $eye; ?>[error_type]">
          <option value="">—</option>
          <option value="TN">TN</option>
          <option value="FP">FP</option>
          <option value="TP">TP</option>
          <option value="FN">FN</option>
          <option value="none">none</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">MERCI Score</label>
        <input type="text" class="form-control" name="eye_data_<?php echo $eye; ?>[merci_score]" placeholder="0–100 or 'unable'">
      </div>
      <div class="col-md-4">
        <label class="form-label">MERCI Diagnosis</label>
        <select class="form-select" name="eye_data_<?php echo $eye; ?>[merci_diagnosis]">
          <option value="normal">Normal</option>
          <option value="abnormal">Abnormal</option>
          <option value="no value" selected>No Value</option>
        </select>
      </div>

      <div class="col-md-4"></div>

      <div class="col-md-4">
        <label class="form-label">FAF Grade (1–4)</label>
        <input type="number" min="1" max="4" class="form-control" name="eye_data_<?php echo $eye; ?>[faf_grade]" placeholder="1–4">
      </div>
      <div class="col-md-4">
        <label class="form-label">OCT Score</label>
        <input type="number" step="0.01" class="form-control" name="eye_data_<?php echo $eye; ?>[oct_score]" placeholder="e.g., 7.25">
      </div>
      <div class="col-md-4">
        <label class="form-label">VF Score</label>
        <input type="number" step="0.01" class="form-control" name="eye_data_<?php echo $eye; ?>[vf_score]" placeholder="e.g., 2.40">
      </div>

      <div class="col-md-4">
        <label class="form-label">Medication Name</label>
        <input type="text" class="form-control" name="eye_data_<?php echo $eye; ?>[medication_name]" placeholder="e.g., Hydroxychloroquine">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dosage</label>
        <div class="input-group">
          <input type="number" step="0.01" class="form-control" name="eye_data_<?php echo $eye; ?>[dosage]" placeholder="e.g., 200">
          <select class="form-select" name="eye_data_<?php echo $eye; ?>[dosage_unit]" style="max-width:110px">
            <option value="mg" selected>mg</option>
            <option value="g">g</option>
          </select>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Duration (days)</label>
        <input type="number" min="0" class="form-control" name="eye_data_<?php echo $eye; ?>[duration_days]" placeholder="e.g., 90">
      </div>

      <div class="col-md-4">
        <label class="form-label">Cumulative Dosage</label>
        <input type="number" step="0.01" class="form-control" name="eye_data_<?php echo $eye; ?>[cumulative_dosage]" placeholder="e.g., 1800">
      </div>
      <div class="col-md-4">
        <label class="form-label">Date of Continuation</label>
        <input type="date" class="form-control" name="eye_data_<?php echo $eye; ?>[date_of_continuation]">
      </div>
      <div class="col-md-4">
        <label class="form-label">Treatment Notes</label>
        <input type="text" class="form-control" name="eye_data_<?php echo $eye; ?>[treatment_notes]" placeholder="Optional notes">
      </div>
    </div>

    <hr class="my-4">

    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">FAF Image (PNG/JPG)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_faf_<?php echo strtolower($eye); ?>" accept="image/png,image/jpeg" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">OCT Image (PNG/JPG)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_oct_<?php echo strtolower($eye); ?>" accept="image/png,image/jpeg" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">VF Report (PDF)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_vf_<?php echo strtolower($eye); ?>" accept="application/pdf" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">MFERG Image (PNG/JPG)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_mferg_<?php echo strtolower($eye); ?>" accept="image/png,image/jpeg" hidden>
      </div>
    </div>
  </div>
</div>
<?php
    return ob_get_clean();
}
