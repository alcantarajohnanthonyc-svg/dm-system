<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

session_start();
require_once 'config.php';
require_once 'main.php';
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
// INTEGRATED AJAX HANDLERS (DATI NAKALAGAY SA EMAIL_HUB.PHP)
// -------------------------------------------------------------------------
if (isset($_GET['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    function getDefaultEmailContent($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00') {
        if (function_exists('getEmailTemplate')) {
            $template = getEmailTemplate($account_number, $company, $recipient_name, $employee_id, $mobile_number, $data_coverage, $approved_plan_display, $current_charges_display, $final_dm_val);
            return [
                'subject' => $template['subject'],
                'body'    => $template['body']
            ];
        }
        throw new Exception("Critical Error: email_template.php or getEmailTemplate() function is missing.");
    }

    function checkSharedDriveSOA($conn, $account_number, $dm_id, $start_date, $end_date, $item_ids = []) {
        if (empty($dm_id) || $dm_id == 0) return false;
        
        $soa_query = "SELECT COUNT(*) as cnt 
                      FROM debit_memo_items dmi 
                      JOIN pdf_extracted_details pdf ON pdf.account_number = ? 
                      WHERE dmi.dm_id = ?";
        $soa_params = [$account_number, $dm_id];
        
        if (!empty($item_ids) && is_array($item_ids)) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $soa_query .= " AND dmi.id IN ($placeholders)";
            foreach ($item_ids as $iid) {
                $soa_params[] = (int)$iid;
            }
        } elseif (!empty($start_date) && !empty($end_date)) {
            $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
            array_push($soa_params, $start_date, $end_date);
        }

        $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                        AND (
                            pdf.mobile_number IS NULL 
                            OR pdf.mobile_number = '' 
                            OR pdf.mobile_number = 'N/A' 
                            OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                        )";

        $stmt = $conn->prepare($soa_query);
        $stmt->execute($soa_params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($res && $res['cnt'] > 0);
    }

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

        $approved_plan_display = ($total_approved_plan > 0) ? "₱ " . number_format($total_approved_plan, 2, '.', ',') : "₱ 0.00";
        $current_charges_display = "₱ " . number_format($total_current_charges, 2, '.', ',');
        $total_final_dm_display = number_format($total_final_dm, 2, '.', ',');

        return [
            'data_coverage' => $data_coverage,
            'approved_plan_display' => $approved_plan_display,
            'current_charges_display' => $current_charges_display,
            'total_final_dm' => $total_final_dm_display
        ];
    }

    // 1. GET BY PASTE
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

                            $final_start = !empty($global_start) ? $global_start : ($memo['min_start'] ?? '');
                            $final_end = !empty($global_end) ? $global_end : ($memo['max_end'] ?? '');

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
                            'start_date' => $global_start,
                            'end_date' => $global_end,
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

    // 2. GET BY CSV
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

    // 3. BULK EMAIL PREVIEW
    if ($action === 'get_bulk_email_preview') {
        $input = json_decode(file_get_contents('php://input'), true);
        $accounts_input = isset($input['accounts']) ? $input['accounts'] : [];
        echo json_encode(['status' => 'success', 'accounts' => $accounts_input]);
        exit;
    }

   // 4. SINGLE EMAIL PREVIEW (UPDATED WITH SOA ATTACHMENT FETCHING)
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

        if (!empty($breakdown_item_ids)) {
            $in_clause = implode(',', array_map('intval', $breakdown_item_ids));
            $itemQuery = "SELECT * FROM debit_memo_items WHERE id IN ($in_clause) ORDER BY coverage_start ASC";
            $stmtItems = $conn->prepare($itemQuery);
            $stmtItems->execute();
        } else {
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
        }
        
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $total_final_dm = 0.00;
        $total_current_charges = 0.00;
        $total_approved_plan = 0.00;
        $raw_start_dates = [];
        $raw_end_dates = [];

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

        $approved_plan_display = ($total_approved_plan > 0) ? "₱ " . number_format($total_approved_plan, 2, '.', ',') : "₱ 0.00";
        $current_charges_display = "₱ " . number_format($total_current_charges, 2, '.', ',');
        $total_final_dm_display = number_format($total_final_dm, 2, '.', ',');

        $defaults = getDefaultEmailContent(
            $account_number, 
            $dm['company'] ?? $recipient_name, 
            $recipient_name, 
            $employee_id, 
            $mobile_number, 
            $data_coverage, 
            $approved_plan_display, 
            $current_charges_display, 
            $total_final_dm_display
        );

        // 1. Generate Debit Memo PDF Attachment
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

        // 2. FETCH SOA ATTACHMENT DETAILS FOR PREVIEW
        $soa_query = "
            SELECT DISTINCT pdf.filename 
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
        } elseif (!empty($start_date) && !empty($end_date)) {
            $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
            $soa_params[] = $start_date;
            $soa_params[] = $end_date;
        }

        $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                        AND (
                            pdf.mobile_number IS NULL 
                            OR pdf.mobile_number = '' 
                            OR pdf.mobile_number = 'N/A' 
                            OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                        )";

        $soa_stmt = $conn->prepare($soa_query);
        $soa_stmt->execute($soa_params);
        $soa_files = $soa_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($soa_files as $soa) {
            if (!empty($soa['filename'])) {
                $attachments[] = [
                    'path' => $soa['filename'], // gagamitin bilang identifier sa modal
                    'name' => $soa['filename']
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'recipient_email' => $recipient_email,
            'cc_emails' => '',
            'subject' => $defaults['subject'],
            'html_content' => $defaults['body'],
            'attachments' => $attachments
        ]);
        exit;
    }
}

