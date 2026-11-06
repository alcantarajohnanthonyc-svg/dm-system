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

// Excel uses the shared workbook builder
if ($export_type === 'excel') {
    require_once 'excel_generator.php';
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
            // Same workbook as the single export: one tab per year, old columns for 2025 & backward,
            // new template columns for 2026 & forward. 'overlap' keeps this feature's original date matching.
            $res = buildDebitMemoExcelXml($dm_id, $conn, null, $s_date, $e_date, null, 'overlap');

            // Skip file generation if no items match the specific date breakdown
            if (empty($res['item_count'])) {
                continue;
            }

            $zip->addFromString("Account_" . $res['acc_num'] . '.xls', $res['xml']);

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