<?php
// Enable error reporting para sa debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

session_start();
require_once 'config.php';
require_once 'main.php';
include_once 'emailhub.php';
require_once 'email_template.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
} elseif (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

// -------------------------------------------------------------------------
// AJAX HANDLERS PARA SA EMAIL HUB (Standalone Endpoints)
// -------------------------------------------------------------------------
if (isset($_GET['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // Standard Default Email Subject and Body Generator
  function getDefaultEmailContent($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00') {
        if (function_exists('getEmailTemplate')) {
            $template = getEmailTemplate($account_number, $company, $recipient_name, $employee_id, $mobile_number, $data_coverage, $approved_plan_display, $current_charges_display, $final_dm_val);
            return [
                'subject' => $template['subject'],
                'body'    => $template['body']
            ];
        }

        // Kung hindi makita ang email_template.php, ihihinto ang proseso para hindi matuloy ang padala
        throw new Exception("Critical Error: email_template.php or getEmailTemplate() function is missing. Aborting email generation.");
    }

    // Helper function para i-check kung may Shared Drive SOA
    function checkSharedDriveSOA($conn, $account_number, $dm_id, $start_date, $end_date) {
        if (empty($dm_id) || $dm_id == 0) return false;
        
        $soa_query = "SELECT COUNT(*) as cnt 
                      FROM debit_memo_items dmi 
                      JOIN pdf_extracted_details pdf ON pdf.account_number = ? 
                      WHERE dmi.dm_id = ?";
        $soa_params = [$account_number, $dm_id];
        
        if (!empty($start_date) && !empty($end_date)) {
            $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
            array_push($soa_params, $start_date, $end_date);
        }

        $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5";

        $stmt = $conn->prepare($soa_query);
        $stmt->execute($soa_params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($res && $res['cnt'] > 0);
    }

    // Helper function para ma-check kung may actual data items
    function checkDataCoverageExists($conn, $dm_id, $start_date, $end_date) {
        if (empty($dm_id) || $dm_id == 0) return false;
        
        $query = "SELECT COUNT(*) as cnt FROM debit_memo_items WHERE dm_id = ?";
        $params = [$dm_id];
        
        if (!empty($start_date) && !empty($end_date)) {
            $query .= " AND coverage_start >= ? AND coverage_end <= ?";
            array_push($params, $start_date, $end_date);
        }
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($res && $res['cnt'] > 0);
    }

    // Helper function para kalkulahin ang summary metrics (SUM ng current_charges at final_dm)
    function calculateAccountSummaryMetrics($conn, $dm_id, $start_date = '', $end_date = '') {
        $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
        $itemParams = [$dm_id];

        if (!empty($start_date) && !empty($end_date)) {
            $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
            $itemParams[] = $start_date;
            $itemParams[] = $end_date;
        }
        $itemQuery .= " ORDER BY coverage_start ASC";

        $stmtItems = $conn->prepare($itemQuery);
        $stmtItems->execute($itemParams);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $total_final_dm = 0.00;
        $total_current_charges = 0.00;
        $total_approved_plan = 0.00;
        $raw_start_dates = [];
        $raw_end_dates = [];
        $sample_approved_plan = 0.00;

        foreach ($items as $it) {
            $curr_charge_v = isset($it['current_charges']) ? (float)$it['current_charges'] : 0.00;
            $total_current_charges += $curr_charge_v;

            $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
            $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
            $final_dm_val = isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
            $total_final_dm += $final_dm_val;


            if (isset($it['approved_plan'])) {
                $total_approved_plan += (float)$it['approved_plan'];
            }

            $c_start = $it['coverage_start'] ?? '';
            $c_end = $it['coverage_end'] ?? '';

            if (!empty($c_start) && !empty($c_end)) {
                $raw_start_dates[] = $c_start;
                $raw_end_dates[] = $c_end;
            }
        }

        if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
            $data_coverage = date('M d, Y', strtotime(min($raw_start_dates))) . " to " . date('M d, Y', strtotime(max($raw_end_dates)));
        } else {
            $data_coverage = "As of current billing";
        }

      if ($total_approved_plan > 0) {
            $approved_plan_display = "₱ " . number_format($total_approved_plan, 2, '.', ',');
        } else {
            $approved_plan_display = "₱ 0.00";
        }

        $current_charges_display = "₱ " . number_format($total_current_charges, 2, '.', ',');
        $total_final_dm_display = number_format($total_final_dm, 2, '.', ',');

        return [
            'data_coverage' => $data_coverage,
            'approved_plan_display' => $approved_plan_display,
            'current_charges_display' => $current_charges_display,
            'total_final_dm' => $total_final_dm_display
        ];
    }

    // 1. Paste Handler
    if ($action === 'get_dm_ids_by_paste_advanced' || $action === 'get_dm_ids_by_paste') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $raw_text = $input['pasted_text'] ?? '';
        $global_start = $input['start_date'] ?? '';
        $global_end = $input['end_date'] ?? '';

        $resolved_accounts = [];
        
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $line = preg_replace('/\s+/', ' ', $line);
                $parts = explode(' ', $line);
                
                $account_number = $parts[0] ?? '';
                
                if (!empty($account_number)) {
                    $line_start = $global_start;
                    $line_end = $global_end;

                    $extracted_dates = [];
                    for ($i = 1; $i < count($parts); $i++) {
                        $date_val = trim($parts[$i]);
                        if (empty($date_val)) continue;

                        $normalized_date = str_replace('-', '/', $date_val);
                        $parsed_date = strtotime($normalized_date);

                        if ($parsed_date !== false) {
                            $extracted_dates[] = date('Y-m-d', $parsed_date);
                        } else {
                            $d_obj = DateTime::createFromFormat('m/d/Y', $normalized_date);
                            if (!$d_obj) $d_obj = DateTime::createFromFormat('Y/m/d', $normalized_date);
                            if (!$d_obj) $d_obj = DateTime::createFromFormat('m-d-Y', $date_val);
                            if (!$d_obj) $d_obj = DateTime::createFromFormat('Y-m-d', $date_val);
                            
                            if ($d_obj) {
                                $extracted_dates[] = $d_obj->format('Y-m-d');
                            }
                        }
                    }

                    if (count($extracted_dates) >= 2) {
                        $line_start = $extracted_dates[0];
                        $line_end = $extracted_dates[1];
                    } elseif (count($extracted_dates) == 1) {
                        $line_start = $extracted_dates[0];
                        $line_end = $extracted_dates[0];
                    }

                    $sql = "SELECT dm.dm_id, dm.account_number, dm.company, 
                            MIN(dmi.coverage_start) as min_start, MAX(dmi.coverage_end) as max_end 
                            FROM debit_memos dm 
                            LEFT JOIN debit_memo_items dmi ON dm.dm_id = dmi.dm_id
                            LEFT JOIN account_emails ae ON dm.account_number = ae.account_number 
                            WHERE (dm.account_number = ? OR ae.email_address = ? OR dm.dm_id = ?)
                            GROUP BY dm.dm_id, dm.account_number, dm.company";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$account_number, $account_number, $account_number]);
                    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($memos)) {
                        foreach ($memos as $memo) {
                            $email_stmt = $conn->prepare("SELECT * FROM account_emails WHERE account_number = ?");
                            $email_stmt->execute([$memo['account_number']]);
                            $account_email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

                            $recipient_email = ($account_email_row && !empty($account_email_row['email_address'])) ? $account_email_row['email_address'] : '';
                            $recipient_name  = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : '';
                            $employee_id     = ($account_email_row && !empty($account_email_row['employee_id'])) ? $account_email_row['employee_id'] : '';
                            $mobile_number   = ($account_email_row && !empty($account_email_row['mobile_number'])) ? $account_email_row['mobile_number'] : '';

                            $final_start = !empty($line_start) ? $line_start : (!empty($global_start) ? $global_start : ($memo['min_start'] ?? ''));
                            $final_end = !empty($line_end) ? $line_end : (!empty($global_end) ? $global_end : ($memo['max_end'] ?? ''));

                            $metrics = calculateAccountSummaryMetrics($conn, $memo['dm_id'], $final_start, $final_end);
                            
                            $defaults = getDefaultEmailContent($memo['account_number'], $memo['company'], $recipient_name, $employee_id, $mobile_number, $metrics['data_coverage'], $metrics['approved_plan_display'], $metrics['current_charges_display'], $metrics['total_final_dm']);
                            
                            $has_soa = checkSharedDriveSOA($conn, $memo['account_number'], $memo['dm_id'], $final_start, $final_end);
                            $has_data = checkDataCoverageExists($conn, $memo['dm_id'], $final_start, $final_end);

                            $resolved_accounts[] = [
                                'dm_id' => $memo['dm_id'] ?? 0,
                                'account_number' => $memo['account_number'] ?? $account_number,
                                'company' => $memo['company'] ?? 'Walang Rekord',
                                'recipient_email' => $recipient_email,
                                'cc_emails' => '',
                                'subject' => $defaults['subject'],
                                'html_content' => $defaults['body'],
                                'start_date' => $final_start,
                                'end_date' => $final_end,
                                'has_soa' => $has_soa,
                                'has_data' => $has_data,
                                'has_dm' => true
                            ];
                        }
                    } else {
                        $defaults = getDefaultEmailContent($account_number, 'Not Found in Database', '', '', '', 'As of current billing', '0.00', '₱ 0.00', '0.00');
                        $resolved_accounts[] = [
                            'dm_id' => 0,
                            'account_number' => $account_number,
                            'company' => 'Not Found in Database',
                            'recipient_email' => '',
                            'cc_emails' => '',
                            'subject' => $defaults['subject'],
                            'html_content' => $defaults['body'],
                            'start_date' => !empty($line_start) ? $line_start : (!empty($global_start) ? $global_start : ''),
                            'end_date' => !empty($line_end) ? $line_end : (!empty($global_end) ? $global_end : ''),
                            'has_soa' => false,
                            'has_data' => false,
                            'has_dm' => false
                        ];
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'accounts' => $resolved_accounts]);
        exit;
    }

    // 1.5 CSV Upload Handler
    if ($action === 'get_dm_ids_by_csv') {
        $resolved_accounts = [];
        
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            $file_handle = fopen($file_tmp_path, 'r');
            
            if ($file_handle !== FALSE) {
                $header = fgetcsv($file_handle);
                
                while (($row = fgetcsv($file_handle)) !== FALSE) {
                    $account_number = trim($row[0] ?? '');
                    if (empty($account_number)) continue;
                    
                    $csv_start      = trim($row[1] ?? '');
                    $csv_end        = trim($row[2] ?? '');
                    $custom_to      = trim($row[3] ?? '');
                    $custom_cc      = trim($row[4] ?? '');
                    $custom_subject = trim($row[5] ?? '');
                    $custom_body    = trim($row[6] ?? '');
                    
                    $final_start = '';
                    $final_end = '';
                    if (!empty($csv_start)) {
                        $parsed = strtotime(str_replace('-', '/', $csv_start));
                        $final_start = $parsed !== false ? date('Y-m-d', $parsed) : $csv_start;
                    }
                    if (!empty($csv_end)) {
                        $parsed = strtotime(str_replace('-', '/', $csv_end));
                        $final_end = $parsed !== false ? date('Y-m-d', $parsed) : $csv_end;
                    }
                    
                    $sql = "SELECT dm.dm_id, dm.account_number, dm.company, 
                            MIN(dmi.coverage_start) as min_start, MAX(dmi.coverage_end) as max_end 
                            FROM debit_memos dm 
                            LEFT JOIN debit_memo_items dmi ON dm.dm_id = dmi.dm_id
                            LEFT JOIN account_emails ae ON dm.account_number = ae.account_number 
                            WHERE (dm.account_number = ? OR ae.email_address = ? OR dm.dm_id = ?)
                            GROUP BY dm.dm_id, dm.account_number, dm.company";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$account_number, $account_number, $account_number]);
                    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($memos)) {
                        foreach ($memos as $memo) {
                            $email_stmt = $conn->prepare("SELECT * FROM account_emails WHERE account_number = ?");
                            $email_stmt->execute([$memo['account_number']]);
                            $account_email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

                            $db_email      = ($account_email_row && !empty($account_email_row['email_address'])) ? $account_email_row['email_address'] : '';
                            $recipient_name = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : '';
                            $employee_id    = ($account_email_row && !empty($account_email_row['employee_id'])) ? $account_email_row['employee_id'] : '';
                            $mobile_number  = ($account_email_row && !empty($account_email_row['mobile_number'])) ? $account_email_row['mobile_number'] : '';
                            
                            $recipient_email = !empty($custom_to) ? $custom_to : $db_email;
                            $cc_emails       = $custom_cc;
                            
                            $resolved_start  = !empty($final_start) ? $final_start : ($memo['min_start'] ?? '');
                            $resolved_end    = !empty($final_end) ? $final_end : ($memo['max_end'] ?? '');

                            $metrics = calculateAccountSummaryMetrics($conn, $memo['dm_id'], $resolved_start, $resolved_end);
                            
                            $defaults = getDefaultEmailContent($memo['account_number'], $memo['company'], $recipient_name, $employee_id, $mobile_number, $metrics['data_coverage'], $metrics['approved_plan_display'], $metrics['current_charges_display'], $metrics['total_final_dm']);
                            
                            $has_soa = checkSharedDriveSOA($conn, $memo['account_number'], $memo['dm_id'], $resolved_start, $resolved_end);
                            $has_data = checkDataCoverageExists($conn, $memo['dm_id'], $resolved_start, $resolved_end);

                            $resolved_accounts[] = [
                                'dm_id' => $memo['dm_id'] ?? 0,
                                'account_number' => $memo['account_number'] ?? $account_number,
                                'company' => $memo['company'] ?? 'Walang Rekord',
                                'recipient_email' => $recipient_email,
                                'cc_emails' => $cc_emails,
                                'subject' => !empty($custom_subject) ? $custom_subject : $defaults['subject'],
                                'html_content' => !empty($custom_body) ? $custom_body : $defaults['body'],
                                'start_date' => $resolved_start,
                                'end_date' => $resolved_end,
                                'has_soa' => $has_soa,
                                'has_data' => $has_data,
                                'has_dm' => true
                            ];
                        }
                    } else {
                        $defaults = getDefaultEmailContent($account_number, 'Not Found in Database', '', '', '', 'As of current billing', '0.00', '₱ 0.00', '0.00');
                        $resolved_accounts[] = [
                            'dm_id' => 0,
                            'account_number' => $account_number,
                            'company' => 'Not Found in Database',
                            'recipient_email' => $custom_to,
                            'cc_emails' => $custom_cc,
                            'subject' => !empty($custom_subject) ? $custom_subject : $defaults['subject'],
                            'html_content' => !empty($custom_body) ? $custom_body : $defaults['body'],
                            'start_date' => $final_start,
                            'end_date' => $final_end,
                            'has_soa' => false,
                            'has_data' => false,
                            'has_dm' => false
                        ];
                    }
                }
                fclose($file_handle);
            }
        }
        
        echo json_encode(['status' => 'success', 'accounts' => $resolved_accounts]);
        exit;
    }

    // 2. Bulk Email Preview Handler
    if ($action === 'get_bulk_email_preview') {
        $input = json_decode(file_get_contents('php://input'), true);
        $accounts_input = isset($input['accounts']) ? $input['accounts'] : [];

        if (empty($accounts_input)) {
            echo json_encode(['status' => 'error', 'message' => 'No accounts provided.']);
            exit;
        }

        echo json_encode(['status' => 'success', 'accounts' => $accounts_input]);
        exit;
    }

    // 3. Single Email Preview Handler
    if ($action === 'get_email_preview') {
        $dm_id = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
        $item_ids_str = isset($_GET['item_ids']) ? trim($_GET['item_ids']) : '';
        $breakdown_item_ids = !empty($item_ids_str) ? array_filter(explode(',', $item_ids_str)) : [];

        $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
        $end_date   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

        $stmt = $conn->prepare("SELECT * FROM debit_memos WHERE dm_id = ?");
        $stmt->execute([$dm_id]);
        $dm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dm) {
            echo json_encode(['status' => 'error', 'message' => 'Debit memo not found.']);
            exit;
        }

        $account_number = $dm['account_number'];

        $email_stmt = $conn->prepare("SELECT * FROM account_emails WHERE account_number = ?");
        $email_stmt->execute([$account_number]);
        $account_email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

        $recipient_email = ($account_email_row && !empty($account_email_row['email_address'])) ? $account_email_row['email_address'] : '';
        $recipient_name  = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : '';
        $employee_id     = ($account_email_row && !empty($account_email_row['employee_id'])) ? $account_email_row['employee_id'] : '';
        $mobile_number   = ($account_email_row && !empty($account_email_row['mobile_number'])) ? $account_email_row['mobile_number'] : '';
        $cc_emails       = '';

        $metrics = calculateAccountSummaryMetrics($conn, $dm_id, $start_date, $end_date);
        
        $defaults = getDefaultEmailContent($account_number, $dm['company'] ?? $recipient_name, $recipient_name, $employee_id, $mobile_number, $metrics['data_coverage'], $metrics['approved_plan_display'], $metrics['current_charges_display'], $metrics['total_final_dm']);

        require_once 'pdf_generator.php';
        $pdf_result = createDebitMemoPDF($dm_id, $conn, $breakdown_item_ids, $start_date, $end_date);
        $attachments = [];

        if (is_array($pdf_result) && count($pdf_result) == 2) {
            $pdf_obj = $pdf_result[0];
            $acc_num = $pdf_result[1];
            $pdf_temp_path = tempnam(sys_get_temp_dir(), 'dm_pdf_');
            $pdf_obj->Output('F', $pdf_temp_path);
            $attachments[] = [
                'path' => $pdf_temp_path,
                'name' => 'Debit_Memo_' . $acc_num . '.pdf'
            ];
        }

        $soa_query = "SELECT DISTINCT pdf.filename, pdf.file_link, pdf.billing_period 
                      FROM debit_memo_items dmi 
                      JOIN pdf_extracted_details pdf ON pdf.account_number = ? 
                      WHERE dmi.dm_id = ?";
        $soa_params = [$account_number, $dm_id];
        
        if (!empty($breakdown_item_ids)) {
            $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
            $soa_query .= " AND dmi.id IN ($placeholders)";
            foreach ($breakdown_item_ids as $iid) { $soa_params[] = $iid; }
        }
        
        if (!empty($start_date) && !empty($end_date)) {
            $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
            array_push($soa_params, $start_date, $end_date);
        }

        $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5";

        $soa_stmt = $conn->prepare($soa_query);
        $soa_stmt->execute($soa_params);
        $soa_files = $soa_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($soa_files as $soa) {
            $target_file = !empty($soa['filename']) ? $soa['filename'] : $soa['file_link'];
            if (!empty($target_file)) {
                $attachments[] = [
                    'path' => $target_file,
                    'name' => basename($target_file)
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'recipient_email' => $recipient_email,
            'cc_emails' => $cc_emails,
            'subject' => $defaults['subject'],
            'html_content' => $defaults['body'],
            'attachments' => $attachments
        ]);
        exit;
    }
}

