<?php
session_start();

// Ensure data exists in session
if (!isset($_SESSION['import_result'])) {
    exit("No import data available for download.");
}

$res = $_SESSION['import_result'];

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="full_report_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');

// Add Full CSV Header
fputcsv($output, [
    'Row', 'Company', 'Assignee', 'Account Number', 'Mobile Number', 'Approved', 'Phone Amt', 
    'Debit Adj', 'Credit Adj', 'Other Charges', 'Local', 'NDD', 'IDD', 'Roam', 'SMS', 
    'GPRS', 'Wiz Usage', 'Loading C', 'VAT', 'OCT', 'Current Ch', 'Total Amt', 
    'Debit Memo', 'Coverage Start', 'Coverage End', 'Telco', 'Status', 'Remarks'
]);

// Iterate through details
foreach ($res['details'] as $row) {
    $orig = $row['original_row'];
    
    fputcsv($output, [
        $row['row'],
        isset($orig[1])  ? $orig[1]  : '', // Company
        isset($orig[2])  ? $orig[2]  : '', // Assignee
        isset($orig[3])  ? $orig[3]  : '', // Account
        isset($orig[4])  ? $orig[4]  : '', // Mobile
        isset($orig[5])  ? $orig[5]  : '', // Approved
        isset($orig[6])  ? $orig[6]  : '', // Phone Amt
        isset($orig[7])  ? $orig[7]  : '', // Debit Adj
        isset($orig[8])  ? $orig[8]  : '', // Credit Adj
        isset($orig[9])  ? $orig[9]  : '', // Other
        isset($orig[10]) ? $orig[10] : '', // Local
        isset($orig[11]) ? $orig[11] : '', // NDD
        isset($orig[12]) ? $orig[12] : '', // IDD
        isset($orig[13]) ? $orig[13] : '', // Roam
        isset($orig[14]) ? $orig[14] : '', // SMS
        isset($orig[15]) ? $orig[15] : '', // GPRS
        isset($orig[16]) ? $orig[16] : '', // Wiz
        isset($orig[17]) ? $orig[17] : '', // Loading
        isset($orig[18]) ? $orig[18] : '', // VAT
        isset($orig[19]) ? $orig[19] : '', // OCT
        isset($orig[20]) ? $orig[20] : '', // Current Ch
        isset($orig[21]) ? $orig[21] : '', // Total Amt
        isset($orig[22]) ? $orig[22] : '', // Debit Memo
        isset($orig[23]) ? $orig[23] : '', // Coverage Start
        isset($orig[24]) ? $orig[24] : '', // Coverage End
        isset($orig[25]) ? $orig[25] : '', // Telco
        $row['status'],
        $row['remarks'],
      
    ]);
}

fclose($output);
exit;