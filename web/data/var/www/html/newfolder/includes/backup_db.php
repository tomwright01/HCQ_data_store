<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

try {
    $backupFile = backupDatabase();
    
    if ($backupFile) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Backup created successfully',
            'file' => basename($backupFile)
        ]);
    } else {
        throw new Exception("Backup process failed");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
