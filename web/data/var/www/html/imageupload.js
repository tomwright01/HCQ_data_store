/*
import React, { useState } from 'react';
import {
  Button, Paper, Typography, Box, Grid,
  TextField, FormControl, InputLabel, Select, MenuItem
} from '@mui/material';
import CloudUploadIcon from '@mui/icons-material/CloudUpload';

const ImageUpload = () => {
  const [file, setFile] = useState(null);
  const [patientId, setPatientId] = useState('');
  const [testType, setTestType] = useState('FAF');
  const [eye, setEye] = useState('OD');
  const [uploadResult, setUploadResult] = useState(null);

  const testTypes = ['FAF', 'OCT', 'VF', 'MFERG'];

  const handleFileChange = (e) => {
    setFile(e.target.files[0]);
  };

  const handleUpload = async () => {
    if (!file || !patientId) {
      alert('Please select a file and enter a patient ID');
      return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('test_type', testType);
    formData.append('eye', eye);
    
    try {
      const response = await fetch('http://localhost:5000/api/upload', {
        method: 'POST',
        body: formData,
      });
      
      const data = await response.json();
      if (response.ok) {
        setUploadResult(data.result);
        
        // Prepare visit data based on test type
        const visitData = {
          patient_id: patientId,
          visit_date: new Date().toISOString().split('T')[0],
          visit_notes: `Uploaded ${testType} image for ${eye === 'OD' ? 'right' : 'left'} eye`
        };
        
        // Set the appropriate fields based on test type and eye
        if (testType === 'FAF') {
          if (eye === 'OD') {
            visitData.faf_test_id_OD = data.result.test_id;
            visitData.faf_image_number_OD = data.result.image_number;
          } else {
            visitData.faf_test_id_OS = data.result.test_id;
            visitData.faf_image_number_OS = data.result.image_number;
          }
        } else if (testType === 'OCT') {
          if (eye === 'OD') {
            visitData.oct_test_id_OD = data.result.test_id;
            visitData.oct_image_number_OD = data.result.image_number;
          } else {
            visitData.oct_test_id_OS = data.result.test_id;
            visitData.oct_image_number_OS = data.result.image_number;
          }
        }
        // Similarly for VF and MFERG
        
        // For MERCI ratings (example - you might want to adjust this)
        if (eye === 'OD') {
          visitData.merci_rating_right_eye = data.result.score;
        } else {
          visitData.merci_rating_left_eye = data.result.score;
        }
        
        // Save the visit
        const visitResponse = await fetch('http://localhost:5000/api/visits', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(visitData)
        });
        
        const visitResult = await visitResponse.json();
        if (visitResponse.ok) {
          alert(`Image processed and visit recorded! Score: ${data.result.score}`);
        } else {
          alert('Image processed but failed to record visit');
        }
      } else {
        alert('Upload failed');
      }
    } catch (error) {
      console.error('Error:', error);
      alert('Upload failed');
    }
  };

  return (
    <Paper elevation={3} style={{ padding: '20px', marginTop: '20px' }}>
      <Typography variant="h5" gutterBottom>Upload Medical Images</Typography>
      
      <Grid container spacing={3}>
        <Grid item xs={12} sm={6}>
          <TextField
            fullWidth
            label="Patient ID"
            type="number"
            value={patientId}
            onChange={(e) => setPatientId(e.target.value)}
            required
          />
        </Grid>
        
        <Grid item xs={12} sm={6}>
          <FormControl fullWidth>
            <InputLabel>Test Type</InputLabel>
            <Select
              value={testType}
              onChange={(e) => setTestType(e.target.value)}
            >
              {testTypes.map(type => (
                <MenuItem key={type} value={type}>{type}</MenuItem>
              ))}
            </Select>
          </FormControl>
        </Grid>
        
        <Grid item xs={12} sm={6}>
          <FormControl fullWidth>
            <InputLabel>Eye</InputLabel>
            <Select
              value={eye}
              onChange={(e) => setEye(e.target.value)}
            >
              <MenuItem value="OD">OD (Right Eye)</MenuItem>
              <MenuItem value="OS">OS (Left Eye)</MenuItem>
            </Select>
          </FormControl>
        </Grid>
        
        <Grid item xs={12}>
          <input
            accept="image/*"
            style={{ display: 'none' }}
            id="contained-button-file"
            type="file"
            onChange={handleFileChange}
          />
          <label htmlFor="contained-button-file">
            <Button
              variant="contained"
              component="span"
              startIcon={<CloudUploadIcon />}
            >
              Select Image
            </Button>
          </label>
          {file && (
            <Typography variant="body1" style={{ marginTop: '10px' }}>
              Selected: {file.name}
            </Typography>
          )}
        </Grid>
        
        <Grid item xs={12}>
          <Button
            variant="contained"
            color="primary"
            onClick={handleUpload}
            disabled={!file || !patientId}
          >
            Upload and Process
          </Button>
        </Grid>
        
        {uploadResult && (
          <Grid item xs={12}>
            <Typography variant="h6">Results:</Typography>
            <Typography>Filename: {uploadResult.filename}</Typography>
            <Typography>Test ID: {uploadResult.test_id}</Typography>
            <Typography>Image Number: {uploadResult.image_number}</Typography>
            <Typography>Score: {uploadResult.score}</Typography>
          </Grid>
        )}
      </Grid>
    </Paper>
  );
};

export default ImageUpload;
*/