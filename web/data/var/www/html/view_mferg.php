<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$test_type  = 'MFERG';
$ref        = $_GET['ref'] ?? '';
$patient_id = $_GET['patient_id'] ?? '';
$eye        = strtoupper($_GET['eye'] ?? '');

if (!$ref || !$patient_id || !in_array($eye, ['OD','OS'], true)) {
    http_response_code(400);
    die("Invalid parameters. Required: ref, patient_id, eye(OD|OS).");
}

$fieldName = strtolower($test_type) . '_reference_' . $eye; // mferg_reference_OD|OS

$sql = "
    SELECT te.*, t.date_of_test, t.patient_id,
           p.subject_id, p.location, p.date_of_birth
    FROM test_eyes te
    JOIN tests t    ON te.test_id = t.test_id
    JOIN patients p ON t.patient_id = p.patient_id
    WHERE t.patient_id = ?
      AND te.eye = ?
      AND te.$fieldName = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) die('Database error (prepare): ' . $conn->error);
$stmt->bind_param("sss", $patient_id, $eye, $ref);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); die("No $test_type record found for this reference/eye/patient."); }

$pdf_path = getDynamicImagePath($ref);
if (!$pdf_path) { http_response_code(404); die("$test_type file not found on disk."); }

$test_date = $row['date_of_test'] ?? null;
$age = (!empty($row['date_of_birth']) && $test_date)
    ? date_diff(date_create($row['date_of_birth']), date_create($test_date))->y
    : 'N/A';

$report_diagnosis = $row['report_diagnosis'] ?? 'Not specified';
$exclusion        = $row['exclusion'] ?? 'None';
$merci_score      = $row['merci_score'] ?? 'N/A';
$merci_diagnosis  = $row['merci_diagnosis'] ?? 'Not specified';
$error_type       = $row['error_type'] ?? 'N/A';
$faf_grade        = $row['faf_grade'] ?? 'N/A';
$oct_score        = $row['oct_score'] ?? 'N/A';
$vf_score         = $row['vf_score'] ?? 'N/A';