// -------------------------------------------------------------------------
// AJAX HANDLER FOR ACCOUNT DETAILS (AUTOFILL IN ADD/EDIT MODAL)
// -------------------------------------------------------------------------
if (isset($_GET['account_number'])) {
    $acc = $_GET['account_number'];
    $stmt = $conn->prepare("SELECT company, assignee_name, mobile_number, carrier_name FROM debit_memos dm 
                            JOIN debit_memo_items dmi ON dm.dm_id = dmi.dm_id 
                            WHERE dm.account_number = ? LIMIT 1");
    $stmt->execute([$acc]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit; 
}

// 1. Setup Parameters
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit_input = isset($_GET['limit']) ? $_GET['limit'] : '20';
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_by   = isset($_GET['search_by']) ? trim($_GET['search_by']) : 'all'; 
$carrier     = isset($_GET['carrier']) ? trim($_GET['carrier']) : '';
$start_date  = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date    = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$eff_start = !empty($start_date) ? $start_date : '1900-01-01';
$eff_end   = !empty($end_date) ? $end_date : '2999-12-31';

$limit  = ($limit_input === 'ALL') ? 999999 : (in_array((int)$limit_input, [20, 50, 100]) ? (int)$limit_input : 20);
$offset = ($page - 1) * $limit;


$filteredSubQuery = "SELECT dmi.dm_id, 
                            GROUP_CONCAT(DISTINCT dmi.carrier_name SEPARATOR '|') as carrier_names, 
                            MIN(dmi.coverage_start) as start_date, 
                            MAX(dmi.coverage_end) as end_date, 
                            SUM(CAST(dmi.debit_memo_details AS DECIMAL(10,2))) as filtered_total,
                            COUNT(DISTINCT dmi.id) as total_items_count,
                            SUM(
                                CASE 
                                    WHEN EXISTS (
                                        SELECT 1 
                                        FROM pdf_extracted_details pdf 
                                        JOIN debit_memos dm ON pdf.account_number = dm.account_number
                                        WHERE dm.dm_id = dmi.dm_id 
                                          AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                                          AND (
                                              pdf.mobile_number IS NULL 
                                              OR pdf.mobile_number = '' 
                                              OR pdf.mobile_number = 'N/A' 
                                              OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                                          )
                                    ) THEN 1 
                                    ELSE 0 
                                END
                            ) as matched_soa_count
                     FROM `debit_memo_items` dmi
                     WHERE dmi.coverage_start >= :where_start 
                       AND dmi.coverage_end <= :where_end
                     GROUP BY dmi.dm_id";

$params = [
    'where_start' => $eff_start,
    'where_end'   => $eff_end
];

$whereClause = " WHERE 1=1";
if ($search !== '') {
    if ($search_by === 'account_number') {
        $whereClause .= " AND dm.account_number LIKE :search";
        $params['search'] = "%$search%";
    } elseif ($search_by === 'company') {
        $whereClause .= " AND dm.company LIKE :search";
        $params['search'] = "%$search%";
    } elseif ($search_by === 'carrier') {
        $whereClause .= " AND sub.carrier_names LIKE :search";
        $params['search'] = "%$search%";
    } elseif ($search_by === 'phone_number') {
        $whereClause .= " AND dm.dm_id IN (SELECT dm_id FROM debit_memo_items WHERE mobile_number LIKE :search)";
        $params['search'] = "%$search%";
    } else {
        $whereClause .= " AND (dm.account_number LIKE :search OR dm.company LIKE :search OR sub.carrier_names LIKE :search OR dm.dm_id IN (SELECT dm_id FROM debit_memo_items WHERE mobile_number LIKE :search))";
        $params['search'] = "%$search%";
    }
}

// 3. Count Query
$countSql = "SELECT COUNT(*) 
             FROM `debit_memos` AS dm
             INNER JOIN ($filteredSubQuery) AS sub ON dm.dm_id = sub.dm_id
             $whereClause";

if ($carrier !== '') {
    $countSql .= " AND sub.carrier_names LIKE :carrier";
    $params['carrier'] = "%$carrier%";
}

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_rows = $countStmt->fetchColumn();
$total_pages = ($total_rows > 0) ? ceil($total_rows / $limit) : 1;

// 4. Main Query
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_dir = (isset($_GET['dir']) && $_GET['dir'] === 'asc') ? 'ASC' : 'DESC';

$sort_map = [
    '1' => 'dm.account_number',
    '2' => 'dm.company',
    '3' => 'sub.carrier_names',
    '4' => 'sub.start_date',
    '5' => 'sub.filtered_total'
];
$order_col = isset($sort_map[$sort_by]) ? $sort_map[$sort_by] : 'dm.created_at';

$sql = "SELECT dm.*, sub.carrier_names, sub.start_date, sub.end_date, sub.filtered_total as sum_debit_memo_details, sub.matched_soa_count, sub.total_items_count
        FROM `debit_memos` AS dm
        INNER JOIN ($filteredSubQuery) AS sub ON dm.dm_id = sub.dm_id$whereClause";

if ($carrier !== '') {
    $sql .= " AND sub.carrier_names LIKE :carrier";
}

$sql .= " ORDER BY $order_col $sort_dir LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$memos = $stmt->fetchAll();

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
    #dmListTable { table-layout: fixed; width: 100%; }
    #dmListTable th, #dmListTable td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
</style>

<div class="fixed-layout-container p-2">
    <div class="bg-white p-2 rounded-lg shadow-sm border border-gray-100 mb-0">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-3 items-center justify-between">
            <div class="relative inline-block text-left">
                <button type="button" onclick="toggleMainGearDropdown(event)" class="px-3 py-1.5 bg-slate-100 text-slate-700 border border-slate-300 rounded-md text-[10px] font-bold hover:bg-slate-200 flex items-center gap-1">
                    <i class="las la-cog text-base"></i> Actions
                </button>

                <div id="mainGearDropdown" class="hidden absolute left-0 mt-1 w-52 bg-white border border-gray-200 rounded-xl shadow-xl z-50 py-1.5 overflow-hidden">
                    <?php if ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin'): ?>
                        <button type="button" onclick="openAddEditModal(activeDmId || ''); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-indigo-700 font-semibold hover:bg-indigo-50 hover:text-indigo-900 flex items-center transition-colors border-l-4 border-transparent hover:border-indigo-600">
                            <i class="las la-plus mr-2.5 text-base text-indigo-500"></i> Add New Entry
                        </button>
                        <button type="button" onclick="window.location.href='import_dm.php'" class="w-full text-left px-4 py-2.5 text-xs text-emerald-700 font-semibold hover:bg-emerald-50 hover:text-emerald-900 flex items-center transition-colors border-l-4 border-transparent hover:border-emerald-600">
                            <i class="las la-file-import mr-2.5 text-base text-emerald-500"></i> Import Data
                        </button>
                    <?php endif; ?>

                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button" onclick="sendEmailSelected(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-amber-700 font-semibold hover:bg-amber-50 hover:text-amber-900 flex items-center transition-colors border-l-4 border-transparent hover:border-amber-600">
                        <i class="las la-paper-plane mr-2.5 text-base text-amber-500"></i> Send Selected Email
                    </button>
                    <button type="button" onclick="openEmailHubModal(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-teal-700 font-semibold hover:bg-teal-50 hover:text-teal-900 flex items-center transition-colors border-l-4 border-transparent hover:border-teal-600">
                        <i class="las la-paste mr-2.5 text-base text-teal-500"></i> Email Hub (Paste/CSV)
                    </button>
                    <div class="border-t border-gray-100 my-1"></div>

                    <button type="button" onclick="bulkExportPDF(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-rose-700 font-semibold hover:bg-rose-50 hover:text-rose-900 flex items-center transition-colors border-l-4 border-transparent hover:border-rose-600">
                        <i class="las la-file-export mr-2.5 text-base text-rose-500"></i> Export PDF
                    </button>

                    <button type="button" onclick="document.getElementById('pasteExportModal').classList.remove('hidden'); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-blue-700 font-semibold hover:bg-blue-50 hover:text-blue-900 flex items-center transition-colors border-l-4 border-transparent hover:border-blue-600">
                        <i class="las la-clipboard-list mr-2.5 text-base text-blue-500"></i> Paste Export
                    </button>

                    <?php if ($_SESSION['role'] === 'superadmin'): ?>
                        <div class="border-t border-gray-100 my-1"></div>
                        <button type="button" onclick="deleteSelected(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-red-700 font-semibold hover:bg-red-50 hover:text-red-900 flex items-center transition-colors border-l-4 border-transparent hover:border-red-600">
                            <i class="las la-trash mr-2.5 text-base text-red-500"></i> Delete Selected
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex gap-1 w-full md:w-auto items-center">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="px-2 py-1.5 border rounded-md text-[10px] w-full md:w-55 bg-gray-50" placeholder="Search by mobile number or details...">

                <div class="flex items-center gap-1">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="px-2 py-1.5 border rounded-md text-[10px] bg-gray-50">
                    <span class="text-[9px] font-bold text-gray-400">TO</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="px-2 py-1.5 border rounded-md text-[10px] bg-gray-50">
                </div>

                <select name="search_by" class="px-1 py-1.5 border rounded-md text-[10px] bg-gray-50">
                    <option value="all" <?= (isset($search_by) && $search_by == 'all') ? 'selected' : '' ?>>All Fields</option>
                    <option value="account_number" <?= (isset($search_by) && $search_by == 'account_number') ? 'selected' : '' ?>>Account Number</option>
                    <option value="company" <?= (isset($search_by) && $search_by == 'company') ? 'selected' : '' ?>>Company</option>
                    <option value="carrier" <?= (isset($search_by) && $search_by == 'carrier') ? 'selected' : '' ?>>Carrier</option>
                    <option value="phone_number" <?= (isset($search_by) && $search_by == 'phone_number') ? 'selected' : '' ?>>Phone Number</option>
                </select>

                <button type="submit" class="bg-slate-800 text-white px-3 py-1.5 rounded-md text-[10px] font-bold hover:bg-slate-900">Apply</button>
                
                <a href="list_dm.php" title="Reset Filters" class="px-2 py-1.5 bg-gray-100 text-gray-600 border border-gray-300 rounded-md text-[10px] font-bold hover:bg-gray-200 flex items-center">
                    <i class="las la-redo-alt text-base"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="flex flex-wrap justify-between items-center my-0 gap-0 bg-white p-1 rounded-lg border border-gray-100 shadow-sm">
  <form method="GET" action="list_dm.php" class="flex items-center gap-2">
    <input type="hidden" name="search_by" value="<?= htmlspecialchars($search_by) ?>">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="carrier" value="<?= htmlspecialchars($carrier) ?>">
    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">

    <span class="text-[11px] font-bold text-gray-500 uppercase">Show</span>
    <select name="limit" onchange="this.form.submit()" class="px-1 py-1 border rounded-md text-xs bg-gray-50">
        <option value="20"  <?= $limit_input == '20'  ? 'selected' : '' ?>>20</option>
        <option value="50"  <?= $limit_input == '50'  ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $limit_input == '100' ? 'selected' : '' ?>>100</option>
        <option value="ALL" <?= $limit_input == 'ALL' ? 'selected' : '' ?>>All</option>
    </select>
  </form>

  <nav class="inline-flex items-center -space-x-px">
    <?php
    $query_params = http_build_query([
        'limit'      => $limit_input,
        'search'     => $search,
        'carrier'    => $carrier,
        'start_date' => $start_date,
        'end_date'   => $end_date
    ]);
    ?>
    <a href="?page=1&<?= $query_params ?>" class="px-3 py-1 text-xs text-gray-500 bg-white border border-gray-300 rounded-l-md hover:bg-gray-100">First</a>
    
    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $start + 4);
    if ($end - $start < 4) $start = max(1, $end - 4);
    
    for ($i = $start; $i <= $end; $i++): ?>
        <a href="?page=<?= $i ?>&<?= $query_params ?>" 
           class="px-3 py-1 text-xs border border-gray-300 <?= $page == $i ? 'text-white bg-blue-600' : 'text-gray-500 bg-white hover:bg-gray-100' ?>">
           <?= $i ?>
        </a>
    <?php endfor; ?>
    
    <a href="?page=<?= $total_pages ?>&<?= $query_params ?>" class="px-3 py-1 text-xs text-gray-500 bg-white border border-gray-300 rounded-r-md hover:bg-gray-100">Last</a>
  </nav>
</div>

<?php
$current_params = [
    'search' => $search,
    'carrier' => $carrier,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'limit' => $limit_input
];
$base_query = http_build_query($current_params);
?>

<div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden flex flex-col h-[calc(100vh-180px)]">
    <div class="overflow-y-auto flex-grow">
        <table id="dmListTable" class="w-full text-left border-collapse">
            <thead class="bg-gray-50">
                <tr class="border-b text-gray-900 text-xs font-bold uppercase tracking-wider">
                    <th class="pl-6 py-3 w-10 sticky top-0 bg-gray-50 z-10">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" onclick="window.location='?sort=1&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
                        Account Number &uarr;&darr;
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" onclick="window.location='?sort=2&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
                        Company &uarr;&darr;
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" onclick="window.location='?sort=3&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
                        Carrier &uarr;&darr;
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" onclick="window.location='?sort=4&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
                        Date Covered &uarr;&darr;
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 text-right cursor-pointer hover:text-black" onclick="window.location='?sort=5&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
                        PROCESSED DM &uarr;&darr;
                    </th>
                    <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 text-center">SOA</th>
                    <th class="pr-3 py-3 sticky top-0 bg-gray-50 z-10 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm text-gray-600">
                <?php 
                function getCarrierBadge($carrierName) {
                    $name = strtoupper($carrierName);
                    $colors = [
                        'GLOBE'    => 'bg-blue-100 text-blue-700 border-blue-200',
                        'SMART'    => 'bg-green-100 text-green-700 border-green-200',
                        'DITO'     => 'bg-red-100 text-red-700 border-red-200',
                        'CONVERGE' => 'bg-purple-100 text-purple-700 border-purple-200'
                    ];
                    $shortName = 'OTHER';
                    if (strpos($name, 'GLOBE') !== false) $shortName = 'GLOBE';
                    elseif (strpos($name, 'SMART') !== false) $shortName = 'SMART';
                    elseif (strpos($name, 'DITO') !== false) $shortName = 'DITO';
                    elseif (strpos($name, 'CONVERGE') !== false) $shortName = 'CONVERGE';

                    $style = isset($colors[$shortName]) ? $colors[$shortName] : 'bg-gray-100 text-gray-600 border-gray-200';
                    return '<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase border ' . $style . '">' . $shortName . '</span>';
                }
                foreach ($memos as $row): 
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="pl-6 py-3">
                        <input type="checkbox" class="dm-checkbox" value="<?= $row['dm_id'] ?>">
                    </td>
                    <td class="py-3 px-4 font-semibold text-gray-900">
                        <span title="Company: <?= htmlspecialchars($row['company']) ?>&#10;Assignee: <?= htmlspecialchars($row['assignee_name']) ?>">
                            <?= htmlspecialchars($row['account_number']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-4"><?= htmlspecialchars($row['company']) ?></td>
                    <td class="py-3 px-4 space-y-1">
                        <?php 
                        $carriers = explode('|', $row['carrier_names']);
                        foreach ($carriers as $c) {
                            $trimmedCarrier = trim($c);
                            if (!empty($trimmedCarrier)) {
                                echo getCarrierBadge($trimmedCarrier) . ' ';
                            }
                        }
                        ?>
                    </td>
                    <td class="py-3 px-4 text-[11px] font-bold text-gray-500 uppercase whitespace-nowrap">
                        <?= $row['start_date'] ? date('F d Y', strtotime($row['start_date'])) : '-' ?> <br> to <br> <?= $row['end_date'] ? date('F d Y', strtotime($row['end_date'])) : '-' ?>
                    </td>
                    <td class="py-3 px-4 text-right font-bold text-rose-600">
                        <?php 
                            $total = isset($row['sum_debit_memo_details']) ? $row['sum_debit_memo_details'] : 0;
                            echo '₱' . number_format($total, 2); 
                        ?>
                    </td>
                   <td class="py-3 px-4 text-center">
                        <?php 
                        $matched_soa = isset($row['matched_soa_count']) ? (int)$row['matched_soa_count'] : 0;
                        $total_items = isset($row['total_items_count']) ? (int)$row['total_items_count'] : 0;

                        if ($total_items > 0): 
                            if ($matched_soa > 0):
                        ?>
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-green-100 text-green-700 border border-green-200">
                                    <?= $matched_soa ?>/<?= $total_items ?> SOA
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    0/<?= $total_items ?> SOA
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-gray-100 text-gray-500 border border-gray-200">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="pr-6 py-3 text-center">
                        <div class="flex items-center justify-center gap-4">
                            <button type="button" onclick="openBreakdownModal(event, <?= (int)$row['dm_id'] ?>, '<?= htmlspecialchars($row['account_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($row['company'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(isset($row['assignee_name']) ? $row['assignee_name'] : '', ENT_QUOTES, 'UTF-8') ?>')" class="text-blue-600 hover:text-blue-800 transition">
                                <i class="las la-list-alt text-xl"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- BREAKDOWN MODAL -->
<div id="breakdownModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-[98%] max-w-[1600px] max-h-[90vh] flex flex-col p-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h3 id="modalAccountNumber" class="font-bold text-xl"></h3>
                <p id="modalCompany" class="text-sm text-gray-500"></p>
            </div>
            <div class="flex gap-2">
                <?php if ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin'): ?>
                    <button type="button" onclick="deleteSelectedItems()" class="px-4 py-2 bg-rose-50 text-rose-700 border border-rose-200 rounded-md text-xs font-bold hover:bg-rose-100">
                        <i class="las la-trash mr-1"></i> DELETE SELECTED
                    </button>
                <?php endif; ?>
                <button type="button" onclick="sendEmailBreakdownSelected()" class="bg-amber-600 text-white px-4 py-2 rounded text-xs font-bold hover:bg-amber-700 flex items-center gap-1">
        <i class="las la-paper-plane text-sm"></i> SEND EMAIL
    </button>

                <button type="button" onclick="exportSelectedItems()" class="bg-green-600 text-white px-4 py-2 rounded text-xs font-bold hover:bg-green-700">
                    EXPORT SELECTED
                </button>
                <button type="button" onclick="closeBreakdownModal(event)" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-xs font-bold">
                    CLOSE
                </button>
            </div>
        </div>

        <div class="overflow-x-auto border border-gray-300 rounded-lg flex-grow">
            <table class="w-full text-left border-separate border-spacing-0 text-[10px]">
                <thead class="text-black uppercase font-bold sticky top-0 z-10" style="background-color: #4A86E8;">
                    <tr class="border-b border-black">
                        <th class="p-2 border border-gray-300 text-center"><input type="checkbox" id="modalSelectAll" onclick="toggleModalCheckboxes(this)"></th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #FFE599;">Coverage Date</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">Mobile Number</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">Approved Plan</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">MSF (GLOBE / MRC (SMART)</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">Debit Adj</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">Credit Adj</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">OTHER CHARGES / PHONE AMORTIZATION</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Local (Call/Text)</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">NDD (National)</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">IDD (International)</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Roam</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">SMS</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">GPRS</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Wiz Usage</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Loading Charges</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">VAT</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">OCT</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Current Charges</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #4A86E8;">Total Amount Due</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">PROCESSED DM</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">SYSTEM GENERATED DM</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #F7F700;">DIFFERENCE<br><span class="text-[9px] font-normal">(PROCESSED DM - SYSTEM GENERATED DM)</span></th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">ADD ONS</th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #F7F700;">FINAL DM<br><span class="text-[9px] font-normal">(PROCESSED DM - ADD ONS)</span></th>
                        <th class="p-2 border border-gray-300 whitespace-normal text-center" style="background-color: #93C47D;">SOA</th>
                        <th class="p-2 border border-gray-300 text-center">ACTION</th>
                    </tr>
                </thead>
                <tbody id="modalContentBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- PASTE EXPORT MODAL -->
<div id="pasteExportModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-[90%] max-w-lg p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-bold text-lg">Export Paste (Account/Mobile)</h3>
            <button type="button" onclick="closePasteModal()" class="text-gray-500">✕</button>
        </div>
        <form action="export_paste.php" method="POST" id="pasteExportForm">
            <span id="accountCount" class="text-xs font-bold text-blue-600 block mb-1">0 items found</span>
            <textarea id="exportPasteArea" name="accounts" oninput="updateCount()" class="w-full h-40 p-3 border rounded-md text-sm mb-4 bg-gray-50" placeholder="Paste account numbers..." required></textarea>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">START DATE</label>
                    <input type="date" name="startDate" id="exportStartDate" value="" class="w-full p-2 border rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">END DATE</label>
                    <input type="date" name="endDate" id="exportEndDate" value="" class="w-full p-2 border rounded-md text-sm">
                </div>
            </div>
            <input type="hidden" name="export_type" id="pasteExportType" value="excel">
            <button type="button" onclick="processExportPaste()" class="w-full bg-slate-800 text-white py-2.5 rounded-md font-bold text-sm hover:bg-slate-900">
                Export Records
            </button>
        </form>
    </div>
</div>

<!-- ADD/EDIT MODAL -->
<div id="addEditModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden border border-slate-100">
        
        <div class="px-8 py-5 bg-slate-900 text-white flex items-center justify-between">
            <div>
                <h3 id="modalFormTitle" class="font-bold text-lg tracking-wide">Add Debit Memo Item</h3>
                <p class="text-xs text-slate-400 mt-0.5">Fill in the account details and financial breakdown below.</p>
            </div>
            <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-white transition p-2 rounded-lg hover:bg-slate-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="addEditForm" method="POST" action="save_record.php" novalidate class="flex-1 overflow-y-auto p-8 space-y-6">
            <input type="hidden" id="form_dm_id" name="id" value="">

            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Account Information</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200/60">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Account #</label>
                        <input type="text" name="account_number" list="acc_list" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" onchange="fetchAccountDetails(this.value)" onblur="fetchAccountDetails(this.value)" placeholder="Search/Select..." required>
                        <div id="accFeedback" class="text-[10px] font-medium mt-1"></div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Company</label>
                        <input type="text" name="company" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" required>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Assignee</label>
                        <input type="text" name="assignee" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Mobile #</label>
                        <input type="text" name="mobile_number" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" required>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Carrier</label>
                        <input type="text" name="carrier" list="carrier_input" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" required>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Coverage Period</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200/60">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Start Date</label>
                        <input type="date" name="coverage_start" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" required>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">End Date</label>
                        <input type="date" name="coverage_end" class="w-full p-2.5 bg-white border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-slate-900 focus:outline-none transition" required>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Financial Breakdown & Summary Columns</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3.5 bg-slate-50 p-4 rounded-xl border border-slate-200/60">
                    <?php 
                    $amounts = [
                        'approve_plan' => 'Approved Plan',
                        'phone_amort' => 'Phone Amortization', 
                        'debit_adj' => 'Debit Adj',
                        'credit_adj' => 'Credit Adj',
                        'other_charges' => 'Other Charges', 
                        'local' => 'Local (Call/Text)',
                        'ndd' => 'NDD (National)', 
                        'idd' => 'IDD (International)', 
                        'roam' => 'Roam', 
                        'sms' => 'SMS',
                        'gprs' => 'GPRS',
                        'wiz_usage' => 'Wiz Usage', 
                        'loading' => 'Loading Charges',
                        'vat' => 'VAT', 
                        'oct' => 'Overseas Comm. Tax',
                        'current_charges' => 'Current Charges', 
                        'total_amount_due' => 'Total Amount Due',
                        'debit_memo_details' => 'Processed DM',
                        'system_generated_dm' => 'System Generated DM',
                        'difference' => 'Difference (Proc - Sys)',
                        'add_ons' => 'Add Ons',
                        'final_dm' => 'Final DM (Proc - Add)'
                    ];

                    foreach ($amounts as $name => $label): 
                        $isComputed = in_array($name, ['difference', 'final_dm', 'system_generated_dm']);
                        $isProcessed = ($name === 'debit_memo_details');
                        
                        $bgClass = 'bg-white';
                        if ($isComputed) $bgClass = 'bg-yellow-50/60 border-yellow-200/80';
                        if ($isProcessed) $bgClass = 'bg-emerald-50/60 border-emerald-200/80';
                    ?>
                        <div class="p-2.5 rounded-lg border border-slate-200/80 <?= $bgClass ?>">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-tight mb-1 leading-snug"><?= $label ?></label>
                            <input type="number" step="0.01" name="<?= $name ?>" id="field_<?= $name ?>" 
                                   class="w-full p-2 bg-white border border-slate-200 rounded-md text-xs font-semibold text-slate-700 focus:ring-2 focus:ring-slate-900 focus:outline-none transition" 
                                   value="0.00" <?= ($name === 'difference' || $name === 'final_dm') ? 'readonly tabindex="-1"' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex justify-end items-center gap-3 pt-6 border-t border-slate-100">
                <button type="button" onclick="closeModal()" class="px-6 py-2.5 bg-slate-100 text-slate-700 rounded-xl text-xs font-bold hover:bg-slate-200 transition">CANCEL</button>
                <button type="submit" class="px-6 py-2.5 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 shadow-lg shadow-slate-900/10 transition">SAVE ITEM</button>
            </div>
        </form>
    </div>
</div>

<!-- UNIFIED EXPORT CHOICE MODAL -->
<div id="unifiedExportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: #ffffff; padding: 30px; border-radius: 16px; width: 360px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); text-align: center; position: relative; border: 1px solid #f1f5f9;">
        <button onclick="closeUnifiedExportModal()" style="position: absolute; top: 14px; right: 16px; background: #f8fafc; border: none; color: #64748b; width: 28px; height: 28px; border-radius: 50%; font-size: 16px; cursor: pointer; display: flex; items-center; justify-content: center;">&times;</button>
        <div style="width: 48px; height: 48px; background: #eff6ff; color: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 20px;">
            <i class="las la-file-export"></i>
        </div>
        <h3 style="color: #0f172a; font-size: 16px; font-weight: 600; margin-bottom: 8px;">Export Options</h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 24px;">Choose your preferred file format to continue.</p>
        <div style="display: flex; gap: 12px;">
            <button onclick="executeUnifiedExport('pdf')" style="flex: 1; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 10px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="las la-file-pdf" style="font-size: 16px;"></i> PDF
            </button>
            <button onclick="executeUnifiedExport('excel')" style="flex: 1; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 10px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="las la-file-excel" style="font-size: 16px;"></i> Excel
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- EMBEDDED EMAIL HUB MODALS (BULK REVIEW, PREVIEW, PROGRESS & LOADER)      -->
<!-- ========================================================================= -->

<!-- 1. BULK EMAIL REVIEW MODAL -->
<div id="bulkEmailReviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-6xl p-6 mx-4 flex flex-col max-h-[90vh]">
        <div class="flex justify-between items-start pb-4 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                    <i class="las la-envelope-open-text text-amber-600 text-xl"></i> Bulk Email Review & Validation
                </h3>
                <p id="bulkSummaryCount" class="text-xs text-gray-500 font-medium mt-0.5">0 accounts loaded for review</p>
            </div>
            <button type="button" onclick="closeBulkEmailReviewModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-700 hover:bg-gray-200 transition-all font-bold">✕</button>
        </div>

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
                            Coverage Dates <span id="sortIcon_coverage" class="text-amber-600 ml-0.5"></span>
                        </th>
                        <th class="p-3">Recipient Email</th>
                        <th class="p-3">CC Emails</th>
                        <th class="p-3 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="bulkReviewTableBody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>

        <div class="flex justify-end gap-3 mt-4 pt-3 border-t border-gray-100">
            <button type="button" onclick="closeBulkEmailReviewModal()" class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-200 transition-all">Cancel</button>
            <button type="button" onclick="proceedBulkDispatchFromModal()" class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-2">
                <i class="las la-paper-plane text-sm"></i> Confirm & Send Emails
            </button>
        </div>
    </div>
</div>

<!-- 2. SINGLE EMAIL PREVIEW MODAL -->
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

            <div class="flex flex-col flex-grow">
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                    <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                        <button type="button" onclick="switchPreviewMode('visual')" id="btnVisualTab" class="px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all">Visual Preview</button>
                        <button type="button" onclick="switchPreviewMode('code')" id="btnCodeTab" class="px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all">HTML Code</button>
                    </div>
                </div>

                <div id="visualPreviewContainer" class="w-full p-4 border rounded-xl bg-white shadow-inner overflow-y-auto max-h-72"></div>
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

<!-- 3. DISPATCH PROGRESS TERMINAL MODAL -->
<div id="dispatchProgressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-4xl p-6 md:p-8 mx-4 flex flex-col max-h-[85vh] overflow-hidden">
        <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-4 shrink-0">
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
    
        <div class="overflow-y-auto flex-grow space-y-4 pr-1">
            <div class="bg-slate-50/80 border border-slate-200/80 p-4 rounded-2xl">
                <div class="flex justify-between items-center mb-2">
                    <span id="dispatchProgressText" class="text-sm md:text-base font-extrabold text-slate-900">Initializing dispatch process...</span>                
                    <span id="dispatchProgressPercent" class="text-xs font-extrabold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">0%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3.5 overflow-hidden p-0.5 border border-slate-300 shadow-inner">
                    <div id="dispatchProgressBar" class="bg-gradient-to-r from-emerald-500 to-teal-500 h-2.5 rounded-full transition-all duration-400 shadow-sm" style="width: 0%"></div>
                </div>
            </div>

            <div class="flex flex-col min-h-[260px] max-h-[320px] bg-slate-950 text-slate-200 text-xs font-mono p-4 rounded-2xl shadow-inner border border-slate-800 overflow-hidden">
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800/80 text-[11px] text-slate-400 uppercase tracking-wider font-bold shrink-0">
                    <span>Live Terminal Activity Log</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Live</span>
                </div>
                <div id="dispatchProgressLog" class="flex-grow overflow-y-auto space-y-1.5 pr-2 custom-scrollbar">
                    <div class="text-slate-500 italic">Waiting for batch dispatch to start...</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4 mt-4 border-t border-gray-100 shrink-0">
            <button type="button" id="closeDispatchModalBtn" onclick="closeDispatchModal()" style="display:none;" class="px-6 py-2.5 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 shadow-lg shadow-slate-900/20 transition-all flex items-center gap-2">
                <i class="las la-check-circle text-base"></i> Close & Reload Page
            </button>
        </div>
    </div>
</div>

<!-- 4. FULL-PAGE LOADER OVERLAY -->
<div id="pageLoaderOverlay" style="display: none;" class="fixed inset-0 z-[999999] flex flex-col items-center justify-center bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white p-6 rounded-2xl shadow-2xl flex flex-col items-center space-y-3 border border-slate-100">
        <i class="las la-spinner animate-spin text-4xl text-amber-600"></i>
        <p class="text-xs font-bold text-slate-700 tracking-wide uppercase">Processing accounts, please wait...</p>
    </div>
</div>

<!-- 5. EMAIL HUB INPUT MODAL (PASTE / CSV) -->
<div id="emailHubInputModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-2xl p-6 mx-4 flex flex-col">
        
        <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
            <div>
                <h3 class="text-base font-bold text-gray-800 uppercase tracking-wider">Email Hub</h3>
                <p class="text-[11px] text-gray-500">Manage bulk emailing via pasted accounts or CSV upload.</p>
            </div>
            <button type="button" onclick="closeEmailHubModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-700 hover:bg-gray-200 transition-all font-bold">✕</button>
        </div>

        <div class="flex border-b border-gray-200 mb-4">
            <button type="button" onclick="switchEmailHubTab(1)" id="tabBtn1" class="flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider">
                Paste Accounts / Emails
            </button>
            <button type="button" onclick="switchEmailHubTab(2)" id="tabBtn2" class="flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider">
                Upload CSV
            </button>
        </div>

        <!-- TAB 1: PASTE -->
        <div id="emailHubTab1" class="space-y-3">
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Accounts or Email List</label>
                    <span id="pasteItemCount" class="text-xs font-semibold text-blue-600">0 items found</span>
                </div>
                <textarea id="pasteAccountInput" rows="6" oninput="updateEmailHubPasteCount()" class="w-full p-3 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none resize-none bg-gray-50 font-mono" placeholder="Paste account numbers per line..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3 p-3 bg-gray-50 rounded-xl border border-gray-200">
                <div>
                    <label class="block text-[10px] font-bold text-gray-600 uppercase mb-1">Start Date (Optional)</label>
                    <input type="date" id="hubStartDate" class="w-full p-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-600 uppercase mb-1">End Date (Optional)</label>
                    <input type="date" id="hubEndDate" class="w-full p-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 shadow-sm">
                </div>
            </div>

            <button type="button" onclick="processPasteEmailHub()" class="w-full py-2.5 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-2 flex items-center justify-center gap-2">
                <i class="las la-paper-plane text-sm"></i> Process and Review Emails
            </button>
        </div>

        <!-- TAB 2: CSV UPLOAD -->
        <div id="emailHubTab2" class="space-y-3" style="display: none;">
            <div class="p-6 border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 text-center flex flex-col items-center justify-center py-6">
                <i class="las la-cloud-upload-alt text-4xl text-amber-600 mb-2"></i>
                <h4 class="font-bold text-xs text-gray-700 mb-1 uppercase">Upload CSV File</h4>
                <p class="text-[11px] text-gray-500 mb-3">Drag your CSV file here or click to browse.</p>
                <input type="file" id="csvEmailFile" accept=".csv" class="hidden" onchange="handleCsvFileSelect(this)">
                <button type="button" onclick="document.getElementById('csvEmailFile').click()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-xl text-xs font-semibold hover:bg-gray-100 shadow-sm">
                    Browse File
                </button>
                <span id="selectedCsvFileName" class="text-xs text-emerald-600 font-medium mt-2"></span>
            </div>

            <div class="flex items-center justify-between p-3 bg-amber-50 border border-amber-200 rounded-xl">
                <div>
                    <h5 class="text-xs font-bold text-amber-900">Need a template?</h5>
                    <p class="text-[10px] text-amber-700">Download the CSV template format.</p>
                </div>
                <button type="button" onclick="downloadEmailCsvTemplate()" class="px-3 py-1.5 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 shadow-sm whitespace-nowrap">
                    Download Template
                </button>
            </div>

            <button type="button" onclick="processCsvEmailHub()" class="w-full py-2.5 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-2 flex items-center justify-center gap-2">
                <i class="las la-upload text-sm"></i> Upload and Process CSV
            </button>
        </div>

    </div>
</div>

<script>
let currentExportContext = ''; 
let activeDmId = 0; 
let hasChanges = false;
let downloadTimer;
let bulkAccountsCache = [];

// -------------------------------------------------------------------------
// EXPORT AND BREAKDOWN HANDLERS
// -------------------------------------------------------------------------
function bulkExportPDF() {
    let checkboxes = document.querySelectorAll('.dm-checkbox:checked');
    if (checkboxes.length === 0) return alert("Please select at least one account.");
    currentExportContext = 'bulk';
    document.getElementById('unifiedExportModal').style.display = 'flex';
}

function updateCount() {
    const textarea = document.getElementById('exportPasteArea');
    const countSpan = document.getElementById('accountCount');
    if (!textarea || !countSpan) return;

    const rawText = textarea.value;
    const lines = rawText.split('\n');
    const validAccounts = new Set();

    lines.forEach(line => {
        const trimmed = line.trim();
        if (trimmed !== '') {
            const parts = trimmed.split(/\s+/);
            const token = parts[0];
            if (/^\d{5,15}$/.test(token)) {
                validAccounts.add(token);
            }
        }
    });

    const count = validAccounts.size;
    countSpan.innerText = `${count} item${count === 1 ? '' : 's'} found`;
}

function processExportPaste() {
    const rawData = document.getElementById('exportPasteArea').value;
    if (!rawData.trim()) return alert("No data pasted to export.");
    currentExportContext = 'paste';
    document.getElementById('unifiedExportModal').style.display = 'flex';
}

function exportSelectedItems() {
    const checkedBoxes = document.querySelectorAll('.item-checkbox:checked');
    if (checkedBoxes.length === 0) return alert("Please select at least one record to export.");
    currentExportContext = 'breakdown_selected';
    document.getElementById('unifiedExportModal').style.display = 'flex';
}

function exportBreakdown() {
    if (activeDmId > 0) {
        currentExportContext = 'breakdown_single';
        document.getElementById('unifiedExportModal').style.display = 'flex';
    } else {
        alert("Error: No account selected.");
    }
}

function openPasteExportModal() {
    const pasteArea = document.getElementById('exportPasteArea');
    const startDate = document.getElementById('exportStartDate');
    const endDate = document.getElementById('exportEndDate');
    const countSpan = document.getElementById('accountCount');

    if (pasteArea) pasteArea.value = '';
    if (startDate) startDate.value = '';
    if (endDate) endDate.value = '';
    if (countSpan) countSpan.innerText = '0 items found';

    document.getElementById('pasteExportModal').classList.remove('hidden');
}

function closePasteModal() {
    document.getElementById('pasteExportModal').classList.add('hidden');
    const pasteArea = document.getElementById('exportPasteArea');
    const startDate = document.getElementById('exportStartDate');
    const endDate = document.getElementById('exportEndDate');
    const countSpan = document.getElementById('accountCount');

    if (pasteArea) pasteArea.value = '';
    if (startDate) startDate.value = '';
    if (endDate) endDate.value = '';
    if (countSpan) countSpan.innerText = '0 items found';
}

function closeUnifiedExportModal() {
    document.getElementById('unifiedExportModal').style.display = 'none';
}

function executeUnifiedExport(format) {
    closeUnifiedExportModal();
    let startDate = document.querySelector('input[name="start_date"]') ? document.querySelector('input[name="start_date"]').value : '';
    let endDate = document.querySelector('input[name="end_date"]') ? document.querySelector('input[name="end_date"]').value : '';

    if (currentExportContext === 'bulk') {
        let checkboxes = document.querySelectorAll('.dm-checkbox:checked');
        let ids = Array.from(checkboxes).map(cb => cb.value);
        let target = 'export_bulk_pdf.php';
        window.location.href = target + '?type=' + format + '&ids=' + ids.join(',') + '&start_date=' + startDate + '&end_date=' + endDate;

    } else if (currentExportContext === 'paste') {
        let formData = new FormData();
        formData.append('accounts', document.getElementById('exportPasteArea').value);
        formData.append('startDate', document.getElementById('exportStartDate').value);
        formData.append('endDate', document.getElementById('exportEndDate').value);
        
        let target = (format === 'excel') ? 'export_paste_excel.php' : 'export_paste.php';
        fetch(target, { method: 'POST', body: formData })
        .then(res => res.blob())
        .then(blob => {
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = "Export_" + new Date().getTime() + ".zip";
            a.click();
        });

    } else if (currentExportContext === 'breakdown_selected' || currentExportContext === 'breakdown_single') {
        let ids = [];
        if (currentExportContext === 'breakdown_selected') {
            ids = Array.from(document.querySelectorAll('.item-checkbox:checked')).map(cb => cb.value);
        }
        let target = 'export_breakdown_pdf.php';
        window.location.href = target + '?type=' + format + '&dm_id=' + activeDmId + (ids.length ? '&item_ids=' + ids.join(',') : '') + '&start_date=' + startDate + '&end_date=' + endDate;
    }
}

function openBreakdownModal(event, dm_id, accountNum, company, assignee) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    hasChanges = false;
    activeDmId = dm_id; 
    
    const modal = document.getElementById('breakdownModal');
    if (modal) {
        modal.classList.remove('hidden');
    }

    document.getElementById('modalAccountNumber').innerText = "Account Number: " + accountNum;
    document.getElementById('modalCompany').innerText = "Company: " + company;

    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    const startDate = startDateInput ? startDateInput.value : '';
    const endDate = endDateInput ? endDateInput.value : '';

    fetch('get_breakdown.php?dm_id=' + dm_id + '&start=' + encodeURIComponent(startDate) + '&end=' + encodeURIComponent(endDate))
    .then(response => response.text())
    .then(html => {
        document.getElementById('modalContentBody').innerHTML = html;
    })
    .catch(err => {
        console.error("Fetch error:", err);
    });
}

function toggleModalCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = source.checked);
}

