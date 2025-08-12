-- ============================================================
-- FULL CLINICAL DATABASE (compatible with old importer)
-- - test_id is VARCHAR (string IDs like TEST_401_...)
-- - test_eyes includes dosage / duration / cumulative fields
-- - merci_score accepts 'unable' (stored as text)
-- - date_of_continuation stored as VARCHAR (no date parsing required)
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
    record_id     VARCHAR(120) NOT NULL,
    action        ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    old_values    TEXT NULL,
    new_values    TEXT NULL,
    changed_by    VARCHAR(100),
    changed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_record (table_name, record_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TESTS  (one row per visit/session; STRING test_id)
-- ============================================================
CREATE TABLE IF NOT EXISTS tests (
    test_id        VARCHAR(64) PRIMARY KEY,
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
-- Matches old importer column set and types
-- ============================================================
CREATE TABLE IF NOT EXISTS test_eyes (
    test_id              VARCHAR(64) NOT NULL,
    eye                  ENUM('OD','OS') NOT NULL,
    age                  TINYINT UNSIGNED NULL,

    report_diagnosis     ENUM('normal','abnormal','exclude','no input') NOT NULL DEFAULT 'no input',
    exclusion            VARCHAR(100) NOT NULL DEFAULT 'none',

    -- importer may send number or 'unable' -> store as text
    merci_score          VARCHAR(10) NULL,
    merci_diagnosis      ENUM('normal','abnormal','no value') NOT NULL DEFAULT 'no value',

    error_type           ENUM('TN','FP','TP','FN','none') NOT NULL DEFAULT 'none',

    faf_grade            TINYINT UNSIGNED NULL,
    oct_score            DECIMAL(10,2) NULL,
    vf_score             DECIMAL(10,2) NULL,

    -- importer may send RA/SLE/Sjogren (uppercase/mixed)
    actual_diagnosis     ENUM('ra','sle','sjogren','other','RA','SLE','Sjogren') NOT NULL DEFAULT 'other',

    -- medication / therapy fields expected by old importer
    medication_name      VARCHAR(100) NULL,
    dosage               DECIMAL(10,2) NULL,
    dosage_unit          VARCHAR(10) NOT NULL DEFAULT 'mg',
    duration_days        SMALLINT UNSIGNED NULL,
    cumulative_dosage    DECIMAL(10,2) NULL,

    -- importer often passes raw string here
    date_of_continuation VARCHAR(255) NULL,

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
-- TRIGGERS (audit)
-- Note: MySQL doesn't support IF NOT EXISTS for triggers; run once.
-- ============================================================
DELIMITER //

-- tests
CREATE TRIGGER trg_tests_after_insert
AFTER INSERT ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'tests',
        NEW.test_id,
        'INSERT',
        CONCAT(
            '{"test_id":"', NEW.test_id,
            '","patient_id":"', NEW.patient_id,
            '","location":"', NEW.location,
            '","date_of_test":"', DATE_FORMAT(NEW.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER trg_tests_after_update
AFTER UPDATE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'tests',
        NEW.test_id,
        'UPDATE',
        CONCAT(
            '{"test_id":"', OLD.test_id,
            '","patient_id":"', OLD.patient_id,
            '","location":"', OLD.location,
            '","date_of_test":"', DATE_FORMAT(OLD.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CONCAT(
            '{"test_id":"', NEW.test_id,
            '","patient_id":"', NEW.patient_id,
            '","location":"', NEW.location,
            '","date_of_test":"', DATE_FORMAT(NEW.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER trg_tests_after_delete
AFTER DELETE ON tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'tests',
        OLD.test_id,
        'DELETE',
        CONCAT(
            '{"test_id":"', OLD.test_id,
            '","patient_id":"', OLD.patient_id,
            '","location":"', OLD.location,
            '","date_of_test":"', DATE_FORMAT(OLD.date_of_test, '%Y-%m-%d'), '"}'
        ),
        CURRENT_USER()
    );
END//

-- test_eyes
CREATE TRIGGER trg_eyes_after_insert
AFTER INSERT ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'INSERT',
        CONCAT(
            '{"test_id":"', NEW.test_id,
            '","eye":"', NEW.eye,
            '","report_diagnosis":"', NEW.report_diagnosis,
            '","merci_score":', IFNULL(CONCAT('"', NEW.merci_score, '"'), 'null'),
            ',"vf_score":', IFNULL(NEW.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER trg_eyes_after_update
AFTER UPDATE ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(NEW.test_id, '_', NEW.eye),
        'UPDATE',
        CONCAT(
            '{"test_id":"', OLD.test_id,
            '","eye":"', OLD.eye,
            '","report_diagnosis":"', OLD.report_diagnosis,
            '","merci_score":', IFNULL(CONCAT('"', OLD.merci_score, '"'), 'null'),
            ',"vf_score":', IFNULL(OLD.vf_score, 'null'), '}'
        ),
        CONCAT(
            '{"test_id":"', NEW.test_id,
            '","eye":"', NEW.eye,
            '","report_diagnosis":"', NEW.report_diagnosis,
            '","merci_score":', IFNULL(CONCAT('"', NEW.merci_score, '"'), 'null'),
            ',"vf_score":', IFNULL(NEW.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

CREATE TRIGGER trg_eyes_after_delete
AFTER DELETE ON test_eyes
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, changed_by)
    VALUES (
        'test_eyes',
        CONCAT(OLD.test_id, '_', OLD.eye),
        'DELETE',
        CONCAT(
            '{"test_id":"', OLD.test_id,
            '","eye":"', OLD.eye,
            '","report_diagnosis":"', OLD.report_diagnosis,
            '","merci_score":', IFNULL(CONCAT('"', OLD.merci_score, '"'), 'null'),
            ',"vf_score":', IFNULL(OLD.vf_score, 'null'), '}'
        ),
        CURRENT_USER()
    );
END//

DELIMITER ;

-- ============================================================
-- END
-- ============================================================
