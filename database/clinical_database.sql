-- Step 1: Create the database
CREATE DATABASE IF NOT EXISTS PatientData;

-- Step 2: Use the created database
USE PatientData;

-- Step 3: Create a table for disease categories
CREATE TABLE IF NOT EXISTS Diseases (
    disease_id INT PRIMARY KEY,
    disease_name ENUM('Lupus', 'Rheumatoid Arthritis', 'RTMD', 'Sjorgens') NOT NULL
);

-- Insert predefined diseases into the Diseases table
INSERT INTO Diseases (disease_id, disease_name) 
VALUES
(1, 'Lupus'),
(2, 'Rheumatoid Arthritis'),
(3, 'RTMD'),
(4, 'Sjorgens');

-- Step 4: Create the Patients table with additional fields, including FAF reference
CREATE TABLE IF NOT EXISTS Patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,  -- unique patient identifier
    location ENUM('Halifax', 'Kensington', 'Montreal') DEFAULT NULL,  -- optional location field
    disease_id INT,  -- references the Diseases table
    year_of_birth INT CHECK (year_of_birth BETWEEN 1900 AND 2023),  -- optional year of birth field
    gender ENUM('m', 'f') DEFAULT NULL,  -- gender field (m for male, f for female)
    referring_doctor VARCHAR(255) DEFAULT NULL,  -- referring doctor's name or ID
    rx_OD FLOAT DEFAULT NULL,  -- Prescription for the right eye (OD)
    rx_OS FLOAT DEFAULT NULL,  -- Prescription for the left eye (OS)
    procedures_done TEXT DEFAULT NULL,  -- procedures done on the patient
    dosage FLOAT DEFAULT NULL,  -- Dosage in milligrams
    duration INT DEFAULT NULL,  -- Duration of treatment in years
    cumulative_dosage FLOAT DEFAULT NULL,  -- Cumulative dosage
    date_of_discontinuation DATE DEFAULT NULL,  -- Date of treatment discontinuation
    extra_notes TEXT DEFAULT NULL,  -- Extra notes from the doctor
    merci_rating_left_eye INT DEFAULT NULL,  -- MERCI rating for the left eye
    merci_rating_right_eye INT DEFAULT NULL,  -- MERCI rating for the right eye
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,  -- Timestamp when the patient record is added
    FOREIGN KEY (disease_id) REFERENCES Diseases(disease_id)  -- foreign key referencing the Diseases table
);

-- Example of inserting a patient with all data
INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor, rx_OD, rx_OS, procedures_done, dosage, duration, cumulative_dosage, date_of_discontinuation, extra_notes, merci_rating_left_eye, merci_rating_right_eye)
VALUES 
('Halifax', 1, 1985, 'f', 'Dr. Smith', 1.5, 1.5, 'Routine check-up', 50, 2, 100, '2025-12-31', 'Patient is responding well to treatment', 10, 12),
('Montreal', 2, 1975, 'm', 'Dr. Johnson', 1.0, 1.0, 'Follow-up for RA', 75, 3, 225, NULL, 'Patient has experienced some joint pain', 15, 14),
('Kensington', 3, 1990, 'f', 'Dr. Lee', 1.2, 1.2, 'Initial consultation, ongoing monitoring', 100, 1, 100, NULL, 'No complications observed so far', 8, 10),
('Halifax', 4, 1980, 'm', 'Dr. Martin', 1.8, 1.8, 'Sjorgens diagnosis confirmed, prescribed treatment', 200, 4, 800, '2023-08-15', 'Treatment has been effective, but eye dryness persists', 18, 17);

