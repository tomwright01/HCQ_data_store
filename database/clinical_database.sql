-- Step 1: Create the database
CREATE DATABASE IF NOT EXISTS PatientData;

-- Step 2: Use the created database
USE PatientData;

-- Step 3: Create a table for disease categories
CREATE TABLE IF NOT EXISTS Diseases (
    disease_id INT PRIMARY KEY,
    disease_name ENUM('Lupus', 'Rheumatoid Arthritis', 'RTMD', 'Sjorgens') NOT NULL
);

-- Insert predefined diseases
INSERT INTO Diseases (disease_id, disease_name) 
VALUES
(1, 'Lupus'),
(2, 'Rheumatoid Arthritis'),
(3, 'RTMD'),
(4, 'Sjorgens');

-- Step 4: Create the Patients table (MERCI scores removed)
CREATE TABLE IF NOT EXISTS Patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    location ENUM('Halifax', 'Kensington', 'Montreal') DEFAULT NULL,
    disease_id INT,
    year_of_birth INT CHECK (year_of_birth BETWEEN 1900 AND 2023),
    gender ENUM('m', 'f') DEFAULT NULL,
    referring_doctor VARCHAR(255) DEFAULT NULL,
    rx_OD FLOAT DEFAULT NULL,
    rx_OS FLOAT DEFAULT NULL,
    procedures_done TEXT DEFAULT NULL,
    dosage FLOAT DEFAULT NULL,
    duration INT DEFAULT NULL,
    cumulative_dosage FLOAT DEFAULT NULL,
    date_of_discontinuation DATE DEFAULT NULL,
    extra_notes TEXT DEFAULT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (disease_id) REFERENCES Diseases(disease_id)
);

-- Step 5: Create the Visits table (with direct image references)
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    visit_date DATE NOT NULL,
    visit_notes TEXT,

    -- FAF data for both eyes (OD & OS)
    faf_test_id_OD INT DEFAULT NULL,
    faf_image_number_OD INT DEFAULT NULL,
    faf_reference_OD VARCHAR(255) DEFAULT NULL,

    faf_test_id_OS INT DEFAULT NULL,
    faf_image_number_OS INT DEFAULT NULL,
    faf_reference_OS VARCHAR(255) DEFAULT NULL,

    -- OCT data for both eyes (OD & OS)
    oct_test_id_OD INT DEFAULT NULL,
    oct_image_number_OD INT DEFAULT NULL,
    oct_reference_OD VARCHAR(255) DEFAULT NULL,

    oct_test_id_OS INT DEFAULT NULL,
    oct_image_number_OS INT DEFAULT NULL,
    oct_reference_OS VARCHAR(255) DEFAULT NULL,

    -- Visual Field (VF) data for both eyes (OD & OS)
    vf_test_id_OD INT DEFAULT NULL,
    vf_image_number_OD INT DEFAULT NULL,
    vf_reference_OD VARCHAR(255) DEFAULT NULL,

    vf_test_id_OS INT DEFAULT NULL,
    vf_image_number_OS INT DEFAULT NULL,
    vf_reference_OS VARCHAR(255) DEFAULT NULL,

    -- MFERG data for both eyes (OD & OS)
    mferg_test_id_OD INT DEFAULT NULL,
    mferg_image_number_OD INT DEFAULT NULL,
    mferg_reference_OD VARCHAR(255) DEFAULT NULL,

    mferg_test_id_OS INT DEFAULT NULL,
    mferg_image_number_OS INT DEFAULT NULL,
    mferg_reference_OS VARCHAR(255) DEFAULT NULL,

    -- MERCI ratings for both eyes
    merci_rating_left_eye INT DEFAULT NULL,
    merci_rating_right_eye INT DEFAULT NULL,

    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

-- Insert sample patients (without MERCI)
INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes)
VALUES 
('Halifax', 1, 1985, 'f', 'Dr. Smith', 1.5, 1.5, 'Routine check-up', 50, 2, 100, '2025-12-31', 'Patient is responding well to treatment'),
('Montreal', 2, 1975, 'm', 'Dr. Johnson', 1.0, 1.0, 'Follow-up for RA', 75, 3, 225, NULL, 'Patient has experienced some joint pain'),
('Kensington', 3, 1990, 'f', 'Dr. Lee', 1.2, 1.2, 'Initial consultation, ongoing monitoring', 100, 1, 100, NULL, 'No complications observed so far'),
('Halifax', 4, 1980, 'm', 'Dr. Martin', 1.8, 1.8, 'Sjorgens diagnosis confirmed, prescribed treatment', 200, 4, 800, '2023-08-15', 'Treatment has been effective, but eye dryness persists');

