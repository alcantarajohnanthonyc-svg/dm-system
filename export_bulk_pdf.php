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
    // Same workbook as the single export: one tab per year, legacy columns for
    // 2025 & backward and the new template columns for 2026 & forward.
    require_once 'excel_generator.php';

    foreach ($ids as $dm_id) {
        $res = buildDebitMemoExcelXml(intval($dm_id), $conn, null, $start_date, $end_date, null);
        $zip->addFromString("Account_" . $res['acc_num'] . ".xls", $res['xml']);
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