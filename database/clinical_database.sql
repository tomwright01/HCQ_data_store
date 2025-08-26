-- ==========================
-- FULL CLINICAL DATABASE SCHEMA (clean/recreate)
-- ==========================
DROP DATABASE IF EXISTS PatientData;
CREATE DATABASE IF NOT EXISTS PatientData
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- ==========================
-- TESTS TABLE
-- ==========================
CREATE TABLE tests (
    test_id VARCHAR(25) PRIMARY KEY,
    patient_id VARCHAR(25) NOT NULL,
    location ENUM('KH', 'CHUSJ', 'IWK', 'IVEY') DEFAULT 'KH',
    date_of_test DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test)
) ENGINE=InnoDB;

-- ==========================
-- TEST_EYES TABLE
-- (kept as-is; legacy medication fields retained for compatibility)
-- ==========================
CREATE TABLE test_eyes (
    result_id INT AUTO_INCREMENT PRIMARY KEY,       -- Unique result entry
    test_id VARCHAR(25) NOT NULL,                   -- Reference to the test
    eye ENUM('OD', 'OS') NOT NULL,                  -- 'OD' for right eye, 'OS' for left eye

    -- Clinical fields
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

    -- ------- Legacy medication fields (DEPRECATED; keep for now) -------
    medication_name VARCHAR(100) NULL,             -- prefer new `medications` table
    dosage DECIMAL(10,2) NULL,                     -- ambiguous period; prefer new table
    dosage_unit VARCHAR(10) DEFAULT 'mg',          -- prefer unit-less normalized per-day
    duration_days SMALLINT UNSIGNED NULL,          -- prefer computed in new table
    cumulative_dosage DECIMAL(10,2) NULL,          -- prefer computed in new table
    -- -------------------------------------------------------------------

    date_of_continuation VARCHAR(255) NULL,
    treatment_notes TEXT NULL,

    -- References to external images/files
    faf_reference_OD VARCHAR(255) NULL,
    faf_reference_OS VARCHAR(255) NULL,
    oct_reference_OD VARCHAR(255) NULL,
    oct_reference_OS VARCHAR(255) NULL,
    vf_reference_OD VARCHAR(255) NULL,
    vf_reference_OS VARCHAR(255) NULL,
    mferg_reference_OD VARCHAR(255) NULL,
    mferg_reference_OS VARCHAR(255) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (test_id) REFERENCES tests(test_id) ON DELETE CASCADE,
    INDEX idx_test_eye (test_id, eye)
) ENGINE=InnoDB;

-- ==========================
-- MEDICATIONS TABLE (NEW)
-- Normalizes dosage to per-day and duration to days.
-- cumulative_dosage = dosage_per_day * duration_days (computed).
-- ==========================
CREATE TABLE medications (
    med_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Link to a person; keep both for convenience and faster lookups
    patient_id VARCHAR(25) NOT NULL,
    subject_id VARCHAR(50) NOT NULL,

    medication_name VARCHAR(100) NOT NULL,

    -- ACCEPT either a per-day or per-week input; we normalize to per-day
    input_dosage_value DECIMAL(10,3) NOT NULL,
    input_dosage_period ENUM('day','week') NOT NULL DEFAULT 'day',

    -- Normalized dosage per day (generated)
    dosage_per_day DECIMAL(10,3)
        AS (
            CASE input_dosage_period
                WHEN 'day'  THEN input_dosage_value
                WHEN 'week' THEN input_dosage_value / 7
                ELSE NULL
            END
        ) STORED,

    -- Duration options
    start_date DATE NULL,
    end_date   DATE NULL,
    duration_months INT NULL,          -- optional, e.g., "6 months"
    duration_days_manual INT NULL,     -- optional, e.g., "14 days"

    -- Normalized duration in days (generated):
    -- If dates present: inclusive day count; otherwise months*30 + manual days
    duration_days INT
        AS (
            CASE
                WHEN start_date IS NOT NULL AND end_date IS NOT NULL
                    THEN GREATEST(1, DATEDIFF(end_date, start_date) + 1)
                WHEN duration_months IS NOT NULL OR duration_days_manual IS NOT NULL
                    THEN GREATEST(1, COALESCE(duration_months,0)*30 + COALESCE(duration_days_manual,0))
                ELSE NULL
            END
        ) STORED,

    -- Computed cumulative exposure
    cumulative_dosage DECIMAL(12,3)
        AS (
            CASE
                WHEN dosage_per_day IS NOT NULL AND duration_days IS NOT NULL
                    THEN dosage_per_day * duration_days
                ELSE NULL
            END
        ) STORED,

    notes TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_med_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,

    INDEX idx_med_patient (patient_id),
    INDEX idx_med_subject (subject_id),
    INDEX idx_med_name (medication_name),
    INDEX idx_med_start (start_date),
    INDEX idx_med_end (end_date)
) ENGINE=InnoDB;

