<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visit = getVisitById($visit_id);

if (!$visit) {
    die("Visit not found.");
}

// Fetch the grading score for the current visit
$grading_scores = [
    'faf' => getGradingScore($visit_id, 'faf'),
    'oct' => getGradingScore($visit_id, 'oct'),
    'vf' => getGradingScore($visit_id, 'vf'),
    'mferg' => getGradingScore($visit_id, 'mferg')
];

function getGradingScore($visit_id, $test_type) {
    global $conn;
    $stmt = $conn->prepare("SELECT score FROM Grading WHERE visit_id = ? AND test_type = ?");
    $stmt->bind_param("is", $visit_id, $test_type);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ? $result->fetch_assoc()['score'] : null;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading View - Visit ID: <?= htmlspecialchars($visit['visit_id']) ?></title>
    <style>
        /* Add your custom styling here */
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
        .grading-container {
            display: flex;
            justify-content: space-around;
        }
        .grading-container div {
            margin-top: 20px;
        }
        .checkbox-group {
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Grading View - Visit ID: <?= htmlspecialchars($visit['visit_id']) ?></h1>
        
        <div class="image-container">
            <?php
            // Display the image for FAF (example: display FAF OD image)
            $image_path = getDynamicImagePath($visit['faf_reference_OD']);
            if ($image_path) {
                echo "<img src='$image_path' alt='FAF OD Image' />";
            }
            ?>
        </div>

        <!-- Grading Section -->
        <h2>Grading System</h2>
        
        <form method="POST" action="update_grading.php">
            <input type="hidden" name="visit_id" value="<?= $visit['visit_id'] ?>">

            <!-- FAF Grading -->
            <div class="grading-container">
                <div>
                    <h3>FAF Grading</h3>
                    <div class="checkbox-group">
                        <input type="radio" name="faf_score" value="0" <?= $grading_scores['faf'] == 0 ? 'checked' : '' ?>> 0
                        <input type="radio" name="faf_score" value="1" <?= $grading_scores['faf'] == 1 ? 'checked' : '' ?>> 1
                        <input type="radio" name="faf_score" value="2" <?= $grading_scores['faf'] == 2 ? 'checked' : '' ?>> 2
                        <input type="radio" name="faf_score" value="3" <?= $grading_scores['faf'] == 3 ? 'checked' : '' ?>> 3
                    </div>
                </div>

                <!-- OCT Grading -->
                <div>
                    <h3>OCT Grading</h3>
                    <div class="checkbox-group">
                        <input type="radio" name="oct_score" value="0" <?= $grading_scores['oct'] == 0 ? 'checked' : '' ?>> 0
                        <input type="radio" name="oct_score" value="1" <?= $grading_scores['oct'] == 1 ? 'checked' : '' ?>> 1
                        <input type="radio" name="oct_score" value="2" <?= $grading_scores['oct'] == 2 ? 'checked' : '' ?>> 2
                        <input type="radio" name="oct_score" value="3" <?= $grading_scores['oct'] == 3 ? 'checked' : '' ?>> 3
                    </div>
                </div>

                <!-- VF Grading -->
                <div>
                    <h3>VF Grading</h3>
                    <div class="checkbox-group">
                        <input type="radio" name="vf_score" value="0" <?= $grading_scores['vf'] == 0 ? 'checked' : '' ?>> 0
                        <input type="radio" name="vf_score" value="1" <?= $grading_scores['vf'] == 1 ? 'checked' : '' ?>> 1
                        <input type="radio" name="vf_score" value="2" <?= $grading_scores['vf'] == 2 ? 'checked' : '' ?>> 2
                        <input type="radio" name="vf_score" value="3" <?= $grading_scores['vf'] == 3 ? 'checked' : '' ?>> 3
                    </div>
                </div>

                <!-- MFERG Grading -->
                <div>
                    <h3>MFERG Grading</h3>
                    <div class="checkbox-group">
                        <input type="radio" name="mferg_score" value="0" <?= $grading_scores['mferg'] == 0 ? 'checked' : '' ?>> 0
                        <input type="radio" name="mferg_score" value="1" <?= $grading_scores['mferg'] == 1 ? 'checked' : '' ?>> 1
                        <input type="radio" name="mferg_score" value="2" <?= $grading_scores['mferg'] == 2 ? 'checked' : '' ?>> 2
                        <input type="radio" name="mferg_score" value="3" <?= $grading_scores['mferg'] == 3 ? 'checked' : '' ?>> 3
                    </div>
                </div>
            </div>

            <button type="submit">Save Grading</button>
        </form>
    </div>
</body>
</html>