function toggleSelectAll(source) {
    document.querySelectorAll('.dm-checkbox').forEach(cb => cb.checked = source.checked);
}

function deleteBreakdownItem(item_id) {
    if (!confirm("Are you sure you want to delete this record?")) return;
    
    fetch('delete_breakdown_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'item_id=' + item_id
    }).then(() => {
        openBreakdownModal(null, activeDmId, '', '', '');
    });
}

function deleteSelectedItems() {
    const modalBody = document.getElementById('modalContentBody');
    const checkedBoxes = modalBody.querySelectorAll('.item-checkbox:checked');
    const accountNum = document.getElementById('modalAccountNumber').innerText;
    
    if (checkedBoxes.length === 0) {
        alert("Please select at least one record to delete.");
        return;
    }
    
    if (!confirm("Are you sure you want to delete these records?")) return;

    const ids = Array.from(checkedBoxes).map(cb => cb.value);

    fetch('delete_bulk_dm.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'item_ids=' + ids.join(',') + '&dm_id=' + activeDmId
    })
    .then(response => response.text())
    .then(rawText => {
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error("Invalid JSON response:", rawText);
            alert("Server Error: Response is not valid JSON.");
            return;
        }

        if (data.status === 'success') {
            if (data.account_deleted) {
                alert("Notification: Account " + accountNum + " has been removed.");
                window.location.reload(); 
            } else {
                const totalDisplay = document.getElementById('totalDebitMemoDisplay');
                if(totalDisplay) {
                    totalDisplay.innerText = '₱' + data.new_total;
                }
                checkedBoxes.forEach(cb => cb.closest('tr').remove());
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => console.error('Fetch Error:', error));
}

function closeBreakdownModal(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const modal = document.getElementById('breakdownModal');
    if (modal) {
        modal.classList.add('hidden');
    }
    if (hasChanges) {
        window.location.reload();
    }
}

function closeModal() {
    document.getElementById('addEditModal').classList.add('hidden');
}

function fetchAccountDetails(accNum) {
    if (!accNum) return;
    const feedback = document.getElementById('accFeedback');
    feedback.innerText = "Searching...";
    feedback.className = "text-[9px] font-bold text-blue-500 mt-1";

    fetch('list_dm.php?account_number=' + encodeURIComponent(accNum))
    .then(response => response.json())
    .then(data => {
        const form = document.getElementById('addEditForm');
        if (data && data.company) {
            form.querySelector('input[name="company"]').value = data.company;
            form.querySelector('input[name="assignee"]').value = data.assignee_name;
            form.querySelector('input[name="mobile_number"]').value = data.mobile_number;
            form.querySelector('input[name="carrier"]').value = data.carrier_name;
            
            feedback.innerText = "✓ Found";
            feedback.className = "text-[9px] font-bold text-green-600 mt-1";
        } else {
            form.querySelector('input[name="company"]').value = '';
            form.querySelector('input[name="assignee"]').value = '';
            form.querySelector('input[name="mobile_number"]').value = '';
            form.querySelector('input[name="carrier"]').value = '';
            
            feedback.innerText = "⚠ New Account - Please enter details";
            feedback.className = "text-[9px] font-bold text-amber-600 mt-1";
        }
    });
}

document.addEventListener('focusin', function(e) {
    if (e.target.tagName === 'INPUT' && e.target.type === 'number') {
        if (e.target.value === '0.00') {
            e.target.value = '';
        }
    }
});

document.addEventListener('focusout', function(e) {
    if (e.target.tagName === 'INPUT' && e.target.type === 'number') {
        if (e.target.value === '' || e.target.value === null) {
            e.target.value = '0.00';
        }
    }
});

function loadBreakdown(dm_id) {
    activeDmId = dm_id;
    const timestamp = new Date().getTime();
    fetch('get_breakdown.php?dm_id=' + dm_id + '&t=' + timestamp)
    .then(response => response.text())
    .then(html => {
        document.getElementById('modalContentBody').innerHTML = html;
        document.getElementById('breakdownModal').classList.remove('hidden');
    });
}

document.getElementById('addEditForm').addEventListener('submit', function(e) {
    e.preventDefault(); 
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    
    const requiredFields = ['account_number', 'company', 'mobile_number', 'carrier', 'coverage_start', 'coverage_end'];
    let allFieldsFilled = true;

    requiredFields.forEach(fieldName => {
        const input = this.querySelector(`input[name="${fieldName}"]`);
        if (!input || input.value.trim() === '') {
            allFieldsFilled = false;
        }
    });

    if (!allFieldsFilled) {
        alert("Validation Error: Please fill in all required fields.");
        return;
    }

    const amountInputs = this.querySelectorAll('input[type="number"]');
    let hasAmount = false;
    amountInputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasAmount = true;
        }
    });

    if (!hasAmount) {
        alert("Validation Error: Please enter at least one amount value greater than zero.");
        return;
    }

    submitBtn.innerText = "SAVING...";
    submitBtn.disabled = true;

    const formElement = this;

    fetch('save_record.php', {
        method: 'POST',
        body: new FormData(formElement)
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.innerText = originalText;
        submitBtn.disabled = false;

        if (data.status === 'success') {
            closeModal();
            formElement.reset();
            const formDmIdInput = document.getElementById('form_dm_id');
            if (formDmIdInput) {
                formDmIdInput.value = '';
            }

            if (typeof activeDmId !== 'undefined' && activeDmId > 0) {
                loadBreakdown(activeDmId);
            } else {
                window.location.reload();
            }
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(err => {
        console.error("Submission Error:", err);
        alert("An unexpected error occurred.");
        submitBtn.innerText = originalText;
        submitBtn.disabled = false;
    });
});

function openAddEditModal(dm_id = '') {
    const form = document.getElementById('addEditForm');
    form.reset();
    hasChanges = true;
    const accInput = form.querySelector('input[name="account_number"]');
    accInput.readOnly = false;
    accInput.value = '';

    document.getElementById('modalFormTitle').innerText = "Add Debit Memo Item";
    document.getElementById('form_dm_id').value = dm_id;
    document.getElementById('addEditModal').classList.remove('hidden');
}

function editBreakdownItem(itemId) {
    hasChanges = true;
    fetch('get_item_data.php?id=' + itemId)
    .then(res => res.text())
    .then(text => {
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error("Server returned invalid JSON:", text);
            alert("Error: get_item_data.php did not return valid JSON.");
            return;
        }

        if (!data) return;

        const form = document.getElementById('addEditForm');
        const accInput = form.querySelector('input[name="account_number"]');
        accInput.readOnly = false;

        const mappings = {
            'account_number': data.account_number,
            'company': data.company,
            'assignee': data.assignee_name,
            'mobile_number': data.mobile_number,
            'carrier': data.carrier_name,
            'coverage_start': data.coverage_start,
            'coverage_end': data.coverage_end,
            'approve_plan': data.approved_plan,
            'phone_amort': data.phone_amortization,
            'debit_adj': data.debit_adj,
            'credit_adj': data.credit_adj,
            'other_charges': data.other_charges,
            'local': data.local_call_text,
            'ndd': data.ndd_charges,
            'idd': data.idd_charges,
            'roam': data.roaming_charges,
            'sms': data.sms_charges,
            'gprs': data.gprs_charges,
            'wiz_usage': data.wiz_usage,
            'loading': data.loading_charges,
            'vat': data.vat,
            'oct': data.oct,
            'current_charges': data.current_charges,
            'total_amount_due': data.total_amount_due,
            'debit_memo_details': data.debit_memo_details,
            'system_generated_dm': data.system_generated_dm,
            'add_ons': data.add_ons,          
            'final_dm': data.final_dm         
        };

        for (const [name, value] of Object.entries(mappings)) {
            const input = form.querySelector(`input[name="${name}"]`);
            if (input) {
                input.value = value !== null ? value : '';
            }
        }

        document.getElementById('form_dm_id').value = data.id;
        document.getElementById('modalFormTitle').innerText = "Edit Debit Memo Item";
        accInput.readOnly = true;

        document.getElementById('addEditModal').classList.remove('hidden');
        calculateEditModalValues();
    })
    .catch(err => console.error("Fetch Error:", err));
}

