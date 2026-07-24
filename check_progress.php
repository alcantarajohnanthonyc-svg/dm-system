<?php
session_start();
$sessionId = session_id();
session_write_close(); 

$progressFile = __DIR__ . '/progress_' . $sessionId . '.json';

header('Content-Type: application/json');

if (file_exists($progressFile)) {
    echo file_get_contents($progressFile);
} else {
    echo json_encode([
        'percent' => 0,
        'processed' => 0,
        'total' => 0
    ]);
}
?>