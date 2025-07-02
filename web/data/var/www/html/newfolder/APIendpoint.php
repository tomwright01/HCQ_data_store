<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once '../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $patient_id = (int)$_GET['id'];
            $patient = getPatientById($patient_id);
            if ($patient) {
                echo json_encode($patient);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Patient not found']);
            }
        } else {
            $patients = getAllPatients();
            echo json_encode($patients);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!empty($data)) {
            $patient_data = [
                'location' => $data['location'],
                'disease_id' => (int)$data['disease_id'],
                'year_of_birth' => (int)$data['year_of_birth'],
                'gender' => $data['gender'],
                'referring_doctor' => $data['referring_doctor'],
                'rx_OD' => (float)$data['rx_OD'],
                'rx_OS' => (float)$data['rx_OS'],
                'procedures_done' => $data['procedures_done'],
                'dosage' => (float)$data['dosage'],
                'duration' => (int)$data['duration'],
                'cumulative_dosage' => isset($data['cumulative_dosage']) ? (float)$data['cumulative_dosage'] : null,
                'date_of_discontinuation' => $data['date_of_discontinuation'] ?? null,
                'extra_notes' => $data['extra_notes'] ?? null
            ];
            
            $visit_data = [
                'visit_date' => $data['visit_date'],
                'visit_notes' => $data['visit_notes'] ?? null,
                'faf_test_id_OD' => $data['faf_test_id_OD'] ?? null,
                'faf_image_number_OD' => $data['faf_image_number_OD'] ?? null,
                'faf_test_id_OS' => $data['faf_test_id_OS'] ?? null,
                'faf_image_number_OS' => $data['faf_image_number_OS'] ?? null,
                'oct_test_id_OD' => $data['oct_test_id_OD'] ?? null,
                'oct_image_number_OD' => $data['oct_image_number_OD'] ?? null,
                'oct_test_id_OS' => $data['oct_test_id_OS'] ?? null,
                'oct_image_number_OS' => $data['oct_image_number_OS'] ?? null,
                'vf_test_id_OD' => $data['vf_test_id_OD'] ?? null,
                'vf_image_number_OD' => $data['vf_image_number_OD'] ?? null,
                'vf_test_id_OS' => $data['vf_test_id_OS'] ?? null,
                'vf_image_number_OS' => $data['vf_image_number_OS'] ?? null,
                'mferg_test_id_OD' => $data['mferg_test_id_OD'] ?? null,
                'mferg_image_number_OD' => $data['mferg_image_number_OD'] ?? null,
                'mferg_test_id_OS' => $data['mferg_test_id_OS'] ?? null,
                'mferg_image_number_OS' => $data['mferg_image_number_OS'] ?? null,
                'merci_rating_left_eye' => $data['merci_rating_left_eye'] ?? null,
                'merci_rating_right_eye' => $data['merci_rating_right_eye'] ?? null
            ];
            
            $patient_id = addPatientAndVisit($patient_data, $visit_data);
            
            if ($patient_id) {
                http_response_code(201);
                echo json_encode(['patient_id' => $patient_id]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create patient']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
