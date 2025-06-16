<?php
$servername = "mariadb";
$username = "root";
$password = "notgood";
$dbname = "PatientData"; // Name of your database

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get the Patient ID from the URL
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : 0;

// Query to fetch visit data for the selected patient
$sql = "SELECT v.visit_id, v.visit_date, v.visit_notes, 
        v.faf_reference_OD, v.faf_reference_OS, 
        v.oct_reference_OD, v.oct_reference_OS, 
        v.vf_reference_OD, v.vf_reference_OS, 
        v.mferg_reference_OD, v.mferg_reference_OS, 
        v.merci_rating_left_eye, v.merci_rating_right_eye, 
        p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
        FROM Visits v
        LEFT JOIN Patients p ON v.patient_id = p.patient_id
        WHERE v.patient_id = $patient_id";

$result = $conn->query($sql);

// Check if there are results
if ($result->num_rows > 0) {
  // Output data of each row
  echo "<h1>Visits for Patient ID: $patient_id</h1>";
  echo "<table border='1' cellpadding='10'>
          <tr>
            <th>Visit ID</th>
            <th>Visit Date</th>
            <th>Visit Notes</th>
            <th>FAF Reference (OD)</th>
            <th>FAF Reference (OS)</th>
            <th>OCT Reference (OD)</th>
            <th>OCT Reference (OS)</th>
            <th>VF Reference (OD)</th>
            <th>VF Reference (OS)</th>
            <th>MFERG Reference (OD)</th>
            <th>MFERG Reference (OS)</th>
            <th>MERCI Left Eye</th>
            <th>MERCI Right Eye</th>
            <th>Location</th>
            <th>Disease ID</th>
            <th>Year of Birth</th>
            <th>Gender</th>
            <th>Referring Doctor</th>
          </tr>";

  while($row = $result->fetch_assoc()) {
    echo "<tr>
            <td>" . $row["visit_id"] . "</td>
            <td>" . $row["visit_date"] . "</td>
            <td>" . $row["visit_notes"] . "</td>
            <td><a href='#' onclick='openModal(\"" . $row["faf_reference_OD"] . "\", " . $row["merci_rating_left_eye"] . ")'>
                  <img src='" . $row["faf_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["faf_reference_OS"] . "\", " . $row["merci_rating_right_eye"] . ")'>
                  <img src='" . $row["faf_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["oct_reference_OD"] . "\", " . $row["merci_rating_left_eye"] . ")'>
                  <img src='" . $row["oct_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["oct_reference_OS"] . "\", " . $row["merci_rating_right_eye"] . ")'>
                  <img src='" . $row["oct_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["vf_reference_OD"] . "\", " . $row["merci_rating_left_eye"] . ")'>
                  <img src='" . $row["vf_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["vf_reference_OS"] . "\", " . $row["merci_rating_right_eye"] . ")'>
                  <img src='" . $row["vf_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["mferg_reference_OD"] . "\", " . $row["merci_rating_left_eye"] . ")'>
                  <img src='" . $row["mferg_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $row["mferg_reference_OS"] . "\", " . $row["merci_rating_right_eye"] . ")'>
                  <img src='" . $row["mferg_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td>" . $row["merci_rating_left_eye"] . "</td>
            <td>" . $row["merci_rating_right_eye"] . "</td>
            <td>" . $row["location"] . "</td>
            <td>" . $row["disease_id"] . "</td>
            <td>" . $row["year_of_birth"] . "</td>
            <td>" . $row["gender"] . "</td>
            <td>" . $row["referring_doctor"] . "</td>
          </tr>";
  }

  echo "</table>";
} else {
  echo "No visits found for this patient.";
}

// Close connection
$conn->close();
?>

<!-- Modal Popup -->
<div id="imageModal" class="modal">
  <span class="close" onclick="closeModal()">&times;</span>
  <img class="modal-content" id="modalImage">
  <div id="imageText"></div>
</div>

<script>
// Open the modal
function openModal(imageSrc, score) {
  var modal = document.getElementById("imageModal");
  var modalImg = document.getElementById("modalImage");
  var imageText = document.getElementById("imageText");

  modal.style.display = "block";
  modalImg.src = imageSrc;
  imageText.innerHTML = "Score: " + score;
}

// Close the modal
function closeModal() {
  var modal = document.getElementById("imageModal");
  modal.style.display = "none";
}
</script>

<style>
/* Modal styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1;
  padding-top: 60px;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgb(0, 0, 0);
  background-color: rgba(0, 0, 0, 0.8);
}

.modal-content {
  margin: auto;
  display: block;
  width: 80%;
  max-width: 700px;
}

#imageText {
  text-align: center;
  font-size: 18px;
  color: white;
  margin-top: 10px;
}

.close {
  position: absolute;
  top: 15px;
  right: 35px;
  color: #f1f1f1;
  font-size: 40px;
  font-weight: bold;
}

.close:hover,
.close:focus {
  color: #bbb;
  text-decoration: none;
  cursor: pointer;
}
</style>


