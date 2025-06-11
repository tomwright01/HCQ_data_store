CREATE DATABASE HCQ;

Use HCQ;

CREATE TABLE subjects(
    subject_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(50) NOT NULL);
CREATE DATABASE IF NOT EXISTS HOSPITAL;

USE HOSPITAL;

CREATE DATABASE patients (
  patient_id INT AUTO_INCREMENT PRIMARY KEY
  name VARCHAR(100) NOT NULL, 
  age INT 
  gender ENUM ('Male', 'Female', 'Other') 
  sickness_category TINYINT CHECK (sickness_category BETWEEN 1 AND 4),
  admission_date DATABASE
  notes TEXT
);