$page_title = "Email Hub";

ob_start();
?>
<style>
    .fixed-layout-container { font-size: 12px !important; }
    .fixed-layout-container input, 
    .fixed-layout-container button, 
    .fixed-layout-container select,
    .fixed-layout-container a {
        font-size: 12px !important;
        height: 32px !important; 
        padding-top: 4px !important;
        padding-bottom: 4px !important;
    }
</style>

<!-- Main Container -->
<div class="max-w-4xl mx-auto p-4 mt-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 flex flex-col">
        
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
            <div>
                <h2 class="text-base font-bold text-gray-800 uppercase tracking-wider">Email Hub</h2>
                <p class="text-[11px] text-gray-500">Manage bulk emailing via pasted accounts or CSV upload.</p>
            </div>
            <a href="list_dm.php" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all">
                <i class="las la-arrow-left text-sm"></i> Back to List DM
            </a>
        </div>

        <div class="flex border-b border-gray-200 mb-6">
            <button type="button" onclick="switchEmailHubTab(1)" id="tabBtn1" class="flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider">
                Paste Accounts / Emails
            </button>
            <button type="button" onclick="switchEmailHubTab(2)" id="tabBtn2" class="flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider">
                Upload CSV
            </button>
        </div>

        <div id="emailHubTab1" class="space-y-4">
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Accounts or Email List (Supports Paste Formats)</label>
                    <span id="pasteItemCount" class="text-xs font-semibold text-blue-600">0 items found</span>
                </div>
                <textarea id="pasteAccountInput" rows="7" oninput="updateEmailHubPasteCount()" class="w-full p-3 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none resize-none bg-gray-50 font-mono" placeholder="Paste account numbers per line..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Start Date (Optional / Global Fallback)</label>
                    <input type="date" id="hubStartDate" class="w-full p-2.5 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">End Date (Optional / Global Fallback)</label>
                    <input type="date" id="hubEndDate" class="w-full p-2.5 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                </div>
            </div>

            <button type="button" onclick="processPasteEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-4 flex items-center justify-center gap-2">
                <i class="las la-paper-plane text-sm"></i> Process and Review Emails
            </button>
        </div>

        <div id="emailHubTab2" class="space-y-4" style="display: none;">
            <div class="p-6 border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 text-center flex flex-col items-center justify-center py-10">
                <i class="las la-cloud-upload-alt text-4xl text-amber-600 mb-2"></i>
                <h4 class="font-bold text-xs text-gray-700 mb-1 uppercase">Upload CSV File</h4>
                <p class="text-[11px] text-gray-500 mb-4">Drag your CSV file here or click to browse from your r.</p>
                <input type="file" id="csvEmailFile" accept=".csv" class="hidden" onchange="handleCsvFileSelect(this)">
                <button type="button" onclick="document.getElementById('csvEmailFile').click()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-xl text-xs font-semibold hover:bg-gray-100 shadow-sm">
                    Browse File
                </button>
                <span id="selectedCsvFileName" class="text-xs text-emerald-600 font-medium mt-3"></span>
            </div>

            <div class="flex items-center justify-between p-4 bg-amber-50 border border-amber-200 rounded-xl">
                <div>
                    <h5 class="text-xs font-bold text-amber-900">Need a template?</h5>
                    <p class="text-[11px] text-amber-700">Download the CSV template for the correct email/accounts format.</p>
                </div>
                <button type="button" onclick="downloadEmailCsvTemplate()" class="px-4 py-2 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 shadow-sm whitespace-nowrap">
                    Download Template
                </button>
            </div>

            <button type="button" onclick="processCsvEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-4 flex items-center justify-center gap-2">
                <i class="las la-upload text-sm"></i> Upload and Process CSV
            </button>
        </div>

    </div>
