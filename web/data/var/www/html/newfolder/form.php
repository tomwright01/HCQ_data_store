<?php
require_once 'includes/functions.php';

// Get diseases for dropdown
$diseases = [
    1 => 'Lupus',
    2 => 'Rheumatoid Arthritis',
    3 => 'RTMD',
    4 => 'Sjorgens'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Patient and Visit Information</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <img src="assets/images/kensington-logo.png" alt="Kensington Clinic Logo" class="logo">
        <h1>Add New Patient and Visit</h1>
    </header>

    <main class="container">
        <form action="submit.php" method="post" class="patient-form">
            <section>
                <h2>Patient Information</h2>
                
                <div class="form-group">
                    <div>
                        <label for="location">Location:</label>
                        <select name="location" id="location" required>
                            <option value="Halifax">Halifax</option>
                            <option value="Kensington">Kensington</option>
                            <option value="Montreal">Montreal</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="disease_id">Disease:</label>
                        <select name="disease_id" id="disease_id" required>
                            <?php foreach ($diseases as $id => $name): ?>
                                <option value="<?= $id ?>"><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="year_of_birth">Year of Birth:</label>
                        <input type="number" name="year_of_birth" id="year_of_birth" min="1900" max="<?= date('Y') ?>" required>
                    </div>
                    
                    <div>
                        <label>Gender:</label>
                        <div class="radio-group">
                            <label><input type="radio" name="gender" value="m" required> Male</label>
                            <label><input type="radio" name="gender" value="f"> Female</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="referring_doctor">Referring Doctor:</label>
                        <input type="text" name="referring_doctor" id="referring_doctor" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="rx_OD">Prescription OD:</label>
                        <input type="number" step="0.01" name="rx_OD" id="rx_OD" required>
                    </div>
                    
                    <div>
                        <label for="rx_OS">Prescription OS:</label>
                        <input type="number" step="0.01" name="rx_OS" id="rx_OS" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="procedures_done">Procedures Done:</label>
                        <textarea name="procedures_done" id="procedures_done"></textarea>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="dosage">Dosage:</label>
                        <input type="number" step="0.01" name="dosage" id="dosage" required>
                    </div>
                    
                    <div>
                        <label for="duration">Duration (months):</label>
                        <input type="number" name="duration" id="duration" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="cumulative_dosage">Cumulative Dosage:</label>
                        <input type="number" step="0.01" name="cumulative_dosage" id="cumulative_dosage">
                    </div>
                    
                    <div>
                        <label for="date_of_discontinuation">Date of Discontinuation:</label>
                        <input type="date" name="date_of_discontinuation" id="date_of_discontinuation">
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="extra_notes">Extra Notes:</label>
                        <textarea name="extra_notes" id="extra_notes"></textarea>
                    </div>
                </div>
            </section>
            
            <section>
                <h2>Visit Information</h2>
                
                <div class="form-group">
                    <div>
                        <label for="visit_date">Visit Date:</label>
                        <input type="date" name="visit_date" id="visit_date" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label for="visit_notes">Visit Notes:</label>
                        <textarea name="visit_notes" id="visit_notes"></textarea>
                    </div>
                </div>
                
                <h3>Test Data</h3>
                
                <div class="test-section">
                    <h4>FAF Data</h4>
                    <div class="form-group">
                        <div>
                            <label for="faf_test_id_OD">Test ID (OD):</label>
                            <input type="number" name="faf_test_id_OD" id="faf_test_id_OD">
                        </div>
                        <div>
                            <label for="faf_image_number_OD">Image Number (OD):</label>
                            <input type="number" name="faf_image_number_OD" id="faf_image_number_OD">
                        </div>
                        <div>
                            <label for="faf_test_id_OS">Test ID (OS):</label>
                            <input type="number" name="faf_test_id_OS" id="faf_test_id_OS">
                        </div>
                        <div>
                            <label for="faf_image_number_OS">Image Number (OS):</label>
                            <input type="number" name="faf_image_number_OS" id="faf_image_number_OS">
                        </div>
                    </div>
                </div>
                
                <div class="test-section">
                    <h4>OCT Data</h4>
                    <div class="form-group">
                        <div>
                            <label for="oct_test_id_OD">Test ID (OD):</label>
                            <input type="number" name="oct_test_id_OD" id="oct_test_id_OD">
                        </div>
                        <div>
                            <label for="oct_image_number_OD">Image Number (OD):</label>
                            <input type="number" name="oct_image_number_OD" id="oct_image_number_OD">
                        </div>
                        <div>
                            <label for="oct_test_id_OS">Test ID (OS):</label>
                            <input type="number" name="oct_test_id_OS" id="oct_test_id_OS">
                        </div>
                        <div>
                            <label for="oct_image_number_OS">Image Number (OS):</label>
                            <input type="number" name="oct_image_number_OS" id="oct_image_number_OS">
                        </div>
                    </div>
                </div>
                
                <div class="test-section">
                    <h4>VF Data</h4>
                    <div class="form-group">
                        <div>
                            <label for="vf_test_id_OD">Test ID (OD):</label>
                            <input type="number" name="vf_test_id_OD" id="vf_test_id_OD">
                        </div>
                        <div>
                            <label for="vf_image_number_OD">Image Number (OD):</label>
                            <input type="number" name="vf_image_number_OD" id="vf_image_number_OD">
                        </div>
                        <div>
                            <label for="vf_test_id_OS">Test ID (OS):</label>
                            <input type="number" name="vf_test_id_OS" id="vf_test_id_OS">
                        </div>
                        <div>
                            <label for="vf_image_number_OS">Image Number (OS):</label>
                            <input type="number" name="vf_image_number_OS" id="vf_image_number_OS">
                        </div>
                    </div>
                </div>
                
                <div class="test-section">
                    <h4>MFERG Data</h4>
                    <div class="form-group">
                        <div>
                            <label for="mferg_test_id_OD">Test ID (OD):</label>
                            <input type="number" name="mferg_test_id_OD" id="mferg_test_id_OD">
                        </div>
                        <div>
                            <label for="mferg_image_number_OD">Image Number (OD):</label>
                            <input type="number" name="mferg_image_number_OD" id="mferg_image_number_OD">
                        </div>
                        <div>
                            <label for="mferg_test_id_OS">Test ID (OS):</label>
                            <input type="number" name="mferg_test_id_OS" id="mferg_test_id_OS">
                        </div>
                        <div>
                            <label for="mferg_image_number_OS">Image Number (OS):</label>
                            <input type="number" name="mferg_image_number_OS" id="mferg_image_number_OS">
                        </div>
                    </div>
                </div>
                
                <div class="test-section">
                    <h4>MERCI Ratings</h4>
                    <div class="form-group">
                        <div>
                            <label for="merci_rating_left_eye">Left Eye:</label>
                            <input type="number" name="merci_rating_left_eye" id="merci_rating_left_eye" min="0" max="20">
                        </div>
                        <div>
                            <label for="merci_rating_right_eye">Right Eye:</label>
                            <input type="number" name="merci_rating_right_eye" id="merci_rating_right_eye" min="0" max="20">
                        </div>
                    </div>
                </div>
            </section>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">Submit</button>
                <a href="index.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </main>
</body>
</html>