-- Insert sample visits with direct image filenames
INSERT INTO Visits (patient_id, visit_date, visit_notes, 
    faf_test_id_OD, faf_image_number_OD, faf_reference_OD,
    faf_test_id_OS, faf_image_number_OS, faf_reference_OS,
    oct_test_id_OD, oct_image_number_OD, oct_reference_OD,
    oct_test_id_OS, oct_image_number_OS, oct_reference_OS,
    vf_test_id_OD, vf_image_number_OD, vf_reference_OD,
    vf_test_id_OS, vf_image_number_OS, vf_reference_OS,
    mferg_test_id_OD, mferg_image_number_OD, mferg_reference_OD,
    mferg_test_id_OS, mferg_image_number_OS, mferg_reference_OS,
    merci_rating_left_eye, merci_rating_right_eye)
VALUES 
(1, '2023-06-01', 'Routine check-up, no concerns noted', 
    123, 555, '101_OD_20250513.png',
    124, 556, '101_OS_20250513.png',
    201, 801, '101_OD_20250513.png',
    202, 802, '101_OS_20250513.png',
    301, 901, '101_OD_20250513.png',
    302, 902, '101_OS_20250513.png',
    401, 1001, '101_OD_20250513.png',
    402, 1002, '101_OS_20250513.png',
    10, 12),
(2, '2023-06-10', 'Follow-up for RA, increased symptoms', 
    125, 557, '220_OD_20250415.png',
    126, 558, '220_OS_20250415.png',
    203, 803, '220_OD_20250415.png',
    204, 804, '220_OS_20250415.png',
    303, 902, '220_OD_20250415.png',
    304, 903, '220_OS_20250415.png',
    403, 1003, '220_OD_20250415.png',
    404, 1004, '220_OS_20250415.png',
    15, 14),
(3, '2023-07-01', 'New patient, first consultation', 
    127, 559, '12_OD_20250505.png',
    128, 560, '12_OS_20250505.png',
    205, 805, '12_OD_20250505.png',
    206, 806, '12_OS_20250505.png',
    305, 903, '12_OD_20250505.png',
    306, 904, '12_OS_20250505.png',
    405, 1005, '12_OD_20250505.png',
    406, 1006, '12_OS_20250505.png',
    8, 10),
(4, '2023-07-15', 'Sjorgens diagnosis confirmed, prescribed treatment', 
    129, 561, '18_OD_20241127.png',
    130, 562, '18_OS_20241127.png',
    207, 807, '18_OD_20241127.png',
    208, 808, '18_OS_20241127.png',
    307, 905, '18_OD_20241127.png',
    308, 906, '18_OS_20241127.png',
    407, 1007, '18_OD_20241127.png',
    408, 1008, '18_OS_20241127.png',
    18, 17);

-- Query: View visit with patient and all eye data (both OD and OS)
SELECT
    v.visit_id,
    v.visit_date,
    v.visit_notes,
    v.faf_reference_OD,
    v.faf_reference_OS,
    v.oct_reference_OD,
    v.oct_reference_OS,
    v.vf_reference_OD,
    v.vf_reference_OS,
    v.mferg_reference_OD,
    v.mferg_reference_OS,
    v.merci_rating_left_eye,
    v.merci_rating_right_eye,
    p.patient_id,
    p.location,
    p.disease_id,
    p.year_of_birth,
    p.gender,
    p.referring_doctor,
    p.rx_OD,
    p.rx_OS,
    p.procedures_done,
    p.dosage,
    p.duration,
    p.cumulative_dosage,
    p.date_of_discontinuation,
    p.extra_notes
FROM Visits v
JOIN Patients p ON v.patient_id = p.patient_id
WHERE v.visit_id = 1;

-- Query: Check missing visit data for both OD and OS
SELECT v.visit_id, v.patient_id, v.visit_date, v.visit_notes,
    CASE
        WHEN faf_reference_OD IS NULL OR faf_reference_OS IS NULL THEN 'FAF data missing for one or both eyes'
        WHEN oct_reference_OD IS NULL OR oct_reference_OS IS NULL THEN 'OCT data missing for one or both eyes'
        WHEN vf_reference_OD IS NULL OR vf_reference_OS IS NULL THEN 'VF data missing for one or both eyes'
        WHEN mferg_reference_OD IS NULL OR mferg_reference_OS IS NULL THEN 'MFERG data missing for one or both eyes'
        ELSE 'All visit data present'
    END AS visit_status
FROM Visits v;


