/*
import React, { useEffect, useState } from 'react';

const App = () => {
  const [patients, setPatients] = useState([]);

  useEffect(() => {
    fetch('http://localhost:8080/api.php')
      .then(response => response.json())
      .then(data => setPatients(data))
      .catch(error => console.log('Error:', error));
  }, []);

  return (
    <div>
      <h1>Patient List</h1>
      <ul>
        {patients.map(patient => (
          <li key={patient.patient_id}>{patient.location} - {patient.gender}</li>
        ))}
      </ul>
    </div>
  );
};

export default App;
