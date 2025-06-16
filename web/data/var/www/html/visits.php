// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get the Patient ID from the URL
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : 0;

// Use a prepared statement to avoid SQL injection
$sql = "SELECT v.visit_id, v.visit_date, v.visit_notes, 
        v.faf_reference_OD, v.faf_reference_OS, 
        v.oct_reference_OD, v.oct_reference_OS, 
        v.vf_reference_OD, v.vf_reference_OS, 
        v.mferg_reference_OD, v.mferg_reference_OS, 
        v.merci_rating_left_eye, v.merci_rating_right_eye, 
        p.patient_id, p.location, p.disease_id, p.year_of_birth, p.gender, p.referring_doctor
        FROM Visits v
        LEFT JOIN Patients p ON v.patient_id = p.patient_id
        WHERE v.patient_id = ?";

// Prepare the SQL statement
$stmt = $conn->prepare($sql);

// Bind parameters to the SQL query
$stmt->bind_param("i", $patient_id);

// Execute the statement
$stmt->execute();

// Get the result
$result = $stmt->get_result();

// Check if there are results
if ($result->num_rows > 0) {
  // Output patient and visit data
  echo "<h1>Visits for Patient ID: $patient_id</h1>";

  // Patient Info
  echo "<h2>Patient Information</h2>";
  echo "<table border='1' cellpadding='10'>
          <tr>
            <th>Patient ID</th>
            <th>Location</th>
            <th>Disease ID</th>
            <th>Year of Birth</th>
            <th>Gender</th>
            <th>Referring Doctor</th>
          </tr>";

  // Fetch and display patient info
  $patient_data = $result->fetch_assoc();
  echo "<tr>
            <td>" . $patient_data["patient_id"] . "</td>
            <td>" . $patient_data["location"] . "</td>
            <td>" . $patient_data["disease_id"] . "</td>
            <td>" . $patient_data["year_of_birth"] . "</td>
            <td>" . $patient_data["gender"] . "</td>
            <td>" . $patient_data["referring_doctor"] . "</td>
          </tr>";
  echo "</table>";

  // Visits Info
  echo "<h2>Visit Information</h2>";
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
          </tr>";

  // Loop through each visit and display data
  do {
    echo "<tr>
            <td>" . $patient_data["visit_id"] . "</td>
            <td>" . $patient_data["visit_date"] . "</td>
            <td>" . $patient_data["visit_notes"] . "</td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["faf_reference_OD"] . "\", " . $patient_data["merci_rating_left_eye"] . ")'>
                  <img src='" . $patient_data["faf_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["faf_reference_OS"] . "\", " . $patient_data["merci_rating_right_eye"] . ")'>
                  <img src='" . $patient_data["faf_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["oct_reference_OD"] . "\", " . $patient_data["merci_rating_left_eye"] . ")'>
                  <img src='" . $patient_data["oct_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["oct_reference_OS"] . "\", " . $patient_data["merci_rating_right_eye"] . ")'>
                  <img src='" . $patient_data["oct_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["vf_reference_OD"] . "\", " . $patient_data["merci_rating_left_eye"] . ")'>
                  <img src='" . $patient_data["vf_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["vf_reference_OS"] . "\", " . $patient_data["merci_rating_right_eye"] . ")'>
                  <img src='" . $patient_data["vf_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["mferg_reference_OD"] . "\", " . $patient_data["merci_rating_left_eye"] . ")'>
                  <img src='" . $patient_data["mferg_reference_OD"] . "' width='100' height='100' />
                </a>
            </td>
            <td><a href='#' onclick='openModal(\"" . $patient_data["mferg_reference_OS"] . "\", " . $patient_data["merci_rating_right_eye"] . ")'>
                  <img src='" . $patient_data["mferg_reference_OS"] . "' width='100' height='100' />
                </a>
            </td>
            <td>" . $patient_data["merci_rating_left_eye"] . "</td>
            <td>" . $patient_data["merci_rating_right_eye"] . "</td>
          </tr>";
  } while ($patient_data = $result->fetch_assoc());

  echo "</table>";
} else {
  echo "<p>No visits found for this patient.</p>";
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

