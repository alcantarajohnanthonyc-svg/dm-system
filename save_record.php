<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Capture ID
$item_id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;

// Sanitize inputs
$account_number = $_POST['account_number'];
$company = $_POST['company'];
$assignee = $_POST['assignee'];
$mobile = $_POST['mobile_number'];
$carrier = $_POST['carrier'];
$start_date = $_POST['coverage_start'];
$end_date = $_POST['coverage_end'];
$memo_details = $_POST['debit_memo_details'];

$numeric_fields = [
    'approve_plan', 'phone_amort', 'debit_adj', 'credit_adj', 'other_charges', 
    'local', 'ndd', 'idd', 'roam', 'sms', 'gprs', 'wiz_usage', 'loading', 
    'charges', 'vat', 'oct', 'current_charges', 'total_amount_due'
];

$data = [];
foreach ($numeric_fields as $f) {
    $data[$f] = isset($_POST[$f]) && $_POST[$f] !== '' ? (float)$_POST[$f] : 0.00;
}

try {
    $conn->beginTransaction();

    // 1. Get or Create parent record & Synchronize Company/Assignee
    $stmt = $conn->prepare("SELECT dm_id FROM debit_memos WHERE account_number = ?");
    $stmt->execute([$account_number]);
    $memo = $stmt->fetch();

    if (!$memo) {
        $stmt = $conn->prepare("INSERT INTO debit_memos (account_number, company, assignee_name, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$account_number, $company, $assignee, $user_id]);
        $current_dm_id = $conn->lastInsertId();
    } else {
        $current_dm_id = $memo['dm_id'];
        // UPDATE PARENT RECORD: This ensures company/assignee changes are saved
        $stmt = $conn->prepare("UPDATE debit_memos SET company = ?, assignee_name = ? WHERE dm_id = ?");
        $stmt->execute([$company, $assignee, $current_dm_id]);
    }

    // 2. Insert or Update Child Record
    if ($item_id && $item_id > 0) {
        // UPDATE CHILD RECORD
        $sql = "UPDATE debit_memo_items SET 
                dm_id = ?, carrier_name = ?, mobile_number = ?, coverage_start = ?, coverage_end = ?, 
                approved_plan = ?, phone_amortization = ?, debit_adj = ?, credit_adj = ?, other_charges = ?, 
                local_call_text = ?, ndd_charges = ?, idd_charges = ?, roaming_charges = ?, sms_charges = ?, 
                gprs_charges = ?, wiz_usage = ?, loading_charges = ?, vat = ?, oct = ?, 
                current_charges = ?, total_amount_due = ?, debit_memo_details = ?, created_by = ? 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $current_dm_id, $carrier, $mobile, $start_date, $end_date,
            $data['approve_plan'], $data['phone_amort'], $data['debit_adj'], $data['credit_adj'], $data['other_charges'],
            $data['local'], $data['ndd'], $data['idd'], $data['roam'], $data['sms'],
            $data['gprs'], $data['wiz_usage'], $data['loading'], $data['vat'], $data['oct'],
            $data['current_charges'], $data['total_amount_due'], $memo_details, $user_id, $item_id
        ]);
    } else {
        // INSERT CHILD RECORD
        $sql = "INSERT INTO debit_memo_items (dm_id, carrier_name, mobile_number, coverage_start, coverage_end, 
                approved_plan, phone_amortization, debit_adj, credit_adj, other_charges, 
                local_call_text, ndd_charges, idd_charges, roaming_charges, sms_charges, 
                gprs_charges, wiz_usage, loading_charges, vat, oct, 
                current_charges, total_amount_due, debit_memo_details, created_by) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $current_dm_id, $carrier, $mobile, $start_date, $end_date,
            $data['approve_plan'], $data['phone_amort'], $data['debit_adj'], $data['credit_adj'], $data['other_charges'],
            $data['local'], $data['ndd'], $data['idd'], $data['roam'], $data['sms'],
            $data['gprs'], $data['wiz_usage'], $data['loading'], $data['vat'], $data['oct'],
            $data['current_charges'], $data['total_amount_due'], $memo_details, $user_id
        ]);
    }

    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}