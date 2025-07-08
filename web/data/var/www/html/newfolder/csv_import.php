<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Updated CSV file path
$csvFilePath = "/var/www/html/data/Patient Info Master 1(Retrospective Data).csv";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize results
$results = [
    'patients' => 0,
    'visits' => 0,
    'faf_images' => 0,
    'oct_images' => 0,
    'vf_images' => 0,
    'mferg_images' => 0,
    'errors' => []
];

try {
    // Verify file exists and is readable
    if (!file_exists($csvFilePath)) {
        throw new Exception("CSV file not found at: $csvFilePath");
    }
    if (!is_readable($csvFilePath)) {
        throw new Exception("CSV file is not readable. Check permissions.");
    }

    // Open CSV file
    if (($handle = fopen($csvFilePath, "r")) === FALSE) {
        throw new Exception("Could not open CSV file");
    }

    // Start transaction
    $conn->begin_transaction();

    // Skip header row if exists (uncomment if needed)
    // fgetcsv($handle);
    
    $lineNumber = 0;
    
    while (($data = fgetcsv($handle)) !== FALSE) {
        $lineNumber++;
        
        try {
            // Skip empty rows
            if (count(array_filter($data)) === 0) continue;
            
            // Validate minimum columns
            if (count($data) < 13) {
                throw new Exception("Row has only " . count($data) . " columns (minimum 13 required)");
            }

            // Clean data - trim whitespace and convert empty strings to NULL
            $data = array_map(function($value) {
                $value = trim($value);
                return ($value === '') ? null : $value;
            }, $data);

            // Insert Patient
            $patientId = insertPatient($conn, $data);
            $results['patients']++;
            
            // Insert Visit if visit_date exists (column 13)
            if (!empty($data[13])) {
                $visitId = insertVisit($conn, $patientId, $data);
                $results['visits']++;
                
                // Process image references
                processImageReferences($conn, $patientId, $visitId, $data, $results);
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Line $lineNumber: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    // Commit or rollback
    if (empty($results['errors'])) {
        $conn->commit();
        $message = "Successfully imported {$results['patients']} patients and {$results['visits']} visits";
        $messageClass = 'success';
    } else {
        $conn->rollback();
        $message = "Completed with " . count($results['errors']) . " errors. No data was imported.";
        $messageClass = 'error';
    }
    
} catch (Exception $e) {
    $message = "Fatal error: " . $e->getMessage();
    $messageClass = 'error';
    if (isset($conn) && method_exists($conn, 'rollback')) {
        $conn->rollback();
    }
}

// Database functions
function insertPatient($conn, $data) {
    $stmt = $conn->prepare("
        INSERT INTO Patients (
            location, disease_id, year_of_birth, gender, referring_doctor,
            rx_OD, rx_OS, procedures_done, dosage, duration,
            cumulative_dosage, date_of_discontinuation, extra_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Convert empty strings to NULL for numeric/date fields
    $data[1] = ($data[1] === null) ? null : (int)$data[1]; // disease_id
    $data[2] = ($data[2] === null) ? null : (int)$data[2]; // year_of_birth
    $data[5] = ($data[5] === null) ? null : (float)$data[5]; // rx_OD
    $data[6] = ($data[6] === null) ? null : (float)$data[6]; // rx_OS
    $data[8] = ($data[8] === null) ? null : (float)$data[8]; // dosage
    $data[9] = ($data[9] === null) ? null : (int)$data[9]; // duration
    $data[10] = ($data[10] === null) ? null : (float)$data[10]; // cumulative_dosage
    
    $stmt->bind_param(
        "siissdddsddss",
        $data[0],  // location
        $data[1],  // disease_id
        $data[2],  // year_of_birth
        $data[3],  // gender
        $data[4],  // referring_doctor
        $data[5],  // rx_OD
        $data[6],  // rx_OS
        $data[7],  // procedures_done
        $data[8],  // dosage
        $data[9],  // duration
        $data[10], // cumulative_dosage
        $data[11], // date_of_discontinuation
        $data[12]  // extra_notes
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
    
    return $conn->insert_id;
}

function insertVisit($conn, $patientId, $data) {
    $stmt = $conn->prepare("
        INSERT INTO Visits (
            patient_id, visit_date, visit_notes,
            faf_reference_OD, faf_reference_OS,
            oct_reference_OD, oct_reference_OS,
            vf_reference_OD, vf_reference_OS,
            mferg_reference_OD, mferg_reference_OS,
            merci_rating_left_eye, merci_rating_right_eye
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Convert empty strings to NULL
    $data = array_map(function($value) {
        return ($value === null || $value === '') ? null : $value;
    }, $data);
    
    // Convert numeric fields
    $data[23] = ($data[23] === null) ? null : (int)$data[23]; // merci_rating_left_eye
    $data[24] = ($data[24] === null) ? null : (int)$data[24]; // merci_rating_right_eye
    
    $stmt->bind_param(
        "issssssssssss",
        $patientId,
        $data[13], // visit_date
        $data[14], // visit_notes
        $data[15], // faf_reference_OD
        $data[16], // faf_reference_OS
        $data[17], // oct_reference_OD
        $data[18], // oct_reference_OS
        $data[19], // vf_reference_OD
        $data[20], // vf_reference_OS
        $data[21], // mferg_reference_OD
        $data[22], // mferg_reference_OS
        $data[23], // merci_rating_left_eye
        $data[24]  // merci_rating_right_eye
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Visit insert failed: " . $stmt->error);
    }
    
    return $conn->insert_id;
}

function processImageReferences($conn, $patientId, $visitId, $data, &$results) {
    // Process FAF images
    processImageType($conn, 'FAF_Images', $data[15], $patientId, 'OD', $results['faf_images']);
    processImageType($conn, 'FAF_Images', $data[16], $patientId, 'OS', $results['faf_images']);
    
    // Process OCT images
    processImageType($conn, 'OCT_Images', $data[17], $patientId, 'OD', $results['oct_images']);
    processImageType($conn, 'OCT_Images', $data[18], $patientId, 'OS', $results['oct_images']);
    
    // Process VF images
    processImageType($conn, 'VF_Images', $data[19], $patientId, 'OD', $results['vf_images']);
    processImageType($conn, 'VF_Images', $data[20], $patientId, 'OS', $results['vf_images']);
    
    // Process MFERG images
    processImageType($conn, 'MFERG_Images', $data[21], $patientId, 'OD', $results['mferg_images']);
    processImageType($conn, 'MFERG_Images', $data[22], $patientId, 'OS', $results['mferg_images']);
}

function processImageType($conn, $table, $reference, $patientId, $eyeSide, &$counter) {
    if (empty($reference)) return;
    
    $stmt = $conn->prepare("
        INSERT INTO $table (reference, patient_id, eye_side, score) 
        VALUES (?, ?, ?, 0.0)
        ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id)
    ");
    
    $stmt->bind_param("sis", $reference, $patientId, $eyeSide);
    
    if ($stmt->execute()) {
        $counter++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Import Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .results { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .success { color: #28a745; border-left: 4px solid #28a745; padding-left: 10px; }
        .error { color: #dc3545; border-left: 4px solid #dc3545; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .error-list { max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>CSV Import Results</h1>
        <p>File processed: <?= htmlspecialchars($csvFilePath) ?></p>
        
        <div class="results">
            <h2 class="<?= $messageClass ?>"><?= $message ?></h2>
            
            <table>
                <tr>
                    <th>Patients Imported</th>
                    <td><?= $results['patients'] ?></td>
                </tr>
                <tr>
                    <th>Visits Imported</th>
                    <td><?= $results['visits'] ?></td>
                </tr>
                <tr>
                    <th>FAF Images Processed</th>
                    <td><?= $results['faf_images'] ?></td>
                </tr>
                <tr>
                    <th>OCT Images Processed</th>
                    <td><?= $results['oct_images'] ?></td>
                </tr>
                <tr>
                    <th>VF Images Processed</th>
                    <td><?= $results['vf_images'] ?></td>
                </tr>
                <tr>
                    <th>MFERG Images Processed</th>
                    <td><?= $results['mferg_images'] ?></td>
                </tr>
            </table>
            
            <?php if (!empty($results['errors'])): ?>
                <h3>Errors Encountered:</h3>
                <div class="error-list">
                    <?php foreach ($results['errors'] as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <p><a href="index.php">Return to Dashboard</a></p>
    </div>
</body>
</html>