</div>

<!-- UPDATED MODAL: Bulk Email Review & Validation -->
<div id="bulkEmailReviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-6xl p-6 mx-4 flex flex-col max-h-[90vh] animate-in fade-in zoom-in duration-200">
        
        <!-- Modal Header -->
        <div class="flex justify-between items-start pb-4 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                    <i class="las la-envelope-open-text text-amber-600 text-xl"></i> Bulk Email Review & Validation
                </h3>
                <p id="bulkSummaryCount" class="text-xs text-gray-500 font-medium mt-0.5">7 accounts loaded for review</p>
            </div>
            <button type="button" onclick="closeBulkEmailReviewModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-700 hover:bg-gray-200 transition-all font-bold">✕</button>
        </div>

        <!-- Upper Status Count Bar -->
        <div class="py-3 px-4 my-3 bg-slate-50 border border-slate-200/80 rounded-2xl flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 flex-wrap" id="statusCountersContainer">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-bold shadow-xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Ready: <span id="countReady">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-xs font-bold shadow-xs">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span> Missing Email: <span id="countMissingEmail">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-lg text-xs font-bold shadow-xs">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> Without SOA: <span id="countMissingSOA">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-lg text-xs font-bold shadow-xs">
                    <span class="w-2 h-2 rounded-full bg-gray-400"></span> No Data: <span id="countNoData">0</span>
                </span>
            </div>
        </div>
        
        <!-- Table Body Container -->
        <div class="border border-gray-200 rounded-2xl overflow-y-auto flex-grow max-h-[48vh] shadow-inner bg-white">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-100/80 sticky top-0 z-10 text-slate-700 font-bold uppercase text-[10px] tracking-wider backdrop-blur-sm">
                    <tr>
                        <th class="p-3 text-center w-28 cursor-pointer hover:bg-slate-200/60 transition-colors select-none" onclick="sortBulkTable('status')">
                            Status <span id="sortIcon_status" class="text-amber-600 ml-0.5"></span>
                        </th>
                        <th class="p-3 cursor-pointer hover:bg-slate-200/60 transition-colors select-none" onclick="sortBulkTable('account')">
                            Account Number & Company <span id="sortIcon_account" class="text-amber-600 ml-0.5"></span>
                        </th>
                        <th class="p-3 cursor-pointer hover:bg-slate-200/60 transition-colors select-none" onclick="sortBulkTable('coverage')">
                            Coverage Dates (Per Line) <span id="sortIcon_coverage" class="text-amber-600 ml-0.5"></span>
                        </th>
                        <th class="p-3">Recipient Email</th>
                        <th class="p-3">CC Emails</th>
                        <th class="p-3 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="bulkReviewTableBody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>

        <!-- Footer Buttons -->
        <div class="flex justify-end gap-3 mt-4 pt-3 border-t border-gray-100">
            <button type="button" onclick="closeBulkEmailReviewModal()" class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-200 transition-all">Cancel</button>
            <button type="button" onclick="proceedBulkDispatchFromModal()" class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-2">
                <i class="las la-paper-plane text-sm"></i> Confirm & Send Emails
            </button>
        </div>
    </div>
