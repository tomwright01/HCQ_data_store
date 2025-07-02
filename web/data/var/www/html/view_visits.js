import React, { useState, useEffect } from 'react';
import {
  Typography, Paper, Grid, Card, CardContent,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Button
} from '@mui/material';
import { useParams, Link } from 'react-router-dom';

const VisitView = () => {
  const { id } = useParams();
  const [visit, setVisit] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchVisit = async () => {
      try {
        const response = await fetch(`http://localhost:5000/api/patients?id=${id}`);
        const data = await response.json();
        if (response.ok) {
          // Find the specific visit
          const foundVisit = data.visits.find(v => v.visit_id === parseInt(id));
          setVisit(foundVisit || null);
        } else {
          console.error('Error fetching visit');
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
    return <Typography>Loading...</Typography>;
  }

  if (!visit) {
    return <Typography>Visit not found</Typography>;
  }

  return (
    <div>
      <Grid container spacing={3}>
        <Grid item xs={12}>
          <Card>
            <CardContent>
              <Typography variant="h5" gutterBottom>
                Visit Details - {visit.visit_date}
              </Typography>
              
              <Typography><strong>Patient ID:</strong> {visit.patient_id}</Typography>
              <Typography><strong>Visit Notes:</strong> {visit.visit_notes}</Typography>
              
              <Typography variant="h6" style={{ marginTop: '20px' }}>Test Results</Typography>
              
              <TableContainer component={Paper} style={{ marginTop: '10px' }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Test Type</TableCell>
                      <TableCell>OD Reference</TableCell>
                      <TableCell>OS Reference</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    <TableRow>
                      <TableCell>FAF</TableCell>
                      <TableCell>
                        {visit.faf_reference_OD ? (
                          <a href={visit.faf_reference_OD} target="_blank" rel="noopener noreferrer">
                            View
                          </a>
                        ) : 'N/A'}
                      </TableCell>
                      <TableCell>
                        {visit.faf_reference_OS ? (
                          <a href={visit.faf_reference_OS} target="_blank" rel="noopener noreferrer">
                            View
                          </a>
                        ) : 'N/A'}
                      </TableCell>
                    </TableRow>
                    {/* Similar rows for OCT, VF, MFERG */}
                  </TableBody>
                </Table>
              </TableContainer>
              
              <Typography variant="h6" style={{ marginTop: '20px' }}>MERCI Ratings</Typography>
              <Typography><strong>Left Eye:</strong> {visit.merci_rating_left_eye || 'N/A'}</Typography>
              <Typography><strong>Right Eye:</strong> {visit.merci_rating_right_eye || 'N/A'}</Typography>
              
              <Button
                variant="contained"
                color="primary"
                component={Link}
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


