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
