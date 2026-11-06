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
require_once 'email_template.php';
require_once __DIR__ . '/dm_period.php';
if (is_file(__DIR__ . '/mail_settings.php'))      require_once __DIR__ . '/mail_settings.php';        // Gmail login + monitoring settings
if (is_file(__DIR__ . '/email_monitor_lib.php'))  require_once __DIR__ . '/email_monitor_lib.php';    // delivery monitoring (optional)

// ----------------------------------------------------
// SENDING SPEED & GOOGLE-FRIENDLY PACING  (edit here)
// ----------------------------------------------------
if (!defined('SEND_PAUSE_MS'))           define('SEND_PAUSE_MS', 350);        // normal gap between two emails (milliseconds)
if (!defined('SEND_PAUSE_MAX_MS'))       define('SEND_PAUSE_MAX_MS', 4000);   // the gap never grows beyond this when Google pushes back
if (!defined('SEND_BACKOFF_SECONDS'))    define('SEND_BACKOFF_SECONDS', 5);   // wait this long when Google says "try again later", then retry once
if (!defined('SMTP_MAX_PER_CONNECTION')) define('SMTP_MAX_PER_CONNECTION', 40); // reconnect to Gmail after this many emails

$sbe_pause_ms  = SEND_PAUSE_MS;   // current gap (adapts automatically)
$sbe_ok_streak = 0;
$sbe_smtp      = array('sock' => null, 'count' => 0);
$sbe_last_accept = '';   // the server's "250 OK ..." line for the last accepted email
$sbe_logged      = false; // becomes true once this batch was written to dispatch_logs
// remember the gap between batches (if Google slowed us down in the last batch, the next batch starts slower too)
$sbe_pause_file = sys_get_temp_dir() . '/sbe_send_pause.txt';
if (is_file($sbe_pause_file) && (time() - (int)@filemtime($sbe_pause_file)) < 600) {
    $saved_pause = (int)@file_get_contents($sbe_pause_file);
    if ($saved_pause >= SEND_PAUSE_MS && $saved_pause <= SEND_PAUSE_MAX_MS) $sbe_pause_ms = $saved_pause;
}
register_shutdown_function('sbe_smtp_drop');

if (isset($conn)) {
    $pdo = $conn;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database connection not found in config.php']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in again.']);
    exit;
}
session_write_close();

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

// Single preview form fallback inputs (kapag galing sa Single Email Preview modal)
$single_recipient = isset($_POST['recipient_email']) ? trim($_POST['recipient_email']) : '';
$single_cc        = isset($_POST['cc_emails']) ? trim($_POST['cc_emails']) : '';
$single_subject   = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$single_body      = isset($_POST['html_content']) ? trim($_POST['html_content']) : '';

$global_start     = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
$global_end       = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';
$is_final_batch   = isset($_POST['is_final_batch']) ? trim($_POST['is_final_batch']) : '1';

// LIGTAS NA PAGKUHA NG ACCUMULATED REPORTS
$input_accumulated_reports = [];
if (isset($_POST['accumulated_reports']) && !empty($_POST['accumulated_reports'])) {
    $decoded = json_decode($_POST['accumulated_reports'], true);
    if (is_array($decoded)) {
        $input_accumulated_reports = $decoded;
    }
}

// -------------------------------------------------------------------------
// FIX: ISANG BAGSAK NA PAGKUHA NG ITEM_IDS PARA SA LAHAT NG PROCESS (PDF & SOA)
// -------------------------------------------------------------------------
$base_breakdown_item_ids = [];
if (!empty($_POST['item_ids'])) {
    $base_breakdown_item_ids = array_filter(explode(',', $_POST['item_ids']));
}

$success_count = 0;
$fail_count = 0;
$failed_items = [];
$success_items = [];
$admin_report_items = []; 
register_shutdown_function(function () { global $sbe_logged, $admin_report_items, $pdo; if (!$sbe_logged && !empty($admin_report_items) && isset($pdo)) sbe_log_items($pdo, $admin_report_items); });

