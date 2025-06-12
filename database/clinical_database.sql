-- Create database
CREATE DATABASE IF NOT EXISTS patient_management;
USE patient_management;

-- Tables
CREATE TABLE diseases (
    disease_id TINYINT PRIMARY KEY,
    disease_name VARCHAR(50) NOT NULL,
    CONSTRAINT valid_disease CHECK (disease_id BETWEEN 1 AND 4)
);

CREATE TABLE locations (
    location_id TINYINT PRIMARY KEY AUTO_INCREMENT,
    location_name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY,
    location_id TINYINT,
    disease_id TINYINT,
    birth_year SMALLINT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    FOREIGN KEY (disease_id) REFERENCES diseases(disease_id)
);

-- Reference Data
INSERT INTO diseases (disease_id, disease_name) VALUES
(1, 'Lupus'), (2, 'Rheumatoid Arthritis'), (3, 'RTMD'), (4, 'Sjorgens');

INSERT INTO locations (location_name) VALUES
('Halifax'), ('Kensington'), ('Montreal');

-- Physician View
CREATE OR REPLACE VIEW physician_dashboard AS
SELECT 
    p.patient_id,
    IFNULL(l.location_name, '⚠️ MISSING') AS location,
    IFNULL(d.disease_name, '⚠️ MISSING') AS disease,
    IFNULL(p.birth_year, '⚠️ MISSING') AS birth_year,
    last_updated
FROM patients p
LEFT JOIN locations l ON p.location_id = l.location_id
LEFT JOIN diseases d ON p.disease_id = d.disease_id;

