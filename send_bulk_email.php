<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(0);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

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

// Suriin kung ito ay galing sa Bulk Review Table Modal
$is_bulk_review = isset($_POST['is_bulk_review']) && $_POST['is_bulk_review'] === '1';
$bulk_recipients_map = [];
$bulk_ccs_map = [];

if ($is_bulk_review) {
    $recipients_json = isset($_POST['recipients_json']) ? json_decode($_POST['recipients_json'], true) : [];
    $ccs_json = isset($_POST['ccs_json']) ? json_decode($_POST['ccs_json'], true) : [];
    
    foreach ($dm_ids as $index => $id) {
        $bulk_recipients_map[$id] = isset($recipients_json[$index]) ? trim($recipients_json[$index]) : '';
        $bulk_ccs_map[$id] = isset($ccs_json[$index]) ? trim($ccs_json[$index]) : '';
    }
}

// Single preview inputs (kung single account ang pinadala)
$post_recipient       = isset($_POST['recipient_email']) ? trim($_POST['recipient_email']) : '';
$custom_cc            = isset($_POST['cc_emails']) ? trim($_POST['cc_emails']) : '';
$custom_subject       = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$custom_body          = isset($_POST['html_content']) ? trim($_POST['html_content']) : '';
$selected_attachments = isset($_POST['selected_attachments']) ? $_POST['selected_attachments'] : [];

$success_count = 0;
$fail_count = 0;
$failed_items = [];
$success_items = [];
$admin_report_items = []; 

foreach ($dm_ids as $dm_id) {
    $dm_id = trim($dm_id);
    
    $stmt = $pdo->prepare("SELECT * FROM debit_memos WHERE dm_id = ?");
    $stmt->execute([$dm_id]);
    $dm = $stmt->fetch();

    if (!$dm) {
        $fail_count++;
        $error_msg = "ID {$dm_id}: Debit memo record not found.";
        $failed_items[] = $error_msg;
        continue;
    }

    $account_number = $dm['account_number'];

    // Piliin ang recipient email base sa bulk review map o single post
    if ($is_bulk_review) {
        $recipient_email = isset($bulk_recipients_map[$dm_id]) ? $bulk_recipients_map[$dm_id] : '';
        $current_cc = isset($bulk_ccs_map[$dm_id]) ? $bulk_ccs_map[$dm_id] : '';
    } else {
        $email_stmt = $pdo->prepare("SELECT email_address FROM account_emails WHERE account_number = ?");
        $email_stmt->execute([$account_number]);
        $email_row = $email_stmt->fetch();

        $recipient_email = !empty($post_recipient) ? $post_recipient : (($email_row && !empty($email_row['email_address'])) ? $email_row['email_address'] : '');
        $current_cc = $custom_cc;
    }

    if (empty($recipient_email)) {
        $fail_count++;
        $error_msg = "Account {$account_number} - No email address provided.";
        $failed_items[] = $error_msg;
        continue;
    }

    // Pull items & generate PDF
    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $stmtItems = $pdo->prepare($itemQuery);
    $stmtItems->execute([$dm_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $total_final_dm = 0.00;
    foreach ($items as $it) {
        $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
        $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
        $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
    }

    $pdf_result = createDebitMemoPDF($dm_id, $pdo, [], '', '');
    if (!is_array($pdf_result) || count($pdf_result) != 2) {
        $fail_count++;
        $failed_items[] = "Account {$account_number} - Failed to generate PDF.";
        continue;
    }

    $pdf_obj = $pdf_result[0];
    $acc_num = $pdf_result[1];
    $pdf_temp_path = tempnam(sys_get_temp_dir(), 'dm_pdf_');
    $pdf_obj->Output('F', $pdf_temp_path);

    $filename = 'Debit_Memo_' . $acc_num . '.pdf';
    $subject = !empty($custom_subject) ? $custom_subject : "Statement of Account / Debit Memo - " . ($dm['dm_number'] ?? $dm_id);
    
    $html_content = !empty($custom_body) ? "<div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333;'>" . nl2br(htmlspecialchars($custom_body)) . "</div>" : "<p>Dear Client, please find attached your Statement of Account.</p>";

    $attachments = [[
        'path' => $pdf_temp_path,
        'name' => $filename,
        'type' => 'application/pdf'
    ]];

   if (isset($_FILES['additional_attachments']) && !empty($_FILES['additional_attachments']['name'][0])) {
        $file_count = count($_FILES['additional_attachments']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['additional_attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['additional_attachments']['tmp_name'][$i];
                $orig_name = $_FILES['additional_attachments']['name'][$i];
                $file_type = $_FILES['additional_attachments']['type'][$i];
                
                // Ilipat ang temporary file para mabasa ng email attachment loop
                $new_temp_path = tempnam(sys_get_temp_dir(), 'user_att_');
                if (move_uploaded_file($tmp_name, $new_temp_path)) {
                    $attachments[] = [
                        'path' => $new_temp_path,
                        'name' => $orig_name,
                        'type' => $file_type
                    ];
                }
            }
        }
    }

    $mail_sent = send_smtp_mail_with_multi_attachments($recipient_email, $subject, $attachments, $html_content, $current_cc);

    if ($mail_sent === true) {
        $success_count++;
        $success_items[] = "Account {$account_number} ({$recipient_email}) - Successfully sent.";
    } else {
        $fail_count++;
        $failed_items[] = "Account {$account_number} - SMTP failed: " . $mail_sent;
    }

    if (file_exists($pdf_temp_path)) @unlink($pdf_temp_path);
}

echo json_encode([
    'status' => 'success',
    'success_count' => $success_count,
    'fail_count' => $fail_count,
    'success_details' => $success_items,
    'failed_details' => $failed_items
]);

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
    $headers .= "To: {$to}\r\n";
    if (!empty($cc)) $headers .= "Cc: {$cc}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
    
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= "{$html_content}\r\n\r\n";
    
    foreach ($attachments as $att) {
        if (!empty($att['path']) && file_exists($att['path'])) {
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
        return "SMTP Auth error";
    }
    
    foreach (explode(',', $to) as $email) {
        if (!empty(trim($email))) @$run_cmd("RCPT TO: <" . trim($email) . ">", 250);
    }
    if (!empty($cc)) {
        foreach (explode(',', $cc) as $cc_email) {
            if (!empty(trim($cc_email))) @$run_cmd("RCPT TO: <" . trim($cc_email) . ">", 250);
        }
    }

    if (!$run_cmd("DATA", 354)) return "DATA command error";
    fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
    $result = fgets($socket, 512); 
    $run_cmd("QUIT", 221);
    fclose($socket);

    return (substr($result, 0, 3) == '250') ? true : "Failed: " . trim($result);
}
?>