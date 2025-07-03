'''
#!/usr/bin/env python3
import os
import mysql.connector
from mysql.connector import Error

def connect_to_db():
    try:
        connection = mysql.connector.connect(
            host='mariadb',  # Docker service name
            database='PatientData',
            user='root',
            password='notgood'
        )
        return connection
    except Error as e:
        print(f"Error connecting to MySQL: {e}")
        return None

def process_image_file(filename, connection):
    try:
        parts = filename.split('_')
        if len(parts) < 3:
            print(f"Skipping invalid filename format: {filename}")
            return False
        
        patient_id = int(parts[0])
        eye = parts[1]
        date = parts[2].replace('.png', '')
        
        cursor = connection.cursor()
        
        # Check if patient exists
        cursor.execute("SELECT patient_id FROM Patients WHERE patient_id = %s", (patient_id,))
        if not cursor.fetchone():
            cursor.execute("INSERT INTO Patients (patient_id, location) VALUES (%s, 'Unknown')", (patient_id,))
        
        # Check if visit exists for this date
        cursor.execute("SELECT visit_id FROM Visits WHERE patient_id = %s AND visit_date = %s", (patient_id, date))
        visit = cursor.fetchone()
        
        if visit:
            # Update existing visit
            visit_id = visit[0]
            field = f"faf_reference_{eye}"
            cursor.execute(f"UPDATE Visits SET {field} = %s WHERE visit_id = %s", (filename, visit_id))
        else:
            # Create new visit
            field = f"faf_reference_{eye}"
            cursor.execute(f"INSERT INTO Visits (patient_id, visit_date, {field}) VALUES (%s, %s, %s)", 
                          (patient_id, date, filename))
        
        connection.commit()
        cursor.close()
        return True
        
    except Error as e:
        print(f"Error processing {filename}: {e}")
        connection.rollback()
        return False

def main():
    image_dir = '/var/www/html/data/FAF'  # Path in Docker container
    if not os.path.exists(image_dir):
        print(f"Directory not found: {image_dir}")
        return
    
    connection = connect_to_db()
    if not connection:
        return
    
    total = 0
    success = 0
    
    for filename in os.listdir(image_dir):
        if filename.endswith('.png'):
            total += 1
            if process_image_file(filename, connection):
                success += 1
                print(f"Processed: {filename}")
            else:
                print(f"Failed: {filename}")
    
    connection.close()
    print(f"\nImport complete. Success: {success}/{total}")

if __name__ == "__main__":
    main()
'''
