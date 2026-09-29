<?php
// Enable error reporting for debugging during tests
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$db   = 'admin_dm';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Retrieve selected debit memo IDs from POST request
$dm_ids_input = isset($_POST['dm_ids']) ? $_POST['dm_ids'] : [];

if (is_string($dm_ids_input)) {
    $dm_ids = array_filter(explode(',', $dm_ids_input));
} else {
    $dm_ids = $dm_ids_input;
}

if (empty($dm_ids) || !is_array($dm_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No debit memos selected for email dispatch.']);
    exit;
}

$success_count = 0;
$fail_count = 0;
$failed_items = [];

foreach ($dm_ids as $dm_id) {
    $dm_id = trim($dm_id);
    
    // 1. Fetch debit memo record to get account_number and dm_number
    $stmt = $pdo->prepare("SELECT * FROM debit_memos WHERE dm_id = ?");
    $stmt->execute([$dm_id]);
    $dm = $stmt->fetch();

    if (!$dm) {
        $fail_count++;
        $failed_items[] = "ID {$dm_id}: Debit memo record not found.";
        continue;
    }

    $account_number = $dm['account_number'];

    // 2. Pull recipient email from 'account_emails' table using account_number
    $email_stmt = $pdo->prepare("SELECT * FROM account_emails WHERE account_number = ?");
    $email_stmt->execute([$account_number]);
    $account_email_row = $email_stmt->fetch();

    if (!$account_email_row || empty($account_email_row['email_address'])) {
        $fail_count++;
        $error_msg = "Account Number {$account_number} (DM ID: {$dm_id}) has no email mapping in account_emails table.";
        $failed_items[] = $error_msg;
        error_log($error_msg);
        continue;
    }

    $recipient_email = $account_email_row['email_address'];
    $recipient_name  = !empty($account_email_row['full_name']) ? $account_email_row['full_name'] : 'Valued Client';

    // 3. Generate Excel attachment safely for bulk emails
    $excel_path = generate_dm_excel($dm_id, $pdo);
    
    if (empty($excel_path) || !file_exists($excel_path)) {
        $fail_count++;
        $failed_items[] = "ID {$dm_id}: Failed to generate Excel attachment.";
        continue;
    }

    $filename = 'debit_memo_' . $dm_id . '.xls';
    $html_content = "<h3>Statement of Account / Debit Memo</h3><p>Dear {$recipient_name},</p><p>Attached is your debit memo statement spreadsheet for account number <b>{$account_number}</b> for your review.</p>";

    // 4. Send email using SMTP parameters with Excel attachment
    $subject = "Statement of Account / Debit Memo - " . ($dm['dm_number'] ?? $dm_id);
    $mail_sent = send_smtp_mail_with_html_body($recipient_email, $subject, $excel_path, $filename, $html_content, 'application/vnd.ms-excel');

    if ($mail_sent === true) {
        $success_count++;

        // 5. Record successful send into 'email_report' table
        $report_stmt = $pdo->prepare("INSERT INTO email_report (report_type, recipient_email, full_name, created_at) VALUES (?, ?, ?, NOW())");
        $report_stmt->execute(['statement_dispatch', $recipient_email, $recipient_name]);
    } else {
        $fail_count++;
        $failed_items[] = "ID {$dm_id} ({$recipient_email}): SMTP dispatch failed. Details: " . $mail_sent;
    }

    // Clean up temporary Excel file
    if (!empty($excel_path) && file_exists($excel_path)) {
        @unlink($excel_path);
    }
}

echo json_encode([
    'status' => 'success',
    'message' => "Bulk dispatch completed. Successful: {$success_count}, Failed: {$fail_count}.",
    'success_count' => $success_count,
    'fail_count' => $fail_count,
    'failed_details' => $failed_items
]);

// --- Helper Functions ---

/**
 * Generates an Excel file for the debit memo safely without triggering headers or exit,
 * ensuring 100% format consistency with the manual export generator.
 */
function generate_dm_excel($dm_id, $pdo) {
    $excel_path = sys_get_temp_dir() . '/debit_memo_' . $dm_id . '_' . uniqid() . '.xls';

    // 1. Fetch info
    $stmt = $pdo->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = ?");
    $stmt->execute([$dm_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. Fetch items
    $stmtItems = $pdo->prepare("SELECT * FROM debit_memo_items WHERE dm_id = ? ORDER BY coverage_start ASC");
    $stmtItems->execute([$dm_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Group items by year
    $grouped_by_year = [];
    foreach ($items as $row) {
        $year = !empty($row['coverage_start']) ? date('Y', strtotime($row['coverage_start'])) : 'Unknown';
        $grouped_by_year[$year][] = $row;
    }
    if (empty($grouped_by_year)) {
        $grouped_by_year[date('Y')] = [];
    }

    // 3. Build the Excel XML content string
    $output = '<?xml version="1.0" encoding="UTF-8"?>';
    $output .= '<?mso-application progid="Excel.Sheet"?>';
    $output .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    $output .= ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    $output .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    $output .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    $output .= ' xmlns:html="http://www.w3.org/TR/REC-html40">';

    // Styles definitions
    $output .= '<Styles>';
    $output .= '<Style ss:ID="DataCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
    $output .= '<Style ss:ID="RedCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#dc2626"/></Style>';
    $output .= '<Style ss:ID="BlueCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#2563eb"/></Style>';
    $output .= '<Style ss:ID="PurpleCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#7c3aed"/></Style>';
    $output .= '<Style ss:ID="OrangeCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#ea580c"/></Style>';
    $output .= '<Style ss:ID="GreenCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#16a34a"/></Style>';

    $unique_colors = ["#FFE599", "#93C47D", "#4A86E8", "#F7F700"];
    foreach ($unique_colors as $color) {
        $style_id = 'Header_' . md5($color);
        $output .= '<Style ss:ID="' . $style_id . '">';
        $output .= '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>';
        $output .= '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>';
        $output .= '<Interior ss:Color="' . htmlspecialchars($color) . '" ss:Pattern="Solid"/><Font ss:Bold="1"/>';
        $output .= '</Style>';
    }
    $output .= '</Styles>';

    // Worksheets per year
    foreach ($grouped_by_year as $year => $year_items) {
        $output .= '<Worksheet ss:Name="Year ' . htmlspecialchars($year) . '"><Table>';
        
        $colWidths = [150, 130, 110, 130, 100, 100, 180, 130, 130, 140, 90, 80, 80, 90, 120, 80, 80, 130, 130, 100, 140, 140, 120, 120];
        foreach ($colWidths as $w) {
            $output .= '<Column ss:Width="' . $w . '"/>';
        }

        $output .= '<Row><Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">ACCOUNT NUMBER: ' . htmlspecialchars($acc_num) . '</Data></Cell></Row>';
        $output .= '<Row><Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">COMPANY: ' . htmlspecialchars($company) . '</Data></Cell></Row>';
        $output .= '<Row></Row>';

        $headers = [
            "COVERAGE DATE" => "#FFE599", "MOBILE NUMBER" => "#93C47D", "APPROVED PLAN" => "#93C47D",
            "MSF (GLOBE / MRC (SMART)" => "#4A86E8", "DEBIT ADJ" => "#93C47D", "CREDIT ADJ" => "#93C47D",
            "OTHER CHARGES / PHONE AMORTIZATION" => "#4A86E8", "LOCAL (CALL/TEXT)" => "#4A86E8",
            "NDD (NATIONAL)" => "#4A86E8", "IDD (INTERNATIONAL)" => "#4A86E8", "ROAM" => "#4A86E8",
            "SMS" => "#4A86E8", "GPRS" => "#4A86E8", "WIZ USAGE" => "#4A86E8", "LOADING CHARGES" => "#4A86E8",
            "VAT" => "#4A86E8", "OCT" => "#4A86E8", "CURRENT CHARGES" => "#4A86E8", "TOTAL AMOUNT DUE" => "#4A86E8",
            "PROCESSED DM" => "#93C47D", "SYSTEM GENERATED DM" => "#93C47D",
            "DIFFERENCE\n(PROCESSED DM - SYSTEM GENERATED DM)" => "#F7F700",
            "ADD ONS" => "#93C47D", "FINAL DM\n(PROCESSED DM - ADD ONS)" => "#F7F700"
        ];

        $output .= '<Row>';
        foreach($headers as $colTitle => $colorCode) {
            $styleID = 'Header_' . md5($colorCode);
            $finalTitle = implode('&#10;', array_map('htmlspecialchars', explode("\n", $colTitle)));
            $output .= '<Cell ss:StyleID="' . $styleID . '"><Data ss:Type="String">' . $finalTitle . '</Data></Cell>';
        }
        $output .= '</Row>';

        foreach ($year_items as $row) {
            $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
            $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
            $dateText = $start . ' to ' . $end;

            $output .= '<Row>';
            $output .= '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($dateText) . '</Data></Cell>';
            $output .= '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($row['mobile_number']) . '</Data></Cell>';

            $numericFields = [
                'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
                'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
                'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
                'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 
                'debit_memo_details'
            ];

            foreach ($numericFields as $field) {
                $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
                $cellStyle = ($field === 'debit_memo_details') ? 'RedCell' : 'DataCell';
                $output .= '<Cell ss:StyleID="' . $cellStyle . '"><Data ss:Type="Number">' . number_format($val, 2, '.', '') . '</Data></Cell>';
            }

            $approved_plan_val = isset($row['approved_plan']) ? (float)$row['approved_plan'] : 0.00;
            $msf_mrc_val = isset($row['current_charges']) ? (float)$row['current_charges'] : 0.00;
            $dm_val = isset($row['debit_memo_details']) ? (float)$row['debit_memo_details'] : 0.00;

            $col1_val = max(0, $msf_mrc_val - $approved_plan_val);
            $output .= '<Cell ss:StyleID="BlueCell"><Data ss:Type="Number">' . number_format($col1_val, 2, '.', '') . '</Data></Cell>';

            $col2_val = max(0, $dm_val - $col1_val);
            $output .= '<Cell ss:StyleID="PurpleCell"><Data ss:Type="Number">' . number_format($col2_val, 2, '.', '') . '</Data></Cell>';

            $add_ons_val = isset($row['add_ons']) ? (float)$row['add_ons'] : 0.00;
            $output .= '<Cell ss:StyleID="OrangeCell"><Data ss:Type="Number">' . number_format($add_ons_val, 2, '.', '') . '</Data></Cell>';

            $final_dm_val = isset($row['final_dm']) ? (float)$row['final_dm'] : ($dm_val - $add_ons_val);
            $output .= '<Cell ss:StyleID="GreenCell"><Data ss:Type="Number">' . number_format($final_dm_val, 2, '.', '') . '</Data></Cell>';

            $output .= '</Row>';
        }

        $output .= '</Table></Worksheet>';
    }

    $output .= '</Workbook>';

    if (file_put_contents($excel_path, $output) !== false && filesize($excel_path) > 50) {
        return $excel_path;
    }

    return '';
}

function send_smtp_mail_with_html_body($to, $subject, $filepath, $filename, $html_content, $contentType = 'application/vnd.ms-excel', $cc = '') {
    $smtp_host = 'tcp://smtp.gmail.com'; 
    $smtp_port = 587;                    
    
    $auth_user = 'jcalcantara@bounty.com.ph';
    $auth_pass = str_replace(' ', '', 'kowg yhnc dryb uumq'); 

    $from_email = 'testgrp@bounty.com.ph';
    $from_name  = 'Admin Telco';

    $boundary = md5(time());

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_name} <{$from_email}>\r\n";
    $headers .= "To: {$to}\r\n";
    
    if (!empty($cc)) {
        $headers .= "Cc: {$cc}\r\n";
    }
    
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
    
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= "{$html_content}\r\n\r\n";
    
    // Attach File
    if (!empty($filepath) && file_exists($filepath) && filesize($filepath) > 50) {
        $fileData = chunk_split(base64_encode(file_get_contents($filepath)));
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$contentType}; name=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
        $body .= "{$fileData}\r\n\r\n";
    }

    $body .= "--{$boundary}--";

    $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
    if (!is_resource($socket)) return "Connection failed: $errstr";
    
    fgets($socket, 512);
    
    $run_cmd = function($cmd, $code) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
        $res = ''; 
        while ($s = fgets($socket, 512)) { 
            $res .= $s; 
            if (substr($s, 3, 1) == ' ') break; 
        }
        return (substr($res, 0, 3) == $code);
    };

    if (!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250) || !$run_cmd("STARTTLS", 220)) return "TLS handshake error";
    
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    
    if (!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250) || 
        !$run_cmd("AUTH LOGIN", 334) || 
        !$run_cmd(base64_encode($auth_user), 334) || 
        !$run_cmd(base64_encode($auth_pass), 235) || 
        !$run_cmd("MAIL FROM: <{$from_email}>", 250)) {
        return "SMTP Auth/Command error";
    }
    
    $to_emails = explode(',', $to);
    foreach ($to_emails as $to_email) {
        $to_email = trim($to_email);
        if (!empty($to_email)) {
            @$run_cmd("RCPT TO: <{$to_email}>", 250);
        }
    }

    if (!$run_cmd("DATA", 354)) return "DATA command data error";
    
    fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
    $result = fgets($socket, 512); 
    $run_cmd("QUIT", 221);
    fclose($socket);

    return (substr($result, 0, 3) == '250') ? true : "Failed: " . trim($result);
}