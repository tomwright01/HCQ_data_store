-- Database creation
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Patients table
CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    date_of_birth DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject (subject_id)
);

-- Tests table
CREATE TABLE tests (
    test_id VARCHAR(20) PRIMARY KEY,
    patient_id VARCHAR(20) NOT NULL,
    date_of_test DATE NOT NULL,
    eye ENUM('OD', 'OS') NOT NULL COMMENT 'OD=right eye, OS=left eye',
    report_diagnosis ENUM('normal', 'abnormal', 'exclude', 'no input') NOT NULL,
    exclusion ENUM('none', 'retinal detachment', 'generalized retinal dysfunction', 'unilateral testing') NOT NULL DEFAULT 'none',
    merci_score TINYINT UNSIGNED NOT NULL CHECK (merci_score BETWEEN 1 AND 100),
    merci_diagnosis ENUM('normal', 'abnormal', 'no value') NOT NULL,
    error_type ENUM('TN', 'FP', 'none') NOT NULL DEFAULT 'none',
    faf_grade TINYINT UNSIGNED COMMENT 'Fundus Autofluorescence grade (1-4)',
    oct_score DECIMAL(10,2) COMMENT 'Optical Coherence Tomography score',
    vf_score DECIMAL(10,2) COMMENT 'Visual Field score',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test)
);