-- Step 5: Create the Visits table with additional FAF reference field
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,  -- Unique visit identifier
    patient_id INT,  -- Foreign key to reference Patients table
    visit_date DATE NOT NULL,  -- Date of the visit
    visit_notes TEXT,  -- Optional field for additional notes
    faf_test_id INT DEFAULT NULL,  -- FAF test ID for the visit
    faf_eye ENUM('OD', 'OS') DEFAULT NULL,  -- FAF eye (OD or OS) for the visit
    image_number INT DEFAULT NULL,  -- Image number for the FAF
    faf_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('FAF/', faf_test_id, '-', faf_eye, '-', image_number, '.png')
    ) STORED,  -- Generated column for FAF reference
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,  -- Timestamp of when the visit was logged
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)  -- Foreign key constraint
);

-- Insert sample visit data for patients with FAF-related information
INSERT INTO Visits (patient_id, visit_date, visit_notes, faf_test_id, faf_eye, image_number)
VALUES 
(1, '2023-06-01', 'Routine check-up, no concerns noted', 123, 'OD', 555),
(2, '2023-06-10', 'Follow-up for RA, increased symptoms', 124, 'OS', 556),
(3, '2023-07-01', 'New patient, first consultation', 125, 'OD', 557),
(4, '2023-07-15', 'Sjorgens diagnosis confirmed, prescribed treatment', 126, 'OS', 558);

-- Step 6: Query to view visit details including patient and FAF information for a specific visit_id
SELECT
    v.visit_id,
    v.visit_date,
    v.visit_notes,
    v.faf_reference,  -- FAF reference generated based on test_id, eye, image_number
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
    p.extra_notes,
    p.merci_rating_left_eye,
    p.merci_rating_right_eye
FROM Visits v
JOIN Patients p ON v.patient_id = p.patient_id
WHERE v.visit_id = 1;  -- Specify the visit_id to view

-- Query to find missing data for patients
SELECT 
    patient_id,
    location,
    disease_id,
    year_of_birth,
    gender,
    referring_doctor,
    rx_OD,
    rx_OS,
    procedures_done,
    dosage,
    duration,
    cumulative_dosage,
    date_of_discontinuation,
    extra_notes,
    merci_rating_left_eye,
    merci_rating_right_eye,
    faf_test_id,
    faf_eye,
    image_number,
    faf_reference,  -- Display the generated faf_reference column
    CASE
        WHEN location IS NULL THEN 'Location missing'
        WHEN disease_id IS NULL THEN 'Disease missing'
        WHEN year_of_birth IS NULL THEN 'Year of birth missing'
        WHEN gender IS NULL THEN 'Gender missing'
        WHEN referring_doctor IS NULL THEN 'Referring doctor missing'
        WHEN rx_OD IS NULL THEN 'Rx OD missing'
        WHEN rx_OS IS NULL THEN 'Rx OS missing'
        WHEN procedures_done IS NULL THEN 'Procedures missing'
        WHEN dosage IS NULL THEN 'Dosage missing'
        WHEN duration IS NULL THEN 'Duration missing'
        WHEN cumulative_dosage IS NULL THEN 'Cumulative dosage missing'
        WHEN date_of_discontinuation IS NULL THEN 'Date of discontinuation missing'
        WHEN extra_notes IS NULL THEN 'Extra notes missing'
        WHEN merci_rating_left_eye IS NULL THEN 'Merci rating left eye missing'
        WHEN merci_rating_right_eye IS NULL THEN 'Merci rating right eye missing'
        WHEN faf_test_id IS NULL THEN 'FAF test ID missing'
        WHEN faf_eye IS NULL THEN 'FAF eye missing'
        WHEN image_number IS NULL THEN 'Image number missing'
        ELSE 'All data present'
    END AS data_status
FROM Patients;

-- View all patient records
SELECT * FROM Patients;

-- Query to find visits with missing data
SELECT v.visit_id, v.patient_id, v.visit_date, v.visit_notes,
    CASE
        WHEN visit_date IS NULL THEN 'Visit date missing'
        WHEN visit_notes IS NULL THEN 'Visit notes missing'
        ELSE 'All visit data present'
    END AS visit_status
FROM Visits v;






