
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

-- Step 5: Create the Visits table (with MERCI, FAF, OCT, VF, and MFERG)
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    visit_date DATE NOT NULL,
    visit_notes TEXT,

    -- FAF data
    faf_test_id INT DEFAULT NULL,
    faf_eye ENUM('OD', 'OS') DEFAULT NULL,
    image_number INT DEFAULT NULL,
    faf_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('FAF/', faf_test_id, '-', faf_eye, '-', image_number, '.png')
    ) STORED,

    -- OCT data
    oct_test_id INT DEFAULT NULL,
    oct_eye ENUM('OD', 'OS') DEFAULT NULL,
    oct_image_number INT DEFAULT NULL,
    oct_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('OCT/', oct_test_id, '-', oct_eye, '-', oct_image_number, '.png')
    ) STORED,

    -- Visual Field (VF) data
    vf_test_id INT DEFAULT NULL,
    vf_eye ENUM('OD', 'OS') DEFAULT NULL,
    vf_image_number INT DEFAULT NULL,
    vf_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('VF/', vf_test_id, '-', vf_eye, '-', vf_image_number, '.png')
    ) STORED,

    -- MFERG data
    mferg_test_id INT DEFAULT NULL,
    mferg_eye ENUM('OD', 'OS') DEFAULT NULL,
    mferg_image_number INT DEFAULT NULL,
    mferg_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('MFERG/', mferg_test_id, '-', mferg_eye, '-', mferg_image_number, '.png')
    ) STORED,

    -- MERCI
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

-- Insert sample visits (FAF, OCT, VF, MFERG, and MERCI included)
INSERT INTO Visits (patient_id, visit_date, visit_notes, faf_test_id, faf_eye, image_number, oct_test_id, oct_eye, oct_image_number, vf_test_id, vf_eye, vf_image_number, mferg_test_id, mferg_eye, mferg_image_number, merci_rating_left_eye, merci_rating_right_eye)
VALUES 
(1, '2023-06-01', 'Routine check-up, no concerns noted', 123, 'OD', 555, 201, 'OD', 801, 301, 'OD', 901, 401, 'OD', 1001, 10, 12),
(2, '2023-06-10', 'Follow-up for RA, increased symptoms', 124, 'OS', 556, 202, 'OS', 802, 302, 'OS', 902, 402, 'OS', 1002, 15, 14),
(3, '2023-07-01', 'New patient, first consultation', 125, 'OD', 557, 203, 'OD', 803, 303, 'OD', 903, 403, 'OD', 1003, 8, 10),
(4, '2023-07-15', 'Sjorgens diagnosis confirmed, prescribed treatment', 126, 'OS', 558, 204, 'OS', 804, 304, 'OS', 904, 404, 'OS', 1004, 18, 17);

-- Query: View visit with patient, MERCI, FAF, OCT, VF, and MFERG info
SELECT
    v.visit_id,
    v.visit_date,
    v.visit_notes,
    v.faf_reference,
    v.oct_reference,
    v.vf_reference,
    v.mferg_reference,
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

-- Query: View all patients
SELECT * FROM Patients;

-- Query: Check missing visit data (updated for FAF, OCT, VF, and MFERG)
SELECT v.visit_id, v.patient_id, v.visit_date, v.visit_notes,
    CASE
        WHEN visit_date IS NULL THEN 'Visit date missing'
        WHEN visit_notes IS NULL THEN 'Visit notes missing'
        WHEN merci_rating_left_eye IS NULL THEN 'MERCI left eye missing'
        WHEN merci_rating_right_eye IS NULL THEN 'MERCI right eye missing'
        WHEN faf_test_id IS NULL OR faf_eye IS NULL OR image_number IS NULL THEN 'FAF data missing'
        WHEN oct_test_id IS NULL OR oct_eye IS NULL OR oct_image_number IS NULL THEN 'OCT data missing'
        WHEN vf_test_id IS NULL OR vf_eye IS NULL OR vf_image_number IS NULL THEN 'VF data missing'
        WHEN mferg_test_id IS NULL OR mferg_eye IS NULL OR mferg_image_number IS NULL THEN 'MFERG data missing'
        ELSE 'All visit data present'
    END AS visit_status
FROM Visits v;
