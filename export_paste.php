<?php
// Strict error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure no output is sent prematurely
if (ob_get_level()) ob_end_clean();

require_once 'config.php';

$raw_input   = isset($_POST['accounts']) ? $_POST['accounts'] : '';
$globalStart = (!empty($_POST['startDate'])) ? $_POST['startDate'] : null;
$globalEnd   = (!empty($_POST['endDate'])) ? $_POST['endDate'] : null;
$export_type = isset($_POST['export_type']) ? trim($_POST['export_type']) : 'pdf';

if (empty($raw_input)) {
    die("No data received.");
}

$lines = array_filter(array_map('trim', explode("\n", $raw_input)));
$zip = new ZipArchive();
$zip_path = tempnam(sys_get_temp_dir(), 'zip');
$temp_files = array(); 

if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Cannot create zip file.");
}

// Load PDF dependencies only if needed
if ($export_type === 'pdf') {
    require('fpdf/fpdf.php');
    require_once 'pdf_generator.php';
}

foreach ($lines as $line) {
    if (empty($line)) continue;

    // Split line by spaces/tabs to detect account and potential date overrides
    $parts = preg_split('/\s+/', $line);
    $identifier = $parts[0];
    
    $s_date = null;
    $e_date = null;

    if (count($parts) >= 3) {
        $s_date = date('Y-m-d', strtotime($parts[1]));
        
        // Robust handling for invalid end-of-month dates (e.g. 2025-04-31 rolls into May if using standard strtotime)
        $end_raw = $parts[2];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $end_raw, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            $last_day = (int)date('t', strtotime("$y-$mo-01"));
            if ($d > $last_day) {
                $d = $last_day;
            }
            $e_date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        } else {
            $parsed_end = strtotime($end_raw);
            $e_date = ($parsed_end === false) ? null : date('Y-m-d', $parsed_end);
        }
    } elseif (count($parts) == 2) {
        $s_date = date('Y-m-d', strtotime($parts[1]));
        $e_date = null;
    } else {
        $s_date = $globalStart;
        $e_date = $globalEnd;
    }

    $sql = "SELECT DISTINCT dmi.dm_id FROM debit_memo_items dmi 
            JOIN debit_memos dm ON dmi.dm_id = dm.dm_id 
            WHERE (dm.account_number = ? OR dmi.mobile_number = ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute(array($identifier, $identifier));
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $dm_id) {
        if ($export_type === 'excel') {
            // --- EXCEL GENERATION LOGIC ---
            $stmtInfo = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
            $stmtInfo->execute(array(':dm_id' => $dm_id));
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            
            $acc_num = isset($info['account_number']) ? $info['account_number'] : $identifier;
            $company = isset($info['company']) ? $info['company'] : 'N/A';

            // Base item query
            $sqlItems = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
            $params = array(':dm_id' => $dm_id);

            // Date breakdown filter implementation for Excel
            if (!empty($s_date) && !empty($e_date)) {
                $sqlItems .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date 
                                   AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date)";
                $params[':start_date'] = $s_date;
                $params[':end_date']   = $e_date;
            } elseif (!empty($s_date)) {
                $sqlItems .= " AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date";
                $params[':start_date'] = $s_date;
            } elseif (!empty($e_date)) {
                $sqlItems .= " AND STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date";
                $params[':end_date']   = $e_date;
            }

            $stmtItems = $conn->prepare($sqlItems);
            $stmtItems->execute($params);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Skip file generation if no items match the specific date breakdown
            if (empty($items)) {
                continue;
            }

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

                $htmlContent .= '<th style="background-color: ' . $bgColor . '; border: 1px solid #000000; padding: 6px; text-align: center;">' . htmlspecialchars($col) . '</th>';
            }
            $htmlContent .= '</tr>';

            foreach ($items as $row) {
                $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
                $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
                $dateText = $start . ' to ' . $end;

                $htmlContent .= '<tr>';
                $htmlContent .= '<td align="center" style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($dateText) . '</td>';
                $htmlContent .= '<td align="center" style="text-align: center; border: 1px solid #000000; mso-number-format:\@;">' . htmlspecialchars($row['mobile_number']) . '</td>';
                
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
                    $htmlContent .= '<td align="right" style="' . $style . '">' . number_format($val, 2) . '</td>';
                }
                $htmlContent .= '</tr>';
            }
            
            $htmlContent .= '</table>';
            $htmlContent .= '</body></html>';

            $tmp = tempnam(sys_get_temp_dir(), 'excel');
            file_put_contents($tmp, $htmlContent);
            
            if (file_exists($tmp) && filesize($tmp) > 100) {
                $zip->addFile($tmp, "Account_" . $acc_num . '.xls');
                $temp_files[] = $tmp;
            }

        } else {
            // --- PDF GENERATION LOGIC ---
            $res = createDebitMemoPDF($dm_id, $conn, null, $s_date, $e_date);
            
            if (is_array($res) && isset($res[0])) {
                $pdf_obj = $res[0];
                $acc_num = isset($res[1]) ? $res[1] : $identifier;
                
                $tmp = tempnam(sys_get_temp_dir(), 'pdf');
                $pdf_obj->Output('F', $tmp);
                
                if (file_exists($tmp) && filesize($tmp) > 100) {
                    $zip->addFile($tmp, "Account_" . $acc_num . '.pdf');
                    $temp_files[] = $tmp;
                }
            }
        }
    }
}
$zip->close();

// FINAL CLEANUP AND DOWNLOAD
if (file_exists($zip_path) && filesize($zip_path) > 0) {

setcookie("fileDownloadToken", "success", time() + 60, "/");


    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Export_' . strtoupper($export_type) . '_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($zip_path));
    header('Pragma: no-cache');
    
    if (ob_get_level()) ob_end_clean();
    
    readfile($zip_path);

    // Cleanup
    unlink($zip_path);
    foreach ($temp_files as $f) {
        if (file_exists($f)) unlink($f);
    }
    exit;
} else {
    die("No records generated.");
}
?>