<?php
require_once 'config.php';

$dm_id = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
$item_ids_input = isset($_GET['item_ids']) ? trim($_GET['item_ids']) : '';
$breakdown_item_ids = !empty($item_ids_input) ? array_filter(explode(',', $item_ids_input)) : [];

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

if ($dm_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Debit Memo ID.']);
    exit;
}

// 1. Kunin ang debit memo record
$stmt = $pdo->prepare("SELECT * FROM debit_memos WHERE dm_id = ?");
$stmt->execute([$dm_id]);
$dm = $stmt->fetch();

if (!$dm) {
    echo json_encode(['status' => 'error', 'message' => 'Debit memo record not found.']);
    exit;
}

$account_number = $dm['account_number'];

// 2. Kunin ang email mula sa account_emails table (Katulad sa bulk dispatch)
$email_stmt = $pdo->prepare("SELECT * FROM account_emails WHERE account_number = ?");
$email_stmt->execute([$account_number]);
$account_email_row = $email_stmt->fetch();

$recipient_email = ($account_email_row && !empty($account_email_row['email_address'])) ? $account_email_row['email_address'] : '';
$recipient_name  = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : 'Valued Client';
$account_name_display = "{$recipient_name} / {$account_number}";

// 3. Kunin ang mga items base sa breakdown o date filter (Katulad sa bulk dispatch)
$itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
$itemParams = [$dm_id];

if (!empty($breakdown_item_ids)) {
    $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
    $itemQuery .= " AND id IN ($placeholders)";
    foreach ($breakdown_item_ids as $iid) {
        $itemParams[] = $iid;
    }
} elseif (!empty($start_date) && !empty($end_date)) {
    $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
    $itemParams[] = $start_date;
    $itemParams[] = $end_date;
}

$itemQuery .= " ORDER BY coverage_start ASC";

$stmtItems = $pdo->prepare($itemQuery);
$stmtItems->execute($itemParams);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$total_final_dm = 0.00;
$total_approved_plan = 0.00;
$raw_start_dates = [];
$raw_end_dates = [];

if (!empty($items)) {
    foreach ($items as $it) {
        $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
        $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
        $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
        
        $total_approved_plan += isset($it['approved_plan']) ? (float)$it['approved_plan'] : 0.00;

        if (!empty($it['coverage_start'])) $raw_start_dates[] = $it['coverage_start'];
        if (!empty($it['coverage_end'])) $raw_end_dates[] = $it['coverage_end'];
    }
}

// Petsa range kalkulasyon
if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
    $earliest_start = date('M d, Y', strtotime(min($raw_start_dates)));
    $latest_end = date('M d, Y', strtotime(max($raw_end_dates)));
    $data_coverage = "{$earliest_start} to {$latest_end}";
} else {
    $data_coverage = "As of current billing";
}

$approved_plan_display = number_format($total_approved_plan, 2, '.', ',');
$final_dm_val = number_format($total_final_dm, 2, '.', ',');
$dm_number_display = $dm['dm_number'] ?? $dm_id;
$subject = "Statement of Account / Debit Memo - " . $dm_number_display;

// 4. I-render ang HTML email layout para sa preview box
$html_content = "
<div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333;'>
    <p>Dear Ma'am/Sir,</p>
    <p>Please find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges:</p>
    <p><b>Summary Details:</b><br>
    Period Covered: {$data_coverage}<br>
    Account Name: {$account_name_display}<br>
    Approved Plan (Company Share): ₱ {$approved_plan_display}<br>
    Total Chargeable Amount: ₱ {$final_dm_val}</p>
    <p>For any questions or concerns, please reply directly to this email.</p>
    <p>Thank you,</p>
    <p><b>IT Telco Admin Team</b></p>
</div>";

echo json_encode([
    'status' => 'success',
    'recipient' => $recipient_email,
    'cc' => '', // Maaari mong lagyan ng default CC kung kinakailangan
    'subject' => $subject,
    'body_html' => $html_content
]);
exit;
?>