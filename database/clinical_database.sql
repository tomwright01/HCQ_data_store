CREATE DATABASE HCQ;

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
*/


CREATE TABLE IF NOT EXISTS medications (
    medication_id INT AUTO_INCREMENT PRIMARY KEY,
    medication_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    frequency VARCHAR(50)
);

/*
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