function deleteSelected() {
    const checkboxes = document.querySelectorAll('.dm-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);

    if (ids.length === 0) {
        alert("Please select at least one account to delete.");
        return;
    }

    if (!confirm("Are you sure you want to delete these " + ids.length + " accounts?")) {
        return;
    }

    let formData = new FormData();
    formData.append('dm_ids', ids.join(','));

    fetch('delete_bulk_accounts.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert("Done! The selected accounts have been deleted successfully.");
            window.location.reload(); 
        } else {
            alert("Error: " + data.message);
        }
    });
}

function checkDownloadCookie() {
    if (document.cookie.indexOf('fileDownloadToken=success') !== -1) {
        document.cookie = 'fileDownloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.querySelectorAll('.dm-checkbox').forEach(chk => { chk.checked = false; });
        const selectAllChk = document.getElementById('selectAll');
        if (selectAllChk) selectAllChk.checked = false;

        document.querySelectorAll('.item-checkbox').forEach(chk => { chk.checked = false; });
        const modalSelectAllChk = document.getElementById('modalSelectAll');
        if (modalSelectAllChk) modalSelectAllChk.checked = false;

        clearInterval(downloadTimer);
    }
}

const originalExecuteUnifiedExport = window.executeUnifiedExport;
if (typeof executeUnifiedExport === 'function') {
    window.executeUnifiedExport = function(format) {
        originalExecuteUnifiedExport(format);
        downloadTimer = setInterval(checkDownloadCookie, 500);
    };
}

