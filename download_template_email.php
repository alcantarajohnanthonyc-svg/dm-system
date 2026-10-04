<?php
// download_template.php

// Itakda ang header para ma-download bilang CSV file ang output
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=email_import_template.csv');

// Buksan ang output stream para sa pag-write ng CSV
$output = fopen('php://output', 'w');

// I-set ang eksaktong headers batay sa iyong listahan
fputcsv($output, array(
    'account number', 
    'start date', 
    'end date', 
    'to recipient', 
    'cc', 
    'subject', 
    'email body', 
    'attached file local link'
));

// Maglagay ng sample data (account number lang ang required, opsyonal ang iba)
fputcsv($output, array(
    'ACC-10001', 
    '2026-10-01', 
    '2026-10-31', 
    'client@example.com', 
    'cc@example.com', 
    'Follow-up Notice', 
    'Please see your account details.', 
    'uploads/sample.pdf'
));

fclose($output);
exit();
?>