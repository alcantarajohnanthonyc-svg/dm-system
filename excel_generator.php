<?php
/**
 * Generates and downloads a Debit Memo Excel spreadsheet.
 * @param int $dm_id The ID of the debit memo
 * @param PDO $conn The database connection
 * @param array|null $item_ids Optional array of specific item IDs
 * @param string|null $startDate 'YYYY-MM-DD' format
 * @param string|null $endDate 'YYYY-MM-DD' format
 */
function createDebitMemoExcel($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null) {
    // 1. Fetch info
    $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
    $stmt->execute(array(':dm_id' => $dm_id));
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. Build Query and Data Fetching
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = array(':dm_id' => $dm_id);

    if (!empty($item_ids)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $item_ids)) . ")";
    }

    if (!empty($startDate) || !empty($endDate)) {
        $effective_start = !empty($startDate) ? $startDate : '1900-01-01';
        $effective_end   = !empty($endDate) ? $endDate : '2999-12-31';

        $sql .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date 
                       AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date)";
        
        $params[':start_date'] = $effective_start;
        $params[':end_date']   = $effective_end;
    }

    $stmtItems = $conn->prepare($sql);
    $stmtItems->execute($params);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 3. Set Headers for Excel Download
   // $filename = 'Account_' . $acc_num . '_' . date('Ymd_His') . '.xls';
   $filename = 'Account_' . $acc_num . '.xls'; 
   header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 4. Output Spreadsheet HTML Structure
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';

    // Metadata Section
    echo '<table border="0">';
    echo '<tr><td colspan="3" style="font-weight: bold; font-size: 14pt;">ACCOUNT NUMBER: ' . htmlspecialchars($acc_num) . '</td></tr>';
    echo '<tr><td colspan="3" style="font-weight: bold; font-size: 12pt;">COMPANY: ' . htmlspecialchars($company) . '</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';
    echo '</table>';

    // Data Table
    echo '<table border="1" style="border-collapse: collapse; font-size: 10pt; font-family: Arial, sans-serif;">';
    
    $headers = array(
        "COVERAGE DATE", "MOBILE NUMBER", "APPROVED PLAN", "PHONE AMORTIZATION", 
        "DEBIT ADJ", "CREDIT ADJ", "OTHER CHARGES (PRE-TERM)", "LOCAL (CALL/TEXT)", 
        "NDD (NATIONAL)", "IDD (INTERNATIONAL)", "ROAM", "SMS", "GPRS", 
        "WIZ USAGE", "LOADING CHARGES", "VAT", "OCT", "CURRENT CHARGES", 
        "TOTAL AMOUNT DUE", "DEBIT MEMO"
    );

    echo '<tr style="font-weight: bold; text-align: center;">';
    foreach($headers as $index => $col) {
        $bgColor = '#93c5fd'; 
        if ($index == 0) $bgColor = '#fde047'; 
        elseif ($index == 1 || $index == 19) $bgColor = '#4ade80'; 

        echo '<th style="background-color: ' . $bgColor . '; border: 1px solid #000000; padding: 6px; text-align: center;">' . htmlspecialchars($col) . '</th>';
    }
    echo '</tr>';

    // Data Rows Loop
    foreach ($items as $row) {
        $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
        $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
        $dateText = $start . ' to ' . $end;

        echo '<tr>';
        echo '<td align="center" style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($dateText) . '</td>';
        echo '<td align="center" style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($row['mobile_number']) . '</td>';
        
        $numericFields = array(
            'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
            'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
            'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
            'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 
            'debit_memo_details'
        );

       foreach ($numericFields as $field) {
            $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
            // Removed mso-number-format:\@ so Excel recognizes them as actual numbers
            $style = 'text-align: center; border: 1px solid #000000;';
            if ($field === 'debit_memo_details') {
                $style .= ' color: #dc2626; font-weight: bold;';
            }
            $formattedVal = number_format($val, 2, '.', '');
            echo '<td align="center" style="' . $style . '">' . htmlspecialchars($formattedVal) . '</td>';
        }
        echo '</tr>';
    }

    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}
?>