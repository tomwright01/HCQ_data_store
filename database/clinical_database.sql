-- ==========================
-- FULL CLINICAL DATABASE SCHEMA (clean/recreate)
-- ==========================
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- ==========================
-- PATIENTS TABLE
-- ==========================
CREATE TABLE patients (
    patient_id VARCHAR(25) PRIMARY KEY,
    subject_id VARCHAR(50) NOT NULL,
    location ENUM('KH', 'CHUSJ', 'IWK', 'IVEY') DEFAULT 'KH',
    date_of_birth DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject (subject_id),
    INDEX idx_location (location)
);

-- ==========================
-- AUDIT LOG TABLE
-- ==========================
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    record_id VARCHAR(50) NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values TEXT,
    new_values TEXT,
    changed_by VARCHAR(100),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_record (table_name, record_id),
    INDEX idx_changed_at (changed_at)
);

-- ==========================
-- TESTS TABLE
-- ==========================
CREATE TABLE tests (
    -- Identifiers
    test_id VARCHAR(25) PRIMARY KEY,
    patient_id VARCHAR(25) NOT NULL,
    location ENUM('KH', 'CHUSJ', 'IWK', 'IVEY') DEFAULT 'KH',
    date_of_test DATE NOT NULL,

    -- Clinical / metadata
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
    actual_diagnosis ENUM('RA', 'SLE', 'Sjogren', 'other') NOT NULL DEFAULT 'other',
    medication_name VARCHAR(100) NULL COMMENT 'Name of prescribed medication',
    dosage DECIMAL(10,2) NULL COMMENT 'Medication dosage in mg',
    dosage_unit VARCHAR(10) DEFAULT 'mg',
    duration_days SMALLINT UNSIGNED NULL COMMENT 'Treatment duration in days',
    cumulative_dosage DECIMAL(10,2) NULL COMMENT 'Total cumulative dosage in mg',
    date_of_continuation DATE NULL COMMENT 'Date when treatment was continued',
    treatment_notes TEXT NULL COMMENT 'Additional notes about treatment',

    -- Image reference fields
    faf_reference_od VARCHAR(255) NULL,
    faf_reference_os VARCHAR(255) NULL,
    oct_reference_od VARCHAR(255) NULL,
    oct_reference_os VARCHAR(255) NULL,
    vf_reference_od VARCHAR(255) NULL,
    vf_reference_os VARCHAR(255) NULL,
    mferg_reference_od VARCHAR(255) NULL,
    mferg_reference_os VARCHAR(255) NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test),
    INDEX idx_location (location),
    INDEX idx_faf_od (faf_reference_od),
    INDEX idx_faf_os (faf_reference_os),
    INDEX idx_oct_od (oct_reference_od),
    INDEX idx_oct_os (oct_reference_os),
    INDEX idx_vf_od (vf_reference_od),
    INDEX idx_vf_os (vf_reference_os),
    INDEX idx_mferg_od (mferg_reference_od),
    INDEX idx_mferg_os (mferg_reference_os),
    INDEX idx_diagnosis (actual_diagnosis),
    INDEX idx_medication (medication_name),
    INDEX idx_continuation (date_of_continuation),

    -- Constraints
    CONSTRAINT chk_age CHECK (age IS NULL OR (age BETWEEN 0 AND 100)),
    CONSTRAINT chk_merci_score CHECK (
        merci_score IS NULL OR 
        merci_score = 'unable' OR 
        (merci_score REGEXP '^[0-9]+$' AND CAST(merci_score AS UNSIGNED) BETWEEN 0 AND 100)
    ),
    CONSTRAINT chk_faf_grade CHECK (faf_grade IS NULL OR (faf_grade BETWEEN 1 AND 4)),
    CONSTRAINT chk_dosage CHECK (dosage IS NULL OR dosage > 0),
    CONSTRAINT chk_duration CHECK (duration_days IS NULL OR duration_days > 0),
    CONSTRAINT chk_cumulative_dosage CHECK (cumulative_dosage IS NULL OR cumulative_dosage > 0)
);

-- ==========================
-- AUDIT TRIGGERS FOR TESTS
-- ==========================
DELIMITER //
CREATE TRIGGER tests_after_insert
AFTER INSERT ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, new_values, changed_by
    ) VALUES (
        'tests', NEW.test_id, 'INSERT',
        CONCAT('{"test_id":"', NEW.test_id, '", "patient_id":"', NEW.patient_id, '"}'),
        CURRENT_USER()
    );
END//

CREATE TRIGGER tests_after_update
AFTER UPDATE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, old_values, new_values, changed_by
    ) VALUES (
        'tests', NEW.test_id, 'UPDATE',
        CONCAT('{"test_id":"', OLD.test_id, '", "patient_id":"', OLD.patient_id, '"}'),
        CONCAT('{"test_id":"', NEW.test_id, '", "patient_id":"', NEW.patient_id, '"}'),
        CURRENT_USER()
    );
END//

CREATE TRIGGER tests_after_delete
AFTER DELETE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, old_values, changed_by
    ) VALUES (
        'tests', OLD.test_id, 'DELETE',
        CONCAT('{"test_id":"', OLD.test_id, '", "patient_id":"', OLD.patient_id, '"}'),
        CURRENT_USER()
    );
END//
DELIMITER ;

