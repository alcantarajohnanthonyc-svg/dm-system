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

// Kunin ang bulk overrides mula sa POST galing sa cache/modals kung meron man
$bulk_recipients  = isset($_POST['bulk_recipients']) ? json_decode($_POST['bulk_recipients'], true) : [];
$bulk_ccs         = isset($_POST['bulk_ccs']) ? json_decode($_POST['bulk_ccs'], true) : [];
$bulk_start_dates = isset($_POST['bulk_start_dates']) ? json_decode($_POST['bulk_start_dates'], true) : [];
$bulk_end_dates   = isset($_POST['bulk_end_dates']) ? json_decode($_POST['bulk_end_dates'], true) : [];
$bulk_subjects    = isset($_POST['bulk_subjects']) ? json_decode($_POST['bulk_subjects'], true) : [];
$bulk_bodies      = isset($_POST['bulk_bodies']) ? json_decode($_POST['bulk_bodies'], true) : [];

$global_start     = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
$global_end       = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';

// Kunin ang item_ids mula sa POST kung galing sa breakdown modal
$item_ids_input = isset($_POST['item_ids']) ? trim($_POST['item_ids']) : '';
$breakdown_item_ids = !empty($item_ids_input) ? array_filter(explode(',', $item_ids_input)) : [];

$success_count = 0;
$fail_count = 0;
$failed_items = [];
$success_items = [];
$admin_report_items = []; 