foreach ($dm_ids as $index => $dm_id) {
    $dm_id = trim($dm_id);
    
    // Determine breakdown_item_ids for this specific index/account
    $breakdown_item_ids = $base_breakdown_item_ids;
    if (empty($breakdown_item_ids) && isset($_POST['bulk_item_ids'])) {
        $bulk_item_ids_arr = json_decode($_POST['bulk_item_ids'], true);
        if (isset($bulk_item_ids_arr[$index]) && !empty($bulk_item_ids_arr[$index])) {
            $breakdown_item_ids = array_filter(explode(',', $bulk_item_ids_arr[$index]));
        }
    }

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
    
    // Priority: Bulk Array -> Single Form Fallback -> Database
    $recipient_email = isset($bulk_recipients[$index]) && !empty($bulk_recipients[$index]) 
        ? trim($bulk_recipients[$index]) 
        : (!empty($single_recipient) ? $single_recipient : '');
        
    $cc_emails       = isset($bulk_ccs[$index]) && !empty($bulk_ccs[$index]) 
        ? trim($bulk_ccs[$index]) 
        : (!empty($single_cc) ? $single_cc : '');
        
    $custom_start    = isset($bulk_start_dates[$index]) ? trim($bulk_start_dates[$index]) : $global_start;
    $custom_end      = isset($bulk_end_dates[$index]) ? trim($bulk_end_dates[$index]) : $global_end;
    
    $custom_subject  = isset($bulk_subjects[$index]) && !empty($bulk_subjects[$index]) 
        ? trim($bulk_subjects[$index]) 
        : (!empty($single_subject) ? $single_subject : '');
        
    $custom_body     = isset($bulk_bodies[$index]) && !empty($bulk_bodies[$index]) 
        ? trim($bulk_bodies[$index]) 
        : (!empty($single_body) ? $single_body : '');
    
    $recipient_name  = '';
    $employee_id     = '';

    // Query database if no email was entered in the modal
    $email_stmt = $pdo->prepare("SELECT * FROM account_emails WHERE account_number = ?");
    $email_stmt->execute([$account_number]);
    $account_email_row = $email_stmt->fetch();

    if (empty($recipient_email) && $account_email_row && !empty($account_email_row['email_address'])) {
        $recipient_email = trim($account_email_row['email_address']);
    }

    if ($account_email_row && !empty($account_email_row['full_name'])) {
        $recipient_name = $account_email_row['full_name'];
    }

    if ($account_email_row && !empty($account_email_row['employee_id'])) {
        $employee_id = $account_email_row['employee_id'];
    }

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

    // 3.5 Pull details from debit_memo_items
    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $itemParams = [$dm_id];

    if (!empty($breakdown_item_ids)) {
        $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
        $itemQuery .= " AND id IN ($placeholders)";
        foreach ($breakdown_item_ids as $iid) {
            $itemParams[] = (int)$iid;
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
    $total_current_charges = 0.00;
    $mobile_number_val = 'N/A';
    $telco_val = 'N/A';
    
    $raw_start_dates = [];
    $raw_end_dates = [];

    if (!empty($items)) {
        foreach ($items as $it) {
            $curr_charge_v = isset($it['current_charges']) ? (float)$it['current_charges'] : 0.00;
            $total_current_charges += $curr_charge_v;

            // <=2025: Final DM | 2026+: Final Excess Charges (dm_period.php)
            $total_final_dm += dm_final_amount($it);
            
            if (isset($it['approved_plan'])) {
                $total_approved_plan += (float)$it['approved_plan'];
            }
            if (!empty($it['mobile_number'])) $mobile_number_val = $it['mobile_number'];

            if (!empty($it['carrier_name'])) {
                $telco_val = $it['carrier_name'];
            } elseif (!empty($it['telco'])) {
                $telco_val = $it['telco'];
            }

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

    if ($telco_val === 'N/A' || $telco_val === 'Unknown') {
        if (!empty($dm['telco'])) {
            $telco_val = $dm['telco'];
        } elseif (!empty($dm['carrier_name'])) {
            $telco_val = $dm['carrier_name'];
        }
    }

    $approved_plan_display = '₱ ' . number_format($total_approved_plan, 2, '.', ',');
    $current_charges_display = '₱ ' . number_format($total_current_charges, 2, '.', ',');
    $final_dm_val = number_format($total_final_dm, 2, '.', ',');

    // 4. Generate Debit Memo PDF attachment gamit ang filtered item IDs
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

    $company_name = $dm['company'] ?? $recipient_name;

    $template = getEmailTemplate(
        $account_number, 
        $company_name, 
        $recipient_name, 
        $employee_id, 
        $mobile_number_val, 
        $data_coverage, 
        $approved_plan_display, 
        $current_charges_display, 
        $final_dm_val
    );

    $subject = !empty($custom_subject) ? $custom_subject : $template['subject'];
    $html_content = !empty($custom_body) ? $custom_body : $template['body'];

    $attachments = [
        [
            'path' => $pdf_temp_path,
            'name' => $filename,
            'type' => 'application/pdf'
        ]
    ];

    if (!empty($template['attachments'])) {
        foreach ($template['attachments'] as $att_path) {
            if (file_exists($att_path)) {
                $attachments[] = [
                    'path' => $att_path,
                    'name' => basename($att_path),
                    'type' => 'application/pdf'
                ];
            }
        }
    }

    // -------------------------------------------------------------------------
    // 5. SOA ATTACHMENT STRICT FILTERING FIX
    // Filter strictly by checked item IDs OR supplied coverage date range
    // -------------------------------------------------------------------------
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
            $soa_params[] = (int)$iid; 
        }
    } elseif (!empty($custom_start) && !empty($custom_end)) {
        $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
        $soa_params[] = $custom_start;
        $soa_params[] = $custom_end;
    }

    $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                    AND (
                        pdf.mobile_number IS NULL 
                        OR pdf.mobile_number = '' 
                        OR pdf.mobile_number = 'N/A' 
                        OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                    )";

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

    // 6. Send Email via SMTP
    $message_id = sbe_new_message_id();
    $sbe_last_accept = '';
    $mail_sent = sbe_send_paced($recipient_email, $subject, $attachments, $html_content, $cc_emails, $message_id);

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
            'details' => 'Email sent successfully with attachments.',
            'message_id' => $message_id,
            'delivery_status' => 'ACCEPTED',          // Google accepted it; the monitor confirms later whether it bounced
            'smtp_response' => $sbe_last_accept
        ];
    } else {
        $fail_count++;
        $error_msg = "Account {$account_number} ({$recipient_email}) - SMTP failed: " . $mail_sent;
        $failed_items[] = $error_msg;
        $admin_report_items[] = get_failed_report_item($account_number, $telco_val, $mobile_number_val, $recipient_email, $error_msg, $data_coverage, number_format($total_final_dm, 2, '.', ','), $filename, $primary_file_link, $message_id, (string)$mail_sent);
    }

    if (!empty($pdf_temp_path) && file_exists($pdf_temp_path)) @unlink($pdf_temp_path);
    foreach ($downloaded_pdf_paths as $temp_pdf) {
        if (file_exists($temp_pdf)) @unlink($temp_pdf);
    }
    usleep($sbe_pause_ms * 1000);   // short gap so Google is not flooded (was a fixed 2 seconds)
}

