<?php
// Ensure NO whitespace or blank lines exist before this opening PHP tag.
// Turn off error output to the browser so warnings don't break JSON
ini_set('display_errors', 0);
error_reporting(0);

require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(null);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT i.*, m.account_number, m.company, m.assignee_name, i.carrier_name
                            FROM debit_memo_items i 
                            LEFT JOIN debit_memos m ON i.dm_id = m.dm_id 
                            WHERE i.id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($data ? $data : null);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
?>