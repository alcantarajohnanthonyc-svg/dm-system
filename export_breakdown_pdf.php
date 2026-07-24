<?php
session_start();
// Strict error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure no output is sent prematurely
if (ob_get_level()) ob_end_clean();

require_once 'config.php';

$type         = isset($_GET['type']) ? trim($_GET['type']) : 'pdf';
$dm_id        = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
$item_ids_str = isset($_GET['item_ids']) ? trim($_GET['item_ids']) : '';
$start_date   = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date     = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$item_ids = !empty($item_ids_str) ? explode(',', $item_ids_str) : array();

if ($dm_id <= 0) {
    die("Invalid request.");
}

if ($type === 'excel') {
    // --- EXCEL GENERATION LOGIC ---
    require_once 'excel_generator.php';
    createDebitMemoExcel($dm_id, $conn, !empty($item_ids) ? $item_ids : null, $start_date, $end_date);
    exit;

} else {
    // --- PDF GENERATION LOGIC ---
    require('fpdf/fpdf.php');
    require_once 'pdf_generator.php';

    $result = createDebitMemoPDF($dm_id, $conn, !empty($item_ids) ? $item_ids : null, $start_date, $end_date); 
    
    if (is_array($result) && count($result) >= 2) {
        $pdf = $result[0]; 
        $acc_num = $result[1];
        
        // Output to browser for direct download
        $pdf->Output('D', 'Account_' . $acc_num . '.pdf');
    } else {
        echo "Error generating PDF layout.";
    }
    exit;
}
?>