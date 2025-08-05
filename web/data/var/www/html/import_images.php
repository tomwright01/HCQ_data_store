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
    $pattern = ($testType === 'MFERG')
        ? '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf|exp)$/i'
        : '/^(\d+)_(OD|OS)_(\d{8})\.(png|pdf)$/i';
    if (!preg_match($pattern, $originalName, $m)) {
        return ['success'=>false,'message'=>"Filename: PatientID_OD/OS_YYYYMMDD.ext"];
    }
    list(, $pid, $eye, $ds) = $m;
    $eye = strtoupper($eye);
    if (!in_array($eye,['OD','OS'])) {
        return ['success'=>false,'message'=>"Eye must be OD or OS"];
    }
    $dt = DateTime::createFromFormat('Ymd',$ds);
    if (!$dt) {
        return ['success'=>false,'message'=>"Invalid date in filename"];
    }
    $sel = $conn->prepare("
      SELECT test_id
        FROM tests
       WHERE patient_id=?
         AND date_of_test=?
         AND eye=?
    ");
    $sel->bind_param("sss",$pid,$dt->format('Y-m-d'),$eye);
    $sel->execute();
    $existing = $sel->get_result()->fetch_assoc();
    $field = strtolower($testType) . "_reference_" . strtolower($eye);

    if ($existing) {
        $upd = $conn->prepare("UPDATE tests SET `$field`=? WHERE test_id=?");
        $upd->bind_param("ss",$originalName,$existing['test_id']);
    } else {
        $newId = "{$pid}_{$dt->format('Ymd')}_" . substr(md5(uniqid()),0,6);
        $upd = $conn->prepare("
          INSERT INTO tests
            (test_id, patient_id, date_of_test, eye, `$field`)
          VALUES (?,?,?,?,?)
        ");
        $upd->bind_param("sssss",$newId,$pid,$dt->format('Y-m-d'),$eye,$originalName);
    }
    if (!$upd->execute()) {
        return ['success'=>false,'message'=>"DB error: ".$conn->error];
    }
    return ['success'=>true,'message'=>"$testType image saved for $eye"];
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_upload'])) {
    $type = $_POST['test_type'] ?? '';
    if (!array_key_exists($type, ALLOWED_TEST_TYPES)) {
        echo json_encode(['status'=>'error','message'=>'Invalid test type']);
        exit;
    }
    if (!isset($_FILES['image'])||$_FILES['image']['error']!==UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error','message'=>'Upload failed']);
        exit;
    }
    $r = handleSingleFile($type,$_FILES['image']['tmp_name'],$_FILES['image']['name']);
    echo json_encode($r['success']
      ? ['status'=>'success','message'=>$r['message']]
      : ['status'=>'error','message'=>$r['message']]
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Medical Image Importer</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #00A88F;
      --primary-light: #E0FCF8;
      --primary-dark: #008F7A;
      --bg: #F7FAFC;
      --card: #FFFFFF;
      --text: #2D3748;
      --radius: 16px;
      --shadow: 0 8px 24px rgba(0,0,0,0.05);
      --transition: 0.3s ease;
    }
    * { box-sizing: border-box; margin:0; padding:0; }
    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
    }
    .wrapper {
      max-width: 1000px;
      margin: 60px auto;
      padding: 0 20px;
    }
    h1 {
      font-size: 3rem;
      text-align: center;
      margin-bottom: 40px;
      color: var(--primary-dark);
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 30px;
    }
    @media(min-width:768px) {
      .grid { grid-template-columns: 1fr 1fr; }
    }
    .card {
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 30px;
      transition: transform var(--transition);
    }
    .card:hover {
      transform: translateY(-5px);
    }
    .card h2 {
      font-size: 1.5rem;
      margin-bottom: 20px;
      color: var(--primary);
    }
    label {
      display: block;
      font-weight: 600;
      margin-bottom: 8px;
      color: var(--text);
    }
    input, select, button {
      font-family: inherit;
      font-size: 1rem;
      border-radius: var(--radius);
      transition: border-color var(--transition), box-shadow var(--transition);
    }
    input, select {
      width: 100%;
      padding: 14px;
      border: 1px solid #CBD5E0;
      margin-bottom: 20px;
    }
    input:focus, select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0,168,143,0.2);
    }
    input[type="file"] {
      padding: 6px;
    }
    button {
      display: inline-block;
      background: var(--primary);
      color: #fff;
      border: none;
      padding: 14px;
      cursor: pointer;
      font-weight: 600;
      box-shadow: 0 4px 15px rgba(0,168,143,0.3);
      transition: background var(--transition), transform var(--transition);
    }
    button:hover {
      background: var(--primary-dark);
      transform: translateY(-2px);
    }
    .progress-wrap {
      margin-top: 10px;
    }
    .progress-text {
      font-weight: 500;
      margin-bottom: 6px;
    }
    .bar-container {
      background: #E2E8F0;
      border-radius: 8px;
      overflow: hidden;
    }
    .bar {
      height: 12px;
      width: 0;
      background: var(--primary);
      transition: width var(--transition);
    }
    ul {
      list-style: none;
      max-height: 220px;
      overflow-y: auto;
      padding-left: 0;
      margin-top: 15px;
    }
    ul li {
      padding: 8px 0;
      border-bottom: 1px solid #E2E8F0;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    ul li.success::before {
      content: "✔";
      color: green;
    }
    ul li.error::before {
      content: "✖";
      color: red;
    }
    .back {
      display: block;
      margin: 50px auto 0;
      text-align: center;
      text-decoration: none;
      color: var(--primary-dark);
      font-weight: 600;
      transition: color var(--transition);
    }
    .back:hover {
      color: var(--primary);
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <h1>📂 Medical Image Importer</h1>

    <div class="grid">
      <!-- Single File -->
      <div class="card">
        <h2>Single File Upload</h2>
        <form method="POST" enctype="multipart/form-data">
          <label for="single_type">Test Type</label>
          <select id="single_type" name="test_type" required>
            <option value="">Select type…</option>
            <?php foreach(ALLOWED_TEST_TYPES as $t=>$d): ?>
              <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
          </select>

          <label for="image">Choose File (PNG • PDF • EXP)</label>
          <input type="file" id="image" name="image" accept=".png,.pdf,.exp" required>

          <button type="submit" name="bulk_upload">📤 Upload Now</button>
        </form>
      </div>

      <!-- Bulk Folder -->
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

        <button id="startUpload">🗂️ Start Upload</button>

        <div class="progress-wrap">
          <div class="progress-text" id="progressText">No files processed yet</div>
          <div class="bar-container"><div id="bar" class="bar"></div></div>
        </div>
        <ul id="results"></ul>
      </div>
    </div>

    <a href="index.php" class="back">← Return Home</a>
  </div>

  <script>
    document.getElementById('startUpload').addEventListener('click', async() => {
      const files = document.getElementById('bulk_files').files;
      const type  = document.getElementById('bulk_type').value;
      const list  = document.getElementById('results');
      const txt   = document.getElementById('progressText');
      const bar   = document.getElementById('bar');

      if (!type || files.length===0) {
        return alert('Please select a test type and folder.');
      }
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
          const res  = await fetch('', { method:'POST', body:fd });
          const json = await res.json();
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

        const pct = Math.round(((i+1)/files.length)*100);
        bar.style.width = pct + '%';
        txt.textContent = `Processed ${i+1}/${files.length} — ✓${ok} • ✕${err}`;
      }

      txt.textContent = `Finished — ✓${ok} • ✕${err}`;
    });
  </script>
</body>
</html>
