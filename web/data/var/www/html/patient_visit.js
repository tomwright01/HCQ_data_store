import React, { useState, useEffect } from 'react';
import {
  Typography,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Button,
  Grid,
  Divider,
  Card,
  CardContent,
  Chip,
  Link
} from '@mui/material';
import { useParams, Link as RouterLink } from 'react-router-dom';

const PatientView = () => {
  const { id } = useParams();
  const [patient, setPatient] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchPatient = async () => {
      try {
        const response = await fetch(`http://localhost:5000/api/patients?id=${id}`);
        const data = await response.json();
        if (response.ok) {
          setPatient(data);
        } else {
          console.error('Error fetching patient');
        }
      } catch (error) {
        console.error('Error:', error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchPatient();
  }, [id]);

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
    return <Typography>Loading patient data...</Typography>;
  }

  if (!patient) {
    return <Typography>Patient not found</Typography>;
  }

  return (
    <div>
      <Grid container spacing={3}>
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h5" gutterBottom>
                Patient Information
              </Typography>
              
              <Grid container spacing={2}>
                <Grid item xs={6}>
                  <Typography><strong>Patient ID:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.patient_id}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Location:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Chip label={patient.location} color="info" size="small" />
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Disease:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Chip 
                    label={patient.disease_name} 
                    color={getDiseaseColor(patient.disease_name)} 
                    size="small" 
                  />
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Year of Birth:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.year_of_birth}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Gender:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.gender === 'm' ? 'Male' : 'Female'}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Referring Doctor:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.referring_doctor}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>RX OD (Right Eye):</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.rx_OD}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>RX OS (Left Eye):</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.rx_OS}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Dosage:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.dosage}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Duration (months):</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.duration}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Cumulative Dosage:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.cumulative_dosage}</Typography>
                </Grid>
                
                <Grid item xs={6}>
                  <Typography><strong>Date of Discontinuation:</strong></Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography>{patient.date_of_discontinuation || 'N/A'}</Typography>
                </Grid>
              </Grid>
              
              <Divider style={{ margin: '20px 0' }} />
              
              <Typography><strong>Procedures Done:</strong></Typography>
              <Typography style={{ whiteSpace: 'pre-line' }}>{patient.procedures_done || 'None recorded'}</Typography>
              
              <Typography style={{ marginTop: '10px' }}><strong>Extra Notes:</strong></Typography>
              <Typography style={{ whiteSpace: 'pre-line' }}>{patient.extra_notes || 'None'}</Typography>
              
              <Button
                variant="contained"
                color="primary"
                component={RouterLink}
                to={`/patient/${id}/add-visit`}
                style={{ marginTop: '20px' }}
              >
                Add New Visit
              </Button>
            </CardContent>
          </Card>
        </Grid>
        
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h5" gutterBottom>
                Visits
              </Typography>
              
              {patient.visits && patient.visits.length > 0 ? (
                <TableContainer component={Paper}>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>Visit Date</TableCell>
                        <TableCell>Tests</TableCell>
                        <TableCell>MERCI</TableCell>
                        <TableCell>Actions</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {patient.visits.map((visit) => (
                        <TableRow key={visit.visit_id}>
                          <TableCell>{visit.visit_date}</TableCell>
                          <TableCell>
                            {visit.faf_reference_OD || visit.faf_reference_OS ? 'FAF ' : ''}
                            {visit.oct_reference_OD || visit.oct_reference_OS ? 'OCT ' : ''}
                            {visit.vf_reference_OD || visit.vf_reference_OS ? 'VF ' : ''}
                            {visit.mferg_reference_OD || visit.mferg_reference_OS ? 'MFERG' : ''}
                          </TableCell>
                          <TableCell>
                            {visit.merci_rating_left_eye || 'N/A'} (L) / {visit.merci_rating_right_eye || 'N/A'} (R)
                          </TableCell>
                          <TableCell>
                            <Button
                              component={RouterLink}
                              to={`/visit/${visit.visit_id}`}
                              variant="outlined"
                              size="small"
                            >
                              Details
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </TableContainer>
              ) : (
                <Typography>No visits recorded for this patient</Typography>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </div>
  );
};

export default PatientView;


