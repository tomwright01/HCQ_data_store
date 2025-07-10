<?php
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

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prepare SQL statement for patient information
    $stmt = $conn->prepare("INSERT INTO patients (
        location, disease_id, year_of_birth, gender, referring_doctor, 
        rx_OD, rx_OS, procedures_done, dosage, dosage_unit, duration, 
        cumulative_dosage, date_of_discontinuation, extra_notes,
        tamoxifen, kidney_disease, liver_disease
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "siisddsssiisssss", 
        $_POST['location'],
        $_POST['disease_id'],
        $_POST['year_of_birth'],
        $_POST['gender'],
        $_POST['referring_doctor'],
        $_POST['rx_OD'],
        $_POST['rx_OS'],
        $_POST['procedures_done'],
        $_POST['dosage'],
        $_POST['dosage_unit'],
        $_POST['duration'],
        $_POST['cumulative_dosage'],
        $_POST['date_of_discontinuation'],
        $_POST['extra_notes'],
        $_POST['tamoxifen'],
        $_POST['kidney_disease'],
        $_POST['liver_disease']
    );
    
    $stmt->execute();
    $patient_id = $stmt->insert_id;
    $stmt->close();
    
    // Handle file uploads
    $file_fields = ['faf_od', 'faf_os', 'oct_od', 'oct_os', 'vf_od', 'vf_os', 'mferg_od', 'mferg_os'];
    $file_data = [];
    
    foreach ($file_fields as $field) {
        if (!empty($_FILES[$field]['name'])) {
            $file_data[$field] = file_get_contents($_FILES[$field]['tmp_name']);
        } else {
            $file_data[$field] = null;
        }
    }
    
    // Prepare SQL statement for visit information
    $stmt = $conn->prepare("INSERT INTO visits (
        patient_id, visit_date, visit_notes,
        faf_od, faf_os, oct_od, oct_os,
        vf_od, vf_os, mferg_od, mferg_os,
        merci_rating_left_eye, merci_rating_right_eye
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "issssssssssii", 
        $patient_id,
        $_POST['visit_date'],
        $_POST['visit_notes'],
        $file_data['faf_od'],
        $file_data['faf_os'],
        $file_data['oct_od'],
        $file_data['oct_os'],
        $file_data['vf_od'],
        $file_data['vf_os'],
        $file_data['mferg_od'],
        $file_data['mferg_os'],
        $_POST['merci_rating_left_eye'],
        $_POST['merci_rating_right_eye']
    );
    
    if ($stmt->execute()) {
        echo "<script>alert('Patient and visit information successfully saved!');</script>";
    } else {
        echo "<script>alert('Error saving visit information: " . $stmt->error . "');</script>";
    }
    
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Patient and Visit Information</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
            min-height: 100vh;
            background-image: url('https://via.placeholder.com/1500x1000');
            background-size: cover;
            background-position: center;
            box-sizing: border-box;
        }

        .form-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
            overflow: auto;
            max-height: 90vh;
        }

        h1 {
            text-align: center;
            font-size: 36px;
            color: #4CAF50;
            margin-bottom: 20px;
            margin-top: 0;
        }

        .form-title {
            text-align: center;
            font-size: 24px;
            color: #4CAF50;
            margin-bottom: 15px;
            font-weight: bold;
        }

        label {
            display: block;
            margin: 5px 0;
            font-size: 14px;
            font-weight: bold;
        }

        input, textarea, select {
            width: 100%;
            padding: 8px;
            margin: 6px 0 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        textarea {
            min-height: 100px;
        }

        input[type="radio"], input[type="checkbox"] {
            width: auto;
            margin-right: 5px;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-section h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 20px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
        }

        .form-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            white-space: nowrap;
        }

        .dosage-container {
            display: flex;
            gap: 10px;
        }

        .dosage-container input {
            flex: 1;
        }

        .dosage-container select {
            width: 100px;
        }

        .submit-btn {
            background-color: #4CAF50;
            color: white;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .submit-btn:hover {
            background-color: #45a049;
        }

        footer {
            text-align: center;
            margin-top: 20px;
        }

        footer a {
            text-decoration: none;
            color: #4CAF50;
            font-size: 14px;
        }

        footer a:hover {
            text-decoration: underline;
        }

        .file-upload {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .file-upload-row {
            display: flex;
            gap: 15px;
        }

        .file-upload-item {
            flex: 1;
        }
    </style>
</head>
<body>

    <div class="form-container">
        <h1>Add New Patient and Visit Information</h1>

        <div class="form-title">Patient and Visit Information Form</div>

        <form action="" method="post" enctype="multipart/form-data">
            <!-- Patient Information -->
            <div class="form-section">
                <h3>Patient Information</h3>
                
                <div class="form-group">
                    <div>
                        <label for="location">Location:</label>
                        <select name="location" required>
                            <option value="Halifax">Halifax</option>
                            <option value="Kensington">Kensington</option>
                            <option value="Montreal">Montreal</option>
                        </select>
                    </div>

                    <div>
                        <label for="disease_id">Primary Diagnosis:</label>
                        <select name="disease_id" required>
                            <option value="1">Lupus</option>
                            <option value="2">Rheumatoid Arthritis</option>
                            <option value="3">RTMD</option>
                            <option value="4">Sjorgens</option>
                        </select>
                    </div>

                    <div>
                        <label for="year_of_birth">Year of Birth:</label>
                        <input type="number" name="year_of_birth" min="1900" max="<?= date('Y')?>" required>
                    </div>

                    <div>
                        <label for="gender">Gender:</label>
                        <div>
                            <input type="radio" name="gender" value="m" required> Male
                            <input type="radio" name="gender" value="f" required> Female
                        </div>
                    </div>

                    <div>
                        <label for="referring_doctor">Referring Doctor:</label>
                        <input type="text" name="referring_doctor" required>
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="rx_OD">Prescription OD:</label>
                        <input type="number" step="0.01" name="rx_OD" required>
                    </div>

                    <div>
                        <label for="rx_OS">Prescription OS:</label>
                        <input type="number" step="0.01" name="rx_OS" required>
                    </div>
                </div>

                <div class="form-section">
                    <label>Procedures Done:</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="None"> None
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="Cataracts"> Cataracts
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="Refractive Surgery"> Refractive Surgery
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="Retinal Repair"> Retinal Repair
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="Corneal Surgery"> Corneal Surgery
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="procedures_done[]" value="Glaucoma Surgery"> Glaucoma Surgery
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="dosage">Dosage:</label>
                        <div class="dosage-container">
                            <input type="number" step="0.01" name="dosage" required>
                            <select name="dosage_unit" required>
                                <option value="mg/wk">mg/wk</option>
                                <option value="mg/day">mg/day</option>
                                <option value="mg/month">mg/month</option>
                                <option value="IU/wk">IU/wk</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="duration">Duration (months):</label>
                        <input type="number" name="duration" required>
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="cumulative_dosage">Cumulative Dosage:</label>
                        <input type="number" step="0.01" name="cumulative_dosage">
                    </div>

                    <div>
                        <label for="date_of_discontinuation">Date of Discontinuation:</label>
                        <input type="date" name="date_of_discontinuation">
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="extra_notes">Extra Notes:</label>
                        <textarea name="extra_notes"></textarea>
                    </div>
                </div>
            </div>

            <!-- Risk Factors -->
            <div class="form-section">
                <h3>Risk Factors</h3>
                <div class="checkbox-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="tamoxifen" value="1"> Tamoxifen Use
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="kidney_disease" value="1"> Kidney Disease
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="liver_disease" value="1"> Liver Disease
                    </div>
                </div>
            </div>

            <!-- Visit Information -->
            <div class="form-section">
                <h3>Visit Information</h3>

                <div class="form-group">
                    <div>
                        <label for="visit_date">Visit Date:</label>
                        <input type="date" name="visit_date" required>
                    </div>

                    <div>
                        <label for="visit_notes">Visit Notes:</label>
                        <textarea name="visit_notes" style="min-height: 150px;"></textarea>
                    </div>
                </div>
            </div>

            <!-- File Uploads -->
            <div class="form-section">
                <h3>Test Results Upload</h3>
                
                <div class="file-upload">
                    <div class="file-upload-row">
                        <div class="file-upload-item">
                            <label for="faf_od">FAF (OD):</label>
                            <input type="file" name="faf_od" accept="image/*,.pdf">
                        </div>
                        <div class="file-upload-item">
                            <label for="faf_os">FAF (OS):</label>
                            <input type="file" name="faf_os" accept="image/*,.pdf">
                        </div>
                    </div>
                    
                    <div class="file-upload-row">
                        <div class="file-upload-item">
                            <label for="oct_od">OCT (OD):</label>
                            <input type="file" name="oct_od" accept="image/*,.pdf">
                        </div>
                        <div class="file-upload-item">
                            <label for="oct_os">OCT (OS):</label>
                            <input type="file" name="oct_os" accept="image/*,.pdf">
                        </div>
                    </div>
                    
                    <div class="file-upload-row">
                        <div class="file-upload-item">
                            <label for="vf_od">VF (OD):</label>
                            <input type="file" name="vf_od" accept="image/*,.pdf">
                        </div>
                        <div class="file-upload-item">
                            <label for="vf_os">VF (OS):</label>
                            <input type="file" name="vf_os" accept="image/*,.pdf">
                        </div>
                    </div>
                    
                    <div class="file-upload-row">
                        <div class="file-upload-item">
                            <label for="mferg_od">MFERG (OD):</label>
                            <input type="file" name="mferg_od" accept="image/*,.pdf">
                        </div>
                        <div class="file-upload-item">
                            <label for="mferg_os">MFERG (OS):</label>
                            <input type="file" name="mferg_os" accept="image/*,.pdf">
                        </div>
                    </div>
                </div>
            </div>

            <!-- MERCI Ratings -->
            <div class="form-section">
                <h3>MERCI Ratings</h3>
                <div class="form-group">
                    <div>
                        <label for="merci_rating_left_eye">MERCI Rating (Left Eye):</label>
                        <input type="number" name="merci_rating_left_eye" min="0" max="20">
                    </div>

                    <div>
                        <label for="merci_rating_right_eye">MERCI Rating (Right Eye):</label>
                        <input type="number" name="merci_rating_right_eye" min="0" max="20">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn">Submit Data</button>
        </form>
    </div>

    <footer>
        <p>Go back to <a href="index.php">Home</a></p>
    </footer>

</body>
</html>

<?php
$conn->close();
?>
