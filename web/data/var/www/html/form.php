<?php
/* =========================================================
   form.php — Create/Update Test + Upload Attachments
   (Matches import_images.php logic for file refs)
   ========================================================= */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

/* ---------------------------------------------------------
   CORS (harmless for normal use; useful if you ajax later)
--------------------------------------------------------- */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

/* ---------------------------------------------------------
   Utilities mirrored from import_images.php
--------------------------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $db = DB_NAME;
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

    // deterministic-ish unique id
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
 * Save file and write refs to DB (PRIMARY = test_eyes):
 * - Saves to IMAGE_BASE_DIR/<ModalityDir>/<PatientID>_<EYE>_<YYYYMMDD>.<ext>
 * - Writes test_eyes.<modality>_reference_OD|OS (preferred)
 * - Back-fills tests.<modality>_reference_od|os if present
 *
 * $fileArr is a single file input array (e.g., $_FILES['oct_od'])
 */
function processUploadedFile(mysqli $conn, string $testType, array $fileArr, string $patientId, string $eye, string $dateYmd): array {
    $modality = strtoupper($testType);
    if (!defined('ALLOWED_TEST_TYPES') || !is_array(ALLOWED_TEST_TYPES) || !array_key_exists($modality, ALLOWED_TEST_TYPES)) {
        return ['success' => false, 'message' => "Invalid test type"];
    }
    if (empty($fileArr['tmp_name']) || $fileArr['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => "No file uploaded for $modality/$eye"];
    }

    $origName = $fileArr['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowExp = ($modality === 'MFERG');
    $okExts   = $allowExp ? ['png','pdf','exp'] : ['png','pdf'];
    if (!in_array($ext, $okExts, true)) {
        return ['success' => false, 'message' => "Invalid extension .$ext for $modality"];
    }

    // 1) Save the file
    $dirName   = ALLOWED_TEST_TYPES[$modality];
    $targetDir = rtrim(IMAGE_BASE_DIR, '/')."/{$dirName}/";
    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }

    $finalName = "{$patientId}_{$eye}_{$dateYmd}.{$ext}";
    $targetFile = $targetDir . $finalName;
    if (is_file($targetFile)) { @unlink($targetFile); }
    if (!move_uploaded_file($fileArr['tmp_name'], $targetFile)) {
        return ['success' => false, 'message' => "Failed to move $origName"];
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
        // Legacy single-column fallback (unlikely)
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

    return ['success' => true, 'message' => "$finalName saved", 'test_id' => $testId, 'ref' => $finalName, 'modality' => $modality, 'eye' => $eye];
}

/** Update per-eye numeric/text fields on test_eyes */
function updateTestEyeData(mysqli $conn, string $testId, string $eye, array $data): void {
    // Only allow/expect columns known to exist in your schema
    $fields = [
        'age' => 'i',
        'merci_score' => 'd',
        'oct_score' => 'd',
        'vf_score' => 'd',
        'faf_grade' => 's',
        'report_diagnosis' => 's',
        'actual_diagnosis' => 's'
    ];

    $sets = [];
    $types = '';
    $vals = [];
    foreach ($fields as $col => $typ) {
        if (array_key_exists($col, $data)) {
            $sets[] = "$col = ?";
            $types .= $typ;
            // Normalize empty strings to null for numerics
            $v = $data[$col];
            if (($typ === 'd' || $typ === 'i') && $v === '') $v = null;
            $vals[] = $v;
        }
    }
    if (!$sets) return;

    $sql = "UPDATE test_eyes SET " . implode(', ', $sets) . " WHERE test_id = ? AND eye = ?";
    $stmt = $conn->prepare($sql);
    $types .= 'ss';
    $vals[] = $testId;
    $vals[] = $eye;
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

/** Build viewer links like index.php */
function build_view_url(string $type, string $patientId, string $eye, string $ref): string {
    $type = strtoupper($type);
    $eye  = ($eye === 'OS') ? 'OS' : 'OD';
    $page = match ($type) {
        'FAF'   => 'view_faf.php',
        'OCT'   => 'view_oct.php',
        'VF'    => 'view_vf.php',
        'MFERG' => 'view_mferg.php',
        default => '#',
    };
    if ($page === '#' || !$ref) return '#';
    return $page
        . '?ref='        . urlencode($ref)
        . '&patient_id=' . urlencode($patientId)
        . '&eye='        . urlencode($eye);
}

/* ---------------------------------------------------------
   Handle POST: (1) Save/Update Test (+ attachments)
                 (2) Add Medication
--------------------------------------------------------- */
$formMsg = null; $formClass = null;
$uploadResults = []; // for rendering quick links after save
$createdTestId = null;
$usedPatientId = null;
$usedDateYmd   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* 1) Save/Update Test + attachments */
    if (isset($_POST['save_test'])) {
        $patientId = trim($_POST['patient_id'] ?? '');
        $dateIso   = $_POST['test_date'] ?? '';
        $includeOD = isset($_POST['eye_od']);
        $includeOS = isset($_POST['eye_os']);

        if (!$patientId || !$dateIso || (!$includeOD && !$includeOS)) {
            $formMsg = "Please provide Patient ID, Test Date, and select at least one eye (OD/OS).";
            $formClass = "error";
        } elseif (!patientExists($conn, $patientId)) {
            $formMsg = "Patient $patientId not found.";
            $formClass = "error";
        } else {
            $dt = DateTime::createFromFormat('Y-m-d', $dateIso);
            if (!$dt) {
                $formMsg = "Invalid test date.";
                $formClass = "error";
            } else {
                $dateYmd = $dt->format('Ymd');
                $testId  = ensureTest($conn, $patientId, $dateYmd); // create or reuse
                $usedPatientId = $patientId;
                $usedDateYmd   = $dateYmd;
                $createdTestId = $testId;

                // For each selected eye, ensure row and update scalar fields
                $eyes = [];
                if ($includeOD) $eyes[] = 'OD';
                if ($includeOS) $eyes[] = 'OS';

                foreach ($eyes as $eye) {
                    ensureTestEye($conn, $testId, $eye);

                    // Collect per-eye scalar fields (NO medication fields here)
                    $prefix = strtolower($eye); // 'od' | 'os'
                    $data = [
                        'age'               => $_POST["age_$prefix"]               ?? null,
                        'merci_score'       => $_POST["merci_$prefix"]             ?? null,
                        'oct_score'         => $_POST["oct_$prefix"]               ?? null,
                        'vf_score'          => $_POST["vf_$prefix"]                ?? null,
                        'faf_grade'         => $_POST["faf_grade_$prefix"]         ?? null,
                        'report_diagnosis'  => $_POST["report_dx_$prefix"]         ?? null,
                        'actual_diagnosis'  => $_POST["actual_dx_$prefix"]         ?? null,
                    ];
                    updateTestEyeData($conn, $testId, $eye, $data);

                    // Process files for this eye (FAF/OCT/VF/mfERG); write per-eye refs -> test_eyes (primary)
                    $fileMap = [
                        'FAF'   => "faf_$prefix",
                        'OCT'   => "oct_img_$prefix",
                        'VF'    => "vf_img_$prefix",
                        'MFERG' => "mferg_$prefix"
                    ];
                    foreach ($fileMap as $modality => $inputName) {
                        if (!empty($_FILES[$inputName]['tmp_name']) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                            $res = processUploadedFile($conn, $modality, $_FILES[$inputName], $patientId, $eye, $dateYmd);
                            $uploadResults[] = $res + ['patient_id' => $patientId];
                            // keep $createdTestId as returned if needed (already have $testId)
                        }
                    }
                }

                $okFails = ['ok'=>0,'fail'=>0];
                foreach ($uploadResults as $r) { $r['success'] ? $okFails['ok']++ : $okFails['fail']++; }
                $formMsg = "Saved test $testId" .
                           ($okFails['ok'] ? " • {$okFails['ok']} file(s) saved" : "") .
                           ($okFails['fail'] ? " • {$okFails['fail']} file(s) failed" : "");
                $formClass = $okFails['fail'] ? "error" : "success";
            }
        }
    }

    /* 2) Add Medication (separate section) */
    if (isset($_POST['add_med'])) {
        $pid   = trim($_POST['med_patient_id'] ?? '');
        $name  = trim($_POST['medication_name'] ?? '');
        $dose  = $_POST['dosage_per_day'] ?? null;
        $dur   = $_POST['duration_days'] ?? null;
        $cum   = $_POST['cumulative_dosage'] ?? null;
        $sd    = $_POST['start_date'] ?? null;
        $ed    = $_POST['end_date'] ?? null;
        $notes = $_POST['notes'] ?? null;

        if (!$pid || !$name) {
            $formMsg = "Medication requires Patient ID and Medication Name.";
            $formClass = "error";
        } elseif (!patientExists($conn, $pid)) {
            $formMsg = "Patient $pid not found for medications.";
            $formClass = "error";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO medications (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            // Normalize empties to null
            $dose = ($dose === '') ? null : $dose;
            $dur  = ($dur  === '') ? null : $dur;
            $cum  = ($cum  === '') ? null : $cum;
            $sd   = ($sd   === '') ? null : $sd;
            $ed   = ($ed   === '') ? null : $ed;
            $stmt->bind_param("ssiidsss", $pid, $name, $dose, $dur, $cum, $sd, $ed, $notes);
            $stmt->execute();
            $stmt->close();
            $formMsg = "Medication added for patient $pid.";
            $formClass = "success";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Test & Upload Files</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f7f8fb; }
.card { box-shadow: 0 8px 24px rgba(0,0,0,.08); border:1px solid rgba(0,0,0,.08); }
h1 { font-size: 1.6rem; }
.small-muted { color:#6c757d; font-size:.925rem; }
.badge-eye { font-size:.75rem; }
</style>
</head>
<body class="py-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Add Test & Upload Files</h1>
    <a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
  <p class="small-muted">Attachments saved here follow the same logic as <code>import_images.php</code>: refs are written to <strong>test_eyes</strong> (per-eye) and back-filled to <strong>tests</strong> for compatibility.</p>

  <?php if ($formMsg): ?>
    <div class="alert alert-<?= $formClass === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars($formMsg) ?></div>
  <?php endif; ?>

  <!-- ======================================================== -->
  <!-- Section A: Create/Update Test + per-eye fields + files  -->
  <!-- (No per-eye medication fields here)                      -->
  <!-- ======================================================== -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title mb-3"><i class="bi bi-clipboard2-pulse"></i> Test Details</h5>
      <form method="POST" enctype="multipart/form-data">
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label">Patient ID</label>
            <input type="text" name="patient_id" class="form-control" required value="<?= htmlspecialchars($usedPatientId ?? '') ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Test Date</label>
            <input type="date" name="test_date" class="form-control" required value="<?php
                if (!empty($usedDateYmd)) {
                    $d = DateTime::createFromFormat('Ymd', $usedDateYmd);
                    echo htmlspecialchars($d? $d->format('Y-m-d') : '');
                } ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Eyes Included</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="eye_od" id="eye_od" checked>
              <label class="form-check-label" for="eye_od">OD (Right)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="eye_os" id="eye_os" checked>
              <label class="form-check-label" for="eye_os">OS (Left)</label>
            </div>
          </div>
        </div>

        <hr class="my-4">

        <div class="row g-4">
          <!-- OD column -->
          <div class="col-lg-6">
            <div class="p-3 border rounded">
              <h6 class="mb-3"><span class="badge bg-success badge-eye">OD</span> Right Eye</h6>

              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Age at Test</label>
                  <input type="number" name="age_od" class="form-control" step="1" min="0">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">MERCI Score</label>
                  <input type="number" name="merci_od" class="form-control" step="0.01">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">FAF Grade</label>
                  <input type="text" name="faf_grade_od" class="form-control">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">OCT Score</label>
                  <input type="number" name="oct_od" class="form-control" step="0.01">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">VF Score</label>
                  <input type="number" name="vf_od" class="form-control" step="0.01">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Report Diagnosis</label>
                  <select name="report_dx_od" class="form-select">
                    <option value="">—</option>
                    <option value="normal">Normal</option>
                    <option value="abnormal">Abnormal</option>
                    <option value="exclude">Exclude</option>
                    <option value="no input">No Input</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Actual Diagnosis</label>
                  <input type="text" name="actual_dx_od" class="form-control">
                </div>
              </div>

              <div class="row g-2 mt-3">
                <div class="col-sm-6">
                  <label class="form-label">FAF (PNG/PDF)</label>
                  <input type="file" name="faf_od" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">OCT (PNG/PDF)</label>
                  <input type="file" name="oct_img_od" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">VF (PNG/PDF)</label>
                  <input type="file" name="vf_img_od" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">mfERG (PNG/PDF/EXP)</label>
                  <input type="file" name="mferg_od" class="form-control" accept=".png,.pdf,.exp">
                </div>
              </div>
            </div>
          </div>

          <!-- OS column -->
          <div class="col-lg-6">
            <div class="p-3 border rounded">
              <h6 class="mb-3"><span class="badge bg-primary badge-eye">OS</span> Left Eye</h6>

              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Age at Test</label>
                  <input type="number" name="age_os" class="form-control" step="1" min="0">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">MERCI Score</label>
                  <input type="number" name="merci_os" class="form-control" step="0.01">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">FAF Grade</label>
                  <input type="text" name="faf_grade_os" class="form-control">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">OCT Score</label>
                  <input type="number" name="oct_os" class="form-control" step="0.01">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">VF Score</label>
                  <input type="number" name="vf_os" class="form-control" step="0.01">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Report Diagnosis</label>
                  <select name="report_dx_os" class="form-select">
                    <option value="">—</option>
                    <option value="normal">Normal</option>
                    <option value="abnormal">Abnormal</option>
                    <option value="exclude">Exclude</option>
                    <option value="no input">No Input</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Actual Diagnosis</label>
                  <input type="text" name="actual_dx_os" class="form-control">
                </div>
              </div>

              <div class="row g-2 mt-3">
                <div class="col-sm-6">
                  <label class="form-label">FAF (PNG/PDF)</label>
                  <input type="file" name="faf_os" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">OCT (PNG/PDF)</label>
                  <input type="file" name="oct_img_os" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">VF (PNG/PDF)</label>
                  <input type="file" name="vf_img_os" class="form-control" accept=".png,.pdf">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">mfERG (PNG/PDF/EXP)</label>
                  <input type="file" name="mferg_os" class="form-control" accept=".png,.pdf,.exp">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button type="submit" name="save_test" class="btn btn-primary"><i class="bi bi-save"></i> Save Test</button>
          <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>

      <?php if (!empty($uploadResults)): ?>
        <hr class="mt-4">
        <h6 class="mb-2">Attachments</h6>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($uploadResults as $r): ?>
            <?php if (!empty($r['success']) && !empty($r['ref'])): ?>
              <?php $href = build_view_url($r['modality'] ?? '', $r['patient_id'] ?? '', $r['eye'] ?? '', $r['ref'] ?? ''); ?>
              <a class="btn btn-sm btn-outline-dark" href="<?= htmlspecialchars($href) ?>">
                <?= htmlspecialchars(($r['modality'] ?? '').' '.$r['eye']) ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ======================================================== -->
  <!-- Section B: Medications (separate, kept)                  -->
  <!-- ======================================================== -->
  <div class="card">
    <div class="card-body">
      <h5 class="card-title mb-3"><i class="bi bi-capsule-pill"></i> Add Medication (separate table)</h5>
      <form method="POST">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Patient ID</label>
            <input type="text" name="med_patient_id" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Medication Name</label>
            <input type="text" name="medication_name" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Dosage/Day</label>
            <input type="number" step="0.001" name="dosage_per_day" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Duration (days)</label>
            <input type="number" step="1" name="duration_days" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Cumulative</label>
            <input type="number" step="0.001" name="cumulative_dosage" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" name="add_med" class="btn btn-success"><i class="bi bi-plus"></i> Add Medication</button>
        </div>
      </form>
      <p class="small-muted mt-2">Tip: you can also add medications from <em>index.php → per-patient card → “Add Medication”</em>.</p>
    </div>
  </div>

  <div class="mt-4">
    <a href="index.php" class="btn btn-outline-secondary">← Return Home</a>
  </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html>

