<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prevent timeouts and memory exhaustion for large batches
set_time_limit(0);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

// ----------------------------------------------------
// DATABASE CONFIGURATION & DEPENDENCIES
// ----------------------------------------------------
require_once 'config.php'; 
require_once 'fpdf/fpdf.php';
require_once 'pdf_generator.php'; 

if (isset($conn)) {
    $pdo = $conn;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database connection not found in config.php']);
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

$start_date = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
$end_date   = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';

$success_count = 0;
$fail_count = 0;
$failed_items = [];
$success_items = [];
$admin_report_items = []; 

foreach ($dm_ids as $dm_id) {
    $dm_id = trim($dm_id);
    
    // 1. Fetch debit memo record
    $stmt = $pdo->prepare("SELECT * FROM debit_memos WHERE dm_id = ?");
    $stmt->execute([$dm_id]);
    $dm = $stmt->fetch();

    if (!$dm) {
        $fail_count++;
        $error_msg = "ID {$dm_id}: Debit memo record not found.";
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item('N/A', 'N/A', 'N/A', 'No Email', $error_msg);
        continue;
    }

    $account_number = $dm['account_number'];

    // 2. Pull recipient email from 'account_emails' table
    $email_stmt = $pdo->prepare("SELECT * FROM account_emails WHERE account_number = ?");
    $email_stmt->execute([$account_number]);
    $account_email_row = $email_stmt->fetch();

    if (!$account_email_row || empty($account_email_row['email_address'])) {
        $fail_count++;
        $error_msg = "Account {$account_number} (DM ID: {$dm_id}) - No email mapping found.";
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item($account_number, $dm['telco'] ?? 'N/A', $dm['mobile_number'] ?? 'N/A', 'No Email', $error_msg);
        error_log($error_msg);
        continue;
    }

    $recipient_email = $account_email_row['email_address'];
    $recipient_name  = !empty($account_email_row['full_name']) ? $account_email_row['full_name'] : 'Valued Client';

    // 3. Pull details from debit_memo_items
    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $itemParams = [$dm_id];

    if (!empty($start_date) && !empty($end_date)) {
        $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
        $itemParams[] = $start_date;
        $itemParams[] = $end_date;
    }
    $itemQuery .= " ORDER BY coverage_start ASC";

    $stmtItems = $pdo->prepare($itemQuery);
    $stmtItems->execute($itemParams);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $total_final_dm = 0.00;
    $total_approved_plan = 0.00;
    $min_start = null;
    $max_end = null;
    $mobile_number_val = 'N/A';
    $telco_val = 'N/A';

    if (!empty($items)) {
        foreach ($items as $it) {
            $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
            $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
            $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
            
            $total_approved_plan += isset($it['approved_plan']) ? (float)$it['approved_plan'] : 0.00;
            if (!empty($it['mobile_number'])) $mobile_number_val = $it['mobile_number'];
            if (!empty($it['telco'])) $telco_val = $it['telco'];
        }
        $min_start = $items[0]['coverage_start'] ?? null;
        $max_end = end($items)['coverage_end'] ?? null;
    }

    $coverage_start = $min_start ?? ($start_date !== '' ? $start_date : null);
    $coverage_end   = $max_end ?? ($end_date !== '' ? $end_date : null);
    
    $data_coverage = (!empty($coverage_start) && !empty($coverage_end)) ? date('M d, Y', strtotime($coverage_start)) . " to " . date('M d, Y', strtotime($coverage_end)) : "As of current billing";
    $account_name_display = "{$recipient_name} / {$account_number}";
    $approved_plan_display = number_format($total_approved_plan, 2, '.', ',');
    $final_dm_val = number_format($total_final_dm, 2, '.', ',');

    // 4. Generate Debit Memo PDF attachment
    $pdf_result = createDebitMemoPDF($dm_id, $pdo, null, $start_date, $end_date);
    
    if (!is_array($pdf_result) || count($pdf_result) != 2) {
        $fail_count++;
        $error_msg = "Account {$account_number} ({$recipient_email}) - Failed to generate PDF attachment.";
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item($account_number, $telco_val, $mobile_number_val, $recipient_email, $error_msg, $data_coverage, number_format($total_final_dm, 2, '.', ','));
        continue;
    }

    $pdf_obj = $pdf_result[0];
    $acc_num = $pdf_result[1];
    
    $pdf_temp_path = tempnam(sys_get_temp_dir(), 'dm_pdf_');
    $pdf_obj->Output('F', $pdf_temp_path);

    if (!file_exists($pdf_temp_path) || filesize($pdf_temp_path) < 50) {
        $fail_count++;
        $error_msg = "Account {$account_number} ({$recipient_email}) - Generated PDF file is empty or missing.";
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item($account_number, $telco_val, $mobile_number_val, $recipient_email, $error_msg, $data_coverage, number_format($total_final_dm, 2, '.', ','));
        if (file_exists($pdf_temp_path)) @unlink($pdf_temp_path);
        continue;
    }

    $filename = 'Debit_Memo_' . $acc_num . '.pdf';
    $dm_number_display = $dm['dm_number'] ?? $dm_id;
    $subject = "Statement of Account / Debit Memo - " . $dm_number_display;

    $html_content = "
    <div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333;'>
        <p>Dear Ma'am/Sir,</p>
        <p>Please find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges:</p>
        <p><b>Summary Details:</b><br>
        Period Covered: {$data_coverage}<br>
        Account Name: {$account_name_display}<br>
        Approved Plan (Company Share): ₱ {$approved_plan_display}<br>
        Total Chargeable Amount: ₱ {$final_dm_val}</p>
        <p>For any questions or concerns, please reply directly to this email.</p>
        <p>Thank you,</p>
        <p><b>IT Telco Admin Team</b></p>
    </div>";

    $attachments = [
        [
            'path' => $pdf_temp_path,
            'name' => $filename,
            'type' => 'application/pdf'
        ]
    ];

    // 5. Dynamically attach matching PDFs from Google Drive
    $soa_query = "
        SELECT DISTINCT pdf.filename, pdf.file_link 
        FROM debit_memo_items dmi
        JOIN pdf_extracted_details pdf ON pdf.account_number = ?
        WHERE dmi.dm_id = ?
          AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
          AND (pdf.mobile_number IS NULL OR pdf.mobile_number = '' OR pdf.mobile_number = 'N/A' OR pdf.mobile_number = dmi.mobile_number)
    ";
    
    $soa_params = [$account_number, $dm_id];
    if (!empty($start_date) && !empty($end_date)) {
        $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
        $soa_params[] = $start_date;
        $soa_params[] = $end_date;
    }

    $soa_stmt = $pdo->prepare($soa_query);
    $soa_stmt->execute($soa_params);
    $soa_files = $soa_stmt->fetchAll(PDO::FETCH_ASSOC);

    $downloaded_pdf_paths = [];
    $primary_file_link = '';
    foreach ($soa_files as $index => $soa) {
        $pdf_filename = $soa['filename'];
        if ($index === 0) $primary_file_link = $soa['file_link'] ?? '';
        if (!empty($pdf_filename)) {
            $downloaded_path = get_or_download_pdf_path($pdf_filename, $pdo);
            if (!empty($downloaded_path) && file_exists($downloaded_path)) {
                $downloaded_pdf_paths[] = $downloaded_path;
                $attachments[] = [
                    'path' => $downloaded_path,
                    'name' => basename($pdf_filename),
                    'type' => 'application/pdf'
                ];
            }
        }
    }

    // 6. Send Email via SMTP
    $mail_sent = send_smtp_mail_with_multi_attachments($recipient_email, $subject, $attachments, $html_content);

    if ($mail_sent === true) {
        $success_count++;
        $success_msg = "Account {$account_number} ({$recipient_email}) - Successfully sent.";
        $success_items[] = $success_msg;
        $admin_report_items[] = [
            'status' => 'SUCCESS',
            'date' => date('Y-m-d H:i:s'),
            'account' => $account_number,
            'telco' => $telco_val,
            'mobile_number' => $mobile_number_val,
            'email' => $recipient_email,
            'billing_period' => $data_coverage,
            'total_charge' => 'PHP ' . number_format($total_final_dm, 2, '.', ','),
            'filename' => $filename,
            'file_link' => $primary_file_link,
            'sent_by' => 'System Administrator',
            'details' => 'Email sent successfully with attachments.'
        ];
    } else {
        $fail_count++;
        $error_msg = "Account {$account_number} ({$recipient_email}) - SMTP failed: " . $mail_sent;
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item($account_number, $telco_val, $mobile_number_val, $recipient_email, $error_msg, $data_coverage, number_format($total_final_dm, 2, '.', ','), $filename, $primary_file_link);
    }

    // Cleanup temporary generated PDF
    if (!empty($pdf_temp_path) && file_exists($pdf_temp_path)) @unlink($pdf_temp_path);
    foreach ($downloaded_pdf_paths as $temp_pdf) {
        if (file_exists($temp_pdf)) @unlink($temp_pdf);
    }
}

// 7. Dispatch Summary Report to Admins
send_dispatch_report_to_admins($pdo, $admin_report_items, $success_count, $fail_count);

// Build structured detailed message for modal/response display
$detailed_message = "Bulk PDF dispatch completed. Successful: {$success_count}, Failed: {$fail_count}.";
if (!empty($success_items)) {
    $detailed_message .= "\n\nSuccessful Accounts:\n- " . implode("\n- ", $success_items);
}
if (!empty($failed_items)) {
    $detailed_message .= "\n\nFailed Accounts & Errors:\n- " . implode("\n- ", $failed_items);
}

echo json_encode([
    'status' => 'success',
    'message' => $detailed_message,
    'success_count' => $success_count,
    'fail_count' => $fail_count,
    'success_details' => $success_items,
    'failed_details' => $failed_items
]);

// --- Helper Functions ---

function get_failed_report_item($account, $telco, $mobile, $email, $details, $period = 'N/A', $charge = '0.00', $filename = 'N/A', $link = '') {
    return [
        'status' => 'FAILED',
        'date' => date('Y-m-d H:i:s'),
        'account' => $account,
        'telco' => $telco,
        'mobile_number' => $mobile,
        'email' => $email,
        'billing_period' => $period,
        'total_charge' => $charge,
        'filename' => $filename,
        'file_link' => $link,
        'sent_by' => 'System Administrator',
        'details' => $details
    ];
}

function send_dispatch_report_to_admins($pdo, $report_items, $success_count, $fail_count) {
    // 1. Get admin emails from the email_report table
    $stmt = $pdo->prepare("SELECT DISTINCT recipient_email FROM email_report WHERE report_type = ?");
    $stmt->execute(['statement_dispatch']);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipient_emails = [];
    foreach ($admins as $admin) {
        if (!empty($admin['recipient_email'])) {
            $recipient_emails[] = trim($admin['recipient_email']);
        }
    }

    // 2. Automatically include the currently logged-in user's email if available in session
    // (Make sure session is started and you store the user's email or username upon login)
    if (isset($_SESSION['user_email']) && !empty($_SESSION['user_email'])) {
        $recipient_emails[] = trim($_SESSION['user_email']);
    } elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
        // Fallback: lookup email from the users table using the logged-in username
        $user_stmt = $pdo->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
        $user_stmt->execute([$_SESSION['username']]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['email'])) {
            $recipient_emails[] = trim($user_row['email']);
        }
    }

    // 3. Remove duplicates so everyone gets exactly ONE copy only
    $unique_recipients = array_unique(array_filter($recipient_emails));

    if (empty($unique_recipients)) return; 

    $current_timestamp = date('Y-m-d H:i:s');
    $csv_filename = 'Statement_Dispatch_Report_' . date('Ymd_His') . '.csv';
    $csv_temp_path = tempnam(sys_get_temp_dir(), 'csv_report_');
    $csv_file = fopen($csv_temp_path, 'w');
    
    fputcsv($csv_file, ['Status', 'Date', 'Account', 'Telco', 'Mobile Number', 'Email', 'Billing Period', 'Total Charge', 'Filename', 'Sent By', 'Details']);
    
    $table_rows_html = '';
    foreach ($report_items as $item) {
        fputcsv($csv_file, [$item['status'], $item['date'], $item['account'], $item['telco'], $item['mobile_number'], $item['email'], $item['billing_period'], $item['total_charge'], $item['filename'], $item['sent_by'], $item['details']]);

        $status_color = ($item['status'] === 'SUCCESS') ? 'green' : 'red';
        $border_style = ($item['status'] === 'SUCCESS') ? 'border: 1px solid green;' : 'border: 1px solid red;';
        $filename_display = !empty($item['file_link']) ? "<a href='" . htmlspecialchars($item['file_link']) . "' target='_blank'>" . htmlspecialchars($item['filename']) . "</a>" : htmlspecialchars($item['filename']);

        $table_rows_html .= "
        <tr>
            <td style='padding: 6px; text-align: center; color: {$status_color}; font-weight: bold; {$border_style} word-break: break-word;'>" . htmlspecialchars($item['status']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: center; word-break: break-word;'>" . htmlspecialchars($item['date']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: center; word-break: break-all;'>" . htmlspecialchars($item['account']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: center;'>" . htmlspecialchars($item['telco']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: center; word-break: break-all;'>" . htmlspecialchars($item['mobile_number']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; word-break: break-all;'>" . htmlspecialchars($item['email']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: center;'>" . htmlspecialchars($item['billing_period']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; text-align: right; font-weight: bold;'>" . htmlspecialchars($item['total_charge']) . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; word-break: break-all;'>" . $filename_display . "</td>
            <td style='padding: 6px; border: 1px solid #ddd; word-break: break-word;'>" . htmlspecialchars($item['sent_by']) . "</td>
        </tr>";
    }
    fclose($csv_file);

    $html_body = "
   <div style='font-family: Arial, sans-serif; font-size: 10pt; color: #333;'>
        <h2 style='color: #0056b3; margin-bottom: 5px; font-size: 14pt;'>Debit Memo Email Dispatch Report</h2>
        <p>Summary of debit memo email notifications sent on <b>{$current_timestamp}</b>. Attached to this email is a CSV file containing the full details.</p>
        <table border='1' cellpadding='6' cellspacing='0' style='border-collapse: collapse; width: 100%; table-layout: fixed; border-color: #ddd; margin-top: 15px; font-size: 9pt;'>
            <colgroup>
                <col style='width: 7%;'>   <!-- Status -->
                <col style='width: 11%;'>  <!-- Date -->
                <col style='width: 8%;'>   <!-- Account -->
                <col style='width: 5%;'>   <!-- Telco -->
                <col style='width: 9%;'>   <!-- Mobile -->
                <col style='width: 15%;'>  <!-- Email -->
                <col style='width: 11%;'>  <!-- Billing Period -->
                <col style='width: 8%;'>   <!-- Total Charge -->
                <col style='width: 13%;'> <!-- Filename -->
                <col style='width: 13%;'> <!-- Sent By -->
            </colgroup>
            <thead>
                <tr style='background-color: #f8f9fa; text-align: center;'>
                    <th style='padding: 6px;'>Status</th>
                    <th style='padding: 6px;'>Date</th>
                    <th style='padding: 6px;'>Account</th>
                    <th style='padding: 6px;'>Telco</th>
                    <th style='padding: 6px;'>Mobile</th>
                    <th style='padding: 6px;'>Email</th>
                    <th style='padding: 6px;'>Period</th>
                    <th style='padding: 6px;'>Charge</th>
                    <th style='padding: 6px;'>Filename</th>
                    <th style='padding: 6px;'>Sent By</th>
                </tr>
            </thead>
            <tbody>{$table_rows_html}</tbody>
        </table>
        <p style='margin-top: 15px; font-size: 8pt; color: #666;'>This is an automated system report with attached CSV log.</p>
    </div>";

    $admin_attachments = [['path' => $csv_temp_path, 'name' => $csv_filename, 'type' => 'text/csv']];
    
    // 4. Send email to each unique recipient only once
    foreach ($unique_recipients as $recipient_email) {
        if (filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            send_smtp_mail_with_multi_attachments($recipient_email, "Summary Report - " . $current_timestamp, $admin_attachments, $html_body);
        }
    }
    if (file_exists($csv_temp_path)) @unlink($csv_temp_path);
}

function get_or_download_pdf_path($filename, $pdo = null) {
    $filename = basename($filename);
    $temp_file_path = sys_get_temp_dir() . '/' . md5($filename) . '.pdf';
      
    if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) return $temp_file_path;
    usleep(500000); 
      
    if ($pdo) {
        try {
            $stmtFile = $pdo->prepare("SELECT file_link FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
            $stmtFile->execute([$filename]);
            if ($fRow = $stmtFile->fetch(PDO::FETCH_ASSOC)) {
                $link = $fRow['file_link'] ?? '';
                if (!empty($link)) {
                    $gdrive_id = '';
                    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $link, $m)) $gdrive_id = $m[1];
                    elseif (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $link, $m)) $gdrive_id = $m[1];
                      
                    if (!empty($gdrive_id)) {
                        $download_url = "https://drive.google.com/uc?export=download&id=" . $gdrive_id;
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $download_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        $response = curl_exec($ch);
                          
                        if (strpos($response, 'confirm=') !== false && preg_match('/confirm=([a-zA-Z0-9_\-]+)/', $response, $matches)) {
                            curl_setopt($ch, CURLOPT_URL, "https://drive.google.com/uc?export=download&confirm=" . $matches[1] . "&id=" . $gdrive_id);
                            $response = curl_exec($ch);
                        }
                        curl_close($ch);
                          
                        if (!empty($response) && strlen($response) > 500 && stripos($response, '<html') === false) {
                            file_put_contents($temp_file_path, $response);
                            if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) return $temp_file_path;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    }
    return '';
}

function send_smtp_mail_with_multi_attachments($to, $subject, $attachments, $html_content, $cc = '') {
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
    if (!empty($cc)) $headers .= "Cc: {$cc}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
    
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= "{$html_content}\r\n\r\n";
    
    foreach ($attachments as $att) {
        if (!empty($att['path']) && file_exists($att['path']) && filesize($att['path']) > 50) {
            $fileData = chunk_split(base64_encode(file_get_contents($att['path'])));
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$att['type']}; name=\"{$att['name']}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n\r\n";
            $body .= "{$fileData}\r\n\r\n";
        }
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
    
    if ((!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250)) || 
        !$run_cmd("AUTH LOGIN", 334) || 
        !$run_cmd(base64_encode($auth_user), 334) || 
        !$run_cmd(base64_encode($auth_pass), 235) || 
        !$run_cmd("MAIL FROM: <{$from_email}>", 250)) {
        return "SMTP Auth/Command error";
    }
    
    $to_emails = explode(',', $to);
    foreach ($to_emails as $to_email) {
        $to_email = trim($to_email);
        if (!empty($to_email)) @$run_cmd("RCPT TO: <{$to_email}>", 250);
    }

    if (!$run_cmd("DATA", 354)) return "DATA command error";
    fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
    $result = fgets($socket, 512); 
    $run_cmd("QUIT", 221);
    fclose($socket);

    return (substr($result, 0, 3) == '250') ? true : "Failed: " . trim($result);
}