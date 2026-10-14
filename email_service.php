<?php
// email_service.php - Standard Unified Helper Backend
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

if (file_exists('email_template.php')) {
    require_once 'email_template.php';
}

function getDefaultEmailContent($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00') {
    if (function_exists('getEmailTemplate')) {
        $template = getEmailTemplate($account_number, $company, $recipient_name, $employee_id, $mobile_number, $data_coverage, $approved_plan_display, $current_charges_display, $final_dm_val);
        return [
            'subject' => $template['subject'],
            'body'    => $template['body']
        ];
    }
    throw new Exception("Critical Error: getEmailTemplate() function missing. Aborting operation.");
}

function checkSharedDriveSOA($conn, $account_number, $dm_id, $start_date = '', $end_date = '') {
    if (empty($dm_id) || $dm_id == 0) return false;
    
    $soa_query = "SELECT COUNT(*) as cnt 
                  FROM debit_memo_items dmi 
                  JOIN pdf_extracted_details pdf ON pdf.account_number = ? 
                  WHERE dmi.dm_id = ?";
    $soa_params = [$account_number, $dm_id];
    
    if (!empty($start_date) && !empty($end_date)) {
        $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
        array_push($soa_params, $start_date, $end_date);
    }

    $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                    AND (
                        pdf.mobile_number IS NULL 
                        OR pdf.mobile_number = '' 
                        OR pdf.mobile_number = 'N/A' 
                        OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                    )";

    $stmt = $conn->prepare($soa_query);
    $stmt->execute($soa_params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return ($res && $res['cnt'] > 0);
}

function checkDataCoverageExists($conn, $dm_id, $start_date = '', $end_date = '') {
    if (empty($dm_id) || $dm_id == 0) return false;
    
    $query = "SELECT COUNT(*) as cnt FROM debit_memo_items WHERE dm_id = ?";
    $params = [$dm_id];
    
    if (!empty($start_date) && !empty($end_date)) {
        $query .= " AND coverage_start >= ? AND coverage_end <= ?";
        array_push($params, $start_date, $end_date);
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return ($res && $res['cnt'] > 0);
}

function calculateAccountSummaryMetrics($conn, $dm_id, $start_date = '', $end_date = '') {
    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $itemParams = [$dm_id];

    if (!empty($start_date) && !empty($end_date)) {
        $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
        $itemParams[] = $start_date;
        $itemParams[] = $end_date;
    }
    $itemQuery .= " ORDER BY coverage_start ASC";

    $stmtItems = $conn->prepare($itemQuery);
    $stmtItems->execute($itemParams);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $total_final_dm = 0.00;
    $total_current_charges = 0.00;
    $total_approved_plan = 0.00;
    $raw_start_dates = [];
    $raw_end_dates = [];

    foreach ($items as $it) {
        $curr_charge_v = isset($it['current_charges']) ? (float)$it['current_charges'] : 0.00;
        $total_current_charges += $curr_charge_v;

        $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
        $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
        $final_dm_val = isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
        $total_final_dm += $final_dm_val;

        if (isset($it['approved_plan'])) {
            $total_approved_plan += (float)$it['approved_plan'];
        }

        $c_start = $it['coverage_start'] ?? '';
        $c_end = $it['coverage_end'] ?? '';

        if (!empty($c_start) && !empty($c_end)) {
            $raw_start_dates[] = $c_start;
            $raw_end_dates[] = $c_end;
        }
    }

    if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
        $data_coverage = date('M d, Y', strtotime(min($raw_start_dates))) . " to " . date('M d, Y', strtotime(max($raw_end_dates)));
    } else {
        $data_coverage = "As of current billing";
    }

    $approved_plan_display = ($total_approved_plan > 0) ? "₱ " . number_format($total_approved_plan, 2, '.', ',') : "₱ 0.00";
    $current_charges_display = "₱ " . number_format($total_current_charges, 2, '.', ',');
    $total_final_dm_display = number_format($total_final_dm, 2, '.', ',');

    return [
        'data_coverage' => $data_coverage,
        'approved_plan_display' => $approved_plan_display,
        'current_charges_display' => $current_charges_display,
        'total_final_dm' => $total_final_dm_display
    ];
}
?>