function calculateEditModalValues() {
    const form = document.getElementById('addEditForm');
    if (!form) return;

    let approvedPlan = parseFloat(form.querySelector('input[name="approve_plan"]').value) || 0;
    let currentCharges = parseFloat(form.querySelector('input[name="current_charges"]').value) || 0;
    let processedDm = parseFloat(form.querySelector('input[name="debit_memo_details"]').value) || 0;
    let addOns = parseFloat(form.querySelector('input[name="add_ons"]').value) || 0;

    let systemGeneratedDm = Math.max(0, currentCharges - approvedPlan); 
    let difference = Math.max(0, processedDm - systemGeneratedDm);      
    let finalDm = processedDm - addOns;                                 

    const sysGenInput = form.querySelector('input[name="system_generated_dm"]');
    const diffInput = form.querySelector('input[name="difference"]');
    const finalInput = form.querySelector('input[name="final_dm"]');

    if (sysGenInput) sysGenInput.value = systemGeneratedDm.toFixed(2);
    if (diffInput) diffInput.value = difference.toFixed(2);
    if (finalInput) finalInput.value = finalDm.toFixed(2);
}

['approve_plan', 'current_charges', 'debit_memo_details', 'add_ons'].forEach(name => {
    const el = document.querySelector(`input[name="${name}"]`);
    if (el) {
        el.removeEventListener('input', calculateEditModalValues);
        el.addEventListener('input', calculateEditModalValues);
    }
});