</div>

<div id="emailPreviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-3xl p-6 mx-4 max-h-[90vh] flex flex-col">
        
        <div class="flex justify-between items-center mb-4 pb-2 border-b">
            <h3 class="text-base font-bold text-gray-800">Email Preview & Customization</h3>
            <button type="button" onclick="closeEmailPreviewModal()" class="text-gray-400 hover:text-gray-600 font-bold">✕</button>
        </div>

        <form id="emailPreviewForm" class="flex flex-col flex-grow overflow-y-auto space-y-3" enctype="multipart/form-data">
            
            <input type="hidden" id="preview_dm_id" name="dm_ids" value="">
            <input type="hidden" id="preview_item_ids" name="item_ids" value="">
            <input type="hidden" id="preview_start_date" name="start_date" value="">
            <input type="hidden" id="preview_end_date" name="end_date" value="">
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">To Email</label>
                <input type="email" id="preview_to" name="recipient_email" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">CC Emails (Comma-separated)</label>
                <input type="text" id="preview_cc" name="cc_emails" class="w-full p-2 border rounded-xl text-xs bg-gray-50" placeholder="e.g. accounting@company.com">
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Subject</label>
                <input type="text" id="preview_subject" name="subject" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <!-- DUAL VIEW TABS (Visual Preview vs HTML Code Editor) -->
            <div class="flex flex-col flex-grow">
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                    <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                        <button type="button" onclick="switchPreviewMode('visual')" id="btnVisualTab" class="px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all">Visual Preview</button>
                        <button type="button" onclick="switchPreviewMode('code')" id="btnCodeTab" class="px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all">HTML Code</button>
                    </div>
                </div>

                <!-- Visual Rendered Container -->
                <div id="visualPreviewContainer" class="w-full p-4 border rounded-xl bg-white shadow-inner overflow-y-auto max-h-72"></div>

                <!-- HTML Code Editor Textarea (Hidden by default unless switched) -->
                <textarea id="preview_body" name="html_content" rows="8" oninput="syncVisualPreviewFromCode()" class="w-full p-3 border rounded-xl text-xs bg-gray-50 font-mono hidden" required></textarea>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Attachments to Include</label>
                <div id="preview_attachments_container" class="p-2 border rounded-xl bg-gray-50 text-xs space-y-1 max-h-24 overflow-y-auto"></div>
                <div class="mt-2 pt-2 border-t border-gray-200">
                    <label class="block text-[9px] font-bold text-emerald-600 uppercase mb-1">+ Upload New File (Optional)</label>
                    <input type="file" name="additional_attachments[]" id="additional_attachments" multiple class="w-full p-1 border rounded-lg text-xs bg-white text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[10px] file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t">
                <button type="button" onclick="closeEmailPreviewModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-300">Cancel</button>
                <button type="button" onclick="submitConfirmedEmail()" class="px-5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-md">Send Email Now</button>
            </div>

        </form>
    </div>
