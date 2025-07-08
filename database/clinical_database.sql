-- Step 1: Create the database
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Step 2: Create Patients table with new structure
CREATE TABLE IF NOT EXISTS Patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id VARCHAR(50) NOT NULL UNIQUE,
    date_of_birth DATE NOT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Step 3: Create Tests table
CREATE TABLE IF NOT EXISTS Tests (
    test_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    date_of_test DATE NOT NULL,
    eye ENUM('OD', 'OS') NOT NULL,
    report_diagnosis ENUM('normal', 'abnormal', 'no input') DEFAULT 'no input',
    exclusion ENUM('retinal detachment', 'generalized retinal dysfunction', 'unilateral testing', 'none') DEFAULT 'none',
    merci_score TINYINT CHECK (merci_score BETWEEN 1 AND 100 OR merci_score IS NULL),
    merci_diagnosis ENUM('normal', 'abnormal', 'no value') DEFAULT 'no value',
    error_type ENUM('TN', 'FP', 'none') DEFAULT 'none',
    faf_grade TINYINT CHECK (faf_grade BETWEEN 1 AND 4 OR faf_grade IS NULL),
    oct_score DECIMAL(5,2) CHECK (oct_score BETWEEN 0 AND 10 OR oct_score IS NULL),
    vf_score DECIMAL(5,2) CHECK (vf_score BETWEEN 0 AND 10 OR vf_score IS NULL),
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

-- Create indexes for better performance
CREATE INDEX idx_patient_subject ON Patients(subject_id);
CREATE INDEX idx_test_patient ON Tests(patient_id);
CREATE INDEX idx_test_date ON Tests(date_of_test);







/*-- Step 1: Create the database
CREATE DATABASE IF NOT EXISTS PatientData;

-- Step 2: Use the created database
USE PatientData;

-- Step 3: Create a table for disease categories
CREATE TABLE IF NOT EXISTS Diseases (
    disease_id INT PRIMARY KEY,
    disease_name ENUM('Lupus', 'Rheumatoid Arthritis', 'RTMD', 'Sjorgens') NOT NULL
);

-- Step 4: Create the Patients table
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

-- Step 5: Create the Visits table
CREATE TABLE IF NOT EXISTS Visits (
    visit_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    visit_date DATE NOT NULL,
    visit_notes TEXT,
    faf_reference_OD VARCHAR(255) DEFAULT NULL,
    faf_reference_OS VARCHAR(255) DEFAULT NULL,
    oct_reference_OD VARCHAR(255) DEFAULT NULL,
    oct_reference_OS VARCHAR(255) DEFAULT NULL,
    vf_reference_OD VARCHAR(255) DEFAULT NULL,
    vf_reference_OS VARCHAR(255) DEFAULT NULL,
    mferg_reference_OD VARCHAR(255) DEFAULT NULL,
    mferg_reference_OS VARCHAR(255) DEFAULT NULL,
    merci_rating_left_eye INT DEFAULT NULL,
    merci_rating_right_eye INT DEFAULT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

-- Create FAF Images table if not exists
CREATE TABLE IF NOT EXISTS FAF_Images (
    faf_reference VARCHAR(255) PRIMARY KEY,
    faf_score DECIMAL(10,2) NOT NULL,
    patient_id INT NOT NULL,
    eye_side ENUM('OD', 'OS') NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

-- Create similar tables for other image types
CREATE TABLE IF NOT EXISTS OCT_Images (
    oct_reference VARCHAR(255) PRIMARY KEY,
    oct_score DECIMAL(10,2) NOT NULL,
    patient_id INT NOT NULL,
    eye_side ENUM('OD', 'OS') NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

CREATE TABLE IF NOT EXISTS VF_Images (
    vf_reference VARCHAR(255) PRIMARY KEY,
    vf_score DECIMAL(10,2) NOT NULL,
    patient_id INT NOT NULL,
    eye_side ENUM('OD', 'OS') NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

CREATE TABLE IF NOT EXISTS MFERG_Images (
    mferg_reference VARCHAR(255) PRIMARY KEY,
    mferg_score DECIMAL(10,2) NOT NULL,
    patient_id INT NOT NULL,
    eye_side ENUM('OD', 'OS') NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES Patients(patient_id)
);

CREATE TABLE IF NOT EXISTS Grading (
    grading_id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    test_type ENUM('faf', 'oct', 'vf', 'mferg') NOT NULL,
    eye_side ENUM('OD', 'OS') NOT NULL,
    score_type ENUM('stage', 'merci', 'severity', 'other') NOT NULL,
    score_value TINYINT NOT NULL,
    notes TEXT,
    grader_id INT,
    date_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES Visits(visit_id),
    FOREIGN KEY (grader_id) REFERENCES Users(user_id)  -- Assuming you have a Users table
);
*/