-- ==========================
-- TRIGGERS FOR AUDIT LOGGING
-- ==========================
DELIMITER //

-- TESTS
CREATE TRIGGER tests_after_insert
AFTER INSERT ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'tests',
        NEW.test_id,
        'INSERT',
        CONCAT('{"test_id":"', NEW.test_id, '","patient_id":"', NEW.patient_id, '"}'),
        CURRENT_USER()
    );
END;
//

CREATE TRIGGER tests_after_update
AFTER UPDATE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'tests',
        NEW.test_id,
        'UPDATE',
        CONCAT('{"test_id":"', OLD.test_id, '","patient_id":"', OLD.patient_id, '"}'),
        CONCAT('{"test_id":"', NEW.test_id, '","patient_id":"', NEW.patient_id, '"}'),
        CURRENT_USER()
    );
END;
//

CREATE TRIGGER tests_after_delete
AFTER DELETE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'tests',
        OLD.test_id,
        'DELETE',
        CONCAT('{"test_id":"', OLD.test_id, '","patient_id":"', OLD.patient_id, '"}'),
        CURRENT_USER()
    );
END;
//

-- MEDICATIONS
CREATE TRIGGER medications_after_insert
AFTER INSERT ON medications
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'medications',
        CAST(NEW.med_id AS CHAR),
        'INSERT',
        CONCAT(
            '{"med_id":"', NEW.med_id,
            '","patient_id":"', NEW.patient_id,
            '","subject_id":"', NEW.subject_id,
            '","medication_name":"', REPLACE(NEW.medication_name,'"','\"'), '"}'
        ),
        CURRENT_USER()
    );
END;
//

CREATE TRIGGER medications_after_update
AFTER UPDATE ON medications
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'medications',
        CAST(NEW.med_id AS CHAR),
        'UPDATE',
        CONCAT(
            '{"med_id":"', OLD.med_id,
            '","patient_id":"', OLD.patient_id,
            '","subject_id":"', OLD.subject_id,
            '","medication_name":"', REPLACE(OLD.medication_name,'"','\"'), '"}'
        ),
        CONCAT(
            '{"med_id":"', NEW.med_id,
            '","patient_id":"', NEW.patient_id,
            '","subject_id":"', NEW.subject_id,
            '","medication_name":"', REPLACE(NEW.medication_name,'"','\"'), '"}'
        ),
        CURRENT_USER()
    );
END;
//

CREATE TRIGGER medications_after_delete
AFTER DELETE ON medications
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'medications',
        CAST(OLD.med_id AS CHAR),
        'DELETE',
        CONCAT(
            '{"med_id":"', OLD.med_id,
            '","patient_id":"', OLD.patient_id,
            '","subject_id":"', OLD.subject_id,
            '","medication_name":"', REPLACE(OLD.medication_name,'"','\"'), '"}'
        ),
        CURRENT_USER()
    );
END;
//

DELIMITER ;

-- ==========================
-- OPTIONAL: VIEW for quick medication summary per patient
-- ==========================
CREATE OR REPLACE VIEW v_patient_medication_summary AS
SELECT
    p.patient_id,
    p.subject_id,
    m.medication_name,
    m.dosage_per_day,
    m.duration_days,
    m.cumulative_dosage,
    m.start_date,
    m.end_date
FROM medications m
JOIN patients p ON p.patient_id = m.patient_id;
