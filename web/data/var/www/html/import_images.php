<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $testType = $_POST['test_type'] ?? '';
    $eye = $_POST['eye'] ?? '';
    $patientId = $_POST['patient_id'] ?? '';
    $testDate = $_POST['test_date'] ?? '';

    if (empty($testType) || empty($eye) || empty($patientId) || empty($testDate)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        exit;
    }
    if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid test type']);
        exit;
    }
    if (!in_array($eye, ['OD', 'OS'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid eye']);
        exit;
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file']);
        exit;
    }

    // Process using your existing function
    if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
        echo json_encode(['status' => 'success', 'message' => $_FILES['image']['name'] . ' uploaded']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to import ' . $_FILES['image']['name']]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Progressive Folder Upload</title>
    <style>
        #file-list li.success { color: green; }
        #file-list li.error { color: red; }
        #progress { margin: 10px 0; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Upload Entire Folder (Progressive)</h1>
    <label>Test Type:
        <select id="test_type">
            <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Eye:
        <select id="eye">
            <option value="OD">OD</option>
            <option value="OS">OS</option>
        </select>
    </label>
    <label>Patient ID: <input type="text" id="patient_id"></label>
    <label>Test Date: <input type="date" id="test_date"></label>
    <input type="file" id="bulk_files" webkitdirectory multiple>
    <button id="startUpload">Start Upload</button>

    <div id="progress">No files uploaded yet</div>
    <ul id="file-list"></ul>

<script>
const filesInput = document.getElementById('bulk_files');
const fileList = document.getElementById('file-list');
const progress = document.getElementById('progress');

document.getElementById('startUpload').addEventListener('click', async () => {
    const files = filesInput.files;
    if (files.length === 0) {
        alert('Please select a folder first');
        return;
    }
    fileList.innerHTML = '';
    let successCount = 0;
    let errorCount = 0;

    const test_type = document.getElementById('test_type').value;
    const eye = document.getElementById('eye').value;
    const patient_id = document.getElementById('patient_id').value;
    const test_date = document.getElementById('test_date').value;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const li = document.createElement('li');
        li.textContent = file.webkitRelativePath;
        fileList.appendChild(li);

        const formData = new FormData();
        formData.append('import', '1');
        formData.append('test_type', test_type);
        formData.append('eye', eye);
        formData.append('patient_id', patient_id);
        formData.append('test_date', test_date);
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

        progress.innerText = `Processed ${i+1}/${files.length} files | Success: ${successCount}, Errors: ${errorCount}`;
    }

    progress.innerText = `Upload finished! Success: ${successCount}, Errors: ${errorCount}`;
});
</script>
</body>
</html>
