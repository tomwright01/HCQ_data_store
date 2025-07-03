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
        rx_OD, rx_OS, procedures_done, dosage, duration, 
        cumulative_dosage, date_of_discontinuation, extra_notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "siisdddsdiiss", 
        $_POST['location'],
        $_POST['disease_id'],
        $_POST['year_of_birth'],
        $_POST['gender'],
        $_POST['referring_doctor'],
        $_POST['rx_OD'],
        $_POST['rx_OS'],
        $_POST['procedures_done'],
        $_POST['dosage'],
        $_POST['duration'],
        $_POST['cumulative_dosage'],
        $_POST['date_of_discontinuation'],
        $_POST['extra_notes']
    );
    
    $stmt->execute();
    $patient_id = $stmt->insert_id;
    $stmt->close();
    
    // Prepare SQL statement for visit information
    $stmt = $conn->prepare("INSERT INTO visits (
        patient_id, visit_date, visit_notes,
        faf_test_id_OD, faf_image_number_OD, faf_test_id_OS, faf_image_number_OS,
        oct_test_id_OD, oct_image_number_OD, oct_test_id_OS, oct_image_number_OS,
        vf_test_id_OD, vf_image_number_OD, vf_test_id_OS, vf_image_number_OS,
        mferg_test_id_OD, mferg_image_number_OD, mferg_test_id_OS, mferg_image_number_OS,
        merci_rating_left_eye, merci_rating_right_eye
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "issiiiiiiiiiiiiiiiiii", 
        $patient_id,
        $_POST['visit_date'],
        $_POST['visit_notes'],
        $_POST['faf_test_id_OD'],
        $_POST['faf_image_number_OD'],
        $_POST['faf_test_id_OS'],
        $_POST['faf_image_number_OS'],
        $_POST['oct_test_id_OD'],
        $_POST['oct_image_number_OD'],
        $_POST['oct_test_id_OS'],
        $_POST['oct_image_number_OS'],
        $_POST['vf_test_id_OD'],
        $_POST['vf_image_number_OD'],
        $_POST['vf_test_id_OS'],
        $_POST['vf_image_number_OS'],
        $_POST['mferg_test_id_OD'],
        $_POST['mferg_image_number_OD'],
        $_POST['mferg_test_id_OS'],
        $_POST['mferg_image_number_OS'],
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

        input[type="radio"] {
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
                        <label for="disease_id">Disease:</label>
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
                        <input type="radio" name="gender" value="m" required> Male
                        <input type="radio" name="gender" value="f" required> Female
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

                <div class="form-group">
                    <div>
                        <label for="procedures_done">Procedures Done:</label>
                        <textarea name="procedures_done"></textarea>
                    </div>

                    <div>
                        <label for="dosage">Dosage:</label>
                        <input type="number" step="0.01" name="dosage" required>
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="duration">Duration (months):</label>
                        <input type="number" name="duration" required>
                    </div>

                    <div>
                        <label for="cumulative_dosage">Cumulative Dosage:</label>
                        <input type="number" step="0.01" name="cumulative_dosage">
                    </div>
                </div>

                <div class="form-group">
                    <div>
                        <label for="date_of_discontinuation">Date of Discontinuation:</label>
                        <input type="date" name="date_of_discontinuation">
                    </div>

                    <div>
                        <label for="extra_notes">Extra Notes:</label>
                        <textarea name="extra_notes"></textarea>
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
                        <textarea name="visit_notes"></textarea>
                    </div>
                </div>
            </div>

            <!-- FAF Data -->
            <div class="form-section">
                <h3>FAF Data</h3>
                <div class="form-group">
                    <div>
                        <label for="faf_test_id_OD">FAF Test ID (OD):</label>
                        <input type="number" name="faf_test_id_OD">
                    </div>

                    <div>
                        <label for="faf_image_number_OD">FAF Image Number (OD):</label>
                        <input type="number" name="faf_image_number_OD">
                    </div>

                    <div>
                        <label for="faf_test_id_OS">FAF Test ID (OS):</label>
                        <input type="number" name="faf_test_id_OS">
                    </div>

                    <div>
                        <label for="faf_image_number_OS">FAF Image Number (OS):</label>
                        <input type="number" name="faf_image_number_OS">
                    </div>
                </div>
            </div>

            <!-- OCT Data -->
            <div class="form-section">
                <h3>OCT Data</h3>
                <div class="form-group">
                    <div>
                        <label for="oct_test_id_OD">OCT Test ID (OD):</label>
                        <input type="number" name="oct_test_id_OD">
                    </div>

                    <div>
                        <label for="oct_image_number_OD">OCT Image Number (OD):</label>
                        <input type="number" name="oct_image_number_OD">
                    </div>

                    <div>
                        <label for="oct_test_id_OS">OCT Test ID (OS):</label>
                        <input type="number" name="oct_test_id_OS">
                    </div>

                    <div>
                        <label for="oct_image_number_OS">OCT Image Number (OS):</label>
                        <input type="number" name="oct_image_number_OS">
                    </div>
                </div>
            </div>

            <!-- VF Data -->
            <div class="form-section">
                <h3>VF Data</h3>
                <div class="form-group">
                    <div>
                        <label for="vf_test_id_OD">VF Test ID (OD):</label>
                        <input type="number" name="vf_test_id_OD">
                    </div>

                    <div>
                        <label for="vf_image_number_OD">VF Image Number (OD):</label>
                        <input type="number" name="vf_image_number_OD">
                    </div>

                    <div>
                        <label for="vf_test_id_OS">VF Test ID (OS):</label>
                        <input type="number" name="vf_test_id_OS">
                    </div>

                    <div>
                        <label for="vf_image_number_OS">VF Image Number (OS):</label>
                        <input type="number" name="vf_image_number_OS">
                    </div>
                </div>
            </div>

            <!-- MFERG Data -->
            <div class="form-section">
                <h3>MFERG Data</h3>
                <div class="form-group">
                    <div>
                        <label for="mferg_test_id_OD">MFERG Test ID (OD):</label>
                        <input type="number" name="mferg_test_id_OD">
                    </div>

                    <div>
                        <label for="mferg_image_number_OD">MFERG Image Number (OD):</label>
                        <input type="number" name="mferg_image_number_OD">
                    </div>

                    <div>
                        <label for="mferg_test_id_OS">MFERG Test ID (OS):</label>
                        <input type="number" name="mferg_test_id_OS">
                    </div>

                    <div>
                        <label for="mferg_image_number_OS">MFERG Image Number (OS):</label>
                        <input type="number" name="mferg_image_number_OS">
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
