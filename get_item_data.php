<?php
require_once 'config.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("SELECT i.*, m.account_number, m.company, m.assignee_name, i.carrier_name
                        FROM debit_memo_items i 
                        LEFT JOIN debit_memos m ON i.dm_id = m.dm_id 
                        WHERE i.id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// Return ONLY the JSON data
echo json_encode($data);
?>