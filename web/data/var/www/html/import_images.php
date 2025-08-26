<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

/* =========================
   helpers
   ========================= */

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $db = DB_NAME; // set in includes/config.php
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

    // Create deterministic-ish unique test id
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
 * Save file and write refs to DB:
 * - Saves to IMAGE_BASE_DIR/<ModalityDir>/<PatientID>_<EYE>_<YYYYMMDD>.<ext>
 * - Writes to test_eyes.<modality>_reference_OD|OS (primary)
 * - Optionally backfills tests.<modality>_reference_od|os if columns exist
 */
function processImage(mysqli $conn, string $testType, string $finalName, string $patientId, string $eye, string $dateYmd): array {
    $modality = strtoupper($testType);
    if (!defined('ALLOWED_TEST_TYPES') || !is_array(ALLOWED_TEST_TYPES) || !array_key_exists($modality, ALLOWED_TEST_TYPES)) {
        return ['success' => false, 'message' => "Invalid test type"];
    }

    // 1) Save the file
    $dirName   = ALLOWED_TEST_TYPES[$modality];
    $targetDir = rtrim(IMAGE_BASE_DIR, '/')."/{$dirName}/";
    if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }

    $tmp = $_FILES['image']['tmp_name'] ?? null;
    if (!$tmp || !is_uploaded_file($tmp)) {
        return ['success' => false, 'message' => "Upload failed (tmp not found)"];
    }
    $targetFile = $targetDir . $finalName;
    if (is_file($targetFile)) { @unlink($targetFile); }
    if (!move_uploaded_file($tmp, $targetFile)) {
        return ['success' => false, 'message' => "Failed to move file"];
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
        // Extremely old installs might only have a single column (not your schema, but we’re defensive)
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

    return ['success' => true, 'message' => "$finalName processed", 'test_id' => $testId];
}

/** Validate bulk filename and route to processImage */
function handleSingleFile(mysqli $conn, string $testType, string $tmpName, string $originalName): array {
    // Pattern: 920_OD_20250112.(png|pdf|exp) (exp only allowed for MFERG)
    $allowExp = (strtoupper($testType) === 'MFERG');
    $extPat   = $allowExp ? '(png|pdf|exp)' : '(png|pdf)';
    $pattern  = '/^(\d+)_(OD|OS)_(\d{8})\.' . $extPat . '$/i';

    if (!preg_match($pattern, $originalName, $m)) {
        return ['success' => false, 'message' => "Invalid filename: use PatientID_OD|OS_YYYYMMDD.(png|pdf" . ($allowExp? '|exp':'') . ')'];
    }

    $patientId = $m[1];
    $eye       = strtoupper($m[2]);
    $dateYmd   = $m[3];
    $ext       = strtolower($m[4]);

    if (!patientExists($conn, $patientId)) {
        return ['success' => false, 'message' => "Patient $patientId not found"];
    }

    $_FILES['image']['tmp_name'] = $tmpName; // for processImage

    return processImage($conn, $testType, "{$patientId}_{$eye}_{$dateYmd}.{$ext}", $patientId, $eye, $dateYmd);
}

/** Handle the single-file HTML form */
function handleSingleForm(mysqli $conn): array {
    $testType  = $_POST['test_type']  ?? '';
    $patientId = trim($_POST['patient_id'] ?? '');
    $eye       = strtoupper(trim($_POST['eye'] ?? ''));
    $dateIso   = $_POST['test_date']  ?? '';
    $tmpName   = $_FILES['image']['tmp_name'] ?? null;
    $origName  = $_FILES['image']['name']     ?? null;

    if (!$testType || !$patientId || !in_array($eye, ['OD','OS'], true) || !$dateIso || !$tmpName || !$origName) {
        return ['success' => false, 'message' => 'Missing required fields'];
    }
    if (!patientExists($conn, $patientId)) {
        return ['success' => false, 'message' => "Patient $patientId not found"];
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowExp = (strtoupper($testType) === 'MFERG');
    $okExts = $allowExp ? ['png','pdf','exp'] : ['png','pdf'];
    if (!in_array($ext, $okExts, true)) {
        return ['success' => false, 'message' => "Invalid extension .$ext for $testType"];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $dateIso);
    if (!$dt) return ['success' => false, 'message' => "Invalid date"];
    $dateYmd = $dt->format('Ymd');

    $_FILES['image']['tmp_name'] = $tmpName; // for processImage

    return processImage($conn, $testType, "{$patientId}_{$eye}_{$dateYmd}.{$ext}", $patientId, $eye, $dateYmd);
}

/* =========================
   routes
   ========================= */

// AJAX bulk upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!defined('ALLOWED_TEST_TYPES') || !array_key_exists(strtoupper($testType), ALLOWED_TEST_TYPES)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid test type']);
        exit;
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file']);
        exit;
    }

    $res = handleSingleFile($conn, $testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    echo json_encode($res['success'] ? ['status' => 'success', 'message' => $res['message']]
                                     : ['status' => 'error',   'message' => $res['message']]);
    exit;
}

