<?php
// This file contains the form that submits to submit.php

?>

<h2>Enter Patient and Visit Information</h2>
<form action="submit.php" method="post">
    <h3>Patient Information</h3>
    <label for="location">Location:</label>
    <input type="text" name="location" required><br><br>

    <label for="disease_id">Disease ID (1 = Lupus, 2 = RA, etc.):</label>
    <input type="number" name="disease_id" required><br><br>

    <label for="year_of_birth">Year of Birth:</label>
    <input type="number" name="year_of_birth" required><br><br>

    <label for="gender">Gender:</label>
    <input type="radio" name="gender" value="m"> Male
    <input type="radio" name="gender" value="f"> Female<br><br>

    <label for="referring_doctor">Referring Doctor:</label>
    <input type="text" name="referring_doctor" required><br><br>

    <label for="rx_OD">Prescription OD:</label>
    <input type="text" name="rx_OD" required><br><br>

    <label for="rx_OS">Prescription OS:</label>
    <input type="text" name="rx_OS" required><br><br>

    <label for="procedures_done">Procedures Done:</label>
    <textarea name="procedures_done"></textarea><br><br>

    <label for="dosage">Dosage:</label>
    <input type="text" name="dosage" required><br><br>

    <label for="duration">Duration:</label>
    <input type="number" name="duration" required><br><br>

    <label for="cumulative_dosage">Cumulative Dosage:</label>
    <input type="text" name="cumulative_dosage"><br><br>

    <label for="date_of_discontinuation">Date of Discontinuation:</label>
    <input type="date" name="date_of_discontinuation"><br><br>

    <label for="extra_notes">Extra Notes:</label>
    <textarea name="extra_notes"></textarea><br><br>

    <h3>Visit Information</h3>
    <label for="visit_date">Visit Date:</label>
    <input type="date" name="visit_date" required><br><br>

    <label for="visit_notes">Visit Notes:</label>
    <textarea name="visit_notes"></textarea><br><br>

    <!-- FAF Data -->
    <h4>FAF Data</h4>
    <label for="faf_test_id_OD">FAF Test ID (OD):</label>
    <input type="number" name="faf_test_id_OD"><br><br>

    <label for="faf_image_number_OD">FAF Image Number (OD):</label>
    <input type="number" name="faf_image_number_OD"><br><br>

    <label for="faf_test_id_OS">FAF Test ID (OS):</label>
    <input type="number" name="faf_test_id_OS"><br><br>

    <label for="faf_image_number_OS">FAF Image Number (OS):</label>
    <input type="number" name="faf_image_number_OS"><br><br>

    <!-- OCT Data -->
    <h4>OCT Data</h4>
    <label for="oct_test_id_OD">OCT Test ID (OD):</label>
    <input type="number" name="oct_test_id_OD"><br><br>

    <label for="oct_image_number_OD">OCT Image Number (OD):</label>
    <input type="number" name="oct_image_number_OD"><br><br>

    <label for="oct_test_id_OS">OCT Test ID (OS):</label>
    <input type="number" name="oct_test_id_OS"><br><br>

    <label for="oct_image_number_OS">OCT Image Number (OS):</label>
    <input type="number" name="oct_image_number_OS"><br><br>

    <!-- VF Data -->
    <h4>VF Data</h4>
    <label for="vf_test_id_OD">VF Test ID (OD):</label>
    <input type="number" name="vf_test_id_OD"><br><br>

    <label for="vf_image_number_OD">VF Image Number (OD):</label>
    <input type="number" name="vf_image_number_OD"><br><br>

    <label for="vf_test_id_OS">VF Test ID (OS):</label>
    <input type="number" name="vf_test_id_OS"><br><br>

    <label for="vf_image_number_OS">VF Image Number (OS):</label>
    <input type="number" name="vf_image_number_OS"><br><br>

    <!-- MFERG Data -->
    <h4>MFERG Data</h4>
    <label for="mferg_test_id_OD">MFERG Test ID (OD):</label>
    <input type="number" name="mferg_test_id_OD"><br><br>

    <label for="mferg_image_number_OD">MFERG Image Number (OD):</label>
    <input type="number" name="mferg_image_number_OD"><br><br>

    <label for="mferg_test_id_OS">MFERG Test ID (OS):</label>
    <input type="number" name="mferg_test_id_OS"><br><br>

    <label for="mferg_image_number_OS">MFERG Image Number (OS):</label>
    <input type="number" name="mferg_image_number_OS"><br><br>

    <!-- MERCI Ratings -->
    <h4>MERCI Ratings</h4>
    <label for="merci_rating_left_eye">MERCI Rating (Left Eye):</label>
    <input type="number" name="merci_rating_left_eye"><br><br>

    <label for="merci_rating_right_eye">MERCI Rating (Right Eye):</label>
    <input type="number" name="merci_rating_right_eye"><br><br>

    <input type="submit" value="Submit">
</form>

