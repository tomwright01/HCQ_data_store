<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";

// Create database connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully";

// phpinfo();
?>


