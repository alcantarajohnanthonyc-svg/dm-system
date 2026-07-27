<?php
if (ob_get_level()) ob_end_clean();
set_time_limit(600);
ini_set('memory_limit', '256M');
ini_set('display_errors', 0);

require_once 'config.php';

$type       = isset($_GET['type']) ? trim($_GET['type']) : 'pdf';
$ids_raw    = isset($_GET['ids']) ? $_GET['ids'] : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$ids = explode(',', $ids_raw);
if (empty($ids_raw)) {
    die("Debug: No data received.");
}

$zip = new ZipArchive();
$zip_filename = "Bulk_Export_" . strtoupper($type) . "_" . date('Y-m-d_His') . ".zip";
$zip_path = tempnam(sys_get_temp_dir(), 'zip');

if ($zip->open($zip_path, ZipArchive::OVERWRITE) !== TRUE) {
    die("Cannot create zip file");
}

$temp_files = [];

if ($type === 'excel') {
    foreach ($ids as $dm_id) {
        $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
        $stmt->execute(array(':dm_id' => $dm_id));
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
        $company = isset($info['company']) ? $info['company'] : 'N/A';

        $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
        $params = array(':dm_id' => $dm_id);

        if (!empty($start_date) || !empty($end_date)) {
            $effective_start = !empty($start_date) ? $start_date : '1900-01-01';
            $effective_end   = !empty($end_date) ? $end_date : '2999-12-31';

            $sql .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date 
                           AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date)";
            
            $params[':start_date'] = $effective_start;
            $params[':end_date']   = $effective_end;
        }

        $stmtItems = $conn->prepare($sql);
        $stmtItems->execute($params);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $htmlContent = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $htmlContent .= '<head><meta charset="UTF-8"></head>';
        $htmlContent .= '<body>';

        $htmlContent .= '<table border="0">';
        $htmlContent .= '<tr><td colspan="3" style="font-weight: bold; font-size: 14pt;">ACCOUNT NUMBER: ' . htmlspecialchars($acc_num) . '</td></tr>';
        $htmlContent .= '<tr><td colspan="3" style="font-weight: bold; font-size: 12pt;">COMPANY: ' . htmlspecialchars($company) . '</td></tr>';
        $htmlContent .= '<tr><td colspan="3"></td></tr>';
        $htmlContent .= '</table>';

        $htmlContent .= '<table border="1" style="border-collapse: collapse; font-size: 10pt; font-family: Arial, sans-serif;">';
        
        $headers = array(
            "COVERAGE DATE", "MOBILE NUMBER", "APPROVED PLAN", "PHONE AMORTIZATION", 
            "DEBIT ADJ", "CREDIT ADJ", "OTHER CHARGES (PRE-TERM)", "LOCAL (CALL/TEXT)", 
            "NDD (NATIONAL)", "IDD (INTERNATIONAL)", "ROAM", "SMS", "GPRS", 
            "WIZ USAGE", "LOADING CHARGES", "VAT", "OCT", "CURRENT CHARGES", 
            "TOTAL AMOUNT DUE", "DEBIT MEMO"
        );

        $htmlContent .= '<tr style="font-weight: bold; text-align: center;">';
        foreach($headers as $index => $col) {
            $bgColor = '#93c5fd'; 
            if ($index == 0) $bgColor = '#fde047'; 
            elseif ($index == 1 || $index == 19) $bgColor = '#4ade80'; 

            $htmlContent .= '<th style="background-color: ' . $bgColor . '; border: 1px solid #000000; padding: 6px;">' . htmlspecialchars($col) . '</th>';
        }
        $htmlContent .= '</tr>';

        foreach ($items as $row) {
            $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
            $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
            $dateText = $start . ' to ' . $end;

            $htmlContent .= '<tr>';
            $htmlContent .= '<td style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($dateText) . '</td>';
            $htmlContent .= '<td style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($row['mobile_number']) . '</td>';
            
            $numericFields = array(
                'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
                'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
                'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
                'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 
                'debit_memo_details'
            );

            foreach ($numericFields as $field) {
                $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
                $style = 'text-align: right; border: 1px solid #000000; mso-number-format:"#,##0.00";';
                if ($field === 'debit_memo_details') {
                    $style .= ' color: #dc2626; font-weight: bold;';
                }
                $htmlContent .= '<td style="' . $style . '">' . number_format($val, 2) . '</td>';
            }
            $htmlContent .= '</tr>';
        }
        
        $htmlContent .= '</table>';
        $htmlContent .= '</body></html>';

        $temp_excel = tempnam(sys_get_temp_dir(), 'excel_export');
        file_put_contents($temp_excel, $htmlContent);
        $temp_files[] = $temp_excel;

        $zip->addFile($temp_excel, "Account_" . $acc_num . ".xls");
    }
} else {
    require('fpdf/fpdf.php');
    require_once 'pdf_generator.php';

    foreach ($ids as $dm_id) {
        $result = createDebitMemoPDF($dm_id, $conn, null, $start_date, $end_date);
        
        if (is_array($result) && count($result) == 2) {
            $pdf = $result[0];
            $acc_num = $result[1];
            
            $temp_pdf = tempnam(sys_get_temp_dir(), 'pdf_export');
            $pdf->Output('F', $temp_pdf);
            $temp_files[] = $temp_pdf;

            $zip->addFile($temp_pdf, "Account_" . $acc_num . ".pdf");
            unset($pdf); 
        }
    }
}

$zip->close(); 

foreach ($temp_files as $file) {
    if (file_exists($file)) unlink($file);
}

if (file_exists($zip_path)) {
    setcookie("fileDownloadToken", "success", time() + 60, "/");

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zip_filename.'"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    unlink($zip_path);
}
exit;
?>