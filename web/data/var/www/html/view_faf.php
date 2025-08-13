<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$test_type  = 'OCT';
$ref        = $_GET['ref'] ?? '';
$patient_id = $_GET['patient_id'] ?? '';
$eye        = strtoupper($_GET['eye'] ?? '');

if (!$ref || !$patient_id || !in_array($eye, ['OD','OS'], true)) {
    http_response_code(400);
    die("Invalid parameters. Required: ref, patient_id, eye(OD|OS).");
}

$fieldName = strtolower($test_type) . '_reference'; // 'oct_reference'
$sql = "
    SELECT
        te.*, t.date_of_test, t.patient_id,
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

if (!$row) {
    http_response_code(404);
    die("No $test_type record found for this reference/eye/patient.");
}

$image_path = getDynamicImagePath($ref);
if (!$image_path) {
    http_response_code(404);
    die("$test_type image not found on disk.");
}

$test_date = $row['date_of_test'] ?? null;
$age = (!empty($row['date_of_birth']))
    ? date_diff(date_create($row['date_of_birth']), date_create($test_date ?: 'today'))->y
    : 'N/A';

$report_diagnosis = $row['report_diagnosis'] ?? 'Not specified';
$exclusion        = $row['exclusion'] ?? 'None';
$merci_score      = $row['merci_score'] ?? 'N/A';
$merci_diagnosis  = $row['merci_diagnosis'] ?? 'Not specified';
$error_type       = $row['error_type'] ?? 'N/A';
$faf_grade        = $row['faf_grade'] ?? 'N/A';
$oct_score        = $row['oct_score'] ?? 'N/A';
$vf_score         = $row['vf_score'] ?? 'N/A';

$ext = strtolower(pathinfo(parse_url($image_path, PHP_URL_PATH), PATHINFO_EXTENSION));
$is_image = in_array($ext, ['png','jpg','jpeg','webp'], true);
$is_pdf   = ($ext === 'pdf');

function eyeLabel($eye){ return $eye === 'OD' ? 'Right Eye' : 'Left Eye'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>OCT Viewer — <?= htmlspecialchars($row['subject_id'] ?? $patient_id) ?> (<?= htmlspecialchars($eye) ?>)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --brand:#1a73e8; --bg:#f7f8fb; --card:#fff; --text:#111827; --border:rgba(0,0,0,0.08); }
body { background: var(--bg); color: var(--text); }
.header { border-bottom:1px solid var(--border); background:#fff; }
.viewer-card, .info-card { background: var(--card); border:1px solid var(--border); }
.img-stage { position:relative; background:#000; min-height:60vh; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.img-stage img { max-width:100%; max-height:100%; transition: transform .15s ease, filter .15s ease; }
.controls { position:absolute; top:1rem; right:1rem; display:flex; gap:.5rem; background:rgba(0,0,0,.55); padding:.5rem; border-radius:.5rem; }
.controls button, .controls input[type="range"]{ color:#fff; }
.controls button { border:1px solid rgba(255,255,255,.25); background:transparent; padding:.25rem .5rem; border-radius:.25rem; }
.meta-pill { background:#eef2ff; color:#1e40af; border-radius:999px; padding:.25rem .6rem; font-size:.85rem; font-weight:600; }
.label { color:#6b7280; }
</style>
</head>
<body>
<header class="header py-2">
  <div class="container-fluid d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <a href="index.php?search_patient_id=<?= urlencode($patient_id) ?>" class="btn btn-outline-secondary btn-sm">← Back</a>
      <h5 class="mb-0">OCT Viewer</h5>
      <span class="meta-pill"><?= htmlspecialchars($row['subject_id'] ?? '') ?></span>
      <span class="meta-pill"><?= htmlspecialchars($patient_id) ?></span>
      <span class="meta-pill"><?= htmlspecialchars($eye) ?> — <?= eyeLabel($eye) ?></span>
    </div>
    <div><a class="btn btn-primary btn-sm" target="_blank" href="<?= htmlspecialchars($image_path) ?>">Open Original</a></div>
  </div>
</header>

<div class="container-fluid py-3">
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="card viewer-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong class="me-2">Image</strong>
          <small class="text-muted"><?= htmlspecialchars(basename($image_path)) ?></small>
        </div>
        <div class="card-body p-0">
          <div class="img-stage" id="stage">
            <?php if ($is_image): ?>
              <div class="controls">
                <button id="zoomOut">-</button>
                <button id="zoomReset">1:1</button>
                <button id="zoomIn">+</button>
                <input id="bright" type="range" min="0.2" max="2.5" step="0.05" value="1" title="Brightness">
              </div>
              <img id="theImage" src="<?= htmlspecialchars($image_path) ?>" alt="OCT">
            <?php elseif ($is_pdf): ?>
              <iframe src="<?= htmlspecialchars($image_path) ?>" style="border:0;width:100%;height:80vh;background:#fff"></iframe>
            <?php else: ?>
              <div class="p-4 text-center text-white">
                <p class="mb-3">Preview not supported for <code>.<?= htmlspecialchars($ext) ?></code>.</p>
                <a class="btn btn-light" target="_blank" href="<?= htmlspecialchars($image_path) ?>">Download / Open</a>
              </div>
            <?php endif; ?>
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

<?php if ($is_image): ?>
<script>
(() => {
  const img = document.getElementById('theImage');
  const zoomIn = document.getElementById('zoomIn');
  const zoomOut = document.getElementById('zoomOut');
  const zoomReset = document.getElementById('zoomReset');
  const bright = document.getElementById('bright');
  let scale = 1;
  const apply = () => { img.style.transform = `scale(${scale})`; img.style.filter = `brightness(${bright.value})`; };
  zoomIn.addEventListener('click', ()=>{ scale = Math.min(4, +(scale + 0.1).toFixed(2)); apply(); });
  zoomOut.addEventListener('click', ()=>{ scale = Math.max(0.2, +(scale - 0.1).toFixed(2)); apply(); });
  zoomReset.addEventListener('click', ()=>{ scale = 1; bright.value = 1; apply(); });
  bright.addEventListener('input', apply);
  img.addEventListener('wheel', (e)=>{ e.preventDefault(); scale += (e.deltaY < 0 ? 0.05 : -0.05); scale = Math.min(4, Math.max(0.2, scale)); apply(); }, {passive:false});
})();
</script>
<?php endif; ?>
</body>
</html>
