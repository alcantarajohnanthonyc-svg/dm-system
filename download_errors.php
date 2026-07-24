<?php
session_start();

if (!isset($_SESSION['import_result'])) {
    exit("No import data available for download.");
}

$res = $_SESSION['import_result'];

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="error_report_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');

// Full Header Row
fputcsv($output, [
    'Row', 'Company', 'Assignee', 'Account Number', 'Mobile Number', 'Approved', 'Phone Amt', 
    'Debit Adj', 'Credit Adj', 'Other Charges', 'Local', 'NDD', 'IDD', 'Roam', 'SMS', 
    'GPRS', 'Wiz Usage', 'Loading C', 'VAT', 'OCT', 'Current Ch', 'Total Amt', 
    'Debit Memo', 'Coverage Start', 'Coverage End', 'Telco', 'Status', 'Remarks'
]);

// Iterate and filter for failures
foreach ($res['details'] as $row) {
    if ($row['status'] === 'FAILED') {
        // Build the row data. 
        // Note: Replace index numbers (e.g., [1]) with the correct index 
        // from your $row['original_row'] array based on your CSV structure.
        fputcsv($output, [
            $row['row'],
            isset($row['original_row'][1])  ? $row['original_row'][1]  : '', // Company
            isset($row['original_row'][2])  ? $row['original_row'][2]  : '', // Assignee
            isset($row['original_row'][3])  ? $row['original_row'][3]  : '', // Account
            isset($row['original_row'][4])  ? $row['original_row'][4]  : '', // Mobile
            isset($row['original_row'][5])  ? $row['original_row'][5]  : '', // Approved
            isset($row['original_row'][6])  ? $row['original_row'][6]  : '', // Phone Amt
            isset($row['original_row'][7])  ? $row['original_row'][7]  : '', // Debit Adj
            isset($row['original_row'][8])  ? $row['original_row'][8]  : '', // Credit Adj
            isset($row['original_row'][9])  ? $row['original_row'][9]  : '', // Other
            isset($row['original_row'][10]) ? $row['original_row'][10] : '', // Local
            isset($row['original_row'][11]) ? $row['original_row'][11] : '', // NDD
            isset($row['original_row'][12]) ? $row['original_row'][12] : '', // IDD
            isset($row['original_row'][13]) ? $row['original_row'][13] : '', // Roam
            isset($row['original_row'][14]) ? $row['original_row'][14] : '', // SMS
            isset($row['original_row'][15]) ? $row['original_row'][15] : '', // GPRS
            isset($row['original_row'][16]) ? $row['original_row'][16] : '', // Wiz
            isset($row['original_row'][17]) ? $row['original_row'][17] : '', // Loading
            isset($row['original_row'][18]) ? $row['original_row'][18] : '', // VAT
            isset($row['original_row'][19]) ? $row['original_row'][19] : '', // OCT
            isset($row['original_row'][20]) ? $row['original_row'][20] : '', // Current Ch
            isset($row['original_row'][21]) ? $row['original_row'][21] : '', // Total Amt
            isset($row['original_row'][22]) ? $row['original_row'][22] : '', // Debit Memo
            isset($row['original_row'][23]) ? $row['original_row'][23] : '', // Coverage Start
            isset($row['original_row'][24]) ? $row['original_row'][24] : '', // Coverage End
            isset($row['original_row'][25]) ? $row['original_row'][25] : '', // Telco
            $row['status'],
            $row['remarks'],
           
        ]);
    }
}

fclose($output);
exit;