#!/usr/bin/env python3
import os
import mysql.connector
from mysql.connector import Error

# Configuration
IMAGE_DIR = 'SAMPLE/FAF'
DB_CONFIG = {
    'host': 'localhost',
    'database': 'PatientData',
    'user': 'root',
    'password': 'notgood'
}

def connect_to_db():
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        if connection.is_connected():
            return connection
    except Error as e:
        print(f"Error while connecting to MySQL: {e}")
        return None

def process_image(filename):
    """Extract patient ID, eye, and date from filename"""
    try:
        parts = filename.split('_')
        if len(parts) < 3:
            return None
            
        patient_id = int(parts[0])
        eye = parts[1].upper()
        date = parts[2].split('.')[0]  # Remove file extension
        
        return {
            'patient_id': patient_id,
            'eye': eye,
            'date': date,
            'filename': filename
        }
    except (ValueError, IndexError):
        return None

def insert_patient(connection, patient_id):
    """Insert patient if not exists"""
    cursor = connection.cursor()
    try:
        cursor.execute("INSERT IGNORE INTO Patients (patient_id) VALUES (%s)", (patient_id,))
        connection.commit()
    except Error as e:
        print(f"Error inserting patient {patient_id}: {e}")
    finally:
        cursor.close()

def insert_visit(connection, image_data):
    """Insert visit data with image reference"""
    cursor = connection.cursor()
    try:
        # Determine which eye field to update
        eye_field = f"faf_reference_{image_data['eye']}"
        
        query = f"""
        INSERT INTO Visits (patient_id, visit_date, {eye_field})
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE {eye_field} = VALUES({eye_field})
        """
        
        cursor.execute(query, (
            image_data['patient_id'],
            image_data['date'],
            image_data['filename']
        ))
        connection.commit()
        print(f"Processed image for patient {image_data['patient_id']}, {image_data['eye']}, {image_data['date']}")
    except Error as e:
        print(f"Error inserting visit for patient {image_data['patient_id']}: {e}")
    finally:
        cursor.close()

def main():
    connection = connect_to_db()
    if not connection:
        return

    try:
        for root, _, files in os.walk(IMAGE_DIR):
            for filename in files:
                if filename.lower().endswith('.png'):
                    image_data = process_image(filename)
                    if image_data:
                        insert_patient(connection, image_data['patient_id'])
                        insert_visit(connection, image_data)
    finally:
        if connection.is_connected():
            connection.close()

if __name__ == "__main__":
    main()
