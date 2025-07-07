<?php
/*
// Assuming database connection is established
require_once 'includes/config.php';

// Get the visit_id from URL parameters
$visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

// Fetch the visit details from the database
function getVisitById($visit_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM Visits WHERE visit_id = ?");
    $stmt->bind_param("i", $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

$visit = getVisitById($visit_id);

if (!$visit) {
    die("Visit not found.");
}

// Fetch image references from visit
$faf_image = $visit['faf_reference_OD']; // FAF OD Image
$oct_image = $visit['oct_reference_OD']; // OCT OD Image
$mferg_image = $visit['mferg_reference_OD']; // MFERG OD Image
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Visit Grading - Visit ID: <?= htmlspecialchars($visit['visit_id']) ?></title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .image-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .image-container img {
            max-width: 100%;
            height: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: center;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        .submit-btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .submit-btn:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1>Grading for Visit ID: <?= htmlspecialchars($visit['visit_id']) ?></h1>

        <!-- Display the FAF Image (OD) -->
        <div class="image-container">
            <?php
            $image_path = getDynamicImagePath($faf_image); // Function to get the image path
            if ($image_path) {
                echo "<img src='$image_path' alt='FAF OD Image' />";
            }
            ?>
        </div>

        <!-- Grading Table -->
        <h2>Grading Table</h2>
        <form method="POST" action="save_grading.php">
            <input type="hidden" name="visit_id" value="<?= $visit['visit_id'] ?>">

            <table>
                <thead>
                    <tr>
                        <th>Test Type</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>FAF</td>
                        <td>
                            <input type="number" name="faf_score" min="0" max="3" placeholder="Enter Score" />
                        </td>
                    </tr>
                    <tr>
                        <td>OCT</td>
                        <td>
                            <input type="number" name="oct_score" min="0" max="3" placeholder="Enter Score" />
                        </td>
                    </tr>
                    <tr>
                        <td>MFERG</td>
                        <td>
                            <input type="number" name="mferg_score" min="0" max="3" placeholder="Enter Score" />
                        </td>
                    </tr>
                    <tr>
                        <td>VF</td>
                        <td>
                            <input type="number" name="vf_score" min="0" max="3" placeholder="Enter Score" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" class="submit-btn">Save Grading</button>
            </div>
        </form>
    </div>

</body>
</html>

<?php
$conn->close();
*/
?>


