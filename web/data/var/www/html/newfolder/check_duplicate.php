<?php
require_once 'config.php';

header('Content-Type: application/json');

try {
    $patientId = $_GET['patient'] ?? '';
    $testDate = $_GET['date'] ?? '';
    $eye = $_GET['eye'] ?? '';
    
    if (empty($patientId) || empty($testDate) || empty($eye)) {
        throw new Exception('Missing parameters');
    }
    
    $stmt = $conn->prepare("SELECT test_id FROM tests 
                          WHERE patient_id = ? 
                          AND date_of_test = ? 
                          AND eye = ?");
    $stmt->bind_param("sss", $patientId, $testDate, $eye);
    $stmt->execute();
    
    echo json_encode([
        'exists' => $stmt->get_result()->num_rows > 0
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