sbe_log_items($pdo, $admin_report_items);   // log this batch now, so nothing is lost if a later batch fails or the page is closed
@file_put_contents($sbe_pause_file, (string)$sbe_pause_ms);   // carry the current pace to the next batch

// Pagsamahin ang mga naunang batch reports at ang kasalukuyang batch reports
$all_accumulated_reports = array_merge($input_accumulated_reports, $admin_report_items);

if ($is_final_batch === '1') {
    $total_success_all = 0;
    $total_fail_all = 0;
    foreach ($all_accumulated_reports as $rep) {
        if ($rep['status'] === 'SUCCESS') {
            $total_success_all++;
        } else {
            $total_fail_all++;
        }
    }
    send_dispatch_report_to_admins($pdo, $all_accumulated_reports, $total_success_all, $total_fail_all);
}

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
    'failed_details' => $failed_items,
    'accumulated_reports' => $all_accumulated_reports
]);
exit;

// Save the report items of one batch into dispatch_logs (with the monitoring columns when they exist).
function sbe_log_items($pdo, $items) {
    global $sbe_logged;
    $sbe_logged = true;
    if (empty($items)) return;
    try {
        $mon = function_exists('emon_ensure_schema') && emon_ensure_schema($pdo);
        $sql = "INSERT INTO dispatch_logs
            (status, dispatch_date, account_number, telco, mobile_number, recipient_email, billing_period, total_charge, filename, file_link, sent_by, details"
            . ($mon ? ", message_id, delivery_status, smtp_response" : "") . ")
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($mon ? ", ?, ?, ?" : "") . ")";
        $st = $pdo->prepare($sql);
        foreach ($items as $item) {
            $clean_charge = (float)str_replace(array('PHP ', '₱', ',', ' '), '', isset($item['total_charge']) ? $item['total_charge'] : '0');
            $row = array(
                isset($item['status']) ? $item['status'] : 'UNKNOWN',
                isset($item['date']) ? $item['date'] : date('Y-m-d H:i:s'),
                isset($item['account']) ? $item['account'] : 'N/A',
                isset($item['telco']) ? $item['telco'] : 'N/A',
                isset($item['mobile_number']) ? $item['mobile_number'] : 'N/A',
                isset($item['email']) ? $item['email'] : 'N/A',
                isset($item['billing_period']) ? $item['billing_period'] : 'N/A',
                $clean_charge,
                isset($item['filename']) ? $item['filename'] : 'N/A',
                isset($item['file_link']) ? $item['file_link'] : '',
                isset($item['sent_by']) ? $item['sent_by'] : 'System Administrator',
                isset($item['details']) ? $item['details'] : ''
            );
            if ($mon) {
                $dstat = isset($item['delivery_status']) ? $item['delivery_status'] : ((isset($item['status']) && $item['status'] === 'SUCCESS') ? 'ACCEPTED' : 'REJECTED');
                $row[] = !empty($item['message_id']) ? $item['message_id'] : null;
                $row[] = $dstat;
                $row[] = isset($item['smtp_response']) ? substr((string)$item['smtp_response'], 0, 250) : null;
            }
            $st->execute($row);
        }
    } catch (Exception $e) {
        error_log("Failed to insert dispatch logs into database: " . $e->getMessage());
    }
}

function get_failed_report_item($account, $telco, $mobile, $email, $details, $period = 'N/A', $charge = '0.00', $filename = 'N/A', $link = '', $message_id = '', $smtp_response = '') {
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
        'details' => $details,
        'message_id' => $message_id,
        'delivery_status' => 'REJECTED',          // never accepted by the mail server
        'smtp_response' => $smtp_response
    ];
}

