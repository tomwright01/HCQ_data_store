<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

/* ============================================
   Utilities (mirror import_images.php behavior)
   ============================================ */

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

/** Ensure a tests row exists; return test_id (same as import_images.php) */
function ensureTest(mysqli $conn, string $patientId, string $dateYmd): string {
    $dateSql = DateTime::createFromFormat('Ymd', $dateYmd)->format('Y-m-d');
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ? LIMIT 1");
    $stmt->bind_param("ss", $patientId, $dateSql);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row['test_id'])) return $row['test_id'];

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

/** Build a view URL that matches index.php’s build_view_url() */
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

/**
 * Save an uploaded file for a modality and write DB references, mirroring import_images.php:
 * - Stores at IMAGE_BASE_DIR/<ModalityDir>/<Patient>_<EYE>_<YYYYMMDD>.<ext>
 * - Updates test_eyes.<modality>_reference_OD|OS
 * - Back-fills tests.<modality>_reference_od|os if present
 * Returns ['success'=>bool,'message'=>string,'file'=>filename]
 */
function processImageDirect(mysqli $conn, string $modality, array $file, string $patientId, string $eye, string $dateYmd): array {
    $modality = strtoupper($modality);

    if (!defined('ALLOWED_TEST_TYPES') || !is_array(ALLOWED_TEST_TYPES) || !array_key_exists($modality, ALLOWED_TEST_TYPES)) {
        return ['success' => false, 'message' => "Invalid test type for upload"];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => "No file or upload error"];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowExp = ($modality === 'MFERG');
    $okExts   = $allowExp ? ['png','pdf','exp'] : ['png','pdf'];
    if (!in_array($ext, $okExts, true)) {
        return ['success' => false, 'message' => "Invalid extension .$ext for $modality"];
    }

    // Ensure parent rows
    $testId = ensureTest($conn, $patientId, $dateYmd);
    ensureTestEye($conn, $testId, $eye);

    // Save file
    $dirName   = ALLOWED_TEST_TYPES[$modality];
    $targetDir = rtrim(IMAGE_BASE_DIR, '/')."/{$dirName}/";
    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }

    $finalName  = "{$patientId}_{$eye}_{$dateYmd}.{$ext}";
    $targetFile = $targetDir . $finalName;

    if (is_file($targetFile)) { @unlink($targetFile); }
    if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => false, 'message' => "Failed to move uploaded file for $modality"];
    }

    // Write DB references (preferred: test_eyes.<modality>_reference_OD|OS)
    $modLower = strtolower($modality);             // faf|oct|vf|mferg
    $eyeUpper = ($eye === 'OS') ? 'OS' : 'OD';
    $eyeLower = strtolower($eye);

    $tePerEye = "{$modLower}_reference_{$eyeUpper}";
    if (hasColumn($conn, 'test_eyes', $tePerEye)) {
        $stmt = $conn->prepare("UPDATE test_eyes SET $tePerEye = ? WHERE test_id = ? AND eye = ?");
        $stmt->bind_param("sss", $finalName, $testId, $eye);
        $stmt->execute();
        $stmt->close();
    } else {
        // Fallback ultra-legacy single column (unlikely, but defensive)
        $teSingle = "{$modLower}_reference";
        if (hasColumn($conn, 'test_eyes', $teSingle)) {
            $stmt = $conn->prepare("UPDATE test_eyes SET $teSingle = ? WHERE test_id = ? AND eye = ?");
            $stmt->bind_param("sss", $finalName, $testId, $eye);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Back-compat on tests table
    $tPerEye = "{$modLower}_reference_{$eyeLower}";
    if (hasColumn($conn, 'tests', $tPerEye)) {
        $stmt = $conn->prepare("UPDATE tests SET $tPerEye = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $finalName, $testId);
        $stmt->execute();
        $stmt->close();
    }

    return ['success'=>true, 'message'=>"$modality saved", 'file'=>$finalName, 'test_id'=>$testId];
}

/* ============================================
   Handle POST
   ============================================ */

$alert = null;
$links = [];   // view links for any images saved (FAF/OCT/VF/MFERG)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // ---------------------------
    // Main: Test + Per-eye + Images (+optional Medication)
    // ---------------------------
    if ($action === 'save_all') {
        $patientId = trim($_POST['patient_id'] ?? '');
        $eye       = strtoupper(trim($_POST['eye'] ?? 'OD'));
        $dateIso   = trim($_POST['test_date'] ?? '');

        // Per-eye fields (no med fields here)
        $age               = $_POST['age']               !== '' ? (int)$_POST['age']               : null;
        $merci_score       = $_POST['merci_score']       !== '' ? (float)$_POST['merci_score']     : null;
        $oct_score         = $_POST['oct_score']         !== '' ? (float)$_POST['oct_score']       : null;
        $vf_score          = $_POST['vf_score']          !== '' ? (float)$_POST['vf_score']        : null;
        $faf_grade         = $_POST['faf_grade']         ?? null;
        $report_diagnosis  = $_POST['report_diagnosis']  ?? null;
        $actual_diagnosis  = $_POST['actual_diagnosis']  ?? null;

        // Optional medication (single)
        $add_meds          = isset($_POST['add_med']) && $_POST['add_med'] === '1';
        $med_name          = trim($_POST['medication_name'] ?? '');
        $med_dose_per_day  = $_POST['dosage_per_day']   !== '' ? (float)$_POST['dosage_per_day']   : null;
        $med_duration_days = $_POST['duration_days']    !== '' ? (int)$_POST['duration_days']      : null;
        $med_cumulative    = $_POST['cumulative_dosage']!== '' ? (float)$_POST['cumulative_dosage']: null;
        $med_start         = $_POST['start_date']        ?? null;
        $med_end           = $_POST['end_date']          ?? null;
        $med_notes         = $_POST['notes']             ?? null;

        // Validate basics
        if (!$patientId || !in_array($eye, ['OD','OS'], true) || !$dateIso) {
            $alert = ['type'=>'error', 'text'=>'Missing required fields (Patient ID, Test Date, Eye).'];
        } elseif (!patientExists($conn, $patientId)) {
            $alert = ['type'=>'error', 'text'=>"Patient $patientId not found."];
        } else {
            $dt = DateTime::createFromFormat('Y-m-d', $dateIso);
            if (!$dt) {
                $alert = ['type'=>'error', 'text'=>'Invalid test date.'];
            } else {
                $dateYmd = $dt->format('Ymd');

                // Ensure test & test_eyes exist
                $testId = ensureTest($conn, $patientId, $dateYmd);
                ensureTestEye($conn, $testId, $eye);

                // Update per-eye fields on test_eyes
                $cols = [];
                $vals = [];

                // Only update provided fields (nulls included)
                $cols[] = 'age = ?';              $vals[] = $age;
                $cols[] = 'merci_score = ?';      $vals[] = $merci_score;
                $cols[] = 'oct_score = ?';        $vals[] = $oct_score;
                $cols[] = 'vf_score = ?';         $vals[] = $vf_score;
                $cols[] = 'faf_grade = ?';        $vals[] = $faf_grade;
                $cols[] = 'report_diagnosis = ?'; $vals[] = $report_diagnosis;
                $cols[] = 'actual_diagnosis = ?'; $vals[] = $actual_diagnosis;

                $set = implode(', ', $cols);
                $stmt = $conn->prepare("UPDATE test_eyes SET $set WHERE test_id = ? AND eye = ?");
                // bind types
                // age(i), merci(d), oct(d), vf(d), faf(s), report(s), actual(s), test_id(s), eye(s)
                $types = 'idddssss';
                $vals[] = $testId;
                $vals[] = $eye;
                // convert nulls with appropriate binding
                // Use call_user_func_array with references
                $bindVals = [];
                $bindTypes = '';

                // Build dynamic types/values honoring nulls
                $dynamicVals = [$age, $merci_score, $oct_score, $vf_score, $faf_grade, $report_diagnosis, $actual_diagnosis, $testId, $eye];
                $dynamicTypes = '';
                foreach ($dynamicVals as $v) {
                    if (is_int($v))       { $dynamicTypes .= 'i'; }
                    elseif (is_float($v)) { $dynamicTypes .= 'd'; }
                    else                  { $dynamicTypes .= 's'; }
                }
                $stmt->bind_param($dynamicTypes, ...array_map(function($v){ return $v; }, $dynamicVals));
                $stmt->execute();
                $stmt->close();

                $messages = [];

                // Process files if provided (FAF/OCT/VF/MFERG)
                $uploads = [
                    'FAF'   => $_FILES['faf_file']   ?? null,
                    'OCT'   => $_FILES['oct_file']   ?? null,
                    'VF'    => $_FILES['vf_file']    ?? null,
                    'MFERG' => $_FILES['mferg_file'] ?? null,
                ];
                foreach ($uploads as $mod => $file) {
                    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $r = processImageDirect($conn, $mod, $file, $patientId, $eye, $dateYmd);
                    if ($r['success']) {
                        $messages[] = $r['message'];
                        // Generate a View link like index.php cards
                        $links[] = ['label'=>$mod, 'href'=>build_view_url($mod, $patientId, $eye, $r['file'])];
                    } else {
                        $messages[] = "{$mod}: " . $r['message'];
                    }
                }

                // Optional medication insert
                if ($add_meds && $med_name !== '') {
                    $stmt = $conn->prepare("
                        INSERT INTO medications
                        (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "ssiidsss",
                        $patientId,
                        $med_name,
                        $med_dose_per_day,
                        $med_duration_days,
                        $med_cumulative,
                        $med_start,
                        $med_end,
                        $med_notes
                    );
                    if ($stmt->execute()) {
                        $messages[] = "Medication saved.";
                    } else {
                        $messages[] = "Medication save failed.";
                    }
                    $stmt->close();
                }

                $alert = ['type'=>'success', 'text'=> ($messages ? implode(' • ', $messages) : 'Saved.')];
            }
        }
    }

    // ---------------------------
    // Medications Only tab
    // ---------------------------
    if ($action === 'med_only') {
        $patientId = trim($_POST['patient_id_med'] ?? '');
        $med_name  = trim($_POST['medication_name_med'] ?? '');
        $dose_per_day   = $_POST['dosage_per_day_med']   !== '' ? (float)$_POST['dosage_per_day_med']   : null;
        $duration_days  = $_POST['duration_days_med']    !== '' ? (int)$_POST['duration_days_med']      : null;
        $cumulative     = $_POST['cumulative_dosage_med']!== '' ? (float)$_POST['cumulative_dosage_med']: null;
        $start_date     = $_POST['start_date_med']        ?? null;
        $end_date       = $_POST['end_date_med']          ?? null;
        $notes          = $_POST['notes_med']             ?? null;

        if (!$patientId || !$med_name) {
            $alert = ['type'=>'error', 'text'=>'Patient and Medication name are required.'];
        } elseif (!patientExists($conn, $patientId)) {
            $alert = ['type'=>'error', 'text'=>"Patient $patientId not found."];
        } else {
            $stmt = $conn->prepare("
                INSERT INTO medications
                (patient_id, medication_name, dosage_per_day, duration_days, cumulative_dosage, start_date, end_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssiidsss",
                $patientId,
                $med_name,
                $dose_per_day,
                $duration_days,
                $cumulative,
                $start_date,
                $end_date,
                $notes
            );
            if ($stmt->execute()) {
                $alert = ['type'=>'success', 'text'=>'Medication saved.'];
            } else {
                $alert = ['type'=>'error', 'text'=>'Failed to save medication.'];
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Test / Images / Medications</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{ background:#f7f8fb; }
.card{ box-shadow:0 8px 24px rgba(0,0,0,.08); border:1px solid rgba(0,0,0,.08); }
.pill{ border-radius:999px; }
.eye-toggle .btn{ min-width:72px; }
.small-muted{ color:#6c757d; font-size:.9rem; }
</style>
</head>
<body>
<nav class="navbar navbar-light bg-white border-bottom sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><i class="bi bi-arrow-left"></i> Back</a>
    <span class="navbar-text fw-semibold">Add Records</span>
  </div>
</nav>

<div class="container py-4">
  <?php if ($alert): ?>
    <div class="alert alert-<?= $alert['type']==='success'?'success':'danger' ?>"><?= htmlspecialchars($alert['text']) ?></div>
    <?php if (!empty($links)): ?>
      <div class="mb-3">
        <?php foreach ($links as $lnk): ?>
          <a class="btn btn-sm btn-outline-dark pill me-1" target="_blank" href="<?= htmlspecialchars($lnk['href']) ?>">
            View <?= htmlspecialchars($lnk['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <div class="card-header bg-white">
      <ul class="nav nav-tabs card-header-tabs" id="addTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" id="tab-main" data-bs-toggle="tab" data-bs-target="#pane-main" type="button" role="tab">Test & Images</button></li>
        <li class="nav-item"><button class="nav-link" id="tab-med" data-bs-toggle="tab" data-bs-target="#pane-med" type="button" role="tab">Medications Only</button></li>
      </ul>
    </div>
    <div class="card-body tab-content">

      <!-- Main: Test + Images (+ optional Medication) -->
      <div class="tab-pane fade show active" id="pane-main" role="tabpanel" aria-labelledby="tab-main">
        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
          <input type="hidden" name="action" value="save_all">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Patient ID</label>
              <input type="text" name="patient_id" class="form-control" required>
              <div class="form-text small-muted">Existing Patient ID (must already exist).</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Test Date</label>
              <input type="date" name="test_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label d-block">Eye</label>
              <div class="btn-group eye-toggle" role="group">
                <input type="radio" class="btn-check" name="eye" id="eyeOD" value="OD" checked>
                <label class="btn btn-outline-primary" for="eyeOD">OD</label>
                <input type="radio" class="btn-check" name="eye" id="eyeOS" value="OS">
                <label class="btn btn-outline-primary" for="eyeOS">OS</label>
              </div>
              <div class="form-text small-muted">Switch to enter or upload for the other eye.</div>
            </div>

            <hr class="mt-3">

            <!-- Per-eye fields (no per-eye meds here) -->
            <div class="col-md-3">
              <label class="form-label">Age at Test</label>
              <input type="number" name="age" class="form-control" min="0" step="1" placeholder="e.g. 54">
            </div>
            <div class="col-md-3">
              <label class="form-label">MERCI Score</label>
              <input type="number" name="merci_score" class="form-control" step="0.01" placeholder="e.g. 0.87">
            </div>
            <div class="col-md-3">
              <label class="form-label">OCT Score</label>
              <input type="number" name="oct_score" class="form-control" step="0.01">
            </div>
            <div class="col-md-3">
              <label class="form-label">VF Score</label>
              <input type="number" name="vf_score" class="form-control" step="0.01">
            </div>

            <div class="col-md-4">
              <label class="form-label">FAF Grade</label>
              <input type="text" name="faf_grade" class="form-control" placeholder="e.g. 0 / 1 / 2 ...">
            </div>
            <div class="col-md-4">
              <label class="form-label">Report Diagnosis</label>
              <select name="report_diagnosis" class="form-select">
                <option value="">(none)</option>
                <option value="normal">normal</option>
                <option value="abnormal">abnormal</option>
                <option value="exclude">exclude</option>
                <option value="no input">no input</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Actual Diagnosis</label>
              <input type="text" name="actual_diagnosis" class="form-control" placeholder="free text">
            </div>

            <hr class="mt-3">

            <!-- Attachments for selected eye -->
            <div class="col-12">
              <h6 class="mb-2">Attachments for <span id="eyeLabel">OD</span></h6>
            </div>

            <div class="col-md-6">
              <label class="form-label">FAF (PNG/PDF)</label>
              <input type="file" name="faf_file" class="form-control" accept=".png,.pdf">
            </div>
            <div class="col-md-6">
              <label class="form-label">OCT (PNG/PDF)</label>
              <input type="file" name="oct_file" class="form-control" accept=".png,.pdf">
            </div>
            <div class="col-md-6">
              <label class="form-label mt-2">VF (PNG/PDF)</label>
              <input type="file" name="vf_file" class="form-control" accept=".png,.pdf">
            </div>
            <div class="col-md-6">
              <label class="form-label mt-2">mfERG (PNG/PDF/EXP)</label>
              <input type="file" name="mferg_file" class="form-control" accept=".png,.pdf,.exp">
              <div class="form-text small-muted">Use .exp only for mfERG.</div>
            </div>

            <hr class="mt-3">

            <!-- Optional Medication (single) -->
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="addMedSwitch" name="add_med" value="1">
                <label class="form-check-label" for="addMedSwitch">Also add a medication with this submission</label>
              </div>
            </div>
            <div id="medOptional" class="row g-3 mt-1" style="display:none">
              <div class="col-md-6">
                <label class="form-label">Medication Name</label>
                <input type="text" name="medication_name" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Dosage per Day</label>
                <input type="number" step="0.001" name="dosage_per_day" class="form-control" placeholder="e.g. 200">
              </div>
              <div class="col-md-3">
                <label class="form-label">Duration (days)</label>
                <input type="number" step="1" name="duration_days" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Cumulative Dosage</label>
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

            <div class="col-12 mt-2">
              <button class="btn btn-primary pill" type="submit"><i class="bi bi-save"></i> Save</button>
              <a href="index.php" class="btn btn-outline-secondary pill">Cancel</a>
            </div>
          </div>
        </form>
      </div>

      <!-- Medications Only -->
      <div class="tab-pane fade" id="pane-med" role="tabpanel" aria-labelledby="tab-med">
        <form method="POST" class="needs-validation" novalidate>
          <input type="hidden" name="action" value="med_only">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Patient ID</label>
              <input type="text" name="patient_id_med" class="form-control" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Medication Name</label>
              <input type="text" name="medication_name_med" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Dosage per Day</label>
              <input type="number" step="0.001" name="dosage_per_day_med" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Duration (days)</label>
              <input type="number" step="1" name="duration_days_med" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Cumulative Dosage</label>
              <input type="number" step="0.001" name="cumulative_dosage_med" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date_med" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Date</label>
              <input type="date" name="end_date_med" class="form-control">
            </div>
            <div class="col-md-9">
              <label class="form-label">Notes</label>
              <input type="text" name="notes_med" class="form-control">
            </div>
            <div class="col-12">
              <button class="btn btn-primary pill" type="submit"><i class="bi bi-plus-circle"></i> Save Medication</button>
              <a href="index.php#patients" class="btn btn-outline-secondary pill">Back to Patients</a>
            </div>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<script>
// OD/OS label sync
const eyeRadios = document.querySelectorAll('input[name="eye"]');
const eyeLabel = document.getElementById('eyeLabel');
eyeRadios.forEach(r => r.addEventListener('change', () => {
  const v = document.querySelector('input[name="eye"]:checked')?.value || 'OD';
  eyeLabel.textContent = v;
}));

// Optional medication toggler
const addMed = document.getElementById('addMedSwitch');
const medOptional = document.getElementById('medOptional');
if (addMed) addMed.addEventListener('change', ()=> {
  medOptional.style.display = addMed.checked ? 'flex' : 'none';
});

// Bootstrap validation
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>
</body>
</html>

