-- Database creation
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- Patients table with location
CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    subject_id VARCHAR(50) NOT NULL,
    location ENUM('KH', 'Montreal', 'Dal', 'Ivey') DEFAULT 'KH',
    date_of_birth DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject (subject_id),
    INDEX idx_location (location)
);

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    record_id VARCHAR(50) NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values TEXT,
    new_values TEXT,
    changed_by VARCHAR(100),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tests table with location and all image reference fields
CREATE TABLE tests (
    test_id VARCHAR(25) PRIMARY KEY,
    patient_id VARCHAR(25) NOT NULL,
    location ENUM('KH', 'Montreal', 'Dal', 'Ivey') DEFAULT 'KH',
    date_of_test DATE NOT NULL,
    age TINYINT UNSIGNED NULL COMMENT 'Patient age at time of test (0-100)',
    eye ENUM('OD', 'OS') NULL COMMENT 'OD=right eye, OS=left eye',
    report_diagnosis ENUM('normal', 'abnormal', 'no input') NOT NULL DEFAULT 'no input',
    exclusion ENUM('none', 'retinal detachment', 'generalized retinal dysfunction', 'unilateral testing') NOT NULL DEFAULT 'none',
    merci_score VARCHAR(10) NULL COMMENT '0-100 or "unable"',
    merci_diagnosis ENUM('normal', 'abnormal', 'no value') NOT NULL DEFAULT 'no value',
    error_type ENUM('TN', 'FP', 'TP', 'FN', 'none') DEFAULT NULL,
    faf_grade TINYINT UNSIGNED NULL COMMENT 'Fundus Autofluorescence grade (1-4)',
    oct_score DECIMAL(10,2) NULL COMMENT 'Optical Coherence Tomography score',
    vf_score DECIMAL(10,2) NULL COMMENT 'Visual Field score',
    
    -- Image reference fields
    faf_reference_od VARCHAR(255) NULL COMMENT 'Reference to FAF image for right eye (OD)',
    faf_reference_os VARCHAR(255) NULL COMMENT 'Reference to FAF image for left eye (OS)',
    oct_reference_od VARCHAR(255) NULL COMMENT 'Reference to OCT image for right eye (OD)',
    oct_reference_os VARCHAR(255) NULL COMMENT 'Reference to OCT image for left eye (OS)',
    vf_reference_od VARCHAR(255) NULL COMMENT 'Reference to VF image for right eye (OD)',
    vf_reference_os VARCHAR(255) NULL COMMENT 'Reference to VF image for left eye (OS)',
    mferg_reference_od VARCHAR(255) NULL COMMENT 'Reference to MFERG image for right eye (OD)',
    mferg_reference_os VARCHAR(255) NULL COMMENT 'Reference to MFERG image for left eye (OS)',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test),
    INDEX idx_location (location),
    
    -- Indexes for image reference fields
    INDEX idx_faf_od (faf_reference_od),
    INDEX idx_faf_os (faf_reference_os),
    INDEX idx_oct_od (oct_reference_od),
    INDEX idx_oct_os (oct_reference_os),
    INDEX idx_vf_od (vf_reference_od),
    INDEX idx_vf_os (vf_reference_os),
    INDEX idx_mferg_od (mferg_reference_od),
    INDEX idx_mferg_os (mferg_reference_os),
    
    CONSTRAINT chk_age CHECK (age IS NULL OR (age BETWEEN 0 AND 100)),
    CONSTRAINT chk_merci_score CHECK (
        merci_score IS NULL OR 
        merci_score = 'unable' OR 
        (merci_score REGEXP '^[0-9]+$' AND CAST(merci_score AS UNSIGNED) BETWEEN 0 AND 100)
    ),
    CONSTRAINT chk_faf_grade CHECK (faf_grade IS NULL OR (faf_grade BETWEEN 1 AND 4))
);
