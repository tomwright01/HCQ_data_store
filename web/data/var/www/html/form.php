<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$flash = ['type'=>null, 'msg'=>null];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_medication'])) {
    // Accept either Subject ID or Patient ID from one input
    $typedId = trim($_POST['subject_id'] ?? '');
    if ($typedId === '' && isset($_POST['patient_id'])) {
        $typedId = trim($_POST['patient_id']);
    }

    $patient_id = resolve_patient_id($conn, $typedId);
    if (!$patient_id) {
        $flash = ['type'=>'danger', 'msg'=>'Unknown patient. Enter an existing Subject ID or Patient ID.'];
    } else {
        $medication_name   = trim($_POST['medication_name'] ?? '');
        $dosage_per_day    = ($_POST['dosage_per_day']    ?? '') === '' ? null : (string)$_POST['dosage_per_day'];
        $duration_days     = ($_POST['duration_days']     ?? '') === '' ? null : (string)$_POST['duration_days'];
        $cumulative_dosage = ($_POST['cumulative_dosage'] ?? '') === '' ? null : (string)$_POST['cumulative_dosage'];
        $start_date        = trim($_POST['start_date'] ?? '') ?: null;  // YYYY-MM-DD or null
        $end_date          = trim($_POST['end_date']   ?? '') ?: null;
        $notes             = trim($_POST['notes']      ?? '') ?: null;

        if ($medication_name === '') {
            $flash = ['type'=>'danger', 'msg'=>'Medication name is required.'];
        } else {
            insertMedication($conn, $patient_id, $medication_name,
                             $dosage_per_day, $duration_days, $cumulative_dosage,
                             $start_date, $end_date, $notes);
            // Redirect to avoid resubmission and show in index
            header('Location: index.php#patients');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Add Medication</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><i class="bi bi-capsule-pill"></i> Hydroxychloroquine Repo</a>
    <div class="ms-auto">
      <a href="index.php#patients" class="btn btn-outline-secondary">Back to Patients</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-capsule-pill"></i> Add Medication</h5>

          <?php if ($flash['type']): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="save_medication" value="1">

            <div class="mb-3">
              <label class="form-label">Subject or Patient ID</label>
              <input type="text" name="subject_id" class="form-control" placeholder="e.g. SUBJ001 or P_abcd123..." required>
              <div class="form-text">Type either the Subject ID or the Patient ID; we’ll link it correctly.</div>
            </div>

            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Medication name</label>
                <input type="text" name="medication_name" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Dosage per day</label>
                <input type="number" step="0.001" name="dosage_per_day" class="form-control" placeholder="e.g. 200">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-4">
                <label class="form-label">Duration (days)</label>
                <input type="number" name="duration_days" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Cumulative dosage</label>
                <input type="number" step="0.001" name="cumulative_dosage" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Start date</label>
                <input type="date" name="start_date" class="form-control">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-4">
                <label class="form-label">End date</label>
                <input type="date" name="end_date" class="form-control">
              </div>
              <div class="col-md-8">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional">
              </div>
            </div>

            <div class="mt-4 d-flex gap-2">
              <button class="btn btn-primary"><i class="bi bi-check"></i> Save</button>
              <a class="btn btn-outline-secondary" href="index.php#patients">Cancel</a>
            </div>
          </form>

        </div>
      </div>

      <div class="text-muted small mt-3">
        Tip: after saving, you’ll see the medication listed under the patient in <em>index.php</em>.
      </div>
    </div>
  </div>
</div>

<!-- Icons (optional) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



