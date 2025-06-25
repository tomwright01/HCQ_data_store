<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData"; // Name of your database

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Patient and Visit Information</title>
    <style>
        /* General Styling */
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-image: url('https://via.placeholder.com/1500x1000'); /* Optional background image */
            background-size: cover;
            background-position: center;
        }

        .form-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
        }

        h1 {
            text-align: center;
            font-size: 36px;
            color: #4CAF50;
        }

        label {
            display: block;
            margin: 10px 0 5px;
            font-size: 18px;
            font-weight: bold;
        }

        input, textarea, select {
            width: 100%;
            padding: 10px;
            margin: 8px 0 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
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
            margin-bottom: 15px;
            font-size: 24px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
        }

        .form-section label {
            font-size: 16px;
        }

        .form-group {
            display: flex;
            justify-content: space-between;
        }

        .form-group input {
            width: 48%;
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

        .form-container footer {
            text-align: center;
            margin-top: 30px;
        }

        footer a {
            text-decoration: none;
            color: #4CAF50;
            font-size: 16px;
        }

        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="form-container">
        <h1>Add New Patient and Visit Information</h1>

        <form action="submit.php" method="post">
            <!-- Patient Information -->
            <div class="form-section">
                <h3>Patient Information</h3>

                <label for="location">Location:</label>
                <input type="text" name="location" required>

                <label for="disease_id">Disease ID:</label>
                <select name="disease_id" required>
                    <option value="1">Lupus</option>
                    <option value="2">Rheumatoid Arthritis</option>
                    <option value="3">RTMD</option>
                    <option value="4">Sjorgens</option>
                    <option value="5">Other</option>
                </select>

                <label for="year_of_birth">Year of Birth:</label>
                <input type="number" name="year_of_birth" required>

                <label for="gender">Gender:</label>
                <input type="radio" name="gender" value="m" required> Male
                <input type="radio" name="gender" value="f" required> Female

                <label for="referring_doctor">Referring Doctor:</label>
                <input type="text" name="referring_doctor" required>

                <div class="form-group">
                    <div>
                        <label for="rx_OD">Prescription OD:</label>
                        <input type="text" name="rx_OD" required>
                    </div>
                    <div>
                        <label for="rx_OS">Prescription OS:</label>
                        <input type="text" name="rx_OS" required>
                    </div>
                </div>

                <label for="procedures_done">Procedures Done:</label>
                <textarea name="procedures_done"></textarea>

                <div class="form-group">
                    <div>
                        <label for="dosage">Dosage:</label>
                        <input type="text" name="dosage" required>
                    </div>
                    <div>
                        <label for="duration">Duration:</label>
                        <input type="number" name="duration" required>
                    </div>
                </div>

                <label for="cumulative_dosage">Cumulative Dosage:</label>
                <input type="text" name="cumulative_dosage">

                <label for="date_of_discontinuation">Date of Discontinuation:</label>
                <input type="date" name="date_of_discontinuation">

                <label for="extra_notes">Extra Notes:</label>
                <textarea name="extra_notes"></textarea>
            </div>

            <!-- Visit Information -->
            <div class="form-section">
                <h3>Visit Information</h3>
                <label for="visit_date">Visit Date:</label>
                <input type="date" name="visit_date" required>

                <label for="visit_notes">Visit Notes:</label>
                <textarea name="visit_notes"></textarea>
            </div>

            <!-- FAF Data -->
            <div class="form-section">
                <h3>FAF Data</h3>
                <label for="faf_test_id_OD">FAF Test ID (OD):</label>
                <input type="number" name="faf_test_id_OD">

                <label for="faf_image_number_OD">FAF Image Number (OD):</label>
                <input type="number" name="faf_image_number_OD">

                <label for="faf_test_id_OS">FAF Test ID (OS):</label>
                <input type="number" name="faf_test_id_OS">

                <label for="faf_image_number_OS">FAF Image Number (OS):</label>
                <input type="number" name="faf_image_number_OS">
            </div>

            <!-- OCT Data -->
            <div class="form-section">
                <h3>OCT Data</h3>
                <label for="oct_test_id_OD">OCT Test ID (OD):</label>
                <input type="number" name="oct_test_id_OD">

                <label for="oct_image_number_OD">OCT Image Number (OD):</label>
                <input type="number" name="oct_image_number_OD">

                <label for="oct_test_id_OS">OCT Test ID (OS):</label>
                <input type="number" name="oct_test_id_OS">

                <label for="oct_image_number_OS">OCT Image Number (OS):</label>
                <input type="number" name="oct_image_number_OS">
            </div>

            <!-- VF Data -->
            <div class="form-section">
                <h3>VF Data</h3>
                <label for="vf_test_id_OD">VF Test ID (OD):</label>
                <input type="number" name="vf_test_id_OD">

                <label for="vf_image_number_OD">VF Image Number (OD):</label>
                <input type="number" name="vf_image_number_OD">

                <label for="vf_test_id_OS">VF Test ID (OS):</label>
                <input type="number" name="vf_test_id_OS">

                <label for="vf_image_number_OS">VF Image Number (OS):</label>
                <input type="number" name="vf_image_number_OS">
            </div>

            <!-- MFERG Data -->
            <div class="form-section">
                <h3>MFERG Data</h3>
                <label for="mferg_test_id_OD">MFERG Test ID (OD):</label>
                <input type="number" name="mferg_test_id_OD">

                <label for="mferg_image_number_OD">MFERG Image Number (OD):</label>
                <input type="number" name="mferg_image_number_OD">

                <label for="mferg_test_id_OS">MFERG Test ID (OS):</label>
                <input type="number" name="mferg_test_id_OS">

                <label for="mferg_image_number_OS">MFERG Image Number (OS):</label>
                <input type="number" name="mferg_image_number_OS">
            </div>

            <!-- MERCI Ratings -->
            <div class="form-section">
                <h3>MERCI Ratings</h3>
                <label for="merci_rating_left_eye">MERCI Rating (Left Eye):</label>
                <input type="number" name="merci_rating_left_eye">

                <label for="merci_rating_right_eye">MERCI Rating (Right Eye):</label>
                <input type="number" name="merci_rating_right_eye">
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