</div>

    <div id="dispatchProgressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
        <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-4xl p-8 mx-4 flex flex-col max-h-[90vh]">
        
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 border border-amber-100 flex items-center justify-center shadow-sm">
                        <i class="las la-paper-plane text-amber-600 text-xl animate-pulse"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-extrabold text-gray-900 tracking-tight">Dispatching Statement Emails</h3>
                        <p class="text-[11px] text-gray-500 font-medium">Processing records in secure batches...</p>
                    </div>
                </div>
                <span id="batchProgressCounter" class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1.5 rounded-xl shadow-xs">Batch 0/0</span>
            </div>
        
            <!-- Modern Progress Bar Section -->
            <div class="mb-6 bg-slate-50/80 border border-slate-200/80 p-5 rounded-2xl">
                <div class="flex justify-between items-center mb-2">
                <span id="dispatchProgressText" class="text-base font-extrabold text-slate-900">Initializing dispatch process...</span>                <span id="dispatchProgressPercent" class="text-xs font-extrabold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">0%</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden p-0.5 border border-slate-300 shadow-inner">
                <div id="dispatchProgressBar" class="bg-gradient-to-r from-emerald-500 to-teal-500 h-3 rounded-full transition-all duration-400 shadow-sm" style="width: 0%"></div>
            </div>
        </div>

        <!-- Wider & Modern Scrollable Log Box -->
        <div class="flex flex-col flex-grow min-h-[320px] max-h-[420px] bg-slate-950 text-slate-200 text-xs font-mono p-4 rounded-2xl shadow-inner border border-slate-800 overflow-hidden">
            <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800/80 text-[11px] text-slate-400 uppercase tracking-wider font-bold">
                <span>Live Terminal Activity Log</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Live</span>
            </div>
            <div id="dispatchProgressLog" class="flex-grow overflow-y-auto space-y-1.5 pr-2 custom-scrollbar">
                <div class="text-slate-500 italic">Waiting for batch dispatch to start...</div>
            </div>
        </div>

        <!-- Footer Button -->
        <div class="flex justify-end pt-5 mt-4 border-t border-gray-100">
            <button type="button" id="closeDispatchModalBtn" onclick="closeDispatchModal()" style="display:none;" class="px-6 py-3 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 shadow-lg shadow-slate-900/20 transition-all flex items-center gap-2">
                <i class="las la-check-circle text-base"></i> Close & Reload Page
            </button>
        </div>
    </div>
</div>

<!-- FULL-PAGE LOADING OVERLAY -->
<div id="pageLoaderOverlay" style="display: none;" class="fixed inset-0 z-[999999] flex flex-col items-center justify-center bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white p-6 rounded-2xl shadow-2xl flex flex-col items-center space-y-3 border border-slate-100">
        <i class="las la-spinner animate-spin text-4xl text-amber-600"></i>
        <p class="text-xs font-bold text-slate-700 tracking-wide uppercase">Processing accounts, please wait...</p>
    </div>
</div>

