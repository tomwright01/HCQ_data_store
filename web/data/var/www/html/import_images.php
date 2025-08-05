<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

function handleSingleFile($testType, $tmpName, $originalName) {
    global $conn;

    // Enhanced filename validation with eye-specific patterns
    $pattern = ($testType === 'MFERG')
        ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
        : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';

    if (!preg_match($pattern, $originalName, $m)) {
        return ['success' => false, 'message' => "Filename must be: PatientID_OD/OS_YYYYMMDD.ext"];
    }

    list(, $patientId, $eye, $dateStr) = $m;
    $eye = strtoupper($eye);
    if (!in_array($eye, ['OD','OS'])) {
        return ['success' => false, 'message' => "Eye must be OD or OS"];
    }

    $dt = DateTime::createFromFormat('Ymd', $dateStr);
    if (!$dt) {
        return ['success' => false, 'message' => "Invalid date in filename"];
    }

    // find existing test
    $sel = $conn->prepare("
        SELECT test_id 
          FROM tests
         WHERE patient_id = ?
           AND date_of_test = ?
           AND eye = ?
    ");
    $sel->bind_param("sss", $patientId, $dt->format('Y-m-d'), $eye);
    $sel->execute();
    $existing = $sel->get_result()->fetch_assoc();
    $field = strtolower($testType) . "_reference_" . strtolower($eye);

    if ($existing) {
        $up = $conn->prepare("UPDATE tests SET `$field` = ? WHERE test_id = ?");
        $up->bind_param("ss", $originalName, $existing['test_id']);
    } else {
        $newId = $patientId . "_" . $dt->format('Ymd') . "_" . substr(md5(uniqid()),0,6);
        $ins = $conn->prepare("
            INSERT INTO tests
              (test_id, patient_id, date_of_test, eye, `$field`)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->bind_param("sssss", $newId, $patientId, $dt->format('Y-m-d'), $eye, $originalName);
        $up = $ins;
    }

    if (!$up->execute()) {
        return ['success' => false, 'message' => "DB error: " . $conn->error];
    }

    return ['success' => true, 'message' => "$testType image saved for $eye eye"];
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_upload'])) {
    $testType = $_POST['test_type'] ?? '';
    if (! array_key_exists($testType, ALLOWED_TEST_TYPES)) {
        echo json_encode(['status'=>'error','message'=>'Invalid test type']);
        exit;
    }
    if (! isset($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error','message'=>'Upload failed']);
        exit;
    }
    $r = handleSingleFile($testType, $_FILES['image']['tmp_name'], $_FILES['image']['name']);
    echo json_encode($r['success']
        ? ['status'=>'success','message'=>$r['message']]
        : ['status'=>'error','message'=>$r['message']]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Medical Image Importer</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #00a88f;
      --primary-dark: #008f7a;
      --bg: #f5f7fa;
      --card: #ffffff;
      --text: #333;
      --radius: 12px;
      --shadow: 0 4px 20px rgba(0,0,0,0.05);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }
    .wrapper { max-width: 960px; margin: 40px auto; padding: 0 20px; }
    h1 { text-align: center; color: var(--primary); margin-bottom: 30px; font-size: 2.5rem; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
    @media(min-width: 768px) { .grid { grid-template-columns: 1fr 1fr; } }
    .card {
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 30px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .card h2 { color: var(--primary); font-size: 1.5rem; }
    label { font-weight: 600; margin-bottom: 6px; display: block; }
    input, select, button {
      font-family: inherit;
      font-size: 1rem;
      border: 1px solid #ccc;
      border-radius: var(--radius);
    }
    input, select { padding: 12px; width: 100%; }
    input[type="file"] { padding: 6px; }
    input:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 4px rgba(0,168,143,0.3); }
    button {
      background: var(--primary);
      color: #fff;
      border: none;
      padding: 14px;
      cursor: pointer;
      font-weight: 600;
      transition: background 0.2s, transform 0.1s;
    }
    button:hover { background: var(--primary-dark); transform: translateY(-2px); }
    .progress-wrap { margin-top: 10px; }
    .progress-text { font-size: 0.95rem; font-weight: 600; }
    .bar-container {
      background: #e0e0e0;
      border-radius: 7px;
      overflow: hidden;
      margin-top: 6px;
    }
    .bar { height: 12px; width: 0; background: var(--primary); transition: width 0.3s; }
    ul { list-style: none; max-height: 200px; overflow-y: auto; padding-left: 0; }
    ul li { padding: 6px 0; font-size: 0.9rem; }
    ul li.success { color: green; }
    ul li.error { color: red; }
    .back { text-decoration: none; color: var(--primary); font-weight: 600; display: block; text-align: center; margin-top: 30px; }
    .back:hover { color: var(--primary-dark); }
  </style>
</head>
<body>
  <div class="wrapper">
    <h1>Medical Image Importer</h1>
    <div class="grid">
      <!-- Single Upload -->
      <div class="card">
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data">
          <label for="single_type">Test Type</label>
          <select id="single_type" name="test_type" required>
            <option value="">— Choose —</option>
            <?php foreach(ALLOWED_TEST_TYPES as $t=>$d): ?>
              <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
          </select>

          <label for="image">Choose File (PNG • PDF • EXP)</label>
          <input type="file" id="image" name="image" accept=".png,.pdf,.exp" required>

          <button type="submit" name="bulk_upload">Upload Now</button>
        </form>
      </div>

      <!-- Bulk Upload -->
      <div class="card">
        <h2>Bulk Folder Upload</h2>
        <label for="bulk_type">Test Type</label>
        <select id="bulk_type">
          <?php foreach(ALLOWED_TEST_TYPES as $t=>$d): ?>
            <option value="<?= $t ?>"><?= $t ?></option>
          <?php endforeach; ?>
        </select>

        <label for="bulk_files">Select Folder</label>
        <input type="file" id="bulk_files" webkitdirectory multiple>

        <button id="startUpload">Start Upload</button>

        <div class="progress-wrap">
          <div class="progress-text" id="progressText">No files processed</div>
          <div class="bar-container"><div id="bar" class="bar"></div></div>
        </div>
        <ul id="results"></ul>
      </div>
    </div>

    <a href="index.php" class="back">← Return Home</a>
  </div>

  <script>
    document.getElementById('startUpload').addEventListener('click', async () => {
      const files = document.getElementById('bulk_files').files;
      const type  = document.getElementById('bulk_type').value;
      const list  = document.getElementById('results');
      const txt   = document.getElementById('progressText');
      const bar   = document.getElementById('bar');
      if (!type || files.length===0) return alert('Select test type & folder.');

      list.innerHTML = '';
      let ok=0, err=0;
      for (let i=0; i<files.length; i++) {
        const f = files[i];
        const li = document.createElement('li');
        li.textContent = f.webkitRelativePath;
        list.appendChild(li);

        const fd = new FormData();
        fd.append('bulk_upload','1');
        fd.append('test_type',type);
        fd.append('image',f,f.name);

        try {
          let res = await fetch('', { method:'POST', body:fd });
          let json= await res.json();
          if (json.status==='success') {
            li.classList.add('success');
            ok++;
          } else {
            li.classList.add('error');
            li.textContent += ' — ' + json.message;
            err++;
          }
        } catch {
          li.classList.add('error');
          li.textContent += ' — network error';
          err++;
        }
        let pct = Math.round(((i+1)/files.length)*100);
        bar.style.width = pct + '%';
        txt.textContent = `Processed ${i+1}/${files.length} — ✓ ${ok} | ✕ ${err}`;
      }

      txt.textContent = `Done — ✓ ${ok} | ✕ ${err}`;
    });
  </script>
</body>
</html>