/*
    -- Initialize database
CREATE DATABASE IF NOT EXISTS patient_management;
USE patient_management;

-- Disease reference table (using your specified codes)
CREATE TABLE diseases (
    disease_id TINYINT PRIMARY KEY,
    disease_name VARCHAR(50) NOT NULL,
    CONSTRAINT valid_disease CHECK (disease_id BETWEEN 1 AND 4)
);

-- Location reference table
CREATE TABLE locations (
    location_id TINYINT PRIMARY KEY AUTO_INCREMENT,
    location_name VARCHAR(50) NOT NULL UNIQUE
);

-- Main patient table
CREATE TABLE patients (
    patient_id VARCHAR(20) PRIMARY KEY, -- Using custom ID format like "CLINIC-1001"
    location_id TINYINT,
    disease_id TYINT,
    birth_year SMALLINT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    physician_notes TEXT,
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    FOREIGN KEY (disease_id) REFERENCES diseases(disease_id)
);

-- Insert disease codes (exactly as you specified)
INSERT INTO diseases (disease_id, disease_name) VALUES
(1, 'Lupus'),
(2, 'Rheumatoid Arthritis'),
(3, 'RTMD'),
(4, 'Sjorgens');

-- Insert locations
INSERT INTO locations (location_name) VALUES
('Halifax'),
('Kensington'),
('Montreal');

-- Physician view with missing data alerts
CREATE OR REPLACE VIEW physician_dashboard AS
SELECT 
    p.patient_id,
    IFNULL(l.location_name, '⚠️ MISSING LOCATION') AS location,
    IFNULL(d.disease_name, 
           CASE 
               WHEN p.disease_id IS NULL THEN '⚠️ MISSING DISEASE' 
               ELSE CONCAT('⚠️ INVALID CODE (', p.disease_id, ')')
           END) AS disease,
    IFNULL(p.birth_year, '⚠️ MISSING BIRTH YEAR') AS birth_year,
    p.last_updated,
    p.physician_notes,
    CASE 
        WHEN p.location_id IS NULL OR p.disease_id IS NULL OR p.birth_year IS NULL 
        THEN 'INCOMPLETE RECORD' 
        ELSE 'COMPLETE' 
    END AS record_status
FROM 
    patients p
LEFT JOIN 
    locations l ON p.location_id = l.location_id
LEFT JOIN 
    diseases d ON p.disease_id = d.disease_id
ORDER BY 
    record_status, p.last_updated DESC;

/*-- Docker MariaDB Patient Tracking System
-- Focuses on: ID, Location, Disease (1-4), and Missing Data Flagging

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create database (will auto-execute in Docker /docker-entrypoint-initdb.d/)
CREATE DATABASE IF NOT EXISTS patient_tracking;
USE patient_tracking;

-- Simplified disease mapping (1-4 as requested)
CREATE TABLE IF NOT EXISTS diseases (
    disease_id TINYINT PRIMARY KEY CHECK (disease_id BETWEEN 1 AND 4),
    disease_name VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Location table
CREATE TABLE IF NOT EXISTS locations (
    location_id TINYINT PRIMARY KEY AUTO_INCREMENT,
    location_name VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Main patient table with NULL checks
CREATE TABLE IF NOT EXISTS patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    location_id TINYINT,
    disease_id TINYINT CHECK (disease_id BETWEEN 1 AND 4),
    birth_year SMALLINT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
                  ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    FOREIGN KEY (disease_id) REFERENCES diseases(disease_id)
) ENGINE=InnoDB;

-- Insert disease codes (note: changed MCTD to RTMD per your request)
INSERT IGNORE INTO diseases (disease_id, disease_name) VALUES
(1, 'Lupus'),
(2, 'RA'),
(3, 'Sjorgens'),
(4, 'RTMD');  -- Changed from MCTD to RTMD

-- Insert locations
INSERT IGNORE INTO locations (location_name) VALUES
('Kensington'),
('Montreal'),
('Halifax');

-- View to identify missing data
CREATE OR REPLACE VIEW patient_missing_data AS
SELECT 
    p.patient_id,
    l.location_name,
    d.disease_name,
    p.birth_year,
    CASE 
        WHEN p.location_id IS NULL THEN 'Missing Location'
        WHEN p.disease_id IS NULL THEN 'Missing Disease'
        WHEN p.birth_year IS NULL THEN 'Missing Birth Year'
        ELSE 'Complete'
    END AS data_status,
    p.last_updated
FROM 
    patients p
LEFT JOIN 
    locations l ON p.location_id = l.location_id
LEFT JOIN 
    diseases d ON p.disease_id = d.disease_id
ORDER BY 
    data_status DESC, p.last_updated DESC;

-- Sample data with some incomplete records
INSERT INTO patients (location_id, disease_id, birth_year) VALUES
(1, 1, 1985),    -- Complete
(2, NULL, 1978), -- Missing disease
(NULL, 3, 1990), -- Missing location
(3, 4, NULL);    -- Missing birth year

-- View to see all data with missing highlights
CREATE OR REPLACE VIEW patient_dashboard AS
SELECT 
    patient_id,
    IFNULL(location_name, 'LOCATION MISSING') AS location,
    IFNULL(disease_name, 
           CASE 
               WHEN disease_id IS NULL THEN 'DISEASE MISSING' 
               ELSE CONCAT('Invalid disease code: ', disease_id)
           END) AS disease,
    IFNULL(birth_year, 'BIRTH YEAR MISSING') AS birth_year,
    last_updated
FROM 
    patients p
LEFT JOIN 
    locations l ON p.location_id = l.location_id
LEFT JOIN 
    diseases d ON p.disease_id = d.disease_id;

-- Create user for application
CREATE USER IF NOT EXISTS 'tracking_app'@'%' IDENTIFIED BY 'app_password';
GRANT SELECT, INSERT, UPDATE ON patient_tracking.* TO 'tracking_app'@'%';
FLUSH PRIVILEGES;

SET FOREIGN_KEY_CHECKS = 1;



-- Create and configure the complete patient database
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create database
DROP DATABASE IF EXISTS patient_database;
CREATE DATABASE patient_database;
USE patient_database;

-- Create diseases reference table
CREATE TABLE diseases (
    disease_id TINYINT PRIMARY KEY,
    disease_name VARCHAR(20) NOT NULL UNIQUE
);

-- Create locations reference table
CREATE TABLE locations (
    location_id TINYINT PRIMARY KEY,
    location_name VARCHAR(20) NOT NULL UNIQUE
);

-- Create main patients table
CREATE TABLE patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    disease_id TINYINT NOT NULL,
    location_id TINYINT NOT NULL,
    birth_year SMALLINT NOT NULL,
    date_entered TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (disease_id) REFERENCES diseases(disease_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    CHECK (birth_year BETWEEN 1900 AND YEAR(CURRENT_DATE))
);

-- Insert disease types
INSERT INTO diseases (disease_id, disease_name) VALUES
(1, 'Lupus'),
(2, 'RA'),
(3, 'Sjorgens'),
(4, 'MCTD');

-- Insert location options
INSERT INTO locations (location_id, location_name) VALUES
(1, 'Kensington'),
(2, 'Montreal'),
(3, 'Halifax');

-- Create view for easy data viewing
CREATE OR REPLACE VIEW patient_records AS
SELECT 
    p.patient_id,
    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
    d.disease_name,
    l.location_name,
    p.birth_year,
    (YEAR(CURRENT_DATE) - p.birth_year) AS approximate_age,
    p.date_entered
FROM 
    patients p
JOIN 
    diseases d ON p.disease_id = d.disease_id
JOIN 
    locations l ON p.location_id = l.location_id
ORDER BY 
    p.patient_id;

-- Insert sample patient data
INSERT INTO patients (first_name, last_name, disease_id, location_id, birth_year) VALUES
('John', 'Smith', 1, 1, 1985),
('Sarah', 'Johnson', 2, 2, 1978),
('Michael', 'Williams', 3, 3, 1990),
('Emily', 'Brown', 4, 1, 1982),
('David', 'Jones', 1, 2, 1975),
('Jennifer', 'Davis', 2, 3, 1988),
('Robert', 'Miller', 3, 1, 1972),
('Lisa', 'Wilson', 4, 2, 1992),
('James', 'Taylor', 1, 3, 1980),
('Patricia', 'Anderson', 2, 1, 1970);

-- Enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Display confirmation and sample data
SELECT 'Database setup completed successfully!' AS message;
SELECT COUNT(*) AS total_patients FROM patients;
SELECT * FROM patient_records LIMIT 5;/*CREATE DATABASE HCQ;

Use HCQ;

CREATE TABLE subjects(
    subject_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(50) NOT NULL);

CREATE DATABASE IF NOT EXISTS HOSPITAL;

USE HOSPITAL;

CREATE TABLE patients (
  patient_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL, 
  age INT, 
  gender ENUM ('Male', 'Female', 'Other'),
  sickness_category TINYINT CHECK (sickness_category BETWEEN 1 AND 4),
  admission_date DATE,
  notes TEXT
);



CREATE TABLE IF NOT EXISTS medications (
    medication_id INT AUTO_INCREMENT PRIMARY KEY,
    medication_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    frequency VARCHAR(50)
);


CREATE TABLE IF NOT EXISTS prescriptions (
    prescription_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    medication_id INT,
    quantity INT, 
    duration INT,  -- Duration in days
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (medication_id) REFERENCES medications(medication_id) ON DELETE CASCADE
);
*/
