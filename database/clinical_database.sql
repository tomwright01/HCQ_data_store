-- Database creation
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Patients table
CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    subject_id VARCHAR(50) NOT NULL,
    date_of_birth DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject (subject_id)
);

-- Tests table (fully synchronized with PHP code)
CREATE TABLE tests (
    test_id VARCHAR(20) PRIMARY KEY,
    patient_id VARCHAR(20) NOT NULL,
    date_of_test DATE NOT NULL,
    age TINYINT UNSIGNED NULL COMMENT 'Patient age at time of test (0-100)',
    eye ENUM('OD', 'OS') NULL COMMENT 'OD=right eye, OS=left eye',
    report_diagnosis ENUM('normal', 'abnormal', 'no input') NOT NULL DEFAULT 'no input',
    exclusion ENUM('none', 'retinal detachment', 'generalized retinal dysfunction', 'unilateral testing') NOT NULL DEFAULT 'none',
    merci_score VARCHAR(10) NULL COMMENT '0-100 or "unable"',
    merci_diagnosis ENUM('normal', 'abnormal', 'no value') NOT NULL DEFAULT 'no value',
    error_type ENUM('TN', 'FP', 'TP', 'FN', 'NONE') NULL DEFAULT NULL,
    faf_grade TINYINT UNSIGNED NULL COMMENT 'Fundus Autofluorescence grade (1-4)',
    oct_score DECIMAL(10,2) NULL COMMENT 'Optical Coherence Tomography score',
    vf_score DECIMAL(10,2) NULL COMMENT 'Visual Field score',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test),
    CONSTRAINT chk_age CHECK (age IS NULL OR (age BETWEEN 0 AND 100)),
    CONSTRAINT chk_merci_score CHECK (
        merci_score IS NULL OR 
        merci_score = 'unable' OR 
        (merci_score REGEXP '^[0-9]+$' AND CAST(merci_score AS UNSIGNED) BETWEEN 0 AND 100)
    ),
    CONSTRAINT chk_faf_grade CHECK (faf_grade IS NULL OR (faf_grade BETWEEN 1 AND 4))
);
