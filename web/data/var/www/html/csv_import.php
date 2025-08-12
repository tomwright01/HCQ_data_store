<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    header('Content-Type: text/html; charset=utf-8');

    $file = $_FILES['csv_file']['tmp_name'];
    $filename = $_FILES['csv_file']['name'];

    if (!file_exists($file)) {
        die("<div class='error'>Error: File not found.</div>");
    }

    $results = [
        'total_rows' => 0,
        'patients_processed' => 0,
        'tests_processed' => 0,
        'eyes_processed' => 0,
        'errors' => [],
        'warnings' => []
    ];

    try {
        $conn->begin_transaction();

        if (($handle = fopen($file, 'r')) !== false) {
            $lineNumber = 0;

            while (($data = fgetcsv($handle)) !== false) {
                $lineNumber++;
                $results['total_rows']++;

                if ($lineNumber === 1) continue; // header

                // require at least the columns we read below (0..18)
                if (count($data) < 19) {
                    $results['errors'][] = "Line $lineNumber: Skipped - Insufficient columns";
                    continue;
                }

                // Normalize
                $data = array_map(fn($v) => is_string($v) ? trim($v) : $v, $data);
                $nullish = ['null','no value','missing',''];
                $data = array_map(function($v) use ($nullish) {
                    $s = strtolower((string)($v ?? ''));
                    return in_array($s, $nullish, true) ? null : $v;
                }, $data);

                try {
                    // Patient fields
                    $subjectId = $data[0] ?? null;
                    if (!$subjectId) throw new Exception("Missing subject ID");

                    $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                    if (!$dob) throw new Exception("Invalid DOB format");
                    $dobFormatted = $dob->format('Y-m-d');

                    $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                    if (!$testDate) throw new Exception("Invalid test date format");
                    $testDateFormatted = $testDate->format('Y-m-d');

                    $age = (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) ? (int)$data[3] : null;

                    // Test ID (Option B uses BIGINT). If missing or duplicate, generate numeric.
                    $rawTestId = $data[4] ?? null;
                    $testId = is_numeric($rawTestId) ? (int)$rawTestId : null;
                    if ($testId === null || testExists($conn, $testId)) {
                        $testId = generateNumericTestId($conn, $subjectId, $testDate);
                    }

                    // Eye
                    $eye = strtoupper(trim($data[5] ?? ''));
                    if (!in_array($eye, ['OD','OS'], true)) {
                        $results['warnings'][] = "Line $lineNumber: Invalid eye '$eye' - skipping eye insert";
                        $eye = null;
                    }

                    // Diagnosis + metrics (match ENUMs / INTs)
                    $reportDiagnosis = strtolower($data[6] ?? 'no input');
                    if (!in_array($reportDiagnosis, ['normal','abnormal','exclude','no input'], true)) $reportDiagnosis = 'no input';

                    $exclusion = strtolower($data[7] ?? 'none');
                    $allowedExclusions = ['none','retinal detachment','generalized retinal dysfunction','unilateral testing'];
                    if (!in_array($exclusion, $allowedExclusions, true)) $exclusion = 'none';

                    // merci_score INT (treat 'unable' as NULL)
                    $merciScore = null;
                    if (isset($data[8])) {
                        if (is_numeric($data[8])) {
                            $v = (int)$data[8];
                            if ($v >= 0 && $v <= 100) $merciScore = $v;
                        }
                    }

                    $merciDiagnosis = strtolower($data[9] ?? 'no value');
                    if (!in_array($merciDiagnosis, ['normal','abnormal','no value'], true)) $merciDiagnosis = 'no value';

                    $errorType = strtoupper($data[10] ?? 'none');
                    if (!in_array($errorType, ['TN','FP','TP','FN','NONE'], true)) $errorType = 'none';
                    if ($errorType === 'NONE') $errorType = 'none';

                    $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;

                    // INTs in schema
                    $octScore = (isset($data[12]) && is_numeric($data[12])) ? (int)round((float)$data[12]) : null;
                    $vfScore  = (isset($data[13]) && is_numeric($data[13])) ? (int)round((float)$data[13])  : null;

                    // actual_diagnosis enum in lowercase
                    $diagnosisInput = strtolower(trim($data[14] ?? ''));
                    $actualDiagnosis = in_array($diagnosisInput, ['ra','sle','sjogren','other'], true) ? $diagnosisInput : 'other';

                    // We removed dosage/duration/cumulative from schema, so ignore cols [15],[16],[17]
                    $date_of_continuation = null;
                    if (!empty($data[18])) {
                        $dtc = DateTime::createFromFormat('m/d/Y', $data[18]);
                        if ($dtc) $date_of_continuation = $dtc->format('Y-m-d');
                    }

                    // DB ops
                    $location = 'KH'; // or map from file if you have a column
                    $patientId = generatePatientId($subjectId);
                    $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $location, $dobFormatted);
                    $results['patients_processed']++;

                    if (!testExists($conn, $testId)) {
                        insertTest($conn, $testId, $patientId, $location, $testDateFormatted);
                        $results['tests_processed']++;
                    }

                    if ($eye) {
                        insertTestEye(
                            $conn,
                            $testId,
                            $eye,
                            $age,
                            $reportDiagnosis,
                            $exclusion,
                            $merciScore,
                            $merciDiagnosis,
                            $errorType,
                            $fafGrade,
                            $octScore,
                            $vfScore,
                            $actualDiagnosis,
                            $date_of_continuation,
                            /* treatment_notes & references you don't have in CSV: */ null, null, null, null, null
                        );
                        $results['eyes_processed']++;
                    }

                } catch (Exception $e) {
                    $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                }
            }

            fclose($handle);
            $conn->commit();

            // Output
            echo "<div class='results'>";
            echo "<h2>CSV Import Results</h2>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($filename) . "</p>";
            echo "<p><strong>Total rows processed:</strong> {$results['total_rows']}</p>";
            echo "<p><strong>Patients processed:</strong> {$results['patients_processed']}</p>";
            echo "<p><strong>Tests processed:</strong> {$results['tests_processed']}</p>";
            echo "<p><strong>Eyes processed:</strong> {$results['eyes_processed']}</p>";

            if ($results['warnings']) {
                echo "<h3>Warnings:</h3><ul>";
                foreach ($results['warnings'] as $w) echo "<li>" . htmlspecialchars($w) . "</li>";
                echo "</ul>";
            }

            if ($results['errors']) {
                echo "<h3>Errors:</h3><ul>";
                foreach ($results['errors'] as $err) echo "<li>" . htmlspecialchars($err) . "</li>";
                echo "</ul>";
            }

            echo "</div>";

        } else {
            throw new Exception("Could not open CSV file");
        }

    } catch (Exception $e) {
        $conn->rollback();
        die("<div class='error'>Import failed: " . htmlspecialchars($e->getMessage()) . "</div>");
    }

} else {
    // Upload form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Import Patient Data</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 800px; margin: auto; }
            .form-group { margin-bottom: 15px; }
            label { display: block; font-weight: bold; margin-bottom: 5px; }
            input[type="file"] { padding: 8px; border: 1px solid #ddd; }
            button { padding: 10px 15px; background: #007bff; color: white; border: none; cursor: pointer; }
            button:hover { background: #0056b3; }
            .error { color: #dc3545; margin: 10px 0; padding: 10px; background: #f8d7da; }
            .results { margin-top: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Import Patient Data from CSV</h1>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="csv_file">CSV File:</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                </div>
                <button type="submit">Import Data</button>
            </form>
            <div class="instructions">
                <h3>CSV Format Requirements:</h3>
                <ul>
                    <li>First row headers (skipped)</li>
                    <li>Dates: MM/DD/YYYY</li>
                    <li>Eye: OD or OS</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php
}
