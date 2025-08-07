<?php
// csv_import.php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $csvFile = $_FILES['csv_file']['tmp_name'];
    if (!is_uploaded_file($csvFile)) {
        die("Upload failed.");
    }

    $handle = fopen($csvFile, 'r');
    if ($handle === false) {
        die("Failed to open uploaded file.");
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        die("Empty CSV file.");
    }

    // Map headers to lowercase keys for flexibility
    $headers = array_map('strtolower', $headers);

    // Required columns for patient and test
    $requiredColumns = [
        'patient_id', 'subject_id', 'date_of_birth',
        'test_id', 'location', 'date_of_test', 'age', 'eye',
        'report_diagnosis', 'exclusion', 'merci_score', 'merci_diagnosis', 'error_type',
        'faf_grade', 'oct_score', 'vf_score', 'actual_diagnosis',
        'medication_name', 'dosage', 'dosage_unit', 'duration_days',
        'cumulative_dosage', 'date_of_continuation', 'treatment_notes'
    ];

    // Check if all required columns exist
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $headers)) {
            die("Missing required column: $col");
        }
    }

    // Statistics counters
    $stats = [
        'patients_created' => 0,
        'tests_inserted' => 0,
        'tests_updated' => 0,
        'errors' => 0,
        'error_messages' => [],
    ];

    $conn->begin_transaction();

    try {
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) != count($headers)) {
                $stats['errors']++;
                $stats['error_messages'][] = "Row has wrong number of columns.";
                continue;
            }

            // Combine headers and row to associative array
            $data = array_combine($headers, $row);

            // Trim and normalize all data
            foreach ($data as $k => $v) {
                $data[$k] = trim($v);
                if ($data[$k] === '') {
                    $data[$k] = null;
                }
            }

            // Validate required patient fields
            if (empty($data['patient_id']) || empty($data['subject_id']) || empty($data['date_of_birth'])) {
                $stats['errors']++;
                $stats['error_messages'][] = "Missing patient fields in row.";
                continue;
            }

            // Validate test_id, location, date_of_test, eye mandatory for test
            if (empty($data['test_id']) || empty($data['location']) || empty($data['date_of_test']) || empty($data['eye'])) {
                $stats['errors']++;
                $stats['error_messages'][] = "Missing test fields in row.";
                continue;
            }

            // Create or get patient
            try {
                $patient_id = getOrCreatePatient(
                    $conn,
                    $data['patient_id'],
                    $data['subject_id'],
                    $data['date_of_birth'],
                    $data['location'],
                    $stats
                );
            } catch (Exception $e) {
                $stats['errors']++;
                $stats['error_messages'][] = "Patient error: " . $e->getMessage();
                continue;
            }

            // Prepare test data with type normalization
            $testData = [
                'test_id'            => $data['test_id'],
                'patient_id'         => $patient_id,
                'location'           => $data['location'],
                'date_of_test'       => $data['date_of_test'],
                'age'                => is_numeric($data['age']) ? (int)$data['age'] : null,
                'eye'                => $data['eye'],
                'report_diagnosis'   => $data['report_diagnosis'] ?? '',
                'exclusion'          => $data['exclusion'] ?? '',
                'merci_score'        => $data['merci_score'] === 'unable' ? 'unable' : (is_numeric($data['merci_score']) ? $data['merci_score'] : null),
                'merci_diagnosis'    => $data['merci_diagnosis'] ?? '',
                'error_type'         => $data['error_type'] ?? '',
                'faf_grade'          => is_numeric($data['faf_grade']) ? (int)$data['faf_grade'] : null,
                'oct_score'          => is_numeric($data['oct_score']) ? (float)$data['oct_score'] : null,
                'vf_score'           => is_numeric($data['vf_score']) ? (float)$data['vf_score'] : null,
                'actual_diagnosis'   => $data['actual_diagnosis'] ?? '',
                'medication_name'    => $data['medication_name'] ?? null,
                'dosage'             => is_numeric($data['dosage']) ? (float)$data['dosage'] : null,
                'dosage_unit'        => $data['dosage_unit'] ?? null,
                'duration_days'      => is_numeric($data['duration_days']) ? (int)$data['duration_days'] : null,
                'cumulative_dosage'  => is_numeric($data['cumulative_dosage']) ? (float)$data['cumulative_dosage'] : null,
                'date_of_continuation' => $data['date_of_continuation'] ?? null,
                'treatment_notes'    => $data['treatment_notes'] ?? null,
            ];

            // Check if test already exists (for stats)
            $exists = checkDuplicateTest($patient_id, $testData['date_of_test'], $testData['eye']);

            // Insert or update test
            try {
                insertTest($testData);
                if ($exists) {
                    $stats['tests_updated']++;
                } else {
                    $stats['tests_inserted']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $stats['error_messages'][] = "Test insert error (Test ID: {$testData['test_id']}): " . $e->getMessage();
                continue;
            }
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        die("Transaction failed: " . $e->getMessage());
    } finally {
        fclose($handle);
    }

    // Output summary
    echo "<h2>CSV Import Summary</h2>";
    echo "<ul>";
    echo "<li>Patients created: " . $stats['patients_created'] . "</li>";
    echo "<li>Tests inserted: " . $stats['tests_inserted'] . "</li>";
    echo "<li>Tests updated: " . $stats['tests_updated'] . "</li>";
    echo "<li>Errors: " . $stats['errors'] . "</li>";
    echo "</ul>";

    if ($stats['errors'] > 0) {
        echo "<h3>Error Details:</h3><ul>";
        foreach ($stats['error_messages'] as $msg) {
            echo "<li>" . htmlspecialchars($msg) . "</li>";
        }
        echo "</ul>";
    }

    echo '<p><a href="csv_import.php">Import another CSV</a></p>';

} else {
    // Show upload form if not POST
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <title>CSV Import</title>
    </head>
    <body>
        <h1>Upload CSV File for Import</h1>
        <form method="POST" enctype="multipart/form-data" action="csv_import.php">
            <input type="file" name="csv_file" accept=".csv" required />
            <button type="submit">Import CSV</button>
        </form>
        <p>Make sure your CSV columns include:<br>
            <strong>patient_id, subject_id, date_of_birth, test_id, location, date_of_test, age, eye, report_diagnosis, exclusion, merci_score, merci_diagnosis, error_type, faf_grade, oct_score, vf_score, actual_diagnosis, medication_name, dosage, dosage_unit, duration_days, cumulative_dosage, date_of_continuation, treatment_notes</strong>
        </p>
    </body>
    </html>

    <?php
}
