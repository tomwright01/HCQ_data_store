-- Database creation
CREATE DATABASE PatientData;
USE PatientData;

-- Patients table with enhanced constraints
CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    dob DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visits table with comprehensive constraints
CREATE TABLE visits (
    visit_id VARCHAR(20) PRIMARY KEY,
    patient_id VARCHAR(20) NOT NULL,
    dot DATE NOT NULL,
    eye ENUM('OD', 'OS') NOT NULL COMMENT 'OD=right eye, OS=left eye',
    report_diagnosis ENUM('normal', 'abnormal', 'exclude') NOT NULL,
    exclusion ENUM('none', 'retinal detachment', 'generalized retinal dysfunction') NOT NULL DEFAULT 'none',
    merci TINYINT UNSIGNED NOT NULL CHECK (merci BETWEEN 1 AND 100),
    merci_diagnosis ENUM('normal', 'abnormal') NOT NULL,
    error ENUM('TN', 'FP') NOT NULL COMMENT 'TN=True Negative, FP=False Positive',
    faf INT COMMENT 'Fundus Autofluorescence score',
    oct INT COMMENT 'Optical Coherence Tomography score',
    vf INT COMMENT 'Visual Field score',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    CONSTRAINT chk_merci_range CHECK (merci BETWEEN 1 AND 100),
    INDEX idx_patient (patient_id),
    INDEX idx_date (dot),
    INDEX idx_diagnosis (report_diagnosis),
    INDEX idx_eye (eye)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

