import React, { useState, useEffect } from 'react';
import {
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Typography,
  TextField,
  Button,
  Link,
  Chip
} from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';

const PatientList = () => {
  const [patients, setPatients] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchPatients = async () => {
      try {
        const response = await fetch('http://localhost:5000/api/patients');
        const data = await response.json();
        if (response.ok) {
          setPatients(data);
        } else {
          console.error('Error fetching patients');
        }
      } catch (error) {
        console.error('Error:', error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchPatients();
  }, []);

  const filteredPatients = patients.filter(patient =>
    patient.patient_id.toString().includes(searchTerm) ||
    patient.location.toLowerCase().includes(searchTerm.toLowerCase()) ||
    patient.referring_doctor.toLowerCase().includes(searchTerm.toLowerCase()) ||
    patient.disease_name.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const getDiseaseColor = (disease) => {
    switch(disease) {
      case 'Lupus': return 'primary';
      case 'Rheumatoid Arthritis': return 'secondary';
      case 'RTMD': return 'success';
      case 'Sjorgens': return 'error';
      default: return 'default';
    }
  };

  if (loading) {
    return <Typography>Loading patients...</Typography>;
  }

  return (
    <div>
      <Typography variant="h4" gutterBottom>Patient List</Typography>
      
      <TextField
        label="Search Patients"
        variant="outlined"
        fullWidth
        margin="normal"
        value={searchTerm}
        onChange={(e) => setSearchTerm(e.target.value)}
        style={{ marginBottom: '20px' }}
      />
      
      <TableContainer component={Paper}>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>ID</TableCell>
              <TableCell>Location</TableCell>
              <TableCell>Disease</TableCell>
              <TableCell>Year of Birth</TableCell>
              <TableCell>Gender</TableCell>
              <TableCell>Referring Doctor</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {filteredPatients.map((patient) => (
              <TableRow key={patient.patient_id}>
                <TableCell>{patient.patient_id}</TableCell>
                <TableCell>
                  <Chip label={patient.location} color="info" size="small" />
                </TableCell>
                <TableCell>
                  <Chip 
                    label={patient.disease_name} 
                    color={getDiseaseColor(patient.disease_name)} 
                    size="small" 
                  />
                </TableCell>
                <TableCell>{patient.year_of_birth}</TableCell>
                <TableCell>{patient.gender === 'm' ? 'Male' : 'Female'}</TableCell>
                <TableCell>{patient.referring_doctor}</TableCell>
                <TableCell>
                  <Button
                    component={RouterLink}
                    to={`/patient/${patient.patient_id}`}
                    variant="outlined"
                    size="small"
                  >
                    View
                  </Button>
                  <Button
                    component={RouterLink}
                    to={`/patient/${patient.patient_id}/add-visit`}
                    variant="outlined"
                    size="small"
                    style={{ marginLeft: '8px' }}
                  >
                    Add Visit
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </div>
  );
};

export default PatientList;
                   
