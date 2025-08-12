-- ============================================================
-- FULL CLINICAL DATABASE SCHEMA (Option B) WITH SAMPLE DATA
-- ============================================================
CREATE DATABASE IF NOT EXISTS PatientData;
USE PatientData;

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_ALL_TABLES';

-- ============================================================
-- PATIENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS patients (
    patient_id      VARCHAR(25) PRIMARY KEY,
    subject_id      VARCHAR(50) NOT NULL,
    location        ENUM('KH','CHUSJ','IWK','IVEY') DEFAULT 'KH',
    date_of_birth   DATE NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject (subject_id),
    INDEX idx_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name    VARCHAR(50) NOT NULL,
    record_id     VARCHAR(100) NOT NULL,
    action        ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    old_values    TEXT NULL,
    new_values    TEXT NULL,
    changed_by    VARCHAR(100),
    changed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_record (table_name, record_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TESTS (one row per visit/session)
-- ============================================================
CREATE TABLE IF NOT EXISTS tests (
    test_id        BIGINT UNSIGNED PRIMARY KEY,
    patient_id     VARCHAR(25) NOT NULL,
    location       ENUM('KH','CHUSJ','IWK','IVEY') DEFAULT 'KH',
    date_of_test   DATE NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tests_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_date (date_of_test)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TEST_EYES (exactly one row per eye per test)
-- ============================================================
CREATE TABLE IF NOT EXISTS test_eyes (
    test_id              BIGINT UNSIGNED NOT NULL,
    eye                  ENUM('OD','OS') NOT NULL,
    age                  TINYINT UNSIGNED NULL,
    report_diagnosis     ENUM('normal','abnormal','exclude','no input') NOT NULL DEFAULT 'no input',
    exclusion            VARCHAR(100) NOT NULL DEFAULT 'none',
    merci_score          INT NULL,
    merci_diagnosis      ENUM('normal','abnormal','no value') NOT NULL DEFAULT 'no value',
    error_type           ENUM('TN','FP','TP','FN','none') NOT NULL DEFAULT 'none',
    faf_grade            TINYINT UNSIGNED NULL,
    oct_score            INT NULL,
    vf_score             INT NULL,
    actual_diagnosis     ENUM('ra','sle','sjogren','other') NOT NULL DEFAULT 'other',
    date_of_continuation DATE NULL,
    treatment_notes      TEXT NULL,
    faf_reference        VARCHAR(255) NULL,
    oct_reference        VARCHAR(255) NULL,
    vf_reference         VARCHAR(255) NULL,
    mferg_reference      VARCHAR(255) NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (test_id, eye),
    CONSTRAINT fk_eyes_test
        FOREIGN KEY (test_id) REFERENCES tests(test_id)
        ON DELETE CASCADE,
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TRIGGERS: audit tests + test_eyes
-- ============================================================
DELIMITER //

-- tests
CREATE TRIGGER IF NOT EXISTS trg_tests_after_insert
AFTER INSERT ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'tests',
        CAST(NEW.test_id AS CHAR),
        'INSERT',
        CONCAT(
            '{"test_id":', NEW.test_id,
            ',"patient_id":"', NEW.patient_id,
            '","location":"', NEW.location,
            '","date_of_test":"', DATE_FORMAT(NEW.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER IF NOT EXISTS trg_tests_after_update
AFTER UPDATE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'tests',
        CAST(NEW.test_id AS CHAR),
        'UPDATE',
        CONCAT(
            '{"test_id":', OLD.test_id,
            ',"patient_id":"', OLD.patient_id,
            '","location":"', OLD.location,
            '","date_of_test":"', DATE_FORMAT(OLD.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CONCAT(
            '{"test_id":', NEW.test_id,
            ',"patient_id":"', NEW.patient_id,
            '","location":"', NEW.location,
            '","date_of_test":"', DATE_FORMAT(NEW.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER IF NOT EXISTS trg_tests_after_delete
AFTER DELETE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'tests',
        CAST(OLD.test_id AS CHAR),
        'DELETE',
        CONCAT(
            '{"test_id":', OLD.test_id,
            ',"patient_id":"', OLD.patient_id,
            '","location":"', OLD.location,
            '","date_of_test":"', DATE_FORMAT(OLD.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

-- test_eyes
CREATE TRIGGER IF NOT EXISTS trg_eyes_after_insert
AFTER INSERT ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'INSERT',
        CONCAT(
            '{"test_id":', NEW.test_id,
            ',"eye":"', NEW.eye,
            '","report_diagnosis":"', NEW.report_diagnosis,
            '","merci_score":', IFNULL(NEW.merci_score, 'null'),
            ',"vf_score":', IFNULL(NEW.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER IF NOT EXISTS trg_eyes_after_update
AFTER UPDATE ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'UPDATE',
        CONCAT(
            '{"test_id":', OLD.test_id,
            ',"eye":"', OLD.eye,
            '","report_diagnosis":"', OLD.report_diagnosis,
            '","merci_score":', IFNULL(OLD.merci_score, 'null'),
            ',"vf_score":', IFNULL(OLD.vf_score, 'null'), '}'
        ),
        CONCAT(
            '{"test_id":', NEW.test_id,
            ',"eye":"', NEW.eye,
            '","report_diagnosis":"', NEW.report_diagnosis,
            '","merci_score":', IFNULL(NEW.merci_score, 'null'),
            ',"vf_score":', IFNULL(NEW.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER IF NOT EXISTS trg_eyes_after_delete
AFTER DELETE ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(OLD.test_id, '_', OLD.eye),
        'DELETE',
        CONCAT(
            '{"test_id":', OLD.test_id,
            ',"eye":"', OLD.eye,
            '","report_diagnosis":"', OLD.report_diagnosis,
            '","merci_score":', IFNULL(OLD.merci_score, 'null'),
            ',"vf_score":', IFNULL(OLD.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

DELIMITER ;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Patients
INSERT INTO patients (patient_id, subject_id, location, date_of_birth)
VALUES 
('P001', 'SUBJ001', 'KH', '1985-06-15'),
('P002', 'SUBJ002', 'CHUSJ', '1990-03-22');

-- Tests (two sessions, one per patient)
INSERT INTO tests (test_id, patient_id, location, date_of_test)
VALUES
(1001, 'P001', 'KH', '2025-08-01'),
(1002, 'P002', 'CHUSJ', '2025-08-05');

-- Test Eyes for session 1001 (P001)
INSERT INTO test_eyes (test_id, eye, age, report_diagnosis, merci_score, vf_score, actual_diagnosis)
VALUES
(1001, 'OD', 40, 'normal', 85, 92, 'ra'),
(1001, 'OS', 40, 'abnormal', 70, 60, 'sle');

-- Test Eyes for session 1002 (P002)
INSERT INTO test_eyes (test_id, eye, age, report_diagnosis, merci_score, vf_score, actual_diagnosis)
VALUES
(1002, 'OD', 35, 'normal', 90, 95, 'sjogren'),
(1002, 'OS', 35, 'normal', 88, 90, 'other');
