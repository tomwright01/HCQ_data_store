<?php
// form.php — two-mode data entry (Full Entry + Medications Only)
// Now stores per-eye uploads exactly like import_images.php:
//   IMAGE_BASE_DIR/<ModalityDir>/<PATIENT>_<EYE>_<YYYYMMDD>.<ext>
// and writes refs into test_eyes.<modality>_reference_OD|OS
// === NEW: Apply the SAME anonymisation script to VF, OCT, and mfERG PDFs ===

// ======================================================================
// BOOTSTRAP
// ======================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// ======================================================================
// Anonymisation config (same script for VF/OCT/mfERG PDFs)
// ======================================================================
$ANON_SCRIPT = '/usr/local/bin/anonymiseHVF.sh'; // <-- change path if needed

// ======================================================================
// Helpers
// ======================================================================
function respond_json($arr) { header('Content-Type: application/json'); echo json_encode($arr); exit; }
function val($arr, $key, $default=null) { return isset($arr[$key]) && $arr[$key] !== '' ? $arr[$key] : $default; }
function normalize_actual_dx($v) {
    if ($v === null) return 'other';
    $v = strtolower(trim($v));
    if (in_array($v, ['ra','sle','sjogren','other'], true)) return $v;
    if ($v === 'sjögren' || $v === "sjogren's") return 'sjogren';
    return 'other';
}
function normalize_merci_score($v) {
    if ($v === null || $v === '') return null;
    $t = strtolower(trim($v));
    if ($t === 'unable') return 'unable';
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n >= 0 && $n <= 100) return (string)(int)$n;
    }
    return null;
}
function compute_test_id($subject_id, $date_of_test) {
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
function date_diff_inclusive_days(?string $start, ?string $end): ?int {
    if (!$start || !$end) return null;
    try {
        $sd = new DateTime($start);
        $ed = new DateTime($end);
        if ($ed < $sd) return 0;
        return $sd->diff($ed)->days + 1;
    } catch (Throwable $e) { return null; }
}

// ----- schema helpers like import_images.php -----
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
function ensureTestEye(mysqli $conn, string $test_id, string $eye): void {
    $stmt = $conn->prepare("SELECT 1 FROM test_eyes WHERE test_id = ? AND eye = ? LIMIT 1");
    $stmt->bind_param("ss", $test_id, $eye);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($exists) return;
    $stmt = $conn->prepare("INSERT INTO test_eyes (test_id, eye) VALUES (?, ?)");
    $stmt->bind_param("ss", $test_id, $eye);
    $stmt->execute();
    $stmt->close();
}

/**
 * Store one modality upload exactly like import_images.php:
 * - Saves to IMAGE_BASE_DIR/<ModalityDir>/<PATIENT>_<EYE>_<YYYYMMDD>.<ext>
 * - Writes test_eyes.<modality>_reference_OD|OS (and legacy fallbacks)
 * - Runs the SAME anonymisation script for VF/OCT/mfERG PDFs
 * - Returns stored filename, or null if no file present
 */
function store_modality_upload_like_import(
    mysqli $conn,
    string $field,
    string $modality,          // 'FAF'|'OCT'|'VF'|'MFERG'
    string $patient_id,
    string $eye,               // 'OD'|'OS'
    string $date_of_test,      // 'YYYY-MM-DD'
    string $test_id
): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $modality = strtoupper($modality);
    if (!defined('ALLOWED_TEST_TYPES') || !is_array(ALLOWED_TEST_TYPES) || !array_key_exists($modality, ALLOWED_TEST_TYPES)) {
        return null;
    }

    $name = $_FILES[$field]['name'];
    $tmp  = $_FILES[$field]['tmp_name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // Accept matrix
    $okExts = [
        'FAF'   => ['png','jpg','jpeg','webp'],
        'OCT'   => ['pdf'],
        'VF'    => ['pdf'],
        'MFERG' => ['pdf'],
    ];
    if (!in_array($ext, $okExts[$modality] ?? [], true)) return null;

    $dirName   = ALLOWED_TEST_TYPES[$modality];
    $targetDir = rtrim(IMAGE_BASE_DIR, '/')."/{$dirName}/";
    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }

    $dateYmd   = (new DateTime($date_of_test))->format('Ymd');
    $finalName = "{$patient_id}_{$eye}_{$dateYmd}.{$ext}";
    $dest      = $targetDir . $finalName;

    if (is_file($dest)) @unlink($dest);
    if (!move_uploaded_file($tmp, $dest)) return null;

    // === NEW: run same anonymisation script for VF, OCT, mfERG PDFs ===
    if ($ext === 'pdf' && in_array($modality, ['VF','OCT','MFERG'], true)) {
        // prefer $ANON_SCRIPT; fall back to VF_ANON_SCRIPT for back-compat
        $script = $GLOBALS['ANON_SCRIPT'] ?? null;
        if (!$script && defined('VF_ANON_SCRIPT')) $script = VF_ANON_SCRIPT;
        if ($script && is_file($script)) {
            // contract: script <input_pdf> <output_dir>
            $cmd = escapeshellcmd($script) . ' ' . escapeshellarg($dest) . ' ' . escapeshellarg($targetDir) . ' 2>&1';
            @exec($cmd, $out, $code);
            // Not fatal on non-zero exit; file is still indexed so you can diagnose.
        }
    }

    // Ensure test_eyes exists
    ensureTestEye($conn, $test_id, $eye);

    // Write DB references like import_images.php
    $modLower = strtolower($modality);     // faf|oct|vf|mferg
    $eyeUpper = ($eye === 'OS') ? 'OS' : 'OD';
    $eyeLower = strtolower($eye);

    // Preferred test_eyes.<modality>_reference_OD|OS
    $tePerEye = "{$modLower}_reference_{$eyeUpper}";
    if (hasColumn($conn, 'test_eyes', $tePerEye)) {
        $stmt = $conn->prepare("UPDATE test_eyes SET $tePerEye = ? WHERE test_id = ? AND eye = ?");
        $stmt->bind_param("sss", $finalName, $test_id, $eye);
        $stmt->execute(); $stmt->close();
    } else {
        // Very old single-column fallback
        $teSingle = "{$modLower}_reference";
        if (hasColumn($conn, 'test_eyes', $teSingle)) {
            $stmt = $conn->prepare("UPDATE test_eyes SET $teSingle = ? WHERE test_id = ? AND eye = ?");
            $stmt->bind_param("sss", $finalName, $test_id, $eye);
            $stmt->execute(); $stmt->close();
        }
    }

    // Back-compat: tests.<modality>_reference_od|os if present
    $tPerEye = "{$modLower}_reference_{$eyeLower}";
    if (hasColumn($conn, 'tests', $tPerEye)) {
        $stmt = $conn->prepare("UPDATE tests SET $tPerEye = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $finalName, $test_id);
        $stmt->execute(); $stmt->close();
    }

    return $finalName;
}

// ======================================================================
// Duplicate-check mini API (patient + date + eye)
// ======================================================================
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

// ======================================================================
// POST handling
// ======================================================================
$successMessage = '';
$errorMessage   = '';
$activeTab      = 'full';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'full';
    $activeTab = ($mode === 'meds') ? 'meds' : 'full';

    try {
        if ($mode === 'full') {
            // ===== FULL ENTRY =====
            $subject_id     = trim($_POST['subject_id'] ?? '');
            $date_of_birth  = trim($_POST['date_of_birth'] ?? '');
            $location       = $_POST['location'] ?? 'KH';
            $actual_dx_ui   = $_POST['actual_diagnosis'] ?? 'other';

            if ($subject_id === '' || $date_of_birth === '') {
                throw new Exception('Subject ID and Date of Birth are required.');
            }
            if (!in_array($location, ['KH','CHUSJ','IWK','IVEY'], true)) {
                throw new Exception('Invalid location.');
            }
            $actual_dx = normalize_actual_dx($actual_dx_ui);

            // Derive patient_id
            $patient_id = function_exists('generatePatientId')
                ? generatePatientId($subject_id)
                : ('P_' . substr(md5($subject_id), 0, 20));

            $conn->begin_transaction();

            // Upsert patient
            $stmt = $conn->prepare("
                INSERT INTO patients (patient_id, subject_id, location, date_of_birth)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    subject_id = VALUES(subject_id),
                    location   = VALUES(location),
                    date_of_birth = VALUES(date_of_birth)
            ");
            $stmt->bind_param('ssss', $patient_id, $subject_id, $location, $date_of_birth);
            if (!$stmt->execute()) throw new Exception('Failed saving patient: '.$stmt->error);
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
                    'actual_diagnosis' => $actual_dx
                ];
            }

            // Collect "Medications" (global section)
            $medPayloads = [];
            if (isset($_POST['meds']) && is_array($_POST['meds'])) {
                foreach ($_POST['meds'] as $m) {
                    $name   = trim(val($m, 'name', ''));
                    if ($name === '') continue;

                    $dose_val   = safe_num(val($m, 'dose'), 3);
                    $unit_in    = strtolower(val($m, 'unit', 'mg'));
                    $freq_in    = strtolower(val($m, 'freq', 'per_day'));
                    $start      = val($m, 'start_date');
                    $end        = val($m, 'end_date');
                    $months     = safe_int(val($m, 'months'), 0, 1200);
                    $days       = safe_int(val($m, 'days'), 0, 100000);
                    $notes      = val($m, 'notes');

                    if ($dose_val === null) throw new Exception("Medication dose is required for '{$name}'.");

                    $input_value_mg = ($unit_in === 'g') ? $dose_val * 1000.0 : $dose_val;
                    $period = ($freq_in === 'per_week') ? 'week' : 'day';

                    $medPayloads[] = [
                        'name' => $name,
                        'input_value_mg' => $input_value_mg,
                        'period' => $period,
                        'start' => $start ?: null,
                        'end'   => $end   ?: null,
                        'months'=> $months,
                        'days'  => $days,
                        'notes' => $notes
                    ];
                }
            }

            if (empty($eyePayloads) && empty($medPayloads)) {
                throw new Exception('Enter at least one Eye test or one Medication row.');
            }

            // Insert tests (unique per test_id)
            $seenTests = [];
            foreach ($eyePayloads as $p) {
                $tid = $p['test_id'];
                if (isset($seenTests[$tid])) continue;
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

            // For each eye: ensure row, store uploads like import_images.php, then update metadata
            foreach ($eyePayloads as $p) {
                $test_id = $p['test_id'];
                $eye     = $p['eye'];

                // ensure row exists (so updates below always succeed)
                ensureTestEye($conn, $test_id, $eye);

                // store files EXACTLY like import_images.php (and write DB refs)
                // field names match UI below: image_faf_od, image_oct_od, image_vf_od, image_mferg_od
                $lowerEye = strtolower($eye);
                // FAF (images)
                store_modality_upload_like_import($conn, "image_faf_{$lowerEye}",   'FAF',   $patient_id, $eye, $p['date_of_test'], $test_id);
                // OCT (PDF -> anonymise)
                store_modality_upload_like_import($conn, "image_oct_{$lowerEye}",   'OCT',   $patient_id, $eye, $p['date_of_test'], $test_id);
                // VF (PDF -> anonymise)
                store_modality_upload_like_import($conn, "image_vf_{$lowerEye}",    'VF',    $patient_id, $eye, $p['date_of_test'], $test_id);
                // MFERG (PDF -> anonymise)
                store_modality_upload_like_import($conn, "image_mferg_{$lowerEye}", 'MFERG', $patient_id, $eye, $p['date_of_test'], $test_id);

                // update metadata (no ref columns here; helper already wrote refs)
                $sql = "
                    UPDATE test_eyes SET
                        age=?, report_diagnosis=?, exclusion=?, merci_score=?, merci_diagnosis=?, error_type=?,
                        faf_grade=?, oct_score=?, vf_score=?, actual_diagnosis=?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE test_id = ? AND eye = ?
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    'ssssssssssss',
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
                    $test_id,
                    $eye
                );
                if (!$stmt->execute()) throw new Exception('Failed updating '.$eye.' eye: '.$stmt->error);
                $stmt->close();
            }

            // Insert medications (global) via insertMedication()
            if (!empty($medPayloads)) {
                foreach ($medPayloads as $m) {
                    insertMedication(
                        $conn,
                        $subject_id,                // resolver handles subject->patient mapping
                        $m['name'],
                        null,                       // dosage_per_day (computed in DB)
                        null,                       // duration_days (computed)
                        null,                       // cumulative_dosage (computed)
                        $m['start'],
                        $m['end'],
                        $m['notes'],
                        $m['input_value_mg'],       // input mg
                        'mg',
                        $m['period'],               // 'day'|'week'
                        $m['months'],
                        $m['days']
                    );
                }
            }

            $conn->commit();
            $successMessage = 'Saved successfully.';
            $activeTab = 'full';

        } else {
            // ===== MEDICATIONS ONLY =====
            $patient_or_subject = trim($_POST['m_patient_or_subject'] ?? '');
            if ($patient_or_subject === '') throw new Exception('Patient or Subject ID is required.');

            $rows = $_POST['meds2'] ?? [];
            if (!is_array($rows) || empty($rows)) throw new Exception('Add at least one medication row.');

            $conn->begin_transaction();
            $inserted = 0;

            foreach ($rows as $row) {
                $name = trim(val($row, 'name', ''));
                if ($name === '') continue;

                $dose = safe_num(val($row,'dose'), 3);
                if ($dose === null) throw new Exception("Dose is required for '{$name}'.");

                $unit  = strtolower(val($row,'unit','mg'));
                $freq  = strtolower(val($row,'freq','per_day'));
                $start = val($row,'start_date');
                $end   = val($row,'end_date');
                $months= safe_int(val($row,'months'), 0, 1200);
                $days  = safe_int(val($row,'days'), 0, 100000);
                $notes = val($row,'notes');

                $input_value_mg = ($unit === 'g') ? $dose * 1000.0 : $dose;
                $period = ($freq === 'per_week') ? 'week' : 'day';

                insertMedication(
                    $conn,
                    $patient_or_subject,
                    $name,
                    null, null, null,    // computed/generated
                    $start ?: null,
                    $end   ?: null,
                    $notes,
                    $input_value_mg,
                    'mg',
                    $period,
                    $months,
                    $days
                );
                $inserted++;
            }

            $conn->commit();
            $successMessage = "Added {$inserted} medication(s) for ".htmlspecialchars($patient_or_subject).".";
            $activeTab = 'meds';
        }
    } catch (Throwable $e) {
        @ $conn->rollback();
        $errorMessage = $e->getMessage();
    }
}

