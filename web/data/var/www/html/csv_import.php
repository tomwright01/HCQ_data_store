<?php
// csv_import.php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (!is_uploaded_file($file)) {
        die("No file uploaded.");
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        die("Failed to open uploaded file.");
    }

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        die("Empty CSV or invalid format.");
    }

    $results = ['patients' => 0, 'tests' => 0];

    while (($row = fgetcsv($handle)) !== false) {
        $rowData = array_combine($header, $row);
        if (!$rowData) {
            continue; // skip malformed row
        }

        // Extract patient info from row
        $patientId = trim($rowData['patient_id'] ?? '');
        $patientName = trim($rowData['patient_name'] ?? '');
        $dob = trim($rowData['dob'] ?? '');
        $location = trim($rowData['location'] ?? 'KH');

        if (!$patientId) {
            // skip rows without patient_id
            continue;
        }

        // Ensure patient exists
        try {
            getOrCreatePatient($conn, $patientId, $patientName, $dob, $location, $results);
        } catch (Exception $e) {
            error_log("Error inserting patient: " . $e->getMessage());
            continue;
        }

        // Prepare test data for this row (eye-specific)
        $testData = [
            'test_id' => trim($rowData['test_id'] ?? uniqid('test_')),
            'patient_id' => $patientId,
            'location' => $location,
            'date_of_test' => trim($rowData['date_of_test'] ?? ''),
            'age' => is_numeric($rowData['age'] ?? null) ? (int)$rowData['age'] : null,
            'eye' => strtoupper(trim($rowData['eye'] ?? '')),
            'report_diagnosis' => trim($rowData['report_diagnosis'] ?? ''),
            'exclusion' => trim($rowData['exclusion'] ?? ''),
            'merci_score' => $rowData['merci_score'] ?? null,
            'merci_diagnosis' => trim($rowData['merci_diagnosis'] ?? ''),
            'error_type' => trim($rowData['error_type'] ?? ''),
            'faf_grade' => is_numeric($rowData['faf_grade'] ?? null) ? (int)$rowData['faf_grade'] : null,
            'oct_score' => is_numeric($rowData['oct_score'] ?? null) ? (float)$rowData['oct_score'] : null,
            'vf_score' => is_numeric($rowData['vf_score'] ?? null) ? (float)$rowData['vf_score'] : null,
            'actual_diagnosis' => trim($rowData['actual_diagnosis'] ?? ''),
            'medication_name' => trim($rowData['medication_name'] ?? ''),
            'dosage' => is_numeric($rowData['dosage'] ?? null) ? (float)$rowData['dosage'] : null,
            'dosage_unit' => trim($rowData['dosage_unit'] ?? ''),
            'duration_days' => is_numeric($rowData['duration_days'] ?? null) ? (int)$rowData['duration_days'] : null,
            'cumulative_dosage' => is_numeric($rowData['cumulative_dosage'] ?? null) ? (float)$rowData['cumulative_dosage'] : null,
            'date_of_continuation' => trim($rowData['date_of_continuation'] ?? null),
            'treatment_notes' => trim($rowData['treatment_notes'] ?? ''),
        ];

        try {
            insertTest($testData);
            $results['tests']++;
        } catch (Exception $e) {
            error_log("Error inserting test for patient $patientId eye {$testData['eye']}: " . $e->getMessage());
        }
    }

    fclose($handle);

    echo "Import complete: {$results['patients']} patients, {$results['tests']} tests imported.";
} else {
    // Show simple upload form if accessed directly
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>CSV Import</title></head>
    <body>
        <h1>Import Test Data CSV</h1>
        <form action="" method="POST" enctype="multipart/form-data">
            <label for="csv_file">CSV File:</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
            <button type="submit">Import</button>
        </form>
    </body>
    </html>
    <?php
}