function toggleMainGearDropdown(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById('mainGearDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

function closeMainGearDropdown() {
    const dropdown = document.getElementById('mainGearDropdown');
    if (dropdown) {
        dropdown.classList.add('hidden');
    }
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('mainGearDropdown');
    if (dropdown && !dropdown.classList.contains('hidden')) {
        if (!e.target.closest('.relative')) {
            dropdown.classList.add('hidden');
        }
    }
});

// -------------------------------------------------------------------------
// DIRECT EMAIL HUB JS LOGIC INTEGRATION
// -------------------------------------------------------------------------
function sendEmailSelected() {
    let checkboxes = document.querySelectorAll('.dm-checkbox:checked');
    if (checkboxes.length === 0) return alert("Please select at least one account.");

    let accountsToProcess = [];
    let globalStart = document.querySelector('input[name="start_date"]')?.value || '';
    let globalEnd = document.querySelector('input[name="end_date"]')?.value || '';

    checkboxes.forEach(cb => {
        let row = cb.closest('tr');
        let accNum = row.querySelector('td:nth-child(2)').innerText.trim();
        
        accountsToProcess.push({
            account_number: accNum,
            start_date: globalStart,
            end_date: globalEnd
        });
    });

    document.getElementById('pageLoaderOverlay').style.display = 'flex';

    // BAGONG URL: list_dm.php
    fetch('list_dm.php?action=get_dm_ids_by_paste', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            pasted_text: accountsToProcess.map(a => a.account_number).join('\n'),
            start_date: globalStart,
            end_date: globalEnd
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
            document.getElementById('pageLoaderOverlay').style.display = 'none';
            alert("No matching email records found.");
        }
    })
    .catch(err => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';
        alert("Error: " + err.message);
    });
}

