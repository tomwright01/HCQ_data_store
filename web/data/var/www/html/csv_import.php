<?php 
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Helper: check if test already exists
function testExists($conn, $testId) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tests WHERE test_id = ?");
    $stmt->bind_param("s", $testId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

// Helper: generate a unique test ID
function generateUniqueTestId($conn, $subjectId, $testDate) {
    do {
        $newTestId = 'TEST_' . $subjectId . '_' . $testDate->format('Ymd') . '_' . bin2hex(random_bytes(2));
    } while (testExists($conn, $newTestId));
    return $newTestId;
}

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

                if ($lineNumber === 1) continue; // Skip header

                if (count($data) < 19) {
                    $results['errors'][] = "Line $lineNumber: Skipped - Insufficient columns";
                    continue;
                }

                // Clean & normalize
                $data = array_map('trim', $data);
                $data = array_map(function ($value) {
                    return in_array(strtolower($value ?? ''), ['null', 'no value', 'missing', '']) ? null : trim($value ?? '');
                }, $data);

                try {
                    // Patient data
                    $subjectId = $data[0] ?? null;
                    if (!$subjectId) throw new Exception("Missing subject ID");

                    $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                    if (!$dob) throw new Exception("Invalid DOB format");
                    $dobFormatted = $dob->format('Y-m-d');

                    $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                    if (!$testDate) throw new Exception("Invalid test date format");
                    $testDateFormatted = $testDate->format('Y-m-d');

                    $age = (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) ? (int)$data[3] : null;

                    // Test info
                    $testId = $data[4] ?? null;
                    $testId = $testId ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $testId) : null;

                    // Generate a new testId if missing OR already exists
                    if (!$testId || testExists($conn, $testId)) {
                        $testId = generateUniqueTestId($conn, $subjectId, $testDate);
                    }

                    $eye = strtoupper(trim($data[5] ?? ''));
                    if (!in_array($eye, ['OD', 'OS'])) {
                        $results['warnings'][] = "Line $lineNumber: Invalid eye '$eye' - skipping eye insert";
                        $eye = null;
                    }

                    // Diagnosis data
                    $reportDiagnosis = strtolower($data[6] ?? 'no input');
                    if (!in_array($reportDiagnosis, ['normal', 'abnormal', 'exclude', 'no input'])) $reportDiagnosis = 'no input';

                    $exclusion = strtolower($data[7] ?? 'none');
                    if (!in_array($exclusion, ['none', 'retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) $exclusion = 'none';

                    $merciScore = null;
                    if (isset($data[8])) {
                        if (strtolower($data[8]) === 'unable') {
                            $merciScore = 'unable';
                        } elseif (is_numeric($data[8]) && $data[8] >= 0 && $data[8] <= 100) {
                            $merciScore = (int)$data[8];
                        }
                    }

                    $merciDiagnosis = strtolower($data[9] ?? 'no value');
                    if (!in_array($merciDiagnosis, ['normal', 'abnormal', 'no value'])) $merciDiagnosis = 'no value';

                    $errorType = strtoupper($data[10] ?? 'none');
                    if (!in_array($errorType, ['TN', 'FP', 'TP', 'FN', 'NONE'])) $errorType = 'none';
                    $errorType = $errorType === 'NONE' ? 'none' : $errorType;

                    $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                    $octScore = (isset($data[12]) && is_numeric($data[12])) ? round((float)$data[12], 2) : null;
                    $vfScore = (isset($data[13]) && is_numeric($data[13])) ? round((float)$data[13], 2) : null;

                    $diagnosisInput = strtolower(trim($data[14] ?? ''));
                    switch ($diagnosisInput) {
                        case 'ra': $actualDiagnosis = 'RA'; break;
                        case 'sle': $actualDiagnosis = 'SLE'; break;
                        case 'sjogren': $actualDiagnosis = 'Sjogren'; break;
                        default: $actualDiagnosis = 'other'; break;
                    }

                    $dosage = is_numeric($data[15]) ? round(floatval($data[15]), 2) : null;
                    $durationDays = is_numeric($data[16]) ? (int)$data[16] : null;
                    $cumulativeDosage = is_numeric($data[17]) ? round(floatval($data[17]), 2) : null;

                    $date_of_continuation = trim($data[18] ?? '');
                    if ($date_of_continuation === '') $date_of_continuation = null;

                    // DB operations
                    $location = 'KH';
                    $patientId = generatePatientId($subjectId);
                    $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $location, $dobFormatted);
                    $results['patients_processed']++;

                    if (!testExists($conn, $testId)) {
                        insertTest($conn, $testId, $patientId, $location, $testDateFormatted);
                        $results['tests_processed']++;
                    }

                    if ($eye) {
                        try {
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
                                null,
                                $dosage,
                                'mg',
                                $durationDays,
                                $cumulativeDosage,
                                $date_of_continuation,
                                null
                            );
                            $results['eyes_processed']++;
                        } catch (Exception $ex) {
                            $results['errors'][] = "Line $lineNumber: Failed to insert test eye for TestID $testId Eye $eye - " . $ex->getMessage();
                        }
                    }

                } catch (Exception $e) {
                    $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                }
            }

            fclose($handle);
            $conn->commit();

            // Results
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
    // HTML form for CSV upload
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
                    <li>First row should be headers (will be skipped)</li>
                    <li>Date format: MM/DD/YYYY</li>
                    <li>Each row should have a valid eye value (OD or OS)</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
