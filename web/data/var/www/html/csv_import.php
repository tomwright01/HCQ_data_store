<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Debugging: Check if file was uploaded
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        die("<div class='error'>Error uploading file: " . $_FILES['csv_file']['error'] . "</div>");
    }

    // Set headers for CSV upload
    header('Content-Type: text/html; charset=utf-8');
    
    $file = $_FILES['csv_file']['tmp_name'];
    $filename = $_FILES['csv_file']['name'];

    // Debugging: Output file details
    echo "File details:<br>";
    echo "File name: $filename<br>";
    echo "File size: " . $_FILES['csv_file']['size'] . " bytes<br>";
    echo "Temporary file: $file<br>";

    if (!file_exists($file)) {
        die("<div class='error'>Error: File not found.</div>");
    }

    // Initialize results array
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
                
                // Skip header row
                if ($lineNumber === 1) continue;

                // Validate minimum columns
                if (count($data) < 18) {
                    $results['errors'][] = "Line $lineNumber: Skipped - Insufficient columns (expected 18, found " . count($data) . ")";
                    continue;
                }

                // Clean and normalize data
                $data = array_map('trim', $data);
                $data = array_map(function ($value) {
                    $value = trim($value ?? '');
                    return in_array(strtolower($value), ['null', 'no value', 'missing', '']) ? null : $value;
                }, $data);

                try {
                    // ================= PATIENT DATA =================
                    $subjectId = $data[0] ?? null;
                    if (empty($subjectId)) {
                        throw new Exception("Missing subject ID");
                    }

                    // Parse dates with validation
                    $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                    if (!$dob) {
                        throw new Exception("Invalid date of birth format (expected MM/DD/YYYY)");
                    }
                    $dobFormatted = $dob->format('Y-m-d');

                    $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                    if (!$testDate) {
                        throw new Exception("Invalid test date format (expected MM/DD/YYYY)");
                    }
                    $testDateFormatted = $testDate->format('Y-m-d');

                    // Validate age
                    $age = null;
                    if (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) {
                        $age = (int)$data[3];
                    }

                    // ================= TEST DATA =================
                    $testId = $data[4] ?? null;
                    if (empty($testId)) {
                        // Generate a test ID if not provided
                        $testId = 'TEST_' . $subjectId . '_' . $testDate->format('Ymd') . '_' . bin2hex(random_bytes(2));
                    } else {
                        $testId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $testId);
                    }

                    $eye = strtoupper($data[5] ?? '');
                    if (!in_array($eye, ['OD', 'OS'])) {
                        $results['warnings'][] = "Line $lineNumber: Invalid eye value '$eye' - must be OD or OS";
                        $eye = null;
                    }

                    // ================= DIAGNOSIS DATA =================
                    $reportDiagnosis = strtolower($data[6] ?? 'no input');
                    if (!in_array($reportDiagnosis, ['normal', 'abnormal', 'exclude', 'no input'])) {
                        $reportDiagnosis = 'no input';
                    }

                    $exclusion = strtolower($data[7] ?? 'none');
                    if (!in_array($exclusion, ['none', 'retinal detachment', 'generalized retinal dysfunction', 'unilateral testing'])) {
                        $exclusion = 'none';
                    }

                    // MERCI score handling
                    $merciScore = null;
                    if (isset($data[8])) {
                        if (strtolower($data[8]) === 'unable') {
                            $merciScore = 'unable';
                        } elseif (is_numeric($data[8]) && $data[8] >= 0 && $data[8] <= 100) {
                            $merciScore = (int)$data[8];
                        }
                    }

                    $merciDiagnosis = strtolower($data[9] ?? 'no value');
                    if (!in_array($merciDiagnosis, ['normal', 'abnormal', 'no value'])) {
                        $merciDiagnosis = 'no value';
                    }

                    $errorType = strtoupper($data[10] ?? 'none');
                    if (!in_array($errorType, ['TN', 'FP', 'TP', 'FN', 'NONE'])) {
                        $errorType = 'none';
                    }
                    $errorType = $errorType === 'NONE' ? 'none' : $errorType;

                    // ================= TEST SCORES =================
                    $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                    $octScore = (isset($data[12]) && is_numeric($data[12])) ? round((float)$data[12], 2) : null;
                    $vfScore = (isset($data[13]) && is_numeric($data[13])) ? round((float)$data[13], 2) : null;

                    // ================= DIAGNOSIS & MEDICATION =================
                    $allowedDiagnosis = ['RA','SLE','Sjogren','other'];
                    $actualDiagnosis  = 'other';            // ← default value
                    if (!empty($data[14])) {
                      $d = ucfirst(strtolower(trim($data[14]))); 
                      $actualDiagnosis = in_array($d, $allowedDiagnosis) ? $d : 'other';
                    }

                    // [14] Dosage
                    $dosage = is_numeric($data[15])
                        ? round(floatval($data[15]), 2)
                        : null;

                    // [15] Duration Days
                    $durationDays = is_numeric($data[16])
                        ? (int)$data[16]
                        : null;

                    // [16] Cumulative Dosage
                    $cumulativeDosage = is_numeric($data[17])
                        ? round(floatval($data[17]), 2)
                        : null;

                    // Date of continuation
                    $date_of_continuation = trim($data[18] ?? '');  // Get value from column 18
                    if ($date_of_continuation === '') {
                        $date_of_continuation = null;  // Store NULL if empty
                    }

                    // ================= DATABASE OPERATIONS =================
                    // Default location
                    $location = 'KH';
                    
                    // Create or get patient
                    $patientId = generatePatientId($subjectId);
                    $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $location, $dobFormatted);
                    $results['patients_processed']++;

                    // Insert test record
                    insertTest($conn, $testId, $patientId, $location, $testDateFormatted);
                    $results['tests_processed']++;

                    // Insert test eye data for both eyes (OD and OS)
                    $eyes = ['OD', 'OS'];
                    foreach ($eyes as $eye) {
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
                            null, // medication_name
                            $dosage,
                            'mg', // dosage_unit
                            $durationDays,
                            $cumulativeDosage,
                            $date_of_continuation,
                            null  // treatment_notes
                        );
                        $results['eyes_processed']++;
                    }

                } catch (Exception $e) {
                    $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
                    continue;
                }
            }

            fclose($handle);
            $conn->commit();

            // Display results
            echo "<div class='results'>";

            // Display summary
            echo "<h2>CSV Import Results</h2>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($filename) . "</p>";
            echo "<p><strong>Total rows processed:</strong> " . $results['total_rows'] . "</p>";
            echo "<p><strong>Patients processed:</strong> " . $results['patients_processed'] . "</p>";
            echo "<p><strong>Tests processed:</strong> " . $results['tests_processed'] . "</p>";
            echo "<p><strong>Eyes processed:</strong> " . $results['eyes_processed'] . "</p>";

            if (!empty($results['warnings'])) {
                echo "<h3>Warnings:</h3>";
                echo "<ul>";
                foreach ($results['warnings'] as $warning) {
                    echo "<li>" . htmlspecialchars($warning) . "</li>";
                }
                echo "</ul>";
            }

            if (!empty($results['errors'])) {
                echo "<h3>Errors:</h3>";
                echo "<ul>";
                foreach ($results['errors'] as $error) {
                    echo "<li>" . htmlspecialchars($error) . "</li>";
                }
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
    // Display upload form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Import Patient Data</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 800px; margin: 0 auto; }
            .form-group { margin-bottom: 15px; }
            label { display: block; margin-bottom: 5px; font-weight: bold; }
            input[type="file"] { padding: 8px; border: 1px solid #ddd; }
            button { padding: 10px 15px; background: #007bff; color: white; border: none; cursor: pointer; }
            button:hover { background: #0056b3; }
            .error { color: #dc3545; margin: 10px 0; padding: 10px; border: 1px solid #f5c6cb; background: #f8d7da; }
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
                    <li>Minimum 18 columns required</li>
                    <li>Date formats: MM/DD/YYYY</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
