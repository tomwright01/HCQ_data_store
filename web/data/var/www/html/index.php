<?php
require_once 'config.php';

$result = $conn->query("SELECT patient_id, subject_id, location, date_of_birth FROM patients ORDER BY subject_id");

if (!$result) {
    die("Database query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Patients List</title>
<style>
    body { font-family: Arial, sans-serif; margin: 40px; }
    table { border-collapse: collapse; width: 100%; max-width: 700px; }
    th, td { padding: 8px 12px; border: 1px solid #ccc; }
    th { background: #eee; }
</style>
</head>
<body>
<h1>Patients</h1>
<table>
    <thead>
        <tr>
            <th>Patient ID</th>
            <th>Subject ID</th>
            <th>Location</th>
            <th>Date of Birth</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['patient_id']) ?></td>
            <td><?= htmlspecialchars($row['subject_id']) ?></td>
            <td><?= htmlspecialchars($row['location']) ?></td>
            <td><?= htmlspecialchars($row['date_of_birth']) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<p><a href="csv_import.php">Import new CSV data</a></p>
</body>
</html>
