<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// === Handles a single file upload and DB update based on filename ===
function handleSingleFile($testType, $tmpName, $originalName) {
    global $conn;

    $pattern = ($testType === 'MFERG')
        ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
        : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

    if (!preg_match($pattern, $originalName, $matches)) {
        return ['success' => false, 'message' => "Invalid filename format ($originalName)"];
    }

    $patientId = $matches[1];
    $eye = strtoupper($matches[2]);
    $dateStr = $matches[3];
    $testDate = DateTime::createFromFormat('Ymd', $dateStr);
    if (!$testDate) {
        return ['success' => false, 'message' => "Invalid date format in filename: $dateStr"];
    }
    if (!getPatientById($patientId)) {
        return ['success' => false, 'message' => "Patient $patientId not found"];
    }

    $targetDir = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $targetFile = $targetDir . $originalName;
    if (!move_uploaded_file($tmpName, $targetFile)) {
        return ['success' => false, 'message' => "Failed to move file $originalName"];
    }

    $imageField = strtolower($testType) . '_reference_' . strtolower($eye);
    $stmt = $conn->prepare("SELECT test_id FROM tests WHERE patient_id = ? AND date_of_test = ?");
    $stmt->bind_param("ss", $patientId, $testDate->format('Y-m-d'));
    $stmt->execute();
    $test = $stmt->get_result()->fetch_assoc();

    $testId = $test ? $test['test_id'] :
        $patientId . '_' . $testDate->format('Ymd') . '_' . substr(md5(uniqid()), 0, 4);

    if ($test) {
        $stmt = $conn->prepare("UPDATE tests SET $imageField = ? WHERE test_id = ?");
        $stmt->bind_param("ss", $originalName, $testId);
    } else {
        $stmt = $conn->prepare("INSERT INTO tests 
            (test_id, patient_id, date_of_test, $imageField) 
            VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $testId, $patientId, $testDate->format('Y-m-d'), $originalName);
    }

    if (!$stmt->execute()) {
        return ['success' => false, 'message' => "Database error: " . $conn->error];
    }

    return ['success' => true, 'message' => "$originalName processed"];
}

// === Handle AJAX progressive upload ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid test type']);
        exit;
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file']);
        exit;
    }

    $res = handleSingleFile($testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    echo json_encode($res['success']
        ? ['status' => 'success', 'message' => $res['message']]
        : ['status' => 'error', 'message' => $res['message']]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Medical Image Importer</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: #f5f7fa; }
        .container { max-width: 1000px; margin: 40px auto; background: white; padding: 30px; 
                     border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #00a88f; margin-bottom: 20px; }
        h2 { color: #00a88f; margin-top: 0; }
        .form-section { margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        label { display: block; font-weight: bold; margin: 10px 0 5px; }
        select, input[type="text"], input[type="date"], input[type="file"] {
            width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 16px; 
            margin-bottom: 10px;
        }
        button { width: 100%; padding: 12px; background: #00a88f; color: white; border: none; 
                 border-radius: 5px; font-size: 16px; cursor: pointer; transition: 0.3s; }
        button:hover { background: #008f7a; }
        .progress { font-weight: bold; margin: 10px 0; }
        ul#file-list { list-style: none; padding: 0; font-size: 14px; max-height: 300px; overflow-y: auto; }
        #file-list li.success { color: green; }
        #file-list li.error { color: red; }
        .message { text-align: center; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #e6f7e6; color: #3c763d; border: 1px solid #d6e9c6; }
        .error { background: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
    </style>
</head>
<body>
<div class="container">
    <h1>Medical Image Importer</h1>

    <!-- Single File Upload -->
    <div class="form-section">
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data">
            <label>Test Type:</label>
            <select name="test_type" required>
                <option value="">Select Test Type</option>
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                    <option value="<?= $type ?>"><?= $type ?></option>
                <?php endforeach; ?>
            </select>

            <label>Eye:</label>
            <select name="eye" required>
                <option value="OD">Right Eye (OD)</option>
                <option value="OS">Left Eye (OS)</option>
            </select>

            <label>Patient ID:</label>
            <input type="text" name="patient_id" required>

            <label>Test Date:</label>
            <input type="date" name="test_date" required>

            <label>File (PNG or PDF):</label>
            <input type="file" name="image" accept="image/png,.pdf,.exp" required>

            <button type="submit" name="import">Upload File</button>
        </form>
    </div>

    <!-- Folder Upload -->
    <div class="form-section">
        <h2>Bulk Folder Upload (Progressive)</h2>
        <label>Test Type:</label>
        <select id="test_type">
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select>

        <label>Select Folder:</label>
        <input type="file" id="bulk_files" webkitdirectory multiple>
        <button id="startUpload">Start Upload</button>

        <div id="progress" class="progress">No files uploaded yet</div>
        <ul id="file-list"></ul>
    </div>
</div>

<script>
document.getElementById('startUpload').addEventListener('click', async () => {
    const files = document.getElementById('bulk_files').files;
    if (files.length === 0) { alert('Please select a folder'); return; }

    const test_type = document.getElementById('test_type').value;
    const fileList = document.getElementById('file-list');
    const progress = document.getElementById('progress');

    fileList.innerHTML = '';
    let successCount = 0, errorCount = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const li = document.createElement('li');
        li.textContent = file.webkitRelativePath;
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
        progress.innerText = `Processed ${i+1}/${files.length} | Success: ${successCount}, Errors: ${errorCount}`;
    }

    progress.innerText = `Upload finished! Success: ${successCount}, Errors: ${errorCount}`;
});
</script>
</body>
</html>
