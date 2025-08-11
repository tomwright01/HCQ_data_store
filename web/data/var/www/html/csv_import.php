<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    header('Content-Type: text/html; charset=utf-8');

    $file = $_FILES['csv_file']['tmp_name'];
    $filename = $_FILES['csv_file']['name'];

    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        die("<div class='error'>Error uploading file: " . $_FILES['csv_file']['error'] . "</div>");
    }

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

    // Diagnosis mapping to ensure case consistency
    $diagnosisMap = [
        'ra' => 'RA',
        'sle' => 'SLE',
        'sjogren' => 'SJOGREN',
        'other' => 'OTHER'
    ];

    try {
        $conn->begin_transaction();

        if (($handle = fopen($file, 'r')) {
            $lineNumber = 0;

            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) {
                $lineNumber++;
                $results['total_rows']++;

                // Skip header row if present
                if ($lineNumber === 1 && !is_numeric($data[0])) {
                    continue;
                }

                // Normalize data
                $data = array_map('trim', $data);
                $data = array_map(function ($value) {
                    return in_array(strtolower($value), ['null', 'no value', 'missing', '']) ? null : $value;
                }, $data);

                try {
                    // Process patient data
                    $patientId = $data[0] ?? null;
                    if (empty($patientId)) {
                        throw new Exception("Missing patient ID on line $lineNumber");
                    }

                    // Handle date of birth with multiple formats
                    $dob = null;
                    foreach (['m/d/Y', 'n/j/Y', 'm-d-Y', 'Y-m-d'] as $format) {
                        $dob = DateTime::createFromFormat($format, $data[1] ?? '');
                        if ($dob !== false) break;
                    }

                    if ($dob === false) {
                        throw new Exception("Invalid date format on line $lineNumber. Received: '".($data[1] ?? '')."'");
                    }

                    $dobFormatted = $dob->format('Y-m-d');

                    // Insert or retrieve patient
                    $patientId = getOrCreatePatient($conn, $patientId, $patientId, 'KH', $dobFormatted);
                    $results['patients_processed']++;

                    // Process test data
                    $testId = $data[4] ?? null;
                    if (empty($testId)) {
                        $testId = 'TEST_' . $patientId . '_' . $dob->format('Ymd') . '_' . bin2hex(random_bytes(2));
                    }

                    // Handle test date
                    $testDate = null;
                    foreach (['m/d/Y', 'n/j/Y', 'm-d-Y', 'Y-m-d'] as $format) {
                        $testDate = DateTime::createFromFormat($format, $data[2] ?? '');
                        if ($testDate !== false) break;
                    }

                    if ($testDate === false) {
                        throw new Exception("Invalid test date format on line $lineNumber");
                    }
                    $testDateFormatted = $testDate->format('Y-m-d');

                    // Calculate age if not provided
                    $age = isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120 
                        ? (int)$data[3] 
                        : $testDate->diff($dob)->y;

                    // Insert test record
                    insertTest($conn, $testId, $patientId, 'KH', $testDateFormatted);
                    $results['tests_processed']++;

                    // Process diagnosis with case handling
                    $rawDiagnosis = strtolower(trim($data[14] ?? 'other'));
                    $actualDiagnosis = $diagnosisMap[$rawDiagnosis] ?? 'OTHER';

                    // Process eye data
                    $eyes = ['OD', 'OS'];
                    foreach ($eyes as $eye) {
                        insertTestEye(
                            $conn,
                            $testId,
                            $eye,
                            $age,
                            strtolower($data[6] ?? 'no input'),
                            strtolower($data[7] ?? 'none'),
                            isset($data[8]) ? (strtolower($data[8]) === 'unable' ? 'unable' : (is_numeric($data[8]) ? (int)$data[8] : null)) : null,
                            strtolower($data[9] ?? 'no value'),
                            strtoupper($data[10] ?? 'none'),
                            isset($data[11]) && is_numeric($data[11]) ? (int)$data[11] : null,
                            isset($data[12]) && is_numeric($data[12]) ? round((float)$data[12], 2) : null,
                            isset($data[13]) && is_numeric($data[13]) ? round((float)$data[13], 2) : null,
                            $actualDiagnosis, // This is now properly formatted
                            isset($data[15]) && is_numeric($data[15]) ? round(floatval($data[15]), 2) : null,
                            isset($data[16]) && is_numeric($data[16]) ? (int)$data[16] : null,
                            isset($data[17]) && is_numeric($data[17]) ? round(floatval($data[17]), 2) : null,
                            trim($data[18] ?? '')
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
                    <li>First row should be headers (will be automatically skipped)</li>
                    <li>Date formats accepted: MM/DD/YYYY, M/D/YYYY, MM-DD-YYYY, YYYY-MM-DD</li>
                    <li>Diagnosis values: RA, SLE, Sjogren, Other (case insensitive)</li>
                    <li>Required columns: PatientID, DateOfBirth, TestDate</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php
}


