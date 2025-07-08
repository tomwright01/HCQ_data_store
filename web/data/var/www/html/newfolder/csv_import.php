<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Process uploaded file
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    
    // Counters for results
    $patients_inserted = 0;
    $visits_inserted = 0;
    $errors = [];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        if (($handle = fopen($file, "r")) !== FALSE) {
            // Skip header row
            fgetcsv($handle);
            
            $line = 1; // Track line numbers for error reporting
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $line++;
                
                // Basic validation
                if (count($data) < 13) {
                    $errors[] = "Line $line: Insufficient columns (expected at least 13)";
                    continue;
                }
                
                // Insert Patient
                $patient_id = insertPatient($conn, $data);
                if ($patient_id) {
                    $patients_inserted++;
                    
                    // Insert Visit if visit data exists (columns 13-24)
                    if (!empty($data[13])) {
                        $visit_id = insertVisit($conn, $patient_id, $data);
                        if ($visit_id) {
                            $visits_inserted++;
                            
                            // Insert image references if they exist
                            insertImageReferences($conn, $patient_id, $visit_id, $data);
                        }
                    }
                }
            }
            fclose($handle);
            
            // Commit transaction if no errors
            if (empty($errors)) {
                $conn->commit();
                $message = "Successfully imported $patients_inserted patients and $visits_inserted visits.";
            } else {
                $conn->rollback();
                $message = "Completed with errors. No data was imported.";
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = "System error: " . $e->getMessage();
        $message = "Import failed due to system error.";
    }
}

function insertPatient($conn, $data) {
    $stmt = $conn->prepare("
        INSERT INTO Patients (
            location, disease_id, year_of_birth, gender, referring_doctor,
            rx_OD, rx_OS, procedures_done, dosage, duration,
            cumulative_dosage, date_of_discontinuation, extra_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Convert empty strings to NULL for numeric fields
    $data = array_map(function($value) {
        return $value === '' ? null : $value;
    }, $data);
    
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
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    } else {
        throw new Exception("Patient insert failed: " . $stmt->error);
    }
}

function insertVisit($conn, $patient_id, $data) {
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
    
    $stmt->bind_param(
        "issssssssssss",
        $patient_id,
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
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    } else {
        throw new Exception("Visit insert failed: " . $stmt->error);
    }
}

function insertImageReferences($conn, $patient_id, $visit_id, $data) {
    // Insert FAF images if references exist
    if (!empty($data[15])) { // faf_reference_OD
        insertImage($conn, 'FAF_Images', $data[15], $patient_id, 'OD');
    }
    if (!empty($data[16])) { // faf_reference_OS
        insertImage($conn, 'FAF_Images', $data[16], $patient_id, 'OS');
    }
    
    // Insert OCT images if references exist
    if (!empty($data[17])) { // oct_reference_OD
        insertImage($conn, 'OCT_Images', $data[17], $patient_id, 'OD');
    }
    if (!empty($data[18])) { // oct_reference_OS
        insertImage($conn, 'OCT_Images', $data[18], $patient_id, 'OS');
    }
    
    // Insert VF images if references exist
    if (!empty($data[19])) { // vf_reference_OD
        insertImage($conn, 'VF_Images', $data[19], $patient_id, 'OD');
    }
    if (!empty($data[20])) { // vf_reference_OS
        insertImage($conn, 'VF_Images', $data[20], $patient_id, 'OS');
    }
    
    // Insert MFERG images if references exist
    if (!empty($data[21])) { // mferg_reference_OD
        insertImage($conn, 'MFERG_Images', $data[21], $patient_id, 'OD');
    }
    if (!empty($data[22])) { // mferg_reference_OS
        insertImage($conn, 'MFERG_Images', $data[22], $patient_id, 'OS');
    }
}

function insertImage($conn, $table, $reference, $patient_id, $eye_side) {
    $stmt = $conn->prepare("
        INSERT INTO $table 
        (reference, patient_id, eye_side) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE patient_id = VALUES(patient_id)
    ");
    
    $stmt->bind_param("sis", $reference, $patient_id, $eye_side);
    $stmt->execute();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Data Import</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: rgb(0, 168, 143);
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="file"] {
            display: block;
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: rgb(0, 140, 120);
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background-color: #f9f9f9;
            margin-top: 10px;
        }
        .template-link {
            display: inline-block;
            margin-top: 15px;
            color: rgb(0, 168, 143);
            text-decoration: none;
        }
        .template-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>CSV Data Import</h1>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo empty($errors) ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="error-list">
                        <h3>Errors:</h3>
                        <?php foreach ($errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="csvfile">Select CSV File:</label>
                <input type="file" name="csvfile" id="csvfile" accept=".csv" required>
            </div>
            
            <button type="submit">Import Data</button>
            
            <a href="sample_import.csv" class="template-link">Download Sample CSV Template</a>
        </form>
        
        <h2>CSV File Requirements</h2>
        <p>Your CSV file must have the following columns in this exact order:</p>
        
        <ol>
            <li><strong>location</strong> (Halifax, Kensington, Montreal)</li>
            <li><strong>disease_id</strong> (1=Lupus, 2=Rheumatoid Arthritis, 3=RTMD, 4=Sjorgens)</li>
            <li><strong>year_of_birth</strong> (1900-2023)</li>
            <li><strong>gender</strong> (m or f)</li>
            <li><strong>referring_doctor</strong></li>
            <li><strong>rx_OD</strong></li>
            <li><strong>rx_OS</strong></li>
            <li><strong>procedures_done</strong></li>
            <li><strong>dosage</strong></li>
            <li><strong>duration</strong></li>
            <li><strong>cumulative_dosage</strong></li>
            <li><strong>date_of_discontinuation</strong> (YYYY-MM-DD)</li>
            <li><strong>extra_notes</strong></li>
            <li><strong>visit_date</strong> (YYYY-MM-DD) - Optional</li>
            <li><strong>visit_notes</strong> - Optional</li>
            <li><strong>faf_reference_OD</strong> - Optional</li>
            <li><strong>faf_reference_OS</strong> - Optional</li>
            <li><strong>oct_reference_OD</strong> - Optional</li>
            <li><strong>oct_reference_OS</strong> - Optional</li>
            <li><strong>vf_reference_OD</strong> - Optional</li>
            <li><strong>vf_reference_OS</strong> - Optional</li>
            <li><strong>mferg_reference_OD</strong> - Optional</li>
            <li><strong>mferg_reference_OS</strong> - Optional</li>
            <li><strong>merci_rating_left_eye</strong> - Optional</li>
            <li><strong>merci_rating_right_eye</strong> - Optional</li>
        </ol>
        
        <p><strong>Note:</strong> Only the first 13 columns are required. Columns 14-25 are for visit data and are optional.</p>
    </div>
</body>
</html>
