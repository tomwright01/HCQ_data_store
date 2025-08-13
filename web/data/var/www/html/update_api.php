<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'includes/config.php'; // provides $conn (mysqli)
require_once 'includes/functions.php'; // if you need helpers

function respond($arr){ echo json_encode($arr); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok'=>false, 'error'=>'Invalid method']);
$raw = file_get_contents('php://input');
if (!$raw) respond(['ok'=>false, 'error'=>'Empty body']);

$payload = json_decode($raw, true);
if (!is_array($payload)) respond(['ok'=>false, 'error'=>'Bad JSON']);

if (!isset($_SESSION['csrf']) || !isset($payload['csrf']) || !hash_equals($_SESSION['csrf'], (string)$payload['csrf'])) {
    respond(['ok'=>false, 'error'=>'CSRF invalid']);
}

$type = $payload['type'] ?? null;
$id   = $payload['id']   ?? null;
$data = $payload['data'] ?? null;

if (!$type || !$id || !is_array($data)) respond(['ok'=>false, 'error'=>'Missing fields']);

function build_update(mysqli $conn, string $table, array $allowed, array $data, string $idCol, $idVal){
    $set = []; $vals = []; $types = '';
    foreach ($allowed as $col => $meta) {
        if (!array_key_exists($col, $data)) continue;
        $v = $data[$col];
        if ($v === '' || $v === 'null') $v = null;
        // Special case: merci_score allows "unable" string
        if ($col === 'merci_score' && is_string($v) && strtolower($v) === 'unable') {
            $v = 'unable';
        }
        $set[] = "{$col} = ?";
        if (is_null($v)) { $types .= 's'; $vals[] = null; } // send null as NULL via stmt->bind_param? Use workaround below.
        elseif (is_int($v)) { $types.='i'; $vals[]=$v; }
        elseif (is_float($v) || (is_string($v) && is_numeric($v))) { $types.='d'; $vals[]=(float)$v; }
        else { $types.='s'; $vals[]=$v; }
    }
    if (!$set) return [false, 'Nothing to update'];

    $sql = "UPDATE {$table} SET ".implode(', ', $set)." WHERE {$idCol} = ?";
    // id type guessing: patient_id, test_id are strings; result_id is int
    if ($idCol === 'result_id') { $types .= 'i'; } else { $types .= 's'; }
    $vals[] = $idVal;

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [false, 'Prepare failed: '.$conn->error];

    // bind (mysqli can't bind NULLs directly for non-string sometimes; but 's' with null works and becomes NULL)
    $stmt->bind_param($types, ...$vals);
    if (!$stmt->execute()) return [false, 'Execute failed: '.$stmt->error];
    $stmt->close();

    return [true, null];
}

try {
    if ($type === 'patient') {
        // Allowed patient fields
        $allowed = [
            'subject_id'    => 'string',
            'date_of_birth' => 'date',
            'location'      => 'enum'
        ];
        [$ok, $err] = build_update($conn, 'patients', $allowed, $data, 'patient_id', $id);
        if (!$ok) respond(['ok'=>false, 'error'=>$err]);
        respond(['ok'=>true]);
    }
    elseif ($type === 'test') {
        $allowed = [
            'date_of_test' => 'date'
        ];
        [$ok, $err] = build_update($conn, 'tests', $allowed, $data, 'test_id', $id);
        if (!$ok) respond(['ok'=>false, 'error'=>$err]);
        respond(['ok'=>true]);
    }
    elseif ($type === 'eye') {
        // Allowed eye columns
        $allowed = [
            'age'                => 'int',
            'report_diagnosis'   => 'enum',
            'merci_score'        => 'string_or_number', // accepts 'unable' or number
            'faf_grade'          => 'int',
            'oct_score'          => 'float',
            'vf_score'           => 'float',
            'actual_diagnosis'   => 'string',
            'dosage'             => 'float',
            'duration_days'      => 'int',
            'cumulative_dosage'  => 'float',
            'date_of_continuation'=> 'string'
        ];
        // result_id is numeric
        [$ok, $err] = build_update($conn, 'test_eyes', $allowed, $data, 'result_id', (int)$id);
        if (!$ok) respond(['ok'=>false, 'error'=>$err]);
        respond(['ok'=>true]);
    } else {
        respond(['ok'=>false, 'error'=>'Unknown type']);
    }
} catch (Throwable $e) {
    respond(['ok'=>false, 'error'=>'Server error: '.$e->getMessage()]);
}
