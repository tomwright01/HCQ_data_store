-- Step 1: Create the database
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