function openBulkEmailReviewModal(accountsList) {
    // BAGONG URL: list_dm.php
    fetch('list_dm.php?action=get_bulk_email_preview', {
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
    })
    .catch(err => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';
        alert("Error: " + err.message);
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
            statusBadge = '<span class="px-2.5 py-1 bg-amber-50 text-amber-700 rounded-lg text-[10px] font-bold block text-center border border-amber-200">🟡 Without SOA</span>';
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

        switchPreviewMode('visual');

        // BAGONG URL: list_dm.php
        let fetchUrl = `list_dm.php?action=get_email_preview&dm_id=${dmId}&item_ids=${itemIds}&start_date=${cachedAccount.start_date || startDate}&end_date=${cachedAccount.end_date || endDate}`;

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
    const itemIds = document.getElementById('preview_item_ids').value; // <--- Kunin ang item_ids mula sa hidden input

    if (!dmId || dmId == 0) {
        alert("Cannot send email because there is no valid Debit Memo ID.");
        return;
    }

    const form = document.getElementById('emailPreviewForm');
    const formData = new FormData(form);

    const recipientEmail = document.getElementById('preview_to').value.trim();
    const ccEmails = document.getElementById('preview_cc').value.trim();
    const subject = document.getElementById('preview_subject').value.trim();
    const body = document.getElementById('preview_body').value;

    if (!recipientEmail) {
        alert("Please enter a recipient email address.");
        return;
    }

    formData.append('recipient_email', recipientEmail);
    formData.append('cc_emails', ccEmails);
    formData.append('subject', subject);
    formData.append('html_content', body);

    formData.append('dm_ids', dmId);
    formData.append('item_ids', itemIds); // <--- IPASA ANG ITEM_IDS SA DISPATCH
    formData.append('bulk_recipients', JSON.stringify([recipientEmail]));
    formData.append('bulk_ccs', JSON.stringify([ccEmails]));
    formData.append('bulk_subjects', JSON.stringify([subject]));
    formData.append('bulk_bodies', JSON.stringify([body]));

    closeEmailPreviewModal();
    executeDispatchFetch(formData, 1);
}

function proceedBulkDispatchFromModal() {
    let validAccounts = bulkAccountsCache.filter(acc => acc.has_dm && acc.dm_id && acc.dm_id != 0);

    if (validAccounts.length === 0) {
        alert("No valid account with Debit Memo found.");
        return;
    }

    closeBulkEmailReviewModal();
    
    let dmIds = validAccounts.map(acc => acc.dm_id);
    let itemIdsArr = validAccounts.map(acc => acc.item_ids || ''); // <--- KUNIN ANG ITEM_IDS
    let recipients = validAccounts.map(acc => acc.recipient_email || '');
    let ccs = validAccounts.map(acc => acc.cc_emails || '');
    let startDates = validAccounts.map(acc => acc.start_date || '');
    let endDates = validAccounts.map(acc => acc.end_date || '');
    let subjects = validAccounts.map(acc => acc.subject || '');
    let bodies = validAccounts.map(acc => acc.html_content || '');

    let formData = new FormData();
    formData.append('dm_ids', dmIds.join(','));
    formData.append('bulk_item_ids', JSON.stringify(itemIdsArr)); // <--- IPASA SA FORM DATA
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
    
    // Support single item_ids OR bulk_item_ids array
    let rawSingleItemIds = formData.get('item_ids') || '';
    let bulkItemIdsArr = JSON.parse(formData.get('bulk_item_ids') || '[]');

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
        
        // Pass single item_ids or sliced bulk_item_ids
        if (rawSingleItemIds !== '') {
            chunkFormData.append('item_ids', rawSingleItemIds);
        }
        if (bulkItemIdsArr.length > 0) {
            let chunkItemIds = bulkItemIdsArr.slice(i, i + chunkSize);
            chunkFormData.append('bulk_item_ids', JSON.stringify(chunkItemIds));
        }

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

function openEmailHubModal() {
    document.getElementById('emailHubInputModal').style.display = 'flex';
}

function closeEmailHubModal() {
    document.getElementById('emailHubInputModal').style.display = 'none';
}

function switchEmailHubTab(tabNumber) {
    const tab1 = document.getElementById('emailHubTab1');
    const tab2 = document.getElementById('emailHubTab2');
    const btn1 = document.getElementById('tabBtn1');
    const btn2 = document.getElementById('tabBtn2');

    if (tabNumber === 1) {
        tab1.style.display = 'block';
        tab2.style.display = 'none';
        btn1.className = "flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider";
        btn2.className = "flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider";
    } else {
        tab1.style.display = 'none';
        tab2.style.display = 'block';
        btn2.className = "flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider";
        btn1.className = "flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider";
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
        "6020304543,1/1/2026,1/31/2026,,,, \n" +
        "1157296866,1/1/2026,1/31/2026,client.b@company.com,,, \n" +
        "9988776655,1/1/2026,1/31/2026,client.c@company.com,accounting@company.com,Statement of Account Notice,\"Hello Client C, attached is your billing statement for review.\"";
    
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
    
    closeEmailHubModal();
    document.getElementById('pageLoaderOverlay').style.display = 'flex';

    // BAGONG URL: list_dm.php
    fetch('list_dm.php?action=get_dm_ids_by_paste', {
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
            document.getElementById('pageLoaderOverlay').style.display = 'none';
            alert("No matching records found for the pasted list.");
        }
    })
    .catch(err => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';
        alert("An error occurred: " + err.message);
    });
}

function processCsvEmailHub() {
    const fileInput = document.getElementById('csvEmailFile');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a CSV file to upload first.');
        return;
    }
    
    closeEmailHubModal();
    document.getElementById('pageLoaderOverlay').style.display = 'flex';
    
    const formData = new FormData();
    formData.append('csv_file', fileInput.files[0]);

    // BAGONG URL: list_dm.php
    fetch('list_dm.php?action=get_dm_ids_by_csv', {
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
            document.getElementById('pageLoaderOverlay').style.display = 'none';
            alert("No valid records found in the uploaded CSV file.");
        }
    })
    .catch(err => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';
        alert("An error occurred: " + err.message);
    });
}