// ======================================================================
// UI pieces
// ======================================================================
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
    </div>

    <hr class="my-4">

    <!-- Uploads (names match server: image_faf_od|os etc.) -->
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">FAF Image (PNG/JPG/WEBP)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_faf_<?php echo strtolower($eye); ?>" accept="image/png,image/jpeg,image/webp" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">OCT Report (PDF)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_oct_<?php echo strtolower($eye); ?>" accept="application/pdf" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">VF Report (PDF)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_vf_<?php echo strtolower($eye); ?>" accept="application/pdf" hidden>
      </div>
      <div class="col-md-3">
        <label class="form-label">mfERG Report (PDF)</label>
        <div class="dropzone"><i class="bi bi-cloud-arrow-up"></i> Drop file or click</div>
        <input type="file" class="form-control mt-2" name="image_mferg_<?php echo strtolower($eye); ?>" accept="application/pdf" hidden>
      </div>
    </div>
  </div>
</div>
<?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Data Entry — Full & Medications Only</title>
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
.section-title { display:flex; align-items:center; gap:.5rem; }
.nav-pills .nav-link.active{ background:var(--brand); }

/* meds */
.meds-row{ background:#fbfcff; border:1px solid var(--border); border-radius:.75rem; padding:1rem; margin-bottom: .75rem;}
.meds-row .form-label{ margin-bottom: .25rem; }
.meds-row .del-row{ visibility:hidden;}
.meds-row:hover .del-row{ visibility:visible;}
.meds-chip{ display:inline-block; padding:.25rem .6rem; border-radius:999px; border:1px solid var(--border); background:#f2f7ff; }

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
          <h1 class="h4 m-0 section-title"><span class="badge badge-bg text-white">New / Edit</span> Data Entry</h1>
          <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($successMessage): ?>
          <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
        <?php elseif ($errorMessage): ?>
          <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <!-- Mode tabs -->
        <ul class="nav nav-pills mb-3" id="modeTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab==='full'?'active':'' ?>" id="tab-full" data-bs-toggle="pill" data-bs-target="#pane-full" type="button" role="tab">Full Entry</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab==='meds'?'active':'' ?>" id="tab-meds" data-bs-toggle="pill" data-bs-target="#pane-meds" type="button" role="tab">Medications Only</button>
          </li>
        </ul>

        <div class="tab-content" id="modeTabsContent">

          <!-- ================= FULL ENTRY ================= -->
          <div class="tab-pane fade <?= $activeTab==='full'?'show active':'' ?>" id="pane-full" role="tabpanel">
            <div id="dup-alert" class="alert alert-warning d-none"><i class="bi bi-exclamation-circle"></i>
              A record for this <strong>patient + date + eye</strong> already exists. Submitting will update it.</div>

            <form method="post" enctype="multipart/form-data" id="dataForm" novalidate>
              <input type="hidden" name="mode" value="full" />
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

              <!-- Eye tabs -->
              <ul class="nav nav-pills mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#od-pane" type="button">Right Eye (OD)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#os-pane" type="button">Left Eye (OS)</button></li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="od-pane"><?php echo eye_block_html('OD'); ?></div>
                <div class="tab-pane fade" id="os-pane"><?php echo eye_block_html('OS'); ?></div>
              </div>

              <!-- Medications (global; per-eye med fields removed) -->
              <div class="card mb-4" id="medications">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0"><i class="bi bi-capsule-pill"></i> Medications</h5>
                    <button type="button" id="addMed" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Add row</button>
                  </div>
                  <p class="help mb-3">Enter per-day or per-week dosing. We store <code>input_dosage_value (mg)</code> + <code>input_dosage_period</code>; the DB computes <code>dosage_per_day</code>, <code>duration_days</code>, and <code>cumulative_dosage</code>.</p>
                  <div id="medsContainer"></div>
                  <div class="small text-muted">Provide Start/End dates or Months/Days. If both dates given, they take priority.</div>
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

          <!-- ================= MEDS ONLY ================= -->
          <div class="tab-pane fade <?= $activeTab==='meds'?'show active':'' ?>" id="pane-meds" role="tabpanel">
            <form method="post" id="medsOnlyForm" novalidate>
              <input type="hidden" name="mode" value="meds" />
              <div class="card mb-3">
                <div class="card-body">
                  <h5 class="mb-3"><i class="bi bi-capsule"></i> Quick Medications Entry</h5>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Patient or Subject ID <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" name="m_patient_or_subject" id="m_patient_or_subject" placeholder="P_... or SUBJECT..." required>
                      <div class="help">You can paste either the internal <code>patient_id</code> or the <code>subject_id</code>. We’ll map it.</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-4">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Medications</h6>
                    <button type="button" id="addMed2" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Add row</button>
                  </div>
                  <div id="meds2Container"></div>
                  <div class="small text-muted">Dose can be mg or g; choose per-day or per-week; optional dates or months/days; notes optional.</div>
                </div>
              </div>

              <div class="d-flex justify-content-end">
                <button class="btn btn-lg btn-primary"><i class="bi bi-save"></i> Save Medications</button>
              </div>
            </form>
          </div>

        </div><!-- /tab-content -->

      </div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="toastOk" class="toast align-items-center text-bg-success border-0" role="alert">
    <div class="d-flex"><div class="toast-body"><i class="bi bi-check2-circle me-2"></i>Looks good!</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <div id="toastWarn" class="toast align-items-center text-bg-warning border-0" role="alert">
    <div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-triangle me-2"></i>Please complete required fields.</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/crypto-js@4.2.0/crypto-js.js"></script>
<script>
// Small utilities
const $ = (s, r=document)=> r.querySelector(s);
const $$ = (s, r=document)=> Array.from(r.querySelectorAll(s));
const toast = id => new bootstrap.Toast(document.getElementById(id));

// md5 (UI hint only)
function md5(str){ return CryptoJS.MD5(str).toString(); }
function makeTestId(subject, dateStr){ return (!subject || !dateStr) ? '' : 'T_' + md5(subject+'|'+dateStr).slice(0,20); }

function wireEye(eye){
  const root = document.getElementById(eye.toLowerCase()+'-block');
  const date = $('[data-role="date"]', root);
  const tip  = $('[data-role="testid-tip"]', root);
  const sub  = $('#subject_id');
  function updateTip(){
    const t = makeTestId((sub?.value||'').trim(), date?.value);
    if (tip) tip.textContent = t ? `Suggested Test ID: ${t}` : 'Suggested Test ID will appear here.';
  }
  date?.addEventListener('change', updateTip);
  sub?.addEventListener('input', updateTip);
  updateTip();

  // Dropzones
  $$('.dropzone', root).forEach(dz=>{
    const inp = dz.nextElementSibling;
    dz.addEventListener('click', ()=> inp.click());
    dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
    dz.addEventListener('drop', e=>{
      e.preventDefault(); dz.classList.remove('dragover');
      if (e.dataTransfer.files?.length) { inp.files = e.dataTransfer.files; }
    });
  });
}

// ===== Full Entry — Medications block (global) =====
let medIdx = 0;
function medRowTemplate(i){
  return `
  <div class="meds-row" data-med="${i}">
    <div class="d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Medication #${i+1}</div>
      <button class="btn btn-sm btn-outline-danger del-row" type="button"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="row g-3 align-items-end mt-1">
      <div class="col-md-4">
        <label class="form-label">Name</label>
        <input type="text" name="meds[${i}][name]" class="form-control" placeholder="e.g., Hydroxychloroquine">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dose</label>
        <div class="input-group">
          <input type="number" step="0.001" name="meds[${i}][dose]" class="form-control" placeholder="e.g., 200">
          <select name="meds[${i}][unit]" class="form-select" style="max-width:110px">
            <option value="mg" selected>mg</option>
            <option value="g">g</option>
          </select>
          <select name="meds[${i}][freq]" class="form-select" style="max-width:140px">
            <option value="per_day" selected>/day</option>
            <option value="per_week">/week</option>
          </select>
        </div>
        <div class="help">Stored as mg/day (DB computes). Dose is required if a name is set.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Notes</label>
        <input type="text" name="meds[${i}][notes]" class="form-control" placeholder="Optional notes">
      </div>

      <div class="col-md-3">
        <label class="form-label">Start</label>
        <input type="date" name="meds[${i}][start_date]" class="form-control js-start">
      </div>
      <div class="col-md-3">
        <label class="form-label">End</label>
        <input type="date" name="meds[${i}][end_date]" class="form-control js-end">
      </div>
      <div class="col-md-3">
        <label class="form-label">Duration (months)</label>
        <input type="number" min="0" name="meds[${i}][months]" class="form-control js-months" placeholder="e.g., 6">
      </div>
      <div class="col-md-3">
        <label class="form-label">Duration (days)</label>
        <input type="number" min="0" name="meds[${i}][days]" class="form-control js-days" placeholder="e.g., 90">
      </div>

      <div class="col-12">
        <div class="small text-muted">
          <span class="meds-chip"><strong>Preview:</strong> <span class="js-preview">—</span></span>
        </div>
      </div>
    </div>
  </div>
  `;
}
function recalcMedRow(row){
  const dose = parseFloat($('input[name$="[dose]"]', row)?.value || '');
  const unit = $('select[name$="[unit]"]', row)?.value || 'mg';
  const freq = $('select[name$="[freq]"]', row)?.value || 'per_day';
  const s = $('input.js-start', row)?.value || '';
  const e = $('input.js-end', row)?.value || '';
  const months = parseInt($('input.js-months', row)?.value || '0', 10) || 0;
  const days   = parseInt($('input.js-days', row)?.value || '0', 10) || 0;

  let dose_mg = isFinite(dose) ? dose * (unit === 'g' ? 1000 : 1) : NaN;
  let per_day = (freq === 'per_week') ? (dose_mg/7) : dose_mg;

  // duration
  let dur = null;
  if (s && e) {
    const sd = new Date(s+'T00:00:00'), ed = new Date(e+'T00:00:00');
    dur = (ed >= sd) ? Math.round((ed - sd)/(24*3600*1000)) + 1 : 0;
  } else if ((months || days)) {
    dur = (months*30 + days);
  }

  let cum = (isFinite(per_day) && dur !== null) ? (per_day * Math.max(0,dur)) : null;
  const pv = $('.js-preview', row);
  const fmt = (n)=> (isFinite(n) && n !== null) ? String(Math.round(n*1000)/1000) : '—';
  if (pv) pv.textContent = `dosage_per_day ≈ ${fmt(per_day)} mg/day • duration_days = ${dur??'—'} • cumulative ≈ ${fmt(cum)} mg`;
}
function addMedRow(){
  const html = medRowTemplate(medIdx++);
  $('#medsContainer').insertAdjacentHTML('beforeend', html);
  const row = $('#medsContainer .meds-row:last-child');
  $('.del-row', row).addEventListener('click', ()=>{ row.remove(); });
  $$('#medsContainer .meds-row:last-child input, #medsContainer .meds-row:last-child select').forEach(el => {
    el.addEventListener('input', ()=> recalcMedRow(row));
    el.addEventListener('change', ()=> recalcMedRow(row));
  });
  recalcMedRow(row);
}

// ===== Medications Only block =====
let med2Idx = 0;
function med2RowTemplate(i){
  return `
  <div class="meds-row" data-med2="${i}">
    <div class="d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Medication #${i+1}</div>
      <button class="btn btn-sm btn-outline-danger del-row" type="button"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="row g-3 align-items-end mt-1">
      <div class="col-md-4">
        <label class="form-label">Name</label>
        <input type="text" name="meds2[${i}][name]" class="form-control" placeholder="e.g., Hydroxychloroquine">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dose</label>
        <div class="input-group">
          <input type="number" step="0.001" name="meds2[${i}][dose]" class="form-control" placeholder="e.g., 200">
          <select name="meds2[${i}][unit]" class="form-select" style="max-width:110px">
            <option value="mg" selected>mg</option>
            <option value="g">g</option>
          </select>
          <select name="meds2[${i}][freq]" class="form-select" style="max-width:140px">
            <option value="per_day" selected>/day</option>
            <option value="per_week">/week</option>
          </select>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Notes</label>
        <input type="text" name="meds2[${i}][notes]" class="form-control" placeholder="Optional notes">
      </div>

      <div class="col-md-3">
        <label class="form-label">Start</label>
        <input type="date" name="meds2[${i}][start_date]" class="form-control js-start2">
      </div>
      <div class="col-md-3">
        <label class="form-label">End</label>
        <input type="date" name="meds2[${i}][end_date]" class="form-control js-end2">
      </div>
      <div class="col-md-3">
        <label class="form-label">Duration (months)</label>
        <input type="number" min="0" name="meds2[${i}][months]" class="form-control js-months2" placeholder="e.g., 6">
      </div>
      <div class="col-md-3">
        <label class="form-label">Duration (days)</label>
        <input type="number" min="0" name="meds2[${i}][days]" class="form-control js-days2" placeholder="e.g., 90">
      </div>
    </div>
  </div>
  `;
}
function addMed2Row(){
  const html = med2RowTemplate(med2Idx++);
  $('#meds2Container').insertAdjacentHTML('beforeend', html);
  const row = $('#meds2Container .meds-row:last-child');
  $('.del-row', row).addEventListener('click', ()=>{ row.remove(); });
}

document.addEventListener('DOMContentLoaded', ()=>{
  // Full Entry eye blocks
  wireEye('OD'); wireEye('OS');

  // Sync dates (OD -> OS)
  const sync = $('#syncDates');
  const odDate = $('#od_date_of_test');
  const osDate = $('#os_date_of_test');
  function syncNow(){ if (sync?.checked && odDate?.value) osDate.value = odDate.value; }
  sync?.addEventListener('change', syncNow);
  odDate?.addEventListener('change', syncNow);

  // Duplicate warning (patient + date + eye)
  async function checkDup(eye){
    const subject = $('#subject_id')?.value.trim();
    const date = eye==='OD' ? $('#od_date_of_test')?.value : $('#os_date_of_test')?.value;
    if (!subject || !date) return false;
    const params = new URLSearchParams({check:'1', subject, date, eye});
    const resp = await fetch(`form.php?${params.toString()}`);
    const j = await resp.json();
    return j.ok && j.exists;
  }
  async function updateDupBanner(){
    const a = await checkDup('OD');
    const b = await checkDup('OS');
    $('#dup-alert')?.classList.toggle('d-none', !(a || b));
  }
  $('#subject_id')?.addEventListener('blur', updateDupBanner);
  $('#od_date_of_test')?.addEventListener('change', updateDupBanner);
  $('#os_date_of_test')?.addEventListener('change', updateDupBanner);

  // Full Entry meds (global)
  $('#addMed')?.addEventListener('click', addMedRow);
  addMedRow();

  // Meds Only rows
  $('#addMed2')?.addEventListener('click', addMed2Row);
  addMed2Row();

  // Client-side minimal checks
  $('#dataForm')?.addEventListener('submit', (e)=>{
    const req = ['#subject_id','#date_of_birth'];
    let ok = true;
    req.forEach(sel => { if (!$(sel)?.value) ok=false; });
    // allow submit if at least one eye date OR at least one medication name
    const hasOD = !!$('#od_date_of_test')?.value;
    const hasOS = !!$('#os_date_of_test')?.value;
    const medNames = $$('#medsContainer input[name^="meds"][name$="[name]"]');
    const hasMed = medNames.length && medNames.some(inp => (inp.value||'').trim() !== '');
    if (!hasOD && !hasOS && !hasMed) ok = false;

    if (!ok) { e.preventDefault(); toast('toastWarn').show(); }
  });

  $('#medsOnlyForm')?.addEventListener('submit', (e)=>{
    let ok = true;
    if (!$('#m_patient_or_subject')?.value) ok = false;
    const medNames = $$('#meds2Container input[name^="meds2"][name$="[name]"]');
    const hasMed = medNames.length && medNames.some(inp => (inp.value||'').trim() !== '');
    if (!hasMed) ok = false;
    if (!ok) { e.preventDefault(); toast('toastWarn').show(); }
  });
});
</script>
</body>
</html>
