<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
$sessionId = session_id();
session_write_close(); 

$progressFile = __DIR__ . '/progress_' . $sessionId . '.json';

header('Content-Type: application/json; charset=utf-8');

if (file_exists($progressFile)) {
    $data = file_get_contents($progressFile);
    if ($data !== false && !empty($data)) {
        echo $data;
        exit;
    }
}

// Fallback default response if file doesn't exist yet
echo json_encode([
    'percent' => 0,
    'processed' => 0,
    'total' => 0
]);
exit;