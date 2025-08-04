<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Enable errors for debugging (remove or guard in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$successMessage = '';
$errorMessage = '';
$duplicateWarning = false;

// Helper to normalize merci score
function normalize_merci_score($value) {
    if ($value === null || $value === '') return null;
    if (strtolower($value) === 'unable') return 'unable';
    if (is_numeric($value) && $value >= 0 && $value <= 100) return (int)$value;
    return null;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $conn->begin_transaction();

        // Patient
        $subjectId = trim($_POST['subject_id']);
        $dob = $_POST['date_of_birth'];
        $location = $_POST['location'] ?? 'KH';
        $actualDiagnosis = $_POST['actual_diagnosis'] ?? 'other';

        if (empty($subjectId) || empty($dob)) {
            throw new Exception("Subject ID and Date of Birth are required");
        }

        $patientId = $subjectId;

        // Upsert patient including actual_diagnosis
        $stmt = $conn->prepare("INSERT INTO patients (patient_id, subject_id, date_of_birth, location, actual_diagnosis)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                subject_id = VALUES(subject_id),
                date_of_birth = VALUES(date_of_birth),
                location = VALUES(location),
                actual_diagnosis = VALUES(actual_diagnosis)
        ");
        $stmt->bind_param("sssss", $patientId, $subjectId, $dob, $location, $actualDiagnosis);
        if (!$stmt->execute()) {
            throw new Exception("Error saving patient: " . $stmt->error);
        }
        $stmt->close();

        // Process each eye
        foreach (['OD', 'OS'] as $eye) {
            $eyeKey = "eye_data_$eye";
            if (!isset($_POST[$eyeKey])) continue;
            $eyeData = $_POST[$eyeKey];

            // required test date to proceed
            if (empty($eyeData['date_of_test'])) continue;

            $testDate = $eyeData['date_of_test'];
            $age = isset($eyeData['age']) && $eyeData['age'] !== '' ? (int)$eyeData['age'] : null;
            $reportDiagnosis = $eyeData['report_diagnosis'] ?? 'no input';
            $exclusion = $eyeData['exclusion'] ?? 'none';
            $merciScore = normalize_merci_score($eyeData['merci_score'] ?? null);
            $merciDiagnosis = $eyeData['merci_diagnosis'] ?? 'no value';
            $errorType = isset($eyeData['error_type']) && $eyeData['error_type'] !== '' ? $eyeData['error_type'] : null;
            $fafGrade = isset($eyeData['faf_grade']) && $eyeData['faf_grade'] !== '' ? (int)$eyeData['faf_grade'] : null;
            $octScore = isset($eyeData['oct_score']) && $eyeData['oct_score'] !== '' ? round((float)$eyeData['oct_score'], 2) : null;
            $vfScore = isset($eyeData['vf_score']) && $eyeData['vf_score'] !== '' ? round((float)$eyeData['vf_score'], 2) : null;

            $medicationName = $_POST["eye_data_$eye"]['medication_name'] ?? null;
            $dosage = isset($_POST["eye_data_$eye"]['dosage']) && $_POST["eye_data_$eye"]['dosage'] !== '' ? round((float)$_POST["eye_data_$eye"]['dosage'], 2) : null;
            $dosageUnit = $_POST["eye_data_$eye"]['dosage_unit'] ?? 'mg';
            $durationDays = isset($_POST["eye_data_$eye"]['duration_days']) && $_POST["eye_data_$eye"]['duration_days'] !== '' ? (int)$_POST["eye_data_$eye"]['duration_days'] : null;
            $cumulativeDosage = isset($_POST["eye_data_$eye"]['cumulative_dosage']) && $_POST["eye_data_$eye"]['cumulative_dosage'] !== '' ? round((float)$_POST["eye_data_$eye"]['cumulative_dosage'], 2) : null;
            $dateOfContinuation = $_POST["eye_data_$eye"]['date_of_continuation'] ?? null;
            $treatmentNotes = $_POST["eye_data_$eye"]['treatment_notes'] ?? null;

            // Generate or allow override test_id
            if (!empty($eyeData['test_id'])) {
                $testId = $eyeData['test_id'];
            } else {
                $testId = date('YmdHis') . '_' . $eye . '_' . substr(md5(uniqid('', true)), 0, 4);
            }

            // Insert or update test
            $stmt = $conn->prepare("INSERT INTO tests (
                    test_id, patient_id, location, date_of_test,
                    age, eye, report_diagnosis, exclusion,
                    merci_score, merci_diagnosis, error_type,
                    faf_grade, oct_score, vf_score,
                    medication_name, dosage, dosage_unit,
                    duration_days, cumulative_dosage,
                    date_of_continuation, treatment_notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    location = VALUES(location),
                    date_of_test = VALUES(date_of_test),
                    age = VALUES(age),
                    report_diagnosis = VALUES(report_diagnosis),
                    exclusion = VALUES(exclusion),
                    merci_score = VALUES(merci_score),
                    merci_diagnosis = VALUES(merci_diagnosis),
                    error_type = VALUES(error_type),
                    faf_grade = VALUES(faf_grade),
                    oct_score = VALUES(oct_score),
                    vf_score = VALUES(vf_score),
                    medication_name = VALUES(medication_name),
                    dosage = VALUES(dosage),
                    dosage_unit = VALUES(dosage_unit),
                    duration_days = VALUES(duration_days),
                    cumulative_dosage = VALUES(cumulative_dosage),
                    date_of_continuation = VALUES(date_of_continuation),
                    treatment_notes = VALUES(treatment_notes)
            ");

            $stmt->bind_param(
                "ssssissssssdddsdidss",
                $testId,
                $patientId,
                $location,
                $testDate,
                $age,
                $eye,
                $reportDiagnosis,
                $exclusion,
                $merciScore,
                $merciDiagnosis,
                $errorType,
                $fafGrade,
                $octScore,
                $vfScore,
                $medicationName,
                $dosage,
                $dosageUnit,
                $durationDays,
                $cumulativeDosage,
                $dateOfContinuation,
                $treatmentNotes
            );

            if (!$stmt->execute()) {
                throw new Exception("Error saving $eye test: " . $stmt->error);
            }
            $stmt->close();

            // Process image uploads for this eye (assumes importTestImage exists)
            foreach (ALLOWED_TEST_TYPES as $testType => $dir) {
                $fileField = "image_{$testType}_{$eye}";
                if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
                    $tempFilePath = $_FILES[$fileField]['tmp_name'];
                    if (!importTestImage($testType, $eye, $patientId, $testDate, $tempFilePath)) {
                        throw new Exception("Failed to import $testType image for $eye");
                    }
                }
            }
        }

        $conn->commit();
        $successMessage = "Patient and test(s) saved successfully.";
        // Prevent resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Data Entry</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
    :root {
        --primary: #00A88F;
        --primary-dark: #006D44;
        --light: #f7f9fb;
        --radius: 12px;
        --shadow: 0 25px 60px rgba(0,0,0,0.08);
        --border: #e3e8ed;
        --radius-sm: 8px;
        --transition: .25s ease;
    }
    * { box-sizing:border-box; }
    body {
        background: #f1f5fa;
        font-family: 'Arial',sans-serif;
        margin:0;
        padding:30px;
        color:#2f3742;
        min-height:100vh;
    }
    .form-container {
        max-width: 1100px;
        margin: auto;
        background: white;
        border-radius: var(--radius);
        padding: 30px 35px 45px;
        box-shadow: var(--shadow);
        position: relative;
    }
    h1 {
        margin:0;
        font-size:32px;
        color: var(--primary);
        text-align:center;
        margin-bottom:15px;
        font-weight:700;
    }
    .subheader {
        display:flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap:10px;
        margin-bottom:25px;
    }
    .badge {
        background: linear-gradient(135deg,var(--primary), var(--primary-dark));
        color:white;
        padding:10px 16px;
        border-radius:8px;
        font-weight:600;
        display:inline-flex;
        align-items:center;
        gap:8px;
        box-shadow:0 15px 40px rgba(0,168,143,0.3);
    }
    .alert { padding:14px 18px; border-radius:6px; margin-bottom:20px; display:flex; gap:12px; align-items:center; font-weight:600; }
    .alert-success { background:#E8F8F5; border-left:5px solid #00A88F; color:#10675B; }
    .alert-error { background:#FDE8E8; border-left:5px solid #D32F2F; color:#7A1F1F; }
    .form-section { margin-bottom:30px; }
    .form-section h2 {
        font-size:20px;
        margin-bottom:12px;
        display:flex;
        align-items:center;
        gap:8px;
        color: var(--primary-dark);
        border-bottom:2px solid var(--border);
        padding-bottom:6px;
    }
    .row { display:flex; gap:20px; flex-wrap:wrap; }
    .col { flex:1; min-width:220px; }
    label {
        display:block;
        margin-bottom:6px;
        font-weight:600;
        font-size:0.85rem;
        text-transform:uppercase;
        letter-spacing:0.8px;
        color:#555;
    }
    input, select, textarea {
        width:100%;
        padding:12px 14px;
        border-radius:8px;
        border:1px solid var(--border);
        font-size:14px;
        resize:vertical;
        background:#fff;
        transition: all var(--transition);
    }
    input:focus, select:focus, textarea:focus { outline:none; border-color: var(--primary); box-shadow:0 0 12px rgba(0,168,143,0.15); }
    .eye-tabs {
        display:flex;
        gap:10px;
        margin-bottom:20px;
    }
    .eye-tab {
        flex:1;
        padding:12px 16px;
        border:none;
        border-radius:8px 8px 0 0;
        background:#eef4fa;
        cursor:pointer;
        font-weight:600;
        position:relative;
        transition: all .2s;
    }
    .eye-tab.active {
        background:white;
        color: var(--primary-dark);
        box-shadow: 0 -4px 0 var(--primary) inset;
    }
    .eye-content {
        display:none;
        background:#fff;
        border:1px solid var(--border);
        border-radius:8px;
        padding:20px 18px 18px;
        margin-bottom:25px;
    }
    .eye-content.active { display:block; }
    .image-upload-group {
        background:#f5f9fc;
        padding:15px 18px;
        border-radius:8px;
        margin-bottom:18px;
        border:1px solid var(--border);
    }
    .image-upload-group h3 {
        margin-top:0;
        font-size:16px;
        margin-bottom:10px;
        color:#444;
    }
    .small-input-group { display:flex; gap:10px; flex-wrap:wrap; }
    .small-input-group > * { flex:1; min-width:120px; }
    .submit-btn {
        background: linear-gradient(135deg,var(--primary), var(--primary-dark));
        color:white;
        border:none;
        padding:16px;
        font-size:16px;
        font-weight:700;
        width:100%;
        border-radius:8px;
        cursor:pointer;
        transition: all .25s;
        display:inline-flex;
        justify-content:center;
        align-items:center;
        gap:8px;
    }
    .submit-btn:hover { filter:brightness(1.05); }
    .back-link {
        display:inline-block;
        margin-top:18px;
        color: var(--primary-dark);
        text-decoration:none;
        font-weight:600;
    }
    .back-link:hover { text-decoration:underline; }
    .inline-half { display:flex; gap:15px; flex-wrap:wrap; }
    .inline-half .col { flex:1; min-width:180px; }
    .test-id-field {
        display:flex;
        gap:8px;
        align-items:center;
    }
    .test-id-field input {
        flex:1;
    }
    @media (max-width: 1000px) {
        .row { flex-direction:column; }
        .eye-tabs { flex-direction:column; }
    }
</style>
</head>
<body>
    <div class="form-container">
        <h1><i class="fas fa-notes-medical"></i> Patient Data Entry</h1>
        <div class="subheader">
            <div class="badge"><i class="fas fa-user"></i> New / Edit Patient</div>
            <?php if (!empty($successMessage)): ?>
                <div style="flex:1;"><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMessage) ?></div></div>
            <?php elseif (!empty($errorMessage)): ?>
                <div style="flex:1;"><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?></div></div>
            <?php endif; ?>
        </div>

        <div id="duplicate-warning" class="alert alert-error" style="display: none;">
            <i class="fas fa-exclamation-circle"></i> Note: This patient already has data for the selected date/eye. Submitting will update existing record.
        </div>

        <form action="" method="post" enctype="multipart/form-data" id="patient-form" novalidate>
            <!-- Patient Info -->
            <div class="form-section">
                <h2><i class="fas fa-user-injured"></i> Patient Information</h2>
                <div class="row">
                    <div class="col">
                        <label for="subject_id">Subject ID</label>
                        <input type="text" name="subject_id" id="subject_id" required value="<?= htmlspecialchars($_POST['subject_id'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" name="date_of_birth" id="date_of_birth" required value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label for="location">Location</label>
                        <select name="location" id="location" required>
                            <option value="KH" <?= (($_POST['location'] ?? '') === 'KH') ? 'selected' : '' ?>>KH</option>
                            <option value="IVEY" <?= (($_POST['location'] ?? '') === 'IVEY') ? 'selected' : '' ?>>IVEY</option>
                            <option value="CHUSJ" <?= (($_POST['location'] ?? '') === 'CHUSJ') ? 'selected' : '' ?>>CHUSJ</option>
                            <option value="IWK" <?= (($_POST['location'] ?? '') === 'IWK') ? 'selected' : '' ?>>IWK</option>
                        </select>
                    </div>
                    <div class="col">
                        <label for="actual_diagnosis">Actual Diagnosis</label>
                        <select name="actual_diagnosis" id="actual_diagnosis" required>
                            <option value="RA" <?= (($_POST['actual_diagnosis'] ?? '') === 'RA') ? 'selected' : '' ?>>RA</option>
                            <option value="SLE" <?= (($_POST['actual_diagnosis'] ?? '') === 'SLE') ? 'selected' : '' ?>>SLE</option>
                            <option value="Sjogren" <?= (($_POST['actual_diagnosis'] ?? '') === 'Sjogren') ? 'selected' : '' ?>>Sjogren</option>
                            <option value="other" <?= (($_POST['actual_diagnosis'] ?? '') === 'other' || empty($_POST['actual_diagnosis'])) ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Eye Tabs -->
            <div class="eye-tabs">
                <button type="button" class="eye-tab active" data-eye="od">Right Eye (OD)</button>
                <button type="button" class="eye-tab" data-eye="os">Left Eye (OS)</button>
            </div>

            <!-- OD Content -->
            <div class="eye-content active" id="od-content">
                <div class="form-section">
                    <h2><i class="fas fa-eye"></i> Right Eye (OD) Test</h2>

                    <div class="row">
                        <div class="col">
                            <label for="od_date_of_test">Test Date</label>
                            <input type="date" name="eye_data_OD[date_of_test]" id="od_date_of_test" required value="<?= htmlspecialchars($_POST['eye_data_OD']['date_of_test'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_age">Age at Test</label>
                            <input type="number" name="eye_data_OD[age]" id="od_age" min="0" max="120" value="<?= htmlspecialchars($_POST['eye_data_OD']['age'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_test_id">Test ID (optional override)</label>
                            <div class="test-id-field">
                                <input type="text" name="eye_data_OD[test_id]" id="od_test_id" placeholder="Auto-generated if blank" value="<?= htmlspecialchars($_POST['eye_data_OD']['test_id'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="od_report_diagnosis">Report Diagnosis</label>
                            <select name="eye_data_OD[report_diagnosis]" id="od_report_diagnosis" required>
                                <option value="normal" <?= (($_POST['eye_data_OD']['report_diagnosis'] ?? '') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                <option value="abnormal" <?= (($_POST['eye_data_OD']['report_diagnosis'] ?? '') === 'abnormal') ? 'selected' : '' ?>>Abnormal</option>
                                <option value="no input" <?= (($_POST['eye_data_OD']['report_diagnosis'] ?? '') === 'no input') ? 'selected' : '' ?>>No Input</option>
                            </select>
                        </div>
                        <div class="col">
                            <label for="od_exclusion">Exclusion</label>
                            <select name="eye_data_OD[exclusion]" id="od_exclusion" required>
                                <option value="none" <?= (($_POST['eye_data_OD']['exclusion'] ?? '') === 'none') ? 'selected' : '' ?>>None</option>
                                <option value="retinal detachment" <?= (($_POST['eye_data_OD']['exclusion'] ?? '') === 'retinal detachment') ? 'selected' : '' ?>>Retinal Detachment</option>
                                <option value="generalized retinal dysfunction" <?= (($_POST['eye_data_OD']['exclusion'] ?? '') === 'generalized retinal dysfunction') ? 'selected' : '' ?>>Generalized Retinal Dysfunction</option>
                                <option value="unilateral testing" <?= (($_POST['eye_data_OD']['exclusion'] ?? '') === 'unilateral testing') ? 'selected' : '' ?>>Unilateral Testing</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="od_merci_score">MERCI Score</label>
                            <input type="text" name="eye_data_OD[merci_score]" id="od_merci_score" placeholder="0-100 or 'unable'" value="<?= htmlspecialchars($_POST['eye_data_OD']['merci_score'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_merci_diagnosis">MERCI Diagnosis</label>
                            <select name="eye_data_OD[merci_diagnosis]" id="od_merci_diagnosis">
                                <option value="normal" <?= (($_POST['eye_data_OD']['merci_diagnosis'] ?? '') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                <option value="abnormal" <?= (($_POST['eye_data_OD']['merci_diagnosis'] ?? '') === 'abnormal') ? 'selected' : '' ?>>Abnormal</option>
                                <option value="no value" <?= (($_POST['eye_data_OD']['merci_diagnosis'] ?? '') === 'no value') ? 'selected' : '' ?>>No Value</option>
                            </select>
                        </div>
                        <div class="col">
                            <label for="od_error_type">Error Type</label>
                            <select name="eye_data_OD[error_type]" id="od_error_type">
                                <option value="" <?= (empty($_POST['eye_data_OD']['error_type'])) ? 'selected' : '' ?>>Select</option>
                                <option value="TN" <?= (($_POST['eye_data_OD']['error_type'] ?? '') === 'TN') ? 'selected' : '' ?>>TN</option>
                                <option value="FP" <?= (($_POST['eye_data_OD']['error_type'] ?? '') === 'FP') ? 'selected' : '' ?>>FP</option>
                                <option value="TP" <?= (($_POST['eye_data_OD']['error_type'] ?? '') === 'TP') ? 'selected' : '' ?>>TP</option>
                                <option value="FN" <?= (($_POST['eye_data_OD']['error_type'] ?? '') === 'FN') ? 'selected' : '' ?>>FN</option>
                                <option value="none" <?= (($_POST['eye_data_OD']['error_type'] ?? '') === 'none') ? 'selected' : '' ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="od_faf_grade">FAF Grade (1-4)</label>
                            <input type="number" name="eye_data_OD[faf_grade]" id="od_faf_grade" min="1" max="4" value="<?= htmlspecialchars($_POST['eye_data_OD']['faf_grade'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_oct_score">OCT Score</label>
                            <input type="number" step="0.01" name="eye_data_OD[oct_score]" id="od_oct_score" value="<?= htmlspecialchars($_POST['eye_data_OD']['oct_score'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_vf_score">VF Score</label>
                            <input type="number" step="0.01" name="eye_data_OD[vf_score]" id="od_vf_score" value="<?= htmlspecialchars($_POST['eye_data_OD']['vf_score'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="od_medication_name">Medication Name</label>
                            <input type="text" name="eye_data_OD[medication_name]" id="od_medication_name" value="<?= htmlspecialchars($_POST['eye_data_OD']['medication_name'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_dosage">Dosage</label>
                            <div style="display:flex; gap:8px;">
                                <input type="number" step="0.01" name="eye_data_OD[dosage]" id="od_dosage" placeholder="e.g., 200" value="<?= htmlspecialchars($_POST['eye_data_OD']['dosage'] ?? '') ?>">
                                <select name="eye_data_OD[dosage_unit]" id="od_dosage_unit" style="max-width:100px;">
                                    <option value="mg" <?= (($_POST['eye_data_OD']['dosage_unit'] ?? '') === 'mg') ? 'selected' : '' ?>>mg</option>
                                    <option value="g" <?= (($_POST['eye_data_OD']['dosage_unit'] ?? '') === 'g') ? 'selected' : '' ?>>g</option>
                                </select>
                            </div>
                        </div>
                        <div class="col">
                            <label for="od_duration_days">Duration (days)</label>
                            <input type="number" name="eye_data_OD[duration_days]" id="od_duration_days" min="0" value="<?= htmlspecialchars($_POST['eye_data_OD']['duration_days'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="od_cumulative_dosage">Cumulative Dosage</label>
                            <input type="number" step="0.01" name="eye_data_OD[cumulative_dosage]" id="od_cumulative_dosage" value="<?= htmlspecialchars($_POST['eye_data_OD']['cumulative_dosage'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_date_of_continuation">Date of Continuation</label>
                            <input type="date" name="eye_data_OD[date_of_continuation]" id="od_date_of_continuation" value="<?= htmlspecialchars($_POST['eye_data_OD']['date_of_continuation'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="od_treatment_notes">Treatment Notes</label>
                            <input type="text" name="eye_data_OD[treatment_notes]" id="od_treatment_notes" value="<?= htmlspecialchars($_POST['eye_data_OD']['treatment_notes'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- OD Images -->
                <div class="form-section">
                    <h2><i class="fas fa-image"></i> Right Eye (OD) Image Uploads</h2>
                    <div class="image-upload-group">
                        <h3>FAF Image</h3>
                        <label for="image_faf_od">Upload FAF (PNG/JPG)</label>
                        <input type="file" name="image_faf_od" id="image_faf_od" accept="image/png, image/jpeg">
                    </div>
                    <div class="image-upload-group">
                        <h3>OCT Image</h3>
                        <label for="image_oct_od">Upload OCT (PNG/JPG)</label>
                        <input type="file" name="image_oct_od" id="image_oct_od" accept="image/png, image/jpeg">
                    </div>
                    <div class="image-upload-group">
                        <h3>VF Image</h3>
                        <label for="image_vf_od">Upload VF (PDF)</label>
                        <input type="file" name="image_vf_od" id="image_vf_od" accept=".pdf">
                    </div>
                    <div class="image-upload-group">
                        <h3>MFERG Image</h3>
                        <label for="image_mferg_od">Upload MFERG (PNG/JPG)</label>
                        <input type="file" name="image_mferg_od" id="image_mferg_od" accept="image/png, image/jpeg">
                    </div>
                </div>
            </div>

            <!-- OS Content -->
            <div class="eye-content" id="os-content">
                <div class="form-section">
                    <h2><i class="fas fa-eye-slash"></i> Left Eye (OS) Test</h2>

                    <div class="row">
                        <div class="col">
                            <label for="os_date_of_test">Test Date</label>
                            <input type="date" name="eye_data_OS[date_of_test]" id="os_date_of_test" required value="<?= htmlspecialchars($_POST['eye_data_OS']['date_of_test'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_age">Age at Test</label>
                            <input type="number" name="eye_data_OS[age]" id="os_age" min="0" max="120" value="<?= htmlspecialchars($_POST['eye_data_OS']['age'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_test_id">Test ID (optional override)</label>
                            <div class="test-id-field">
                                <input type="text" name="eye_data_OS[test_id]" id="os_test_id" placeholder="Auto-generated if blank" value="<?= htmlspecialchars($_POST['eye_data_OS']['test_id'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="os_report_diagnosis">Report Diagnosis</label>
                            <select name="eye_data_OS[report_diagnosis]" id="os_report_diagnosis" required>
                                <option value="normal" <?= (($_POST['eye_data_OS']['report_diagnosis'] ?? '') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                <option value="abnormal" <?= (($_POST['eye_data_OS']['report_diagnosis'] ?? '') === 'abnormal') ? 'selected' : '' ?>>Abnormal</option>
                                <option value="no input" <?= (($_POST['eye_data_OS']['report_diagnosis'] ?? '') === 'no input') ? 'selected' : '' ?>>No Input</option>
                            </select>
                        </div>
                        <div class="col">
                            <label for="os_exclusion">Exclusion</label>
                            <select name="eye_data_OS[exclusion]" id="os_exclusion" required>
                                <option value="none" <?= (($_POST['eye_data_OS']['exclusion'] ?? '') === 'none') ? 'selected' : '' ?>>None</option>
                                <option value="retinal detachment" <?= (($_POST['eye_data_OS']['exclusion'] ?? '') === 'retinal detachment') ? 'selected' : '' ?>>Retinal Detachment</option>
                                <option value="generalized retinal dysfunction" <?= (($_POST['eye_data_OS']['exclusion'] ?? '') === 'generalized retinal dysfunction') ? 'selected' : '' ?>>Generalized Retinal Dysfunction</option>
                                <option value="unilateral testing" <?= (($_POST['eye_data_OS']['exclusion'] ?? '') === 'unilateral testing') ? 'selected' : '' ?>>Unilateral Testing</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="os_merci_score">MERCI Score</label>
                            <input type="text" name="eye_data_OS[merci_score]" id="os_merci_score" placeholder="0-100 or 'unable'" value="<?= htmlspecialchars($_POST['eye_data_OS']['merci_score'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_merci_diagnosis">MERCI Diagnosis</label>
                            <select name="eye_data_OS[merci_diagnosis]" id="os_merci_diagnosis">
                                <option value="normal" <?= (($_POST['eye_data_OS']['merci_diagnosis'] ?? '') === 'normal') ? 'selected' : '' ?>>Normal</option>
                                <option value="abnormal" <?= (($_POST['eye_data_OS']['merci_diagnosis'] ?? '') === 'abnormal') ? 'selected' : '' ?>>Abnormal</option>
                                <option value="no value" <?= (($_POST['eye_data_OS']['merci_diagnosis'] ?? '') === 'no value') ? 'selected' : '' ?>>No Value</option>
                            </select>
                        </div>
                        <div class="col">
                            <label for="os_error_type">Error Type</label>
                            <select name="eye_data_OS[error_type]" id="os_error_type">
                                <option value="" <?= (empty($_POST['eye_data_OS']['error_type'])) ? 'selected' : '' ?>>Select</option>
                                <option value="TN" <?= (($_POST['eye_data_OS']['error_type'] ?? '') === 'TN') ? 'selected' : '' ?>>TN</option>
                                <option value="FP" <?= (($_POST['eye_data_OS']['error_type'] ?? '') === 'FP') ? 'selected' : '' ?>>FP</option>
                                <option value="TP" <?= (($_POST['eye_data_OS']['error_type'] ?? '') === 'TP') ? 'selected' : '' ?>>TP</option>
                                <option value="FN" <?= (($_POST['eye_data_OS']['error_type'] ?? '') === 'FN') ? 'selected' : '' ?>>FN</option>
                                <option value="none" <?= (($_POST['eye_data_OS']['error_type'] ?? '') === 'none') ? 'selected' : '' ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="os_faf_grade">FAF Grade (1-4)</label>
                            <input type="number" name="eye_data_OS[faf_grade]" id="os_faf_grade" min="1" max="4" value="<?= htmlspecialchars($_POST['eye_data_OS']['faf_grade'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_oct_score">OCT Score</label>
                            <input type="number" step="0.01" name="eye_data_OS[oct_score]" id="os_oct_score" value="<?= htmlspecialchars($_POST['eye_data_OS']['oct_score'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_vf_score">VF Score</label>
                            <input type="number" step="0.01" name="eye_data_OS[vf_score]" id="os_vf_score" value="<?= htmlspecialchars($_POST['eye_data_OS']['vf_score'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="os_medication_name">Medication Name</label>
                            <input type="text" name="eye_data_OS[medication_name]" id="os_medication_name" value="<?= htmlspecialchars($_POST['eye_data_OS']['medication_name'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_dosage">Dosage</label>
                            <div style="display:flex; gap:8px;">
                                <input type="number" step="0.01" name="eye_data_OS[dosage]" id="os_dosage" placeholder="e.g., 200" value="<?= htmlspecialchars($_POST['eye_data_OS']['dosage'] ?? '') ?>">
                                <select name="eye_data_OS[dosage_unit]" id="os_dosage_unit" style="max-width:100px;">
                                    <option value="mg" <?= (($_POST['eye_data_OS']['dosage_unit'] ?? '') === 'mg') ? 'selected' : '' ?>>mg</option>
                                    <option value="g" <?= (($_POST['eye_data_OS']['dosage_unit'] ?? '') === 'g') ? 'selected' : '' ?>>g</option>
                                </select>
                            </div>
                        </div>
                        <div class="col">
                            <label for="os_duration_days">Duration (days)</label>
                            <input type="number" name="eye_data_OS[duration_days]" id="os_duration_days" min="0" value="<?= htmlspecialchars($_POST['eye_data_OS']['duration_days'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label for="os_cumulative_dosage">Cumulative Dosage</label>
                            <input type="number" step="0.01" name="eye_data_OS[cumulative_dosage]" id="os_cumulative_dosage" value="<?= htmlspecialchars($_POST['eye_data_OS']['cumulative_dosage'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_date_of_continuation">Date of Continuation</label>
                            <input type="date" name="eye_data_OS[date_of_continuation]" id="os_date_of_continuation" value="<?= htmlspecialchars($_POST['eye_data_OS']['date_of_continuation'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="os_treatment_notes">Treatment Notes</label>
                            <input type="text" name="eye_data_OS[treatment_notes]" id="os_treatment_notes" value="<?= htmlspecialchars($_POST['eye_data_OS']['treatment_notes'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- OS Images -->
                <div class="form-section">
                    <h2><i class="fas fa-image"></i> Left Eye (OS) Image Uploads</h2>
                    <div class="image-upload-group">
                        <h3>FAF Image</h3>
                        <label for="image_faf_os">Upload FAF (PNG/JPG)</label>
                        <input type="file" name="image_faf_os" id="image_faf_os" accept="image/png, image/jpeg">
                    </div>
                    <div class="image-upload-group">
                        <h3>OCT Image</h3>
                        <label for="image_oct_os">Upload OCT (PNG/JPG)</label>
                        <input type="file" name="image_oct_os" id="image_oct_os" accept="image/png, image/jpeg">
                    </div>
                    <div class="image-upload-group">
                        <h3>VF Image</h3>
                        <label for="image_vf_os">Upload VF (PDF)</label>
                        <input type="file" name="image_vf_os" id="image_vf_os" accept=".pdf">
                    </div>
                    <div class="image-upload-group">
                        <h3>MFERG Image</h3>
                        <label for="image_mferg_os">Upload MFERG (PNG/JPG)</label>
                        <input type="file" name="image_mferg_os" id="image_mferg_os" accept="image/png, image/jpeg">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn"><i class="fas fa-save"></i> Submit Data</button>
        </form>

        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <script>
        // Eye tab logic
        document.querySelectorAll('.eye-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.eye-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.eye-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                const eye = tab.dataset.eye;
                document.getElementById(eye + '-content').classList.add('active');
                checkForDuplicates();
            });
        });

        // Duplicate checker stub (requires your includes/check_duplicate.php)
        async function checkForDuplicates() {
            const subject = document.getElementById('subject_id').value.trim();
            const active = document.querySelector('.eye-tab.active').dataset.eye;
            const dateInput = document.getElementById(`${active}_date_of_test`);
            if (!subject || !dateInput || !dateInput.value) return;
            try {
                const resp = await fetch(`includes/check_duplicate.php?patient=${encodeURIComponent(subject)}&date=${encodeURIComponent(dateInput.value)}&eye=${active.toUpperCase()}`);
                const json = await resp.json();
                document.getElementById('duplicate-warning').style.display = json.exists ? 'flex' : 'none';
            } catch (e) {
                console.warn('Duplicate check failed', e);
            }
        }

        document.getElementById('subject_id')?.addEventListener('blur', checkForDuplicates);
        document.getElementById('od_date_of_test')?.addEventListener('change', () => {
            document.getElementById('os_date_of_test').value = document.getElementById('od_date_of_test').value;
            checkForDuplicates();
        });
        document.getElementById('os_date_of_test')?.addEventListener('change', () => {
            document.getElementById('od_date_of_test').value = document.getElementById('os_date_of_test').value;
            checkForDuplicates();
        });
    </script>
</body>
</html>
