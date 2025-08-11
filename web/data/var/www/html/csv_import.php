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

                // Skip header
                if ($lineNumber === 1) continue;

                // Validate columns
                if (count($data) < 19) {
                    $results['errors'][] = "Line $lineNumber: Skipped - Insufficient columns (expected >=19, found " . count($data) . ")";
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

                    $dob = DateTime::createFromFormat('m/d/Y', $data[1] ?? '');
                    if (!$dob) throw new Exception("Invalid DOB format (MM/DD/YYYY)");
                    $dobFormatted = $dob->format('Y-m-d');

                    $testDate = DateTime::createFromFormat('m/d/Y', $data[2] ?? '');
                    if (!$testDate) throw new Exception("Invalid test date format (MM/DD/YYYY)");
                    $testDateFormatted = $testDate->format('Y-m-d');

                    $age = (isset($data[3]) && is_numeric($data[3]) && $data[3] >= 0 && $data[3] <= 120) ? (int)$data[3] : null;

                    // ================= TEST DATA =================
                    $testId = $data[4] ?? null;
                    if (empty($testId)) {
                        $testId = 'TEST_' . $subjectId . '_' . $testDate->format('Ymd') . '_' . bin2hex(random_bytes(2));
                    } else {
                        $testId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $testId);
                    }

                    $eye = strtoupper(trim($data[5] ?? ''));
                    $validEyes = ['OD', 'OS'];
                    if (!in_array($eye, $validEyes)) {
                        $results['warnings'][] = "Line $lineNumber: Invalid eye '$eye' - skipping eye insert";
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

                    $fafGrade = (isset($data[11]) && is_numeric($data[11]) && $data[11] >= 1 && $data[11] <= 4) ? (int)$data[11] : null;
                    $octScore = (isset($data[12]) && is_numeric($data[12])) ? round((float)$data[12], 2) : null;
                    $vfScore = (isset($data[13]) && is_numeric($data[13])) ? round((float)$data[13], 2) : null;

                    // ================= DIAGNOSIS & MEDICATION =================
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

                    // FIXED VARIABLE NAME
                    $date_of_continuation = trim($data[18] ?? '');
                    if ($date_of_continuation === '') $date_of_continuation = null;

                    // ================= DATABASE OPS =================
                    $location = 'KH';
                    $patientId = generatePatientId($subjectId);
                    $patientId = getOrCreatePatient($conn, $patientId, $subjectId, $location, $dobFormatted);
                    $results['patients_processed']++;

                    // Insert test if not already exists
                    if (!testExists($conn, $testId)) {
                        insertTest($conn, $testId, $patientId, $location, $testDateFormatted);
                        $results['tests_processed']++;
                    }

                    // Insert test eye
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
                    continue;
                }
            }

            fclose($handle);
            $conn->commit();

            // RESULTS OUTPUT
            echo "<div class='results'>";
            echo "<h2>CSV Import Results</h2>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($filename) . "</p>";
            echo "<p><strong>Total rows processed:</strong> {$results['total_rows']}</p>";
            echo "<p><strong>Patients processed:</strong> {$results['patients_processed']}</p>";
            echo "<p><strong>Tests processed:</strong> {$results['tests_processed']}</p>";
            echo "<p><strong>Eyes processed:</strong> {$results['eyes_processed']}</p>";

            if (!empty($results['warnings'])) {
                echo "<h3>Warnings:</h3><ul>";
                foreach ($results['warnings'] as $w) echo "<li>" . htmlspecialchars($w) . "</li>";
                echo "</ul>";
            }

            if (!empty($results['errors'])) {
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
}
?>
