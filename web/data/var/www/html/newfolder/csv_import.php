<?php
// Database configuration
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData";

// CSV file path
$csvFilePath = "C:/Users/owenc/Downloads/Patient Info Master 1(Retrospective Data).csv";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Process the CSV file
try {
    // Open the CSV file
    if (($handle = fopen($csvFilePath, "r")) === FALSE) {
        throw new Exception("Could not open CSV file: $csvFilePath");
    }

    // Counters for results
    $patients_inserted = 0;
    $visits_inserted = 0;
    $errors = [];
    
    // Start transaction
    $conn->begin_transaction();
    
    // Skip header row if needed (uncomment if your CSV has headers)
    // fgetcsv($handle);
    
    $line = 0; // Track line numbers
    
    while (($data = fgetcsv($handle)) !== FALSE) {
        $line++;
        
        try {
            // Basic validation - adjust column count as needed
            if (count($data) < 13) {
                throw new Exception("Insufficient columns (expected at least 13)");
            }
            
            // Insert Patient
            $patient_id = insertPatient($conn, $data);
            $patients_inserted++;
            
            // Insert Visit if visit data exists (adjust column indexes as needed)
            if (!empty($data[13])) {
                $visit_id = insertVisit($conn, $patient_id, $data);
                $visits_inserted++;
                
                // Insert image references if they exist
                insertImageReferences($conn, $patient_id, $visit_id, $data);
            }
            
        } catch (Exception $e) {
            $errors[] = "Line $line: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    // Commit transaction if no errors
    if (empty($errors)) {
        $conn->commit();
        $message = "Successfully imported $patients_inserted patients and $visits_inserted visits from $csvFilePath";
    } else {
        $conn->rollback();
        $message = "Import completed with errors. No data was committed to database.";
    }
    
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Database functions (same as previous implementation)
function insertPatient($conn, $data) { /* ... */ }
function insertVisit($conn, $patient_id, $data) { /* ... */ }
function insertImageReferences($conn, $patient_id, $visit_id, $data) { /* ... */ }
?>

<!DOCTYPE html>
<html>
<head>
    <title>Direct CSV Import</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .message { padding: 15px; margin: 20px 0; border-radius: 4px; }
        .success { background-color: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .error { background-color: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
        .error-list { max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; }
    </style>
</head>
<body>
    <h1>Direct CSV Import Results</h1>
    
    <div class="message <?= empty($errors) ? 'success' : 'error' ?>">
        <?= $message ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <h3>Errors:</h3>
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <p><a href="index.php">Return to Dashboard</a></p>
</body>
</html>