function send_dispatch_report_to_admins($pdo,$report_items, $success_count,$fail_count) {
    // -------------------------------------------------------------------------
    // 1. (the log rows are now saved batch by batch with sbe_log_items(), see above)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 2. KUNIN MGA RECIPIENTS (ADMINS AT CURRENT USER)
    // -------------------------------------------------------------------------
    $stmt =$pdo->prepare("SELECT DISTINCT recipient_email FROM email_report WHERE report_type = ?");
    $stmt->execute(['statement_dispatch']);
    $admins =$stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipient_emails = [];
    foreach ($admins as$admin) {
        if (!empty($admin['recipient_email'])) {
            $recipient_emails[] = trim($admin['recipient_email']);
        }
    }

    if (isset($_SESSION['user_email']) && !empty($_SESSION['user_email'])) {
        $recipient_emails[] = trim($_SESSION['user_email']);
    } elseif (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
        $user_stmt =$pdo->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
        $user_stmt->execute([$_SESSION['username']]);
        $user_row =$user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['email'])) {
            $recipient_emails[] = trim($user_row['email']);
        }
    }

    $unique_recipients = array_unique(array_filter($recipient_emails));
    if (empty($unique_recipients)) return; 

    $current_timestamp = date('Y-m-d H:i:s');$sender_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'System Administrator');
    $total_processed = count($report_items);

    // -------------------------------------------------------------------------
    // 3. I-GENERATE ANG CSV ATTACHMENT REPORT
    // -------------------------------------------------------------------------
    $csv_filename = 'Statement_Dispatch_Report_' . date('Ymd_His') . '.csv';
    $csv_temp_path = tempnam(sys_get_temp_dir(), 'csv_report_');
    $csv_file = fopen($csv_temp_path, 'w');
    
    fputcsv($csv_file, ['Status', 'Date', 'Account', 'Telco', 'Mobile Number', 'Email', 'Billing Period', 'Total Charge', 'Filename', 'Sent By', 'Details']);$table_rows_html = '';
    foreach ($report_items as$item) {
        fputcsv($csv_file, [$item['status'], $item['date'],$item['account'], $item['telco'],$item['mobile_number'], $item['email'],$item['billing_period'], $item['total_charge'],$item['filename'], $item['sent_by'],$item['details']]);

        $status_color = ($item['status'] === 'SUCCESS') ? '#065f46' : '#991b1b';
        $status_bg = ($item['status'] === 'SUCCESS') ? '#d1fae5' : '#fee2e2';
        $filename_display = !empty($item['file_link']) ? "<a href='" . htmlspecialchars($item['file_link']) . "' target='_blank' style='color: #2563eb; text-decoration: underline;'>" . htmlspecialchars($item['filename']) . "</a>" : htmlspecialchars($item['filename']);

        $table_rows_html .= "
        <tr>
            <td style='padding: 8px; text-align: center; font-weight: bold; border: 1px solid #cbd5e1;'><span style='background-color: {$status_bg}; color: {$status_color}; padding: 3px 8px; border-radius: 4px; font-size: 10px; display: inline-block;'>" . htmlspecialchars($item['status']) . "</span></td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: center; word-break: break-word;'>" . htmlspecialchars($item['date']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: bold; color: #1e40af;'>" . htmlspecialchars($item['account']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: center;'>" . htmlspecialchars($item['telco']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: center;'>" . htmlspecialchars($item['mobile_number']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; word-break: break-all;'>" . htmlspecialchars($item['email']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: center;'>" . htmlspecialchars($item['billing_period']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; text-align: right; font-weight: bold;'>" . htmlspecialchars($item['total_charge']) . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; word-break: break-all;'>" . $filename_display . "</td>
            <td style='padding: 8px; border: 1px solid #cbd5e1; word-break: break-word;'>" . htmlspecialchars($item['sent_by']) . "</td>
        </tr>";
    }
    fclose($csv_file);

    // -------------------------------------------------------------------------
    // 4. I-GENERATE ANG HTML EMAIL BODY
    // -------------------------------------------------------------------------
    $html_body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; font-size: 13px; color: #333333; line-height: 1.5; background-color: #f8fafc; padding: 20px;'>
        <div style='max-width: 900px; margin: 0 auto; background: #ffffff; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0;'>
            <p style='font-size: 13px; margin-bottom: 8px; color: #475569;'>Here is the summary of the email dispatches conducted on <strong>{$current_timestamp}</strong> by <strong>{$sender_name}</strong>.</p>
            
            <table style='width: 100%; border-collapse: separate; border-spacing: 12px 0; margin-top: 15px; margin-bottom: 20px;'>
                <tr>
                    <td style='width: 33%; padding: 14px; background-color: #eff6ff; border: 1px solid #bfdbfe; text-align: center; border-radius: 8px;'>
                        <div style='font-size: 11px; color: #1e40af; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;'>TOTAL PROCESSED</div>
                        <div style='font-size: 24px; color: #1e3a8a; font-weight: bold; margin-top: 4px;'>{$total_processed}</div>
                    </td>
                    <td style='width: 33%; padding: 14px; background-color: #ecfdf5; border: 1px solid #a7f3d0; text-align: center; border-radius: 8px;'>
                        <div style='font-size: 11px; color: #047857; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;'>SUCCESSFUL</div>
                        <div style='font-size: 24px; color: #065f46; font-weight: bold; margin-top: 4px;'>{$success_count}</div>
                    </td>
                    <td style='width: 33%; padding: 14px; background-color: #fef2f2; border: 1px solid #fecaca; text-align: center; border-radius: 8px;'>
                        <div style='font-size: 11px; color: #b91c1c; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;'>FAILED</div>
                        <div style='font-size: 24px; color: #991b1b; font-weight: bold; margin-top: 4px;'>{$fail_count}</div>
                    </td>
                </tr>
            </table>

            <h3 style='font-size: 14px; color: #1e293b; margin-bottom: 10px; font-weight: bold;'>Dispatch Details Breakdown</h3>
            <div style='overflow-x: auto;'>
                <table border='0' cellpadding='0' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 11px; border: 1px solid #cbd5e1;'>
                    <thead>
                        <tr style='background-color: #f1f5f9; color: #334155; text-align: center; font-weight: bold;'>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Status</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Date</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Account</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Telco</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Mobile</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Email</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Period</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Charge</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Filename</th>
                            <th style='padding: 8px; border: 1px solid #cbd5e1;'>Sent By</th>
                        </tr>
                    </thead>
                    <tbody>{$table_rows_html}</tbody>
                </table>
            </div>
            <p style='margin-top: 15px; font-size: 11px; color: #64748b; font-style: italic;'>Note: Complete dispatch logs are also saved in the system database and attached as a CSV file for your records.</p>
        </div>
    </body>
    </html>";

    // -------------------------------------------------------------------------
    // 5. I-SEND ANG EMAIL REPORT SA LAHAT NG ADMINS
    // -------------------------------------------------------------------------
    $admin_attachments = [['path' => $csv_temp_path, 'name' =>$csv_filename, 'type' => 'text/csv']];
    
    foreach ($unique_recipients as$recipient_email) {
        if (filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
            send_smtp_mail_with_multi_attachments($recipient_email, "Statement Dispatch Report - " . date('Y-m-d'), $admin_attachments,$html_body);
        }
    }
    if (file_exists($csv_temp_path)) @unlink($csv_temp_path);
}
function get_or_download_pdf_path($filename, $pdo = null) {
    $filename = basename($filename);
    $temp_file_path = sys_get_temp_dir() . '/' . md5($filename) . '.pdf';
      
    if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) {
        return $temp_file_path;
    }

    if ($pdo) {
        try {
            $stmtLocal = $pdo->prepare("SELECT file_path FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
            $stmtLocal->execute([$filename]);
            if ($lRow = $stmtLocal->fetch(PDO::FETCH_ASSOC)) {
                $db_sub_path = $lRow['file_path'] ?? '';
                if (!empty($db_sub_path)) {
                    $local_server_path = __DIR__ . '/uploads/' . $db_sub_path . '/' . $filename;
                    if (file_exists($local_server_path) && filesize($local_server_path) > 100) {
                        @copy($local_server_path, $temp_file_path);
                        if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) {
                            return $temp_file_path;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    }

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
                        
                        $options = [
                            "http" => [
                                "method" => "GET",
                                "timeout" => 30,
                                "header" => "User-Agent: Mozilla/5.0\r\n",
                                "follow_location" => 1,
                                "max_redirects" => 5
                            ],
                            "ssl" => [
                                "verify_peer" => false,
                                "verify_peer_name" => false
                            ]
                        ];
                        $context = stream_context_create($options);
                        $response = @file_get_contents($download_url, false, $context);
                          
                        if ($response !== false && (stripos($response, 'confirm=') !== false || stripos($response, 'download-warning') !== false)) {
                            if (preg_match('/confirm=([a-zA-Z0-9_\-]+)/', $response, $matches)) {
                                $confirm_url = "https://drive.google.com/uc?export=download&confirm=" . $matches[1] . "&id=" . $gdrive_id;
                                $response = @file_get_contents($confirm_url, false, $context);
                            }
                        }
                          
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

// ---------------------------------------------------------------------------
// SMTP (Gmail)
// ONE connection is opened per request and reused for all emails in it.
// Before: connect + STARTTLS + EHLO + AUTH + QUIT for EVERY single email.
// Recommended: define SMTP_USER and SMTP_PASS in config.php. The values below are only a fallback.
// ---------------------------------------------------------------------------
function sbe_smtp_read($sock) {
    $res = '';
    while (($line = fgets($sock, 1024)) !== false) {
        $res .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $res;
}

function sbe_smtp_cmd($sock, $cmd, $code) {
    global $sbe_last_resp;
    if (!is_resource($sock)) { $sbe_last_resp = ''; return false; }
    @fwrite($sock, $cmd . "\r\n");
    $sbe_last_resp = trim(sbe_smtp_read($sock));
    return (substr($sbe_last_resp, 0, 3) == $code);
}

function sbe_smtp_drop() {
    global $sbe_smtp;
    if (isset($sbe_smtp['sock']) && is_resource($sbe_smtp['sock'])) {
        @fwrite($sbe_smtp['sock'], "QUIT\r\n");
        @fclose($sbe_smtp['sock']);
    }
    $sbe_smtp = array('sock' => null, 'count' => 0);
}

function sbe_smtp_open(&$err) {
    $smtp_host = 'tcp://smtp.gmail.com';
    $smtp_port = 587;
    $auth_user = defined('SMTP_USER') ? SMTP_USER : '';
    $auth_pass = defined('SMTP_PASS') ? str_replace(' ', '', SMTP_PASS) : '';
    if ($auth_user === '' || $auth_pass === '') { $err = "SMTP login is not set - open mail_settings.php"; return null; }
    $ehlo      = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';

    $sock = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
    if (!is_resource($sock)) { $err = "Connection failed: $errstr"; return null; }
    stream_set_timeout($sock, 60);
    sbe_smtp_read($sock);

    if (!sbe_smtp_cmd($sock, "EHLO " . $ehlo, 250) || !sbe_smtp_cmd($sock, "STARTTLS", 220)) {
        @fclose($sock); $err = "TLS handshake error"; return null;
    }
    if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        @fclose($sock); $err = "TLS handshake error"; return null;
    }
    if (!sbe_smtp_cmd($sock, "EHLO " . $ehlo, 250) ||
        !sbe_smtp_cmd($sock, "AUTH LOGIN", 334) ||
        !sbe_smtp_cmd($sock, base64_encode($auth_user), 334) ||
        !sbe_smtp_cmd($sock, base64_encode($auth_pass), 235)) {
        @fclose($sock); $err = "SMTP Auth/Command error"; return null;
    }
    return $sock;
}

function sbe_smtp_get(&$err, $fresh = false) {
    global $sbe_smtp;
    if (!isset($sbe_smtp) || !is_array($sbe_smtp)) $sbe_smtp = array('sock' => null, 'count' => 0);
    $alive = is_resource($sbe_smtp['sock']) && !feof($sbe_smtp['sock']);
    if ($fresh || !$alive || $sbe_smtp['count'] >= SMTP_MAX_PER_CONNECTION) {
        sbe_smtp_drop();
        $sock = sbe_smtp_open($err);
        if (!$sock) return null;
        $sbe_smtp = array('sock' => $sock, 'count' => 0);
    }
    return $sbe_smtp['sock'];
}

function send_smtp_mail_with_multi_attachments($to, $subject, $attachments, $html_content, $cc = '', $message_id = '') {
    global $sbe_smtp, $sbe_last_resp, $sbe_last_accept;
    $from_email = 'telcoreports@bounty.com.ph';
    $from_name  = 'Admin Telco';
    $boundary = md5(uniqid('', true));

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_name} <{$from_email}>\r\n";
    $headers .= "To: {$to}\r\n";
    if ($message_id !== '') {
        $headers .= "Message-ID: <{$message_id}>\r\n";          // lets the monitor match a bounce to this exact email
        if (preg_match('/^dm\.\d{14}\.([a-f0-9]{8})@/', $message_id, $mr)) $headers .= "X-DM-Ref: {$mr[1]}\r\n";
    }
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

    $all_recipients = explode(',', $to);
    if (!empty($cc)) {
        $all_recipients = array_merge($all_recipients, explode(',', $cc));
    }

    $last_err = 'SMTP error';
    // Attempt 2 = brand-new connection. We only ever retry BEFORE the message body is sent, so an email is never sent twice.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $err = '';
        $sock = sbe_smtp_get($err, $attempt > 1);
        if (!$sock) { $last_err = $err ? $err : $last_err; continue; }

        if (!sbe_smtp_cmd($sock, "MAIL FROM: <{$from_email}>", 250)) { sbe_smtp_drop(); $last_err = "SMTP Auth/Command error"; continue; }

        $accepted = 0;
        $rcpt_resp = '';
        foreach ($all_recipients as $recipient) {
            $recipient = trim($recipient);
            if ($recipient === '') continue;
            if (sbe_smtp_cmd($sock, "RCPT TO: <{$recipient}>", 250)) { $accepted++; } else { $rcpt_resp = $sbe_last_resp; }
        }
        if ($accepted === 0) {
            sbe_smtp_cmd($sock, "RSET", 250);
            return "No recipient was accepted by the mail server (" . $rcpt_resp . ")";
        }

        if (!sbe_smtp_cmd($sock, "DATA", 354)) { sbe_smtp_drop(); $last_err = "DATA command error"; continue; }

        // SMTP dot-stuffing (plain string replace: no regex limits on big attachments)
        $msg = str_replace("\r\n.", "\r\n..", $headers . "\r\n" . $body);
        @fwrite($sock, $msg . "\r\n.\r\n");
        $result = trim(sbe_smtp_read($sock));

        if (substr($result, 0, 3) == '250') {
            $sbe_smtp['count']++;
            $sbe_last_accept = $result;
            return true;
        }
        sbe_smtp_cmd($sock, "RSET", 250);    // keep the connection usable for the next email
        return "Failed: " . $result;
    }
    return $last_err;
}

// Unique id for each email, e.g. dm.20261105100000.ab12cd34@bounty.com.ph
function sbe_new_message_id() {
    return 'dm.' . date('YmdHis') . '.' . substr(md5(uniqid('', true)), 0, 8) . '@' . (defined('MAIL_MESSAGE_ID_DOMAIN') ? MAIL_MESSAGE_ID_DOMAIN : 'bounty.com.ph');
}

// "Try again later" type problems (SMTP 4xx, or the connection could not be made) - nothing was delivered, so a retry is safe.
function sbe_is_temporary_failure($r) {
    if ($r === true) return false;
    $r = (string)$r;
    if (preg_match('/(?:Failed:|server \()\s*4\d\d\b/', $r)) return true;
    return (strpos($r, 'Connection failed') === 0 || strpos($r, 'TLS handshake') === 0);
}

// Send one email. If Google pushes back, slow down, wait, and try this email once more.
// When everything is fine the gap slowly returns to the normal (fast) value.
function sbe_send_paced($to, $subject, $attachments, $html_content, $cc = '', $message_id = '') {
    global $sbe_pause_ms, $sbe_ok_streak;
    $res = send_smtp_mail_with_multi_attachments($to, $subject, $attachments, $html_content, $cc, $message_id);

    if ($res !== true && sbe_is_temporary_failure($res)) {
        $sbe_pause_ms  = min(SEND_PAUSE_MAX_MS, max($sbe_pause_ms * 2, 1000));
        $sbe_ok_streak = 0;
        sbe_smtp_drop();
        sleep(SEND_BACKOFF_SECONDS);
        $res = send_smtp_mail_with_multi_attachments($to, $subject, $attachments, $html_content, $cc, $message_id);
    }

    if ($res === true) {
        $sbe_ok_streak++;
        if ($sbe_ok_streak >= 10 && $sbe_pause_ms > SEND_PAUSE_MS) {
            $sbe_pause_ms  = max(SEND_PAUSE_MS, (int)($sbe_pause_ms * 0.7));
            $sbe_ok_streak = 0;
        }
    }
    return $res;
}
?>
