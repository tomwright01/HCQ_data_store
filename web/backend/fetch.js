/*
// Example of how to fetch patient data
fetch('http://localhost:8080/api.php')
  .then(response => response.json())
  .then(data => {
    console.log(data);
  })
  .catch(error => {
    console.log('Error:', error);
  });