<script>
    let bulkAccountsCache = [];

    function switchEmailHubTab(tabNumber) {
        const tab1 = document.getElementById('emailHubTab1');
        const tab2 = document.getElementById('emailHubTab2');
        const btn1 = document.getElementById('tabBtn1');
        const btn2 = document.getElementById('tabBtn2');

        if (tabNumber === 1) {
            tab1.style.display = 'block';
            tab2.style.display = 'none';
            btn1.className = "flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider";
            btn2.className = "flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider";
        } else {
            tab1.style.display = 'none';
            tab2.style.display = 'block';
            btn2.className = "flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider";
            btn1.className = "flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider";
        }
    }

    function updateEmailHubPasteCount() {
        const text = document.getElementById('pasteAccountInput').value;
        const lines = text.split('\n').filter(line => line.trim() !== '');
        document.getElementById('pasteItemCount').innerText = `${lines.length} item${lines.length === 1 ? '' : 's'} found`;
    }

    function handleCsvFileSelect(input) {
        if (input.files && input.files[0]) {
            document.getElementById('selectedCsvFileName').innerText = "Selected File: " + input.files[0].name;
        }
    }

    function downloadEmailCsvTemplate() {
        const csvContent = "data:text/csv;charset=utf-8,account_number,start_date,end_date,custom_to,custom_cc,custom_subject,custom_body\n" +
            "6020304543,1/1/2026,1/31/2026,alcantarajohnathon@gmail.com,,Notice for Account 6020304543,\"Hello Client A, eto po ang SOA niyo...\"\n" +
            "1157296866,,,,,,";
        
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "email_hub_custom_template.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function processPasteEmailHub() {
        const pastedText = document.getElementById('pasteAccountInput').value.trim();
        const startDate = document.getElementById('hubStartDate').value;
        const endDate = document.getElementById('hubEndDate').value;

        if (!pastedText) {
            alert("Please paste account numbers or emails first.");
            return;
        }
        document.getElementById('pageLoaderOverlay').style.display = 'flex';
        fetch('email_hub.php?action=get_dm_ids_by_paste', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                pasted_text: pastedText,
                start_date: startDate,
                end_date: endDate
            })
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); } catch (e) { throw new Error("Server returned non-JSON response."); }
        })
        .then(data => {
            if (data.status === 'success' && data.accounts && data.accounts.length > 0) {
                openBulkEmailReviewModal(data.accounts);
            } else {
                alert("No matching records found for the pasted list.");
            }
        })
        .catch(err => {
            alert("An error occurred: " + err.message);
        });
    }

    function processCsvEmailHub() {
        const fileInput = document.getElementById('csvEmailFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Please select a CSV file to upload first.');
            return;
        }
        document.getElementById('pageLoaderOverlay').style.display = 'flex';
        const formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);

        fetch('email_hub.php?action=get_dm_ids_by_csv', {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); } catch (e) { throw new Error("Server returned non-JSON response."); }
        })
        .then(data => {
            if (data.status === 'success' && data.accounts && data.accounts.length > 0) {
                openBulkEmailReviewModal(data.accounts);
            } else {
                alert("No valid records found in the uploaded CSV file.");
            }
        })
        .catch(err => {
            alert("An error occurred: " + err.message);
        });
    }

    function openBulkEmailReviewModal(accountsList) {
        fetch('email_hub.php?action=get_bulk_email_preview', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ accounts: accountsList })
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); } catch (e) { throw new Error("Server returned non-JSON response."); }
        })
        .then(data => {

            document.getElementById('pageLoaderOverlay').style.display = 'none';

            if (data.status === 'success') {
                bulkAccountsCache = data.accounts;
                renderBulkReviewTable();
                document.getElementById('bulkSummaryCount').innerText = `${accountsList.length} accounts loaded for review`;
                document.getElementById('bulkEmailReviewModal').style.display = 'flex';
            } else {
                alert("Error: " + data.message);
            }
        });
    }

    function closeBulkEmailReviewModal() {
        document.getElementById('bulkEmailReviewModal').style.display = 'none';
    }

   function renderBulkReviewTable() {
        const tbody = document.getElementById('bulkReviewTableBody');
        tbody.innerHTML = '';
        
        updateBulkStatusCounters();

        bulkAccountsCache.forEach((acc, idx) => {
            let tr = document.createElement('tr');
            tr.className = "hover:bg-slate-50/80 transition-colors border-b border-gray-100";
            
            let statusBadge = '';
            let previewButton = '';
            
            if (!acc.has_data || !acc.has_dm || acc.dm_id == 0) {
                statusBadge = '<span class="px-2.5 py-1 bg-gray-100 text-gray-600 rounded-lg text-[10px] font-bold block text-center border border-gray-200">⚪ No Data</span>';
                previewButton = '<button type="button" disabled class="px-3 py-1.5 bg-gray-50 text-gray-300 rounded-xl text-xs font-bold cursor-not-allowed">Preview</button>';
            } else if (!acc.recipient_email || !isValidEmail(acc.recipient_email.trim())) {
                statusBadge = '<span class="px-2.5 py-1 bg-rose-50 text-rose-700 rounded-lg text-[10px] font-bold block text-center border border-rose-200">🔴 Missing Email</span>';
                previewButton = `<button type="button" onclick="previewEmailBeforeSend(${acc.dm_id}, '', '${acc.start_date || ''}', '${acc.end_date || ''}')" class="px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-xl text-xs font-bold transition-all shadow-xs">Preview</button>`;
            } else if (!acc.has_soa) {
                statusBadge = '<span class="px-2.5 py-1 bg-amber-50 text-amber-700 rounded-lg text-[10px] font-bold block text-center border border-amber-200">🟡 Without SOA</span>';
                previewButton = `<button type="button" onclick="previewEmailBeforeSend(${acc.dm_id}, '', '${acc.start_date || ''}', '${acc.end_date || ''}')" class="px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-xl text-xs font-bold transition-all shadow-xs">Preview</button>`;
            } else {
                statusBadge = '<span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-[10px] font-bold block text-center border border-emerald-200">🟢 Ready</span>';
                previewButton = `<button type="button" onclick="previewEmailBeforeSend(${acc.dm_id}, '', '${acc.start_date || ''}', '${acc.end_date || ''}')" class="px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-xl text-xs font-bold transition-all shadow-xs">Preview</button>`;
            }
            
            let coverageDisplay = (acc.start_date && acc.end_date) ? 
                `<span class="text-[11px] font-mono font-medium ${!acc.has_data ? 'text-gray-400 line-through' : 'text-blue-600'}">${acc.start_date} to ${acc.end_date}</span>` : 
                `<span class="text-[10px] text-gray-400 italic">No Date</span>`;

           tr.innerHTML = `
                <td class="p-3 text-center align-middle">${statusBadge}</td>
                <td class="p-3 font-semibold text-gray-900 align-middle">${acc.account_number} <br><span class="text-[10px] text-gray-500 font-normal">${acc.company || ''}</span></td>
                <td class="p-3 align-middle">${coverageDisplay}</td>
                <td class="p-3 align-middle"><input type="email" value="${acc.recipient_email || ''}" oninput="updateRecipientEmailAndRefresh(${idx}, this.value)" class="w-full p-2 border border-gray-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none transition-all shadow-xs" placeholder="Enter recipient email..."></td>
                <td class="p-3 align-middle"><input type="text" value="${acc.cc_emails || ''}" oninput="bulkAccountsCache[${idx}].cc_emails = this.value" class="w-full p-2 border border-gray-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none transition-all shadow-xs" placeholder="CC emails..."></td>
                <td class="p-3 text-center align-middle">${previewButton}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    function switchPreviewMode(mode) {
        const visualContainer = document.getElementById('visualPreviewContainer');
        const codeTextarea = document.getElementById('preview_body');
        const btnVisual = document.getElementById('btnVisualTab');
        const btnCode = document.getElementById('btnCodeTab');

        if (mode === 'visual') {
            visualContainer.style.display = 'block';
            codeTextarea.classList.add('hidden');
            btnVisual.className = "px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all";
            btnCode.className = "px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all";
            
            // Sync HTML from textarea to visual box
            visualContainer.innerHTML = codeTextarea.value;
        } else {
            visualContainer.style.display = 'none';
            codeTextarea.classList.remove('hidden');
            btnCode.className = "px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all";
            btnVisual.className = "px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all";
        }
    }

    function syncVisualPreviewFromCode() {
        const codeTextarea = document.getElementById('preview_body');
        const visualContainer = document.getElementById('visualPreviewContainer');
        visualContainer.innerHTML = codeTextarea.value;
    }

    function previewEmailBeforeSend(dmId, itemIds = '', startDate = '', endDate = '') {
        if (!dmId || dmId == 0) {
           alert("No Debit Memo found for this account. Cannot preview.");
            return;
        }

        let cachedAccount = bulkAccountsCache.find(acc => acc.dm_id == dmId || acc.account_number == dmId);

        if (cachedAccount) {
            document.getElementById('preview_dm_id').value = cachedAccount.dm_id || dmId;
            document.getElementById('preview_item_ids').value = itemIds;
            document.getElementById('preview_start_date').value = cachedAccount.start_date || startDate;
            document.getElementById('preview_end_date').value = cachedAccount.end_date || endDate;
            document.getElementById('preview_to').value = cachedAccount.recipient_email || '';
            document.getElementById('preview_cc').value = cachedAccount.cc_emails || '';
            document.getElementById('preview_subject').value = cachedAccount.subject || '';
            
            const bodyContent = cachedAccount.html_content || '';
            document.getElementById('preview_body').value = bodyContent;
            document.getElementById('visualPreviewContainer').innerHTML = bodyContent;

            // Default to Visual Tab when opening
            switchPreviewMode('visual');

            let fetchUrl = `email_hub.php?action=get_email_preview&dm_id=${dmId}&item_ids=${itemIds}&start_date=${cachedAccount.start_date || startDate}&end_date=${cachedAccount.end_date || endDate}`;

            fetch(fetchUrl)
            .then(res => res.json())
            .then(data => {
                let attContainer = document.getElementById('preview_attachments_container');
                attContainer.innerHTML = '';
                
                if (data.status === 'success' && data.attachments && data.attachments.length > 0) {
                    data.attachments.forEach((att) => {
                        attContainer.innerHTML += `
                            <label class="flex items-center gap-2 py-0.5 px-1 hover:bg-gray-100 rounded cursor-pointer">
                                <input type="checkbox" name="selected_attachments[]" value="${att.path}" checked class="rounded border-gray-300 text-blue-600">
                                <span class="font-medium text-gray-700">${att.name}</span>
                            </label>`;
                    });
                } else {
                    attContainer.innerHTML = `<span class="text-gray-400 italic">No attachments found.</span>`;
                }

                document.getElementById('emailPreviewModal').style.display = 'flex';
            });
        }
    }

    function closeEmailPreviewModal() {
        document.getElementById('emailPreviewModal').style.display = 'none';
    }

    function submitConfirmedEmail() {
        const dmId = document.getElementById('preview_dm_id').value;
        if (!dmId || dmId == 0) {
            alert("Cannot send email because there is no valid Debit Memo ID.");
            return;
        }

        const form = document.getElementById('emailPreviewForm');
        const formData = new FormData(form);

        closeEmailPreviewModal();
        executeDispatchFetch(formData, 1);
    }

    function proceedBulkDispatchFromModal() {
        // Huwag i-filter out ang may mga error; isama ang lahat ng may valid dm_id para ma-log sa admin report kung bakit nag-fail
        let validAccounts = bulkAccountsCache.filter(acc => acc.has_dm && acc.dm_id && acc.dm_id != 0);

        if (validAccounts.length === 0) {
            alert("No valid account with Debit Memo found.");
            return;
        }

        closeBulkEmailReviewModal();
        
        let dmIds = validAccounts.map(acc => acc.dm_id);
        let recipients = validAccounts.map(acc => acc.recipient_email || '');
        let ccs = validAccounts.map(acc => acc.cc_emails || '');
        let startDates = validAccounts.map(acc => acc.start_date || '');
        let endDates = validAccounts.map(acc => acc.end_date || '');
        let subjects = validAccounts.map(acc => acc.subject || '');
        let bodies = validAccounts.map(acc => acc.html_content || '');

        let formData = new FormData();
        formData.append('dm_ids', dmIds.join(','));
        formData.append('bulk_recipients', JSON.stringify(recipients));
        formData.append('bulk_ccs', JSON.stringify(ccs));
        formData.append('bulk_start_dates', JSON.stringify(startDates));
        formData.append('bulk_end_dates', JSON.stringify(endDates));
        formData.append('bulk_subjects', JSON.stringify(subjects));
        formData.append('bulk_bodies', JSON.stringify(bodies));

        executeDispatchFetch(formData, dmIds.length);
    }

async function executeDispatchFetch(formData, countNum) {
    const progressModal = document.getElementById('dispatchProgressModal');
    const progressBar = document.getElementById('dispatchProgressBar');
    const progressText = document.getElementById('dispatchProgressText');
    const progressLog = document.getElementById('dispatchProgressLog');
    const closeBtn = document.getElementById('closeDispatchModalBtn');
    const progressPercent = document.getElementById('dispatchProgressPercent');
    const batchProgressCounter = document.getElementById('batchProgressCounter');

    if (progressModal) progressModal.style.display = 'flex';
    if (progressBar) progressBar.style.width = '0%';
    if (progressPercent) progressPercent.innerText = '0%';
    if (progressText) progressText.innerText = `Starting dispatch process for ${countNum} account(s)...`;
    if (progressLog) progressLog.innerHTML = `<div>Initializing connection...</div>`;
    if (closeBtn) closeBtn.style.display = 'none';

    let dmIds = formData.get('dm_ids').split(',').filter(id => id.trim() !== '');
    let recipients = JSON.parse(formData.get('bulk_recipients') || '[]');
    let ccs = JSON.parse(formData.get('bulk_ccs') || '[]');
    let startDates = JSON.parse(formData.get('bulk_start_dates') || '[]');
    let endDates = JSON.parse(formData.get('bulk_end_dates') || '[]');
    let subjects = JSON.parse(formData.get('bulk_subjects') || '[]');
    let bodies = JSON.parse(formData.get('bulk_bodies') || '[]');

    const chunkSize = 10;
    let totalProcessed = 0;
    let totalSuccess = 0;
    let totalFail = 0;
    let totalBatches = Math.ceil(dmIds.length / chunkSize);
    let currentBatchIndex = 0;
    
    let accumulatedReports = []; 
    progressLog.innerHTML = '';

    for (let i = 0; i < dmIds.length; i += chunkSize) {
        currentBatchIndex++;
        if (batchProgressCounter) {
            batchProgressCounter.innerText = `Batch ${currentBatchIndex}/${totalBatches}`;
        }

        let chunkDmIds = dmIds.slice(i, i + chunkSize);
        let chunkRecipients = recipients.slice(i, i + chunkSize);
        let chunkCcs = ccs.slice(i, i + chunkSize);
        let chunkStartDates = startDates.slice(i, i + chunkSize);
        let chunkEndDates = endDates.slice(i, i + chunkSize);
        let chunkSubjects = subjects.slice(i, i + chunkSize);
        let chunkBodies = bodies.slice(i, i + chunkSize);

        let isLastBatch = (i + chunkSize >= dmIds.length) ? '1' : '0';

        let chunkFormData = new FormData();
        chunkFormData.append('dm_ids', chunkDmIds.join(','));
        chunkFormData.append('bulk_recipients', JSON.stringify(chunkRecipients));
        chunkFormData.append('bulk_ccs', JSON.stringify(chunkCcs));
        chunkFormData.append('bulk_start_dates', JSON.stringify(chunkStartDates));
        chunkFormData.append('bulk_end_dates', JSON.stringify(chunkEndDates));
        chunkFormData.append('bulk_subjects', JSON.stringify(chunkSubjects));
        chunkFormData.append('bulk_bodies', JSON.stringify(chunkBodies));
        chunkFormData.append('is_final_batch', isLastBatch);
        chunkFormData.append('accumulated_reports', JSON.stringify(accumulatedReports));

        let fileInput = document.getElementById('additional_attachments');
        if (fileInput && fileInput.files.length > 0 && i === 0) {
            for (let f = 0; f < fileInput.files.length; f++) {
                chunkFormData.append('additional_attachments[]', fileInput.files[f]);
            }
        }

        try {
            let response = await fetch('send_bulk_email.php', {
                method: 'POST',
                body: chunkFormData
            });

            if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);

            let data = await response.json();

            if (data.status === 'success') {
                totalSuccess += data.success_count || 0;
                totalFail += data.fail_count || 0;
                totalProcessed += chunkDmIds.length;

                if (data.accumulated_reports) {
                    accumulatedReports = data.accumulated_reports;
                }

                let percent = Math.round((totalProcessed / dmIds.length) * 100);
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressPercent) progressPercent.innerText = percent + '%';
                if (progressText) progressText.innerText = `Processed: ${totalProcessed} / ${dmIds.length} accounts (${percent}%)`;

                if (data.success_details) {
                    data.success_details.forEach(succ => { progressLog.innerHTML += `<div class="text-emerald-400">+ ${succ}</div>`; });
                }
                if (data.failed_details) {
                    data.failed_details.forEach(err => { progressLog.innerHTML += `<div class="text-rose-400">- ${err}</div>`; });
                }
                progressLog.scrollTop = progressLog.scrollHeight;
            } else {
                progressLog.innerHTML += `<div class="text-rose-400">Batch error: ${data.message}</div>`;
            }
        } catch (error) {
            progressLog.innerHTML += `<div class="text-rose-400">Exception: ${error.message}</div>`;
        }
    }

    // Dynamic color coding for Completed text (Green for Success, Red for Fail)
    if (progressText) {
        progressText.innerHTML = `Completed! <span style="color: #10b981; font-weight: bold;">Success: ${totalSuccess}</span>, <span style="color: ${totalFail > 0 ? '#ef4444' : '#64748b'}; font-weight: bold;">Failed: ${totalFail}</span>`;
    }
    if (closeBtn) closeBtn.style.display = 'block';
}

    function closeDispatchModal() {
        const modal = document.getElementById('dispatchProgressModal');
        if (modal) modal.style.display = 'none';
        window.location.reload();
    }

   function updateRecipientEmailAndRefresh(index, value) {
        bulkAccountsCache[index].recipient_email = value;
        
        let acc = bulkAccountsCache[index];
        const tbody = document.getElementById('bulkReviewTableBody');
        
        if (tbody && tbody.rows[index]) {
            const statusCell = tbody.rows[index].cells[0];
            let statusBadge = '';
            
            if (!acc.has_data || !acc.has_dm || acc.dm_id == 0) {
                statusBadge = '<span class="px-2.5 py-1 bg-gray-100 text-gray-600 rounded-lg text-[10px] font-bold block text-center border border-gray-200">⚪ No Data</span>';
            } else if (!acc.recipient_email || !isValidEmail(acc.recipient_email.trim())) {
                statusBadge = '<span class="px-2.5 py-1 bg-rose-50 text-rose-700 rounded-lg text-[10px] font-bold block text-center border border-rose-200">🔴 Missing Email</span>';
            } else if (!acc.has_soa) {
                statusBadge = '<span class="px-2.5 py-1 bg-amber-50 text-amber-700 rounded-lg text-[10px] font-bold block text-center border border-rose-200">🟡 Without SOA</span>';
            } else {
                statusBadge = '<span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-[10px] font-bold block text-center border border-emerald-200">🟢 Ready</span>';
            }
            
            statusCell.innerHTML = statusBadge;
        }

        updateBulkStatusCounters();
    }

   function updateBulkStatusCounters() {
        let readyCount = 0;
        let missingEmailCount = 0;
        let missingSoaCount = 0;
        let noDataCount = 0;

        bulkAccountsCache.forEach(acc => {
            if (!acc.has_data || !acc.has_dm || acc.dm_id == 0) {
                noDataCount++;
            } else if (!acc.recipient_email || !isValidEmail(acc.recipient_email.trim())) {
                missingEmailCount++;
            } else if (!acc.has_soa) {
                missingSoaCount++;
            } else {
                readyCount++;
            }
        });

        document.getElementById('countReady').innerText = readyCount;
        document.getElementById('countMissingEmail').innerText = missingEmailCount;
        document.getElementById('countMissingSOA').innerText = missingSoaCount;
        document.getElementById('countNoData').innerText = noDataCount;
    }

    let currentSortColumn = '';
    let currentSortDirection = 'asc';

    function sortBulkTable(column) {
        if (currentSortColumn === column) {
            currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortColumn = column;
            currentSortDirection = 'asc';
        }

        ['status', 'account', 'coverage'].forEach(col => {
            const iconEl = document.getElementById(`sortIcon_${col}`);
            if (iconEl) iconEl.innerHTML = '';
        });

        const activeIconEl = document.getElementById(`sortIcon_${column}`);
        if (activeIconEl) {
            activeIconEl.innerHTML = currentSortDirection === 'asc' ? '<i class="las la-sort-amount-up"></i>' : '<i class="las la-sort-amount-down"></i>';
        }

        bulkAccountsCache.sort((a, b) => {
            let valA = '', valB = '';

            if (column === 'status') {
                valA = (!a.has_data || !a.has_dm || a.dm_id == 0) ? '3' : (!a.recipient_email || a.recipient_email.trim() === '' ? '0' : (!a.has_soa ? '1' : '2'));
                valB = (!b.has_data || !b.has_dm || b.dm_id == 0) ? '3' : (!b.recipient_email || b.recipient_email.trim() === '' ? '0' : (!b.has_soa ? '1' : '2'));
            } else if (column === 'account') {
                valA = a.account_number || '';
                valB = b.account_number || '';
            } else if (column === 'coverage') {
                valA = a.start_date || '';
                valB = b.start_date || '';
            }

            if (valA < valB) return currentSortDirection === 'asc' ? -1 : 1;
            if (valA > valB) return currentSortDirection === 'asc' ? 1 : -1;
            return 0;
        });

        renderBulkReviewTable();
    }

    function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
</script>
<?php
$content = ob_get_clean();
render_layout($page_title,$content);
?>