foreach ($dm_ids as $index => $dm_id) {
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
    
    // Kunin ang per-account custom overrides kung meron
    $recipient_email = isset($bulk_recipients[$index]) ? trim($bulk_recipients[$index]) : '';
    $cc_emails       = isset($bulk_ccs[$index]) ? trim($bulk_ccs[$index]) : '';
    $custom_start    = isset($bulk_start_dates[$index]) ? trim($bulk_start_dates[$index]) : $global_start;
    $custom_end      = isset($bulk_end_dates[$index]) ? trim($bulk_end_dates[$index]) : $global_end;
    $custom_subject  = isset($bulk_subjects[$index]) ? trim($bulk_subjects[$index]) : '';
    $custom_body     = isset($bulk_bodies[$index]) ? trim($bulk_bodies[$index]) : '';
    
    $recipient_name  = 'Valued Client';
    $employee_id     = '';

    // 2. Kunin ang impormasyon mula sa 'account_emails' table para sa pangalan, employee_id, o fallback ng email[cite: 7, 11]
    $email_stmt = $pdo->prepare("SELECT * FROM account_emails WHERE account_number = ?");
    $email_stmt->execute([$account_number]);
    $account_email_row = $email_stmt->fetch();

    // Kung walang laman ang galing sa modal, saka natin gamitin ang email mula sa database[cite: 11]
    if (empty($recipient_email)) {
        if ($account_email_row && !empty($account_email_row['email_address'])) {
            $recipient_email = trim($account_email_row['email_address']);
        }
    }

    // Kunin ang full name kung available sa database[cite: 7, 11]
    if ($account_email_row && !empty($account_email_row['full_name'])) {
        $recipient_name = $account_email_row['full_name'];
    }

    // Kunin ang employee_id kung available sa database[cite: 7, 11]
    if ($account_email_row && !empty($account_email_row['employee_id'])) {
        $employee_id = $account_email_row['employee_id'];
    }

    // 3. Kung pagkatapos nito ay wala pa ring email, mag-fail[cite: 11]
    if (empty($recipient_email)) {
        $fail_count++;
        $error_msg = "Account {$account_number} (DM ID: {$dm_id}) - Failed: No email address provided or mapped.";
        $failed_items[] = $error_msg;
        
        $admin_report_items[] = [
            'status' => 'FAILED', 
            'date' => date('Y-m-d H:i:s'), 
            'account' => $account_number,
            'telco' => $dm['telco'] ?? 'N/A', 
            'mobile_number' => $dm['mobile_number'] ?? 'N/A', 
            'email' => 'No Email Provided',
            'billing_period' => 'N/A', 
            'total_charge' => '0.00',
            'filename' => 'N/A', 
            'file_link' => '', 
            'sent_by' => 'System Administrator', 
            'details' => $error_msg
        ];
        continue;
    }

    // 3.5 Pull details from debit_memo_items gamit ang custom date range kung naka-specify[cite: 11]
    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $itemParams = [$dm_id];

    if (!empty($breakdown_item_ids)) {
        $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
        $itemQuery .= " AND id IN ($placeholders)";
        foreach ($breakdown_item_ids as $iid) {
            $itemParams[] = $iid;
        }
    } elseif (!empty($custom_start) && !empty($custom_end)) {
        $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
        $itemParams[] = $custom_start;
        $itemParams[] = $custom_end;
    }
    
    $itemQuery .= " ORDER BY coverage_start ASC";

    $stmtItems = $pdo->prepare($itemQuery);
    $stmtItems->execute($itemParams);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $total_final_dm = 0.00;
    $total_approved_plan = 0.00;
    $approved_plan_set = false;
    $mobile_number_val = 'N/A';
    $telco_val = 'N/A';
    
    $raw_start_dates = [];
    $raw_end_dates = [];

    if (!empty($items)) {
        foreach ($items as $it) {
            $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
            $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
            $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
            
            if (!$approved_plan_set && isset($it['approved_plan'])) {
                $total_approved_plan = (float)$it['approved_plan'];
                $approved_plan_set = true;
            }
            if (!empty($it['mobile_number'])) $mobile_number_val = $it['mobile_number'];
            if (!empty($it['telco'])) $telco_val = $it['telco'];

            if (!empty($it['coverage_start'])) $raw_start_dates[] = $it['coverage_start'];
            if (!empty($it['coverage_end'])) $raw_end_dates[] = $it['coverage_end'];
        }
    }

    if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
        $earliest_start = date('M d, Y', strtotime(min($raw_start_dates)));
        $latest_end = date('M d, Y', strtotime(max($raw_end_dates)));
        $data_coverage = "{$earliest_start} to {$latest_end}";
    } else {
        $data_coverage = "As of current billing";
    }

    // Gamitin ang employee_id kung mayroon, kung wala ay mag-fallback sa account_number
    $identifier_val = !empty($employee_id) ? $employee_id : $account_number;
    $account_name_display = "{$recipient_name} / {$identifier_val}";

    $approved_plan_display = number_format($total_approved_plan, 2, '.', ',');
    $final_dm_val = number_format($total_final_dm, 2, '.', ',');

    // 4. Generate Debit Memo PDF attachment[cite: 11]
    $pdf_result = createDebitMemoPDF($dm_id, $pdo, $breakdown_item_ids, $custom_start, $custom_end);

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
    
    // Gamitin ang custom subject kung mayroon, kung wala ay default[cite: 11]
    $subject = !empty($custom_subject) ? $custom_subject : "Statement of Account / Debit Memo - " . $account_number;

    // Gamitin ang custom body kung mayroon, kung wala ay default HTML format[cite: 11]
    if (!empty($custom_body)) {
        // Palitan ang newline ng <br> para sa maayos na email formatting[cite: 11]
        $html_content = nl2br(htmlspecialchars($custom_body));
    } else {
        $html_content = "
        <div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333;'>
            <p>Dear Ma'am/Sir,</p>
            <p>Please find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges:</p>
            <p><b>Summary Details:</b><br>
            Period Covered: {$data_coverage}<br>
            Account Name: {$account_name_display}<br>
            Approved Plan (Company Share): ₱ {$approved_plan_display}<br>
            Total Chargeable Amount: ₱ {$final_dm_val}</p>
            <p>This statement outlines the specific breakdown and descriptions of the charges applied to your telco account for your information.</p>
            <p>Note: This email provides a detailed breakdown and description of your telco account charges for your reference. If your excess charges is zero (₱0.00), no action is required and you may disregard this notification.</p>
            <p>Please review the attached SOA for full details.</p>
            <p>This is an automated email, please do not reply.</p>
            <p>Thank you,</p>
            <p><b>IT Telco Admin Team</b></p>
        </div>";
    }

    $attachments = [
        [
            'path' => $pdf_temp_path,
            'name' => $filename,
            'type' => 'application/pdf'
        ]
    ];

    // 5. Dynamically attach matching PDFs from Google Drive[cite: 11]
    $soa_query = "
        SELECT DISTINCT pdf.filename, pdf.file_link 
        FROM debit_memo_items dmi
        JOIN pdf_extracted_details pdf ON pdf.account_number = ?
        WHERE dmi.dm_id = ?
    ";
    
    $soa_params = [$account_number, $dm_id];

    if (!empty($breakdown_item_ids)) {
        $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
        $soa_query .= " AND dmi.id IN ($placeholders)";
        foreach ($breakdown_item_ids as $iid) {
            $soa_params[] = $iid;
        }
    } elseif (!empty($custom_start) && !empty($custom_end)) {
        $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
        $soa_params[] = $custom_start;
        $soa_params[] = $custom_end;
    }

    $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                    AND (pdf.mobile_number IS NULL OR pdf.mobile_number = '' OR pdf.mobile_number = 'N/A' OR pdf.mobile_number = dmi.mobile_number)";

    $soa_stmt = $pdo->prepare($soa_query);
    $soa_stmt->execute($soa_params);
    $soa_files = $soa_stmt->fetchAll(PDO::FETCH_ASSOC);

    $downloaded_pdf_paths = [];
    $primary_file_link = '';
    foreach ($soa_files as $idx_f => $soa) {
        $pdf_filename = $soa['filename'];
        if ($idx_f === 0) $primary_file_link = $soa['file_link'] ?? '';
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

    if (isset($_FILES['additional_attachments']) && !empty($_FILES['additional_attachments']['name'][0])) {
        $file_count = count($_FILES['additional_attachments']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['additional_attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['additional_attachments']['tmp_name'][$i];
                $orig_name = $_FILES['additional_attachments']['name'][$i];
                $file_type = $_FILES['additional_attachments']['type'][$i];
                
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

    // 6. Send Email via SMTP[cite: 11]
    $mail_sent = send_smtp_mail_with_multi_attachments($recipient_email, $subject, $attachments, $html_content, $cc_emails);
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

    if (!empty($pdf_temp_path) && file_exists($pdf_temp_path)) @unlink($pdf_temp_path);
    foreach ($downloaded_pdf_paths as $temp_pdf) {
        if (file_exists($temp_pdf)) @unlink($temp_pdf);
    }
}

// 7. Dispatch Summary Report to Admins[cite: 11]
send_dispatch_report_to_admins($pdo, $admin_report_items, $success_count, $fail_count);

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
    $stmt = $pdo->prepare("SELECT DISTINCT recipient_email FROM email_report WHERE report_type = ?");
    $stmt->execute(['statement_dispatch']);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipient_emails = [];
    foreach ($admins as $admin) {
        if (!empty($admin['recipient_email'])) {
            $recipient_emails[] = trim($admin['recipient_email']);
        }
    }

    if (isset($_SESSION['user_email']) && !empty($_SESSION['user_email'])) {
        $recipient_emails[] = trim($_SESSION['user_email']);
    } elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
        $user_stmt = $pdo->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
        $user_stmt->execute([$_SESSION['username']]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['email'])) {
            $recipient_emails[] = trim($user_row['email']);
        }
    }

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
    
    $all_recipients = explode(',', $to);
    if (!empty($cc)) {
        $cc_emails_array = explode(',', $cc);
        $all_recipients = array_merge($all_recipients, $cc_emails_array);
    }

    foreach ($all_recipients as $recipient) {
        $recipient = trim($recipient);
        if (!empty($recipient)) {
            @$run_cmd("RCPT TO: <{$recipient}>", 250);
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