function eyeLabel($eye){ return $eye === 'OD' ? 'Right Eye' : 'Left Eye'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>mfERG Viewer — <?= htmlspecialchars($row['subject_id'] ?? $patient_id) ?> (<?= htmlspecialchars($eye) ?>)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f7f8fb }
.header { border-bottom:1px solid #e5e7eb; background:#fff; }
.viewer-card, .info-card { background:#fff; border:1px solid #e5e7eb; }
.stage { position:relative; background:#000; min-height:60vh; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.controls { position:absolute; top:1rem; right:1rem; display:flex; gap:.5rem; background:rgba(0,0,0,.55); padding:.5rem; border-radius:.5rem; color:#fff }
.controls .btn { --bs-btn-padding-y:.25rem; --bs-btn-padding-x:.5rem; --bs-btn-font-size:.85rem; color:#fff; border-color:rgba(255,255,255,.4); }
.controls .btn:hover { background:rgba(255,255,255,.12); }
.meta-pill { background:#eef2ff; color:#1e40af; border-radius:999px; padding:.25rem .6rem; font-size:.85rem; font-weight:600; }
.label { color:#6b7280; }
iframe.pdf { border:0;width:100%;height:80vh;background:#fff }
.fullscreen { position:fixed; inset:0; z-index:1050; }
</style>
</head>
<body>
<header class="header py-2">
  <div class="container-fluid d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <a href="index.php?search_patient_id=<?= urlencode($patient_id) ?>" class="btn btn-outline-secondary btn-sm">← Back</a>
      <h5 class="mb-0">mfERG Viewer</h5>
      <span class="meta-pill"><?= htmlspecialchars($row['subject_id'] ?? '') ?></span>
      <span class="meta-pill"><?= htmlspecialchars($patient_id) ?></span>
      <span class="meta-pill"><?= htmlspecialchars($eye) ?> — <?= eyeLabel($eye) ?></span>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" target="_blank" href="<?= htmlspecialchars($pdf_path) ?>">Open Original</a>
      <button id="btnDownload" class="btn btn-outline-primary btn-sm">Download</button>
    </div>
  </div>
</header>

<div class="container-fluid py-3">
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="card viewer-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong class="me-2">Report</strong>
          <small class="text-muted"><?= htmlspecialchars(basename(parse_url($pdf_path, PHP_URL_PATH))) ?></small>
        </div>
        <div class="card-body p-0">
          <div class="stage" id="stage">
            <div class="controls">
              <button id="zoomOut"  class="btn btn-outline-light btn-sm">-</button>
              <button id="zoom100" class="btn btn-outline-light btn-sm">100%</button>
              <button id="zoomFit" class="btn btn-outline-light btn-sm">Fit</button>
              <button id="zoomIn"   class="btn btn-outline-light btn-sm">+</button>
              <button id="btnFull"  class="btn btn-outline-light btn-sm">⤢</button>
            </div>
            <iframe id="pdfFrame" class="pdf" src=""></iframe>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card info-card mb-3">
        <div class="card-body">
          <h5 class="mb-3">Patient & Test</h5>
          <div class="row g-3">
            <div class="col-6"><div class="label">Subject</div><div><?= htmlspecialchars($row['subject_id'] ?? '—') ?></div></div>
            <div class="col-6"><div class="label">Patient ID</div><div><?= htmlspecialchars($patient_id) ?></div></div>
            <div class="col-6"><div class="label">Eye</div><div><?= htmlspecialchars($eye) ?> (<?= eyeLabel($eye) ?>)</div></div>
            <div class="col-6"><div class="label">Test Date</div><div><?= htmlspecialchars($test_date ?? 'Unknown') ?></div></div>
            <div class="col-6"><div class="label">Age</div><div><?= htmlspecialchars($age) ?></div></div>
            <div class="col-6"><div class="label">Location</div><div><?= htmlspecialchars($row['location'] ?? '—') ?></div></div>
            <div class="col-12"><div class="label mt-2">Reference</div><code><?= htmlspecialchars($ref) ?></code></div>
          </div>
        </div>
      </div>

      <div class="card info-card">
        <div class="card-body">
          <h5 class="mb-3">Diagnostics</h5>
          <div class="row g-3">
            <div class="col-6"><div class="label">Report Diagnosis</div><div><?= htmlspecialchars($report_diagnosis) ?></div></div>
            <div class="col-6"><div class="label">Exclusion</div><div><?= htmlspecialchars($exclusion) ?></div></div>
            <div class="col-6"><div class="label">MERCI Score</div><div><?= htmlspecialchars($merci_score) ?></div></div>
            <div class="col-6"><div class="label">MERCI Diagnosis</div><div><?= htmlspecialchars($merci_diagnosis) ?></div></div>
            <div class="col-6"><div class="label">Error Type</div><div><?= htmlspecialchars($error_type) ?></div></div>
            <div class="col-6"><div class="label">FAF Grade</div><div><?= htmlspecialchars($faf_grade) ?></div></div>
            <div class="col-6"><div class="label">OCT Score</div><div><?= htmlspecialchars($oct_score) ?></div></div>
            <div class="col-6"><div class="label">VF Score</div><div><?= htmlspecialchars($vf_score) ?></div></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(() => {
  const frame = document.getElementById('pdfFrame');
  const stage = document.getElementById('stage');
  const btnIn  = document.getElementById('zoomIn');
  const btnOut = document.getElementById('zoomOut');
  const btn100 = document.getElementById('zoom100');
  const btnFit = document.getElementById('zoomFit');
  const btnFull= document.getElementById('btnFull');
  const btnDl  = document.getElementById('btnDownload');

  const baseUrl = "<?= htmlspecialchars($pdf_path, ENT_QUOTES) ?>";
  let currentZoom = 100;
  let fitMode = false;

  function srcWithParams() {
    if (fitMode) return baseUrl + "#toolbar=0&navpanes=0&scrollbar=0&view=FitH&zoom=fit";
    return baseUrl + "#toolbar=0&navpanes=0&scrollbar=0&zoom=" + Math.round(currentZoom);
  }

  function load() { frame.src = srcWithParams(); }
  function zoom(delta) { fitMode = false; currentZoom = Math.min(400, Math.max(25, currentZoom + delta)); load(); }
  function zoomTo(val){ fitMode = false; currentZoom = val; load(); }
  function fit(){ fitMode = true; load(); }

  btnIn.addEventListener('click', () => zoom(10));
  btnOut.addEventListener('click', () => zoom(-10));
  btn100.addEventListener('click', () => zoomTo(100));
  btnFit.addEventListener('click', fit);

  btnFull.addEventListener('click', () => {
    if (!document.fullscreenElement) {
      stage.classList.add('fullscreen');
      stage.requestFullscreen?.();
    } else {
      document.exitFullscreen?.();
    }
  });
  document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement) stage.classList.remove('fullscreen');
  });

  btnDl.addEventListener('click', () => {
    const a = document.createElement('a');
    a.href = baseUrl;
    a.download = "mfERG_<?= htmlspecialchars($patient_id) ?>_<?= htmlspecialchars($eye) ?>_<?= htmlspecialchars($test_date ?? 'date') ?>.pdf";
    document.body.appendChild(a);
    a.click();
    a.remove();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === '+') zoom(10);
    else if (e.key === '-') zoom(-10);
    else if (e.key === '0') zoomTo(100);
    else if (e.key.toLowerCase() === 'f') btnFull.click();
    else if (e.key.toLowerCase() === 'd') btnDl.click();
  });

  load();
})();
</script>
</body>
</html>