// Single file HTML form submit
$formMessage = null;
$formClass   = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $formMessage = "Upload failed";
        $formClass = "error";
    } else {
        $res = handleSingleForm($conn);
        $formMessage = $res['message'];
        $formClass   = $res['success'] ? "success" : "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medical Image Importer</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: #f0f4f8; }
        .container { max-width: 1000px; margin: 40px auto; background: white; padding: 40px;
                     border-radius: 15px; box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #00a88f; margin-bottom: 30px; font-size: 32px; }
        h2 { color: #00a88f; margin-top: 0; font-size: 24px; }
        .form-section { margin-bottom: 50px; padding-bottom: 20px; border-bottom: 1px solid #e0e0e0; }
        label { display: block; font-weight: 600; margin: 15px 0 5px; font-size: 15px; }
        select, input[type="text"], input[type="date"], input[type="file"] {
            width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px;
            font-size: 16px; margin-bottom: 15px; transition: 0.3s;
        }
        select:focus, input:focus { outline: none; border-color: #00a88f; box-shadow: 0 0 5px rgba(0,168,143,0.5); }
        button { width: 100%; padding: 14px; background: #00a88f; color: white; border: none;
                 border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer;
                 transition: background 0.3s, transform 0.2s; }
        button:hover { background: #008f7a; transform: translateY(-1px); }
        .progress { font-weight: bold; margin: 15px 0; font-size: 15px; }
        .progress-bar-container { width: 100%; background: #e0e0e0; height: 14px; border-radius: 7px; margin: 10px 0; }
        .progress-bar { height: 14px; background: #00a88f; width: 0%; border-radius: 7px; transition: width 0.3s; }
        ul#file-list { list-style: none; padding: 0; font-size: 14px; max-height: 250px; overflow-y: auto; }
        #file-list li.success { color: green; }
        #file-list li.error { color: red; }
        .message { text-align: center; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 16px; }
        .success { background: #e6f7e6; color: #3c763d; border: 1px solid #d6e9c6; }
        .error { background: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
        .back-link { display: inline-block; margin-top: 25px; color: #00a88f; text-decoration: none;
                     font-weight: bold; font-size: 16px; transition: 0.3s; }
        .back-link:hover { text-decoration: underline; color: #008f7a; }
    </style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>

    <?php if ($formMessage): ?>
        <div class="message <?= htmlspecialchars($formClass) ?>">
            <?= htmlspecialchars($formMessage) ?>
        </div>
    <?php endif; ?>

    <!-- Single File Upload -->
    <div class="form-section">
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data">
            <label>Test Type:</label>
            <select name="test_type" required>
                <option value="">Select Test Type</option>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Patient ID:</label>
            <input type="text" name="patient_id" required>

            <label>Test Date:</label>
            <input type="date" name="test_date" required>

            <label>Eye:</label>
            <select name="eye" required>
                <option value="OD">Right Eye (OD)</option>
                <option value="OS">Left Eye (OS)</option>
            </select>

            <label>File (PNG/PDF<?php /* mention EXP visually */ ?>, and EXP for mfERG):</label>
            <input type="file" name="image" accept=".png,.pdf,.exp" required>

            <button type="submit" name="import">Upload File</button>
        </form>
        <p style="margin-top:8px;color:#666;font-size:14px">
            Bulk mode requires filenames like <code>PatientID_OD_YYYYMMDD.png</code> (use <code>.exp</code> only for MFERG).
        </p>
    </div>

    <!-- Folder Upload -->
    <div class="form-section">
        <h2>Bulk Folder Upload (Progressive)</h2>
        <label>Test Type:</label>
        <select id="test_type">
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Select Folder:</label>
        <input type="file" id="bulk_files" webkitdirectory multiple>
        <button id="startUpload">Start Upload</button>

        <div id="progress" class="progress">No files uploaded yet</div>
        <div class="progress-bar-container"><div class="progress-bar" id="progress-bar"></div></div>
        <ul id="file-list"></ul>
    </div>

    <a href="index.php" class="back-link">← Return Home</a>
</div>

<script>
document.getElementById('startUpload').addEventListener('click', async () => {
    const files = document.getElementById('bulk_files').files;
    if (files.length === 0) { alert('Please select a folder'); return; }

    const test_type = document.getElementById('test_type').value;
    const fileList = document.getElementById('file-list');
    const progress = document.getElementById('progress');
    const progressBar = document.getElementById('progress-bar');

    fileList.innerHTML = '';
    let successCount = 0, errorCount = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const li = document.createElement('li');
        li.textContent = file.webkitRelativePath || file.name;
        fileList.appendChild(li);

        const formData = new FormData();
        formData.append('bulk_upload', '1');
        formData.append('test_type', test_type);
        formData.append('image', file, file.name);

        try {
            const response = await fetch('import_images.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                li.classList.add('success');
                successCount++;
            } else {
                li.classList.add('error');
                li.textContent += ' - ' + result.message;
                errorCount++;
            }
        } catch (err) {
            li.classList.add('error');
            li.textContent += ' - network error';
            errorCount++;
        }

        const percentage = Math.round(((i+1)/files.length)*100);
        progress.innerText = `Processed ${i+1}/${files.length} | Success: ${successCount}, Errors: ${errorCount}`;
        progressBar.style.width = percentage + '%';
    }

    progress.innerText = `Upload finished! Success: ${successCount}, Errors: ${errorCount}`;
});
</script>
</body>
</html>
