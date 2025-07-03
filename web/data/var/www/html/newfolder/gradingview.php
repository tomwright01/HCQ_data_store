<?php
/*
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
*/
?>

