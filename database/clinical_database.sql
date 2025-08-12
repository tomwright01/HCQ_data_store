-- ============================================================
-- FULL CLINICAL DATABASE SCHEMA (modified for per‑eye tests)
-- ============================================================
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

-- ============================================================
-- PATIENTS TABLE
-- ============================================================
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

-- ============================================================
-- AUDIT LOG TABLE
-- ============================================================
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

-- ============================================================
-- TESTS TABLE (Composite Primary Key: test_id + eye)
-- ============================================================
CREATE TABLE tests (
    test_id VARCHAR(25) NOT NULL,
    eye ENUM('OD','OS') NOT NULL,
    patient_id VARCHAR(25) NOT NULL,
    location ENUM('KH','CHUSJ','IWK','IVEY') DEFAULT 'KH',
    date_of_test DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (test_id, eye),
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test)
);

-- ============================================================
-- TEST_EYES TABLE (references composite key from tests)
-- ============================================================
CREATE TABLE test_eyes (
    result_id INT AUTO_INCREMENT PRIMARY KEY,
    test_id VARCHAR(25) NOT NULL,
    eye ENUM('OD', 'OS') NOT NULL,
    age TINYINT UNSIGNED NULL,
    report_diagnosis ENUM('normal', 'abnormal', 'exclude', 'no input') NOT NULL DEFAULT 'no input',
    exclusion VARCHAR(100) NOT NULL DEFAULT 'none',
    merci_score VARCHAR(10) NULL,
    merci_diagnosis ENUM('normal', 'abnormal', 'no value') NOT NULL DEFAULT 'no value',
    error_type ENUM('TN', 'FP', 'TP', 'FN', 'none') DEFAULT NULL,
    faf_grade TINYINT UNSIGNED NULL,
    oct_score DECIMAL(10,2) NULL,
    vf_score DECIMAL(10,2) NULL,
    actual_diagnosis ENUM('ra', 'sle', 'sjogren', 'other') NOT NULL DEFAULT 'other',
    medication_name VARCHAR(100) NULL,
    dosage DECIMAL(10,2) NULL,
    dosage_unit VARCHAR(10) DEFAULT 'mg',
    duration_days SMALLINT UNSIGNED NULL,
    cumulative_dosage DECIMAL(10,2) NULL,
    date_of_continuation VARCHAR(255) NULL,
    treatment_notes TEXT NULL,
    faf_reference VARCHAR(255) NULL,
    oct_reference VARCHAR(255) NULL,
    vf_reference VARCHAR(255) NULL,
    mferg_reference VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Composite foreign key enforces that both test_id and eye exist in tests
    FOREIGN KEY (test_id, eye) REFERENCES tests (test_id, eye) ON DELETE CASCADE,
    INDEX idx_test_eye (test_id, eye)
);

-- ============================================================
-- TRIGGERS FOR AUDIT LOGGING (updated to include eye)
-- ============================================================
DELIMITER //

CREATE TRIGGER tests_after_insert
AFTER INSERT ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, new_values, changed_by
    ) VALUES (
        'tests',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'INSERT',
        CONCAT('{"test_id":"', NEW.test_id, '","eye":"', NEW.eye,
               '","patient_id":"', NEW.patient_id, '"}'),
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
        'tests',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'UPDATE',
        CONCAT('{"test_id":"', OLD.test_id, '","eye":"', OLD.eye,
               '","patient_id":"', OLD.patient_id, '"}'),
        CONCAT('{"test_id":"', NEW.test_id, '","eye":"', NEW.eye,
               '","patient_id":"', NEW.patient_id, '"}'),
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
        'tests',
        CONCAT(OLD.test_id, '_', OLD.eye),
        'DELETE',
        CONCAT('{"test_id":"', OLD.test_id, '","eye":"', OLD.eye,
               '","patient_id":"', OLD.patient_id, '"}'),
        CURRENT_USER()
    );
END//

DELIMITER ;
