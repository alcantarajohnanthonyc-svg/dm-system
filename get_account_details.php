<?php
// 1. Set the header before any other output
header('Content-Type: application/json');

// 2. Clear any output buffer
if (ob_get_length()) ob_end_clean();

require_once 'config.php';

$response = null;

if (isset($_GET['account_number'])) {
    $acc = $_GET['account_number'];
    
    // Using the query logic you provided
    $sql = "SELECT dm.account_number, dm.company, dm.assignee_name, dmi.mobile_number , dmi.carrier_name
            FROM `debit_memos` as dm 
            LEFT JOIN debit_memo_items as dmi on dmi.dm_id = dm.dm_id 
            WHERE dm.account_number = :acc 
            LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute(['acc' => $acc]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 3. Return only JSON
echo json_encode($response);
exit;
?>