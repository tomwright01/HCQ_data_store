<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/config.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    header('Content-Type: text/html; charset=utf-8');

    // Validate file
    $file = $_FILES['csv_file']['tmp_name'];
    $filename = basename($_FILES['csv_file']['name']);
    
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        die("<div class='error'>File upload error: " . $_FILES['csv_file']['error'] . "</div>");
    }

    if (!file_exists($file)) {
        die("<div class='error'>Temp file not found</div>");
    }

    // Configure allowed diagnosis values (must match ENUM exactly)
    $allowedDiagnosis = ['RA', 'SLE', 'SJOGREN', 'OTHER'];
    
    $results = [
        'total_rows' => 0,
        'patients_processed' => 0,
        'tests_processed' => 0,
        'eyes_processed' => 0,
        'errors' => []
    ];

    try {
        $conn->begin_transaction();
        
        if (($handle = fopen($file, 'r')) !== false) {
            $lineNumber = 0;
            
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $lineNumber++;
                $results['total_rows']++;
                
                // Skip header row if detected
                if ($lineNumber === 1 && !is_numeric(trim($data[0]))) {
                    continue;
                }

                // Normalize data
                $data = array_map('trim', $data);
                $data = array_map(function($v) {
                    return in_array(strtolower($v), ['null','na','',' ']) ? null : $v;
                }, $data);

                try {
                    // ================= PATIENT PROCESSING =================
                    $patientId = $data[0] ?? null;
                    if (empty($patientId)) {
                        throw new Exception("Missing patient ID");
                    }

                    // Date parsing with multiple format support
                    $dob = null;
                    foreach (['m/d/Y', 'n/j/Y', 'Y-m-d'] as $format) {
                        $dob = DateTime::createFromFormat($format, $data[1] ?? '');
                        if ($dob !== false) break;
                    }
                    if ($dob === false) {
                        throw new Exception("Invalid birth date format");
                    }
                    $dobFormatted = $dob->format('Y-m-d');

                    // Create/get patient
                    $patientId = getOrCreatePatient($conn, $patientId, $patientId, 'KH', $dobFormatted);
                    $results['patients_processed']++;

                    // ================= TEST PROCESSING =================
                    $testId = $data[4] ?? ('TEST_' . $patientId . '_' . time());
                    
                    // Test date parsing
                    $testDate = null;
                    foreach (['m/d/Y', 'n/j/Y', 'Y-m-d'] as $format) {
                        $testDate = DateTime::createFromFormat($format, $data[2] ?? '');
                        if ($testDate !== false) break;
                    }
                    if ($testDate === false) {
                        throw new Exception("Invalid test date format");
                    }
                    $testDateFormatted = $testDate->format('Y-m-d');

                    // Insert test record
                    insertTest($conn, $testId, $patientId, 'KH', $testDateFormatted);
                    $results['tests_processed']++;

                    // ================= EYE DATA PROCESSING =================
                    // Process diagnosis with strict validation
                    $rawDiagnosis = strtoupper($data[14] ?? 'OTHER');
                    if (!in_array($rawDiagnosis, $allowedDiagnosis)) {
                        error_log("Converted invalid diagnosis '{$data[14]}' to 'OTHER' on line $lineNumber");
                        $rawDiagnosis = 'OTHER';
                    }

                    // Calculate age if not provided
                    $age = (isset($data[3]) && is_numeric($data[3])) 
                        ? (int)$data[3] 
                        : $testDate->diff($dob)->y;

                    // Process both eyes
                    foreach (['OD', 'OS'] as $eye) {
                        insertTestEye(
                            $conn,
                            $testId,          // string
                            $eye,             // string
                            $age,             // integer
                            strtolower($data[6] ?? 'no input'),  // enum
                            strtolower($data[7] ?? 'none'),      // enum
                            is_numeric($data[8] ?? null) ? (float)$data[8] : null, // merci_score
                            strtolower($data[9] ?? 'no value'),  // enum
                            strtoupper($data[10] ?? 'none'),     // enum
                            is_numeric($data[11] ?? null) ? (int)$data[11] : null, // faf_grade
                            is_numeric($data[12] ?? null) ? (float)$data[12] : null, // oct_score
                            is_numeric($data[13] ?? null) ? (float)$data[13] : null, // vf_score
                            $rawDiagnosis,    // validated enum
                            is_numeric($data[15] ?? null) ? (float)$data[15] : null, // dosage
                            is_numeric($data[16] ?? null) ? (int)$data[16] : null,  // duration_days
                            is_numeric($data[17] ?? null) ? (float)$data[17] : null, // cumulative_dosage
                            $data[18] ?? null  // date_of_continuation
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

            // ================= OUTPUT RESULTS =================
            echo "<div class='results'><h2>Import Complete</h2>";
            echo "<p>Processed {$results['total_rows']} rows</p>";
            echo "<p>Patients: {$results['patients_processed']}</p>";
            echo "<p>Tests: {$results['tests_processed']}</p>";
            echo "<p>Eye records: {$results['eyes_processed']}</p>";

            if (!empty($results['errors'])) {
                echo "<h3>Errors:</h3><ul>";
                foreach ($results['errors'] as $error) {
                    echo "<li>" . htmlspecialchars($error) . "</li>";
                }
                echo "</ul>";
            }
            echo "</div>";

        } else {
            throw new Exception("Could not read CSV file");
        }

    } catch (Exception $e) {
        $conn->rollback();
        die("<div class='error'>IMPORT FAILED: " . htmlspecialchars($e->getMessage()) . "</div>");
    }

} else {
    // ================= UPLOAD FORM =================
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Patient Data Import</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 800px; margin: 0 auto; }
            .error { color: red; padding: 10px; border: 1px solid red; }
            .results { padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Import Patient Data</h1>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit">Import</button>
            </form>
            
            <h3>CSV Format Requirements:</h3>
            <ul>
                <li>Column 1: Patient ID (required)</li>
                <li>Column 2: Date of Birth (MM/DD/YYYY or YYYY-MM-DD)</li>
                <li>Column 15: Diagnosis (RA, SLE, Sjogren, Other - case insensitive)</li>
            </ul>
        </div>
    </body>
    </html>
    <?php
}
