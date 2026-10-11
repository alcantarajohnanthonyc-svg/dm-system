<?php
$filename = "Debit_Memo_Import_Template.csv";

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");

$headers = [
    'No.', 
    'Company', 
    'Assignee Name', 
    'Account Number', 
    'Mobile Number', 
    'Approved Plan', 
    'Phone Amortization', 
    'Debit Adj', 
    'Credit Adj', 
    'Other Charges (Pre termination fee)', 
    'Local (Call/Text to other networks)', 
    'NDD (National)', 
    'IDD (International)', 
    'Roam', 
    'SMS', 
    'GPRS', 
    'Wiz Usage', 
    'Loading Charges', 
    'VAT', 
    'OCT', 
    'Current Charges', 
    'Total Amount Due', 
    'Debit Memo', 
    'Add Ons',            // <-- Idinagdag ang Add Ons dito
    'coverage_start', 
    'coverage_end',
    'telco'
];

$output = fopen('php://output', 'w');

// Write the header row
fputcsv($output, $headers);

// Add an example row (dinagdagan din ng '0.00' para sa Add Ons)
$example_row = [
    '1',                                        // No.
    'Company Name',                           // Company
    'Juan Dela Cruz',                         // Assignee Name
    '="1234567890"',                           // Account Number
    '="09171234567"',                           // Mobile Number
    '999',                                      // Approved Plan
    '0.00', '0.00', '0.00', '0.00',          // Amort, D_Adj, C_Adj, Other
    '0.00', '0.00', '0.00', '0.00', '0.00', // Local, NDD, IDD, Roam, SMS
    '0.00', '0.00', '0.00', '0.00', '0.00', // GPRS, Wiz, Load, VAT, OCT
    '0.00', '0.00', '0.00',                     // Current, Total, Debit Memo
    '0.00',                                     // Add Ons (Sample Value)
    '2026-05-01',                               // coverage_start
    '2026-05-31',                               // coverage_end
    'Smart Communications Inc.'                 // telco
];

fputcsv($output, $example_row);

fclose($output);
exit;
?>