/*
import React, { useState, useEffect } from 'react';
import {
  Typography,
  Paper,
  Grid,
  Card,
  CardContent,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Button,
  Divider,
  Link
} from '@mui/material';
import { useParams, Link as RouterLink } from 'react-router-dom';

const VisitView = () => {
  const { id } = useParams();
  const [visit, setVisit] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchVisit = async () => {
      try {
        // First find which patient this visit belongs to
        const patientResponse = await fetch(`http://localhost:5000/api/patients`);
        const patients = await patientResponse.json();
        
        if (patientResponse.ok) {
          // Find the patient with this visit
          let foundVisit = null;
          let foundPatient = null;
          
          for (const patient of patients) {
            if (patient.visits) {
              const visitMatch = patient.visits.find(v => v.visit_id === parseInt(id));
              if (visitMatch) {
                foundVisit = visitMatch;
                foundPatient = patient;
                break;
              }
            }
          }
          
          if (foundVisit && foundPatient) {
            setVisit({
              ...foundVisit,
              patient: foundPatient
            });
          } else {
            setVisit(null);
          }
        } else {
          console.error('Error fetching patients');
        }
      } catch (error) {
        console.error('Error:', error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchVisit();
  }, [id]);

  if (loading) {
    return <Typography>Loading visit data...</Typography>;
  }

  if (!visit) {
    return <Typography>Visit not found</Typography>;
  }

  const hasTestData = (testPrefix) => {
    return visit[`${testPrefix}_reference_OD`] || visit[`${testPrefix}_reference_OS`];
  };

  const renderTestLinks = (testPrefix, testName) => {
    return (
      <TableRow>
        <TableCell>{testName}</TableCell>
        <TableCell>
          {visit[`${testPrefix}_reference_OD`] ? (
            <Link href={visit[`${testPrefix}_reference_OD`]} target="_blank" rel="noopener noreferrer">
              View OD
            </Link>
          ) : 'N/A'}
        </TableCell>
        <TableCell>
          {visit[`${testPrefix}_reference_OS`] ? (
            <Link href={visit[`${testPrefix}_reference_OS`]} target="_blank" rel="noopener noreferrer">
              View OS
            </Link>
          ) : 'N/A'}
        </TableCell>
      </TableRow>
    );
  };

  return (
    <div>
      <Grid container spacing={3}>
        <Grid item xs={12}>
          <Card>
            <CardContent>
              <Typography variant="h5" gutterBottom>
                Visit Details - {visit.visit_date}
              </Typography>
              
              <Typography variant="subtitle1" gutterBottom>
                Patient: {visit.patient.patient_id} - {visit.patient.location} - {visit.patient.disease_name}
              </Typography>
              
              <Divider style={{ margin: '20px 0' }} />
              
              <Typography><strong>Visit Notes:</strong></Typography>
              <Typography style={{ whiteSpace: 'pre-line', marginBottom: '20px' }}>
                {visit.visit_notes || 'No notes recorded'}
              </Typography>
              
              <Typography variant="h6" gutterBottom>Test Results</Typography>
              
              <TableContainer component={Paper} style={{ marginBottom: '20px' }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Test Type</TableCell>
                      <TableCell>OD (Right Eye)</TableCell>
                      <TableCell>OS (Left Eye)</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {hasTestData('faf') && renderTestLinks('faf', 'FAF')}
                    {hasTestData('oct') && renderTestLinks('oct', 'OCT')}
                    {hasTestData('vf') && renderTestLinks('vf', 'VF')}
                    {hasTestData('mferg') && renderTestLinks('mferg', 'MFERG')}
                  </TableBody>
                </Table>
              </TableContainer>
              
              <Typography variant="h6" gutterBottom>MERCI Ratings</Typography>
              <Grid container spacing={2}>
                <Grid item xs={6}>
                  <Typography><strong>Left Eye (OS):</strong> {visit.merci_rating_left_eye || 'Not recorded'}</Typography>
                </Grid>
                <Grid item xs={6}>
                  <Typography><strong>Right Eye (OD):</strong> {visit.merci_rating_right_eye || 'Not recorded'}</Typography>
                </Grid>
              </Grid>
              
              <Button
                variant="contained"
                color="primary"
                component={RouterLink}
                to={`/patient/${visit.patient_id}`}
                style={{ marginTop: '20px' }}
              >
                Back to Patient
              </Button>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </div>
  );
};

export default VisitView;


