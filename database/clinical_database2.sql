-- Create the database
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Patients table
CREATE TABLE IF NOT EXISTS patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    dob DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Visits table
CREATE TABLE IF NOT EXISTS visits (
    visit_id VARCHAR(20) PRIMARY KEY,
    patient_id VARCHAR(20) NOT NULL,
    dot DATE NOT NULL,
    eye ENUM('OD', 'OS') NOT NULL COMMENT 'OD=right eye, OS=left eye',
    report_diagnosis ENUM('normal', 'abnormal', 'exclude') NOT NULL,
    exclusion ENUM('none', 'retinal detachment', 'generalized retinal dysfunction') NOT NULL DEFAULT 'none',
    merci TINYINT UNSIGNED NOT NULL CHECK (merci BETWEEN 1 AND 100),
    merci_diagnosis ENUM('normal', 'abnormal') NOT NULL,
    error ENUM('TN', 'FP') NOT NULL COMMENT 'TN=True Negative, FP=False Positive',
    faf INT,
    oct INT,
    vf INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX (patient_id),
    INDEX (dot),
    INDEX (report_diagnosis)
);
