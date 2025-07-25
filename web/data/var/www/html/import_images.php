<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *"); // allow JS fetch from same server

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

    // Use existing import function
    if (importTestImage($testType, $eye, $patientId, $testDate, $_FILES['image']['tmp_name'])) {
        echo json_encode(['status' => 'success', 'message' => $_FILES['image']['name'] . ' uploaded']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to import']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Medical Image Importer</title>
    <style>
        #file-list li.success { color: green; }
        #file-list li.error { color: red; }
    </style>
</head>
<body>
    <h1>Folder Upload (One File at a Time)</h1>

    <!-- Upload Controls -->
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

    <h3>Files:</h3>
    <ul id="file-list"></ul>
    <div id="status"></div>

    <script>
    const filesInput = document.getElementById('bulk_files');
    const fileList = document.getElementById('file-list');
    const status = document.getElementById('status');

    document.getElementById('startUpload').addEventListener('click', async () => {
        const files = filesInput.files;
        if (files.length === 0) {
            alert('Please select a folder first');
            return;
        }
        fileList.innerHTML = '';
        status.innerText = 'Uploading...';

        const test_type = document.getElementById('test_type').value;
        const eye = document.getElementById('eye').value;
        const patient_id = document.getElementById('patient_id').value;
        const test_date = document.getElementById('test_date').value;

        for (let i = 0; i < files.length; i++) {
            const li = document.createElement('li');
            li.textContent = files[i].webkitRelativePath;
            fileList.appendChild(li);

            const formData = new FormData();
            formData.append('import', '1');
            formData.append('test_type', test_type);
            formData.append('eye', eye);
            formData.append('patient_id', patient_id);
            formData.append('test_date', test_date);
            formData.append('image', files[i], files[i].name);

            try {
                const response = await fetch('import_images.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.status === 'success') {
                    li.classList.add('success');
                } else {
                    li.classList.add('error');
                    li.textContent += ' - ' + result.message;
                }
            } catch (err) {
                li.classList.add('error');
                li.textContent += ' - network error';
            }
        }
        status.innerText = 'All files processed!';
    });
    </script>
</body>
</html>
