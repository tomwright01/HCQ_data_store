<?php
require_once 'includes/config.php';

// Retrieve the visit_id and the grading scores
$visit_id = isset($_POST['visit_id']) ? $_POST['visit_id'] : 0;
$faf_score = isset($_POST['faf_score']) ? (int)$_POST['faf_score'] : null;
$oct_score = isset($_POST['oct_score']) ? (int)$_POST['oct_score'] : null;
$vf_score = isset($_POST['vf_score']) ? (int)$_POST['vf_score'] : null;
$mferg_score = isset($_POST['mferg_score']) ? (int)$_POST['mferg_score'] : null;

if ($visit_id > 0) {
    // Prepare SQL statement to update grading scores for each test type
    if ($faf_score !== null) {
        $stmt = $conn->prepare("INSERT INTO Grading (visit_id, test_type, score) VALUES (?, 'faf', ?) ON DUPLICATE KEY UPDATE score = ?");
        $stmt->bind_param("iii", $visit_id, $faf_score, $faf_score);
        $stmt->execute();
    }
    if ($oct_score !== null) {
        $stmt = $conn->prepare("INSERT INTO Grading (visit_id, test_type, score) VALUES (?, 'oct', ?) ON DUPLICATE KEY UPDATE score = ?");
        $stmt->bind_param("iii", $visit_id, $oct_score, $oct_score);
        $stmt->execute();
    }
    if ($vf_score !== null) {
        $stmt = $conn->prepare("INSERT INTO Grading (visit_id, test_type, score) VALUES (?, 'vf', ?) ON DUPLICATE KEY UPDATE score = ?");
        $stmt->bind_param("iii", $visit_id, $vf_score, $vf_score);
        $stmt->execute();
    }
    if ($mferg_score !== null) {
        $stmt = $conn->prepare("INSERT INTO Grading (visit_id, test_type, score) VALUES (?, 'mferg', ?) ON DUPLICATE KEY UPDATE score = ?");
        $stmt->bind_param("iii", $visit_id, $mferg_score, $mferg_score);
        $stmt->execute();
    }
    
    // Redirect to the visit page or confirm success
    header("Location: gradingview.php?visit_id=" . $visit_id);
    exit;
}
?>