function sendEmailBreakdownSelected() {
    const checkedBoxes = document.querySelectorAll('#modalContentBody .item-checkbox:checked');
    
    if (checkedBoxes.length === 0) {
        alert("Please select at least one record to send an email.");
        return;
    }

    if (!activeDmId || activeDmId == 0) {
        alert("Error: Active account ID not found.");
        return;
    }

    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    const startDate = startDateInput ? startDateInput.value : '';
    const endDate = endDateInput ? endDateInput.value : '';

    const itemIds = Array.from(checkedBoxes).map(cb => cb.value).join(',');
    const accountText = document.getElementById('modalAccountNumber').innerText;
    const accountNumber = accountText.replace('Account Number:', '').trim();

    document.getElementById('pageLoaderOverlay').style.display = 'flex';

    fetch(`list_dm.php?action=get_email_preview&dm_id=${activeDmId}&item_ids=${itemIds}&start_date=${startDate}&end_date=${endDate}`)
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { throw new Error("Server returned non-JSON response."); }
    })
    .then(data => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';

        if (data.status === 'success') {
            // ISINAMA ANG item_ids SA CACHE
            bulkAccountsCache = [{
                dm_id: activeDmId,
                item_ids: itemIds, // <--- IMPORTANT
                account_number: accountNumber,
                recipient_email: data.recipient_email || '',
                cc_emails: data.cc_emails || '',
                subject: data.subject || '',
                html_content: data.html_content || '',
                start_date: startDate,
                end_date: endDate
            }];

            previewEmailBeforeSend(activeDmId, itemIds, startDate, endDate);
        } else {
            alert("Error: " + (data.message || "Failed to load email preview."));
        }
    })
    .catch(err => {
        document.getElementById('pageLoaderOverlay').style.display = 'none';
        alert("Error: " + err.message);
    });
}

</script>

<?php
$content = ob_get_clean();
render_layout("Debit Memo Statements Management", $content);
?>