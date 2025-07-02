import React, { useState, useEffect } from 'react';
import {
  TextField, Button, Grid, Paper, Typography,
  Radio, RadioGroup, FormControlLabel, FormControl, FormLabel,
  MenuItem, Select, InputLabel
} from '@mui/material';
import { useNavigate } from 'react-router-dom';

const PatientForm = () => {
  const [formData, setFormData] = useState({
    location: '',
    disease_id: '',
    year_of_birth: '',
    gender: 'm',
    referring_doctor: '',
    rx_OD: '',
    rx_OS: '',
    procedures_done: '',
    dosage: '',
    duration: '',
    cumulative_dosage: '',
    date_of_discontinuation: '',
    extra_notes: ''
  });

  const [diseases, setDiseases] = useState([]);
  const navigate = useNavigate();

  useEffect(() => {
    const fetchDiseases = async () => {
      try {
        const response = await fetch('http://localhost:5000/api/diseases');
        const data = await response.json();
        setDiseases(data);
      } catch (error) {
        console.error('Error fetching diseases:', error);
      }
    };
    
    fetchDiseases();
  }, []);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      const response = await fetch('http://localhost:5000/api/patients', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
      });
      
      const data = await response.json();
      if (response.ok) {
        alert('Patient added successfully!');
        navigate(`/patient/${data.patient_id}`);
      } else {
        alert('Error adding patient');
      }
    } catch (error) {
      console.error('Error:', error);
      alert('Error adding patient');
    }
  };

  return (
    <Paper elevation={3} style={{ padding: '20px', marginTop: '20px' }}>
      <Typography variant="h5" gutterBottom>Add New Patient</Typography>
      <form onSubmit={handleSubmit}>
        <Grid container spacing={3}>
          <Grid item xs={12} sm={6}>
            <FormControl fullWidth>
              <InputLabel>Location</InputLabel>
              <Select
                name="location"
                value={formData.location}
                onChange={handleChange}
                required
              >
                <MenuItem value="Halifax">Halifax</MenuItem>
                <MenuItem value="Kensington">Kensington</MenuItem>
                <MenuItem value="Montreal">Montreal</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          
          <Grid item xs={12} sm={6}>
            <FormControl fullWidth>
              <InputLabel>Disease</InputLabel>
              <Select
                name="disease_id"
                value={formData.disease_id}
                onChange={handleChange}
                required
              >
                {diseases.map(disease => (
                  <MenuItem key={disease.disease_id} value={disease.disease_id}>
                    {disease.disease_name}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
          </Grid>
          
          {/* Rest of the form fields remain the same */}
          
          <Grid item xs={12}>
            <Button type="submit" variant="contained" color="primary">
              Submit
            </Button>
          </Grid>
        </Grid>
      </form>
    </Paper>
  );
};

export default PatientForm;
