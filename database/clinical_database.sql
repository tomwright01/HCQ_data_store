/*-- Patient Management System with FAF Imaging
-- Database Schema for MariaDB/MySQL

-- 1. Database Setup
CREATE DATABASE IF NOT EXISTS PatientManagementSystem;
USE PatientManagementSystem;

-- 2. Disease Reference Table
CREATE TABLE IF NOT EXISTS Diseases (
    disease_id INT PRIMARY KEY,
    disease_name ENUM('Lupus', 'Rheumatoid Arthritis', 'RTMD', 'Sjorgens') NOT NULL,
    description TEXT,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Patient Core Information
CREATE TABLE IF NOT EXISTS Patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    medical_record_number VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female', 'Other', 'Prefer not to say') NOT NULL,
    primary_phone VARCHAR(15),
    email VARCHAR(100),
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. Clinic Locations
CREATE TABLE IF NOT EXISTS Locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(50) NOT NULL,
    address VARCHAR(100),
    city VARCHAR(50),
    province VARCHAR(50),
    postal_code VARCHAR(10),
    UNIQUE KEY (location_name)
);

-- 5. Visits with FAF Imaging Data
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    location_id INT NOT NULL,
    visit_date DATE NOT NULL,
    visit_time TIME,
    physician_id INT,
    disease_id INT,
    
    -- Ocular Measurements
    rx_od_sphere DECIMAL(4,2),
    rx_od_cylinder DECIMAL(4,2),
    rx_od_axis INT,
    rx_os_sphere DECIMAL(4,2),
    rx_os_cylinder DECIMAL(4,2),
    rx_os_axis INT,
    merci_od INT CHECK (merci_od BETWEEN 0 AND 100),
    merci_os INT CHECK (merci_os BETWEEN 0 AND 100),
    
    -- FAF Imaging Data (Required Fields)
    faf_session_id VARCHAR(20) NOT NULL,
    faf_eye ENUM('OD', 'OS', 'OU') NOT NULL,
    faf_image_series VARCHAR(20) NOT NULL,
    faf_quality ENUM('Excellent', 'Good', 'Fair', 'Poor') NOT NULL,
    
    -- Generated FAF Reference (Virtual for Performance)
    faf_reference VARCHAR(255) GENERATED ALWAYS AS (
        CONCAT('FAF/', DATE_FORMAT(visit_date, '%Y/%m/%d'), '/', 
               faf_session_id, '/', faf_eye, '/Series_', faf_image_series, '/')
    ) VIRTUAL,
    
    -- Clinical Notes
    visit_notes TEXT,
    treatment_plan TEXT,
    follow_up_required BOOLEAN DEFAULT FALSE,
    follow_up_date DATE,
    
    -- System Metadata
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    added_by INT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id),
    FOREIGN KEY (location_id) REFERENCES Locations(location_id),
    FOREIGN KEY (disease_id) REFERENCES Diseases(disease_id),
    
    -- Indexes for Performance
    INDEX idx_visit_date (visit_date),
    INDEX idx_faf_session (faf_session_id),
    INDEX idx_faf_reference (faf_reference(100))
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Insert Reference Data
INSERT INTO Diseases (disease_id, disease_name, description) VALUES
(1, 'Lupus', 'Systemic lupus erythematosus'),
(2, 'Rheumatoid Arthritis', 'Chronic inflammatory disorder'),
(3, 'RTMD', 'Retinal thickness measurement disorder'),
(4, 'Sjorgens', 'Sjögren\'s syndrome');

INSERT INTO Locations (location_name, city, province) VALUES
('Halifax', 'Halifax', 'NS'),
('Kensington', 'Toronto', 'ON'),
('Montreal', 'Montreal', 'QC');

-- 7. Sample Patient Data
INSERT INTO Patients (
    medical_record_number, first_name, last_name, date_of_birth, gender, primary_phone
) VALUES
('MRN12345', 'Sarah', 'Johnson', '1985-03-15', 'Female', '9025551234'),
('MRN12346', 'Michael', 'Brown', '1978-07-22', 'Male', '9025555678');

-- 8. Sample Visit Data with FAF Imaging
INSERT INTO Visits (
    patient_id, location_id, visit_date, physician_id, disease_id,
    rx_od_sphere, rx_od_cylinder, rx_od_axis,
    rx_os_sphere, rx_os_cylinder, rx_os_axis,
    merci_od, merci_os,
    faf_session_id, faf_eye, faf_image_series, faf_quality,
    visit_notes
) VALUES
(1, 1, '2023-06-15', 101, 1, 
 1.25, -0.50, 180, 
 1.50, -0.75, 170,
 12, 14,
 'FAF2023-001', 'OD', 'Series1', 'Good',
 'Routine follow-up, no significant changes observed'),

(1, 1, '2023-09-10', 101, 1,
 1.25, -0.50, 180,
 1.50, -0.75, 170,
 11, 13,
 'FAF2023-002', 'OS', 'Series2', 'Excellent',
 'Patient reports mild discomfort in left eye');

-- 9. Optimized View for Physician Dashboard
CREATE OR REPLACE VIEW PhysicianVisitView AS
SELECT 
    v.visit_id,
    p.medical_record_number,
    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
    p.date_of_birth,
    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
    p.gender,
    v.visit_date,
    l.location_name,
    d.disease_name,
    
    -- Ocular Data
    CONCAT(v.rx_od_sphere, '/', v.rx_od_cylinder, 'x', v.rx_od_axis) AS rx_od,
    CONCAT(v.rx_os_sphere, '/', v.rx_os_cylinder, 'x', v.rx_os_axis) AS rx_os,
    v.merci_od,
    v.merci_os,
    
    -- FAF Data (Guaranteed to Show)
    v.faf_session_id,
    v.faf_eye,
    v.faf_image_series,
    v.faf_quality,
    v.faf_reference,
    
    -- Clinical Notes
    v.visit_notes,
    v.treatment_plan,
    v.follow_up_required,
    v.follow_up_date
FROM 
    Visits v
JOIN 
    Patients p ON v.patient_id = p.patient_id
JOIN 
    Locations l ON v.location_id = l.location_id
LEFT JOIN 
    Diseases d ON v.disease_id = d.disease_id;

-- 10. Query Examples
-- Get all visits for a patient
SELECT * FROM PhysicianVisitView 
WHERE medical_record_number = 'MRN12345' 
ORDER BY visit_date DESC;

-- Get specific visit with FAF data
SELECT * FROM PhysicianVisitView 
WHERE visit_id = 1;

-- Find visits with incomplete data
SELECT 
    visit_id,
    patient_name,
    visit_date,
    CASE
        WHEN rx_od IS NULL THEN 'Missing OD prescription'
        WHEN rx_os IS NULL THEN 'Missing OS prescription'
        WHEN merci_od IS NULL THEN 'Missing OD MERCI'
        WHEN merci_os IS NULL THEN 'Missing OS MERCI'
        WHEN faf_reference IS NULL THEN 'Missing FAF data'
        ELSE 'Complete record'
    END AS data_status
FROM PhysicianVisitView;

/*-- Create database
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Diseases table
CREATE TABLE IF NOT EXISTS Diseases (
    disease_id INT PRIMARY KEY,
    disease_name ENUM('Lupus', 'Rheumatoid Arthritis', 'RTMD', 'Sjorgens') NOT NULL
);

INSERT INTO Diseases (disease_id, disease_name) VALUES
(1, 'Lupus'), (2, 'Rheumatoid Arthritis'), (3, 'RTMD'), (4, 'Sjorgens');

-- Patients table
CREATE TABLE IF NOT EXISTS Patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    location ENUM('Halifax', 'Kensington', 'Montreal') DEFAULT NULL,
    disease_id INT,
    year_of_birth INT CHECK (year_of_birth BETWEEN 1900 AND YEAR(CURRENT_DATE)),
    gender ENUM('m', 'f', 'other') DEFAULT NULL,
    referring_doctor VARCHAR(255) DEFAULT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (disease_id) REFERENCES Diseases(disease_id)
);

-- Visits table with all exam data
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    visit_date DATE NOT NULL,
    rx_OD FLOAT DEFAULT NULL,
    rx_OS FLOAT DEFAULT NULL,
    merci_rating_left_eye INT DEFAULT NULL,
    merci_rating_right_eye INT DEFAULT NULL,
    procedures_done TEXT DEFAULT NULL,
    dosage FLOAT DEFAULT NULL,
    duration INT DEFAULT NULL COMMENT 'Months',
    cumulative_dosage FLOAT DEFAULT NULL,
    date_of_discontinuation DATE DEFAULT NULL,
    faf_test_id VARCHAR(50) DEFAULT NULL,
    faf_eye ENUM('OD', 'OS', 'OU') DEFAULT NULL,
    faf_image_number VARCHAR(50) DEFAULT NULL,
    faf_reference VARCHAR(255) GENERATED ALWAYS AS (
        CASE WHEN faf_test_id IS NOT NULL AND faf_eye IS NOT NULL AND faf_image_number IS NOT NULL 
        THEN CONCAT('FAF/', faf_test_id, '-', faf_eye, '-', faf_image_number, '.png') 
        ELSE NULL END
    ) STORED,
    visit_notes TEXT DEFAULT NULL,
    extra_notes TEXT DEFAULT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

-- Sample data
INSERT INTO Patients (location, disease_id, year_of_birth, gender, referring_doctor) VALUES
('Halifax', 1, 1985, 'f', 'Dr. Smith'),
('Montreal', 2, 1975, 'm', 'Dr. Johnson');

INSERT INTO Visits (patient_id, visit_date, rx_OD, rx_OS, merci_rating_left_eye, merci_rating_right_eye, faf_test_id, faf_eye, faf_image_number) VALUES
(1, '2023-06-01', 1.5, 1.5, 10, 12, 'FAF123', 'OD', 'IMG555'),
(2, '2023-06-10', 1.0, 1.0, 15, 14, 'FAF124', 'OS', 'IMG556');/*-- Step 1: Create the database
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




/*-- Step 1: Create the database
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







