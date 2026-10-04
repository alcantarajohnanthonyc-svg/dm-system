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

    // Standard Default Email Subject and Body Generator (Gaya ng List DM)
    function getDefaultEmailContent($account_number, $company, $final_start, $final_end, $approved_plan = 0.00, $final_dm = 0.00) {
        $default_subject = "Statement of Account / Debit Memo - " . $account_number;
        $period_display = (!empty($final_start) && !empty($final_end)) ? "{$final_start} to {$final_end}" : "As of current billing";
        $account_name_display = (!empty($company) ? $company : 'Valued Client') . " / " . $account_number;
        
        $default_body = "Dear Ma'am/Sir,\n\nPlease find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges:\n\nSummary Details:\nPeriod Covered: " . $period_display . "\nAccount Name: " . $account_name_display . "\n\nFor any questions or concerns, please reply directly to this email.\n\nThank you,\nIT Telco Admin Team";

        return [
            'subject' => $default_subject,
            'body' => $default_body
        ];
    }

    // 1. Paste Handler na may Standard Subject/Body at Date Parsers
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
                            $email_stmt = $conn->prepare("SELECT email_address FROM account_emails WHERE account_number = ?");
                            $email_stmt->execute([$memo['account_number']]);
                            $email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

                            $final_start = !empty($line_start) ? $line_start : (!empty($global_start) ? $global_start : ($memo['min_start'] ?? ''));
                            $final_end = !empty($line_end) ? $line_end : (!empty($global_end) ? $global_end : ($memo['max_end'] ?? ''));

                            $defaults = getDefaultEmailContent($memo['account_number'], $memo['company'], $final_start, $final_end);

                            $resolved_accounts[] = [
                                'dm_id' => $memo['dm_id'] ?? 0,
                                'account_number' => $memo['account_number'] ?? $account_number,
                                'company' => $memo['company'] ?? 'Walang Rekord',
                                'recipient_email' => ($email_row && !empty($email_row['email_address'])) ? $email_row['email_address'] : '',
                                'cc_emails' => '',
                                'subject' => $defaults['subject'],
                                'html_content' => $defaults['body'],
                                'start_date' => $final_start,
                                'end_date' => $final_end
                            ];
                        }
                    } else {
                        $defaults = getDefaultEmailContent($account_number, 'Not Found in Database', $line_start, $line_end);
                        $resolved_accounts[] = [
                            'dm_id' => 0,
                            'account_number' => $account_number,
                            'company' => 'Not Found in Database',
                            'recipient_email' => '',
                            'cc_emails' => '',
                            'subject' => $defaults['subject'],
                            'html_content' => $defaults['body'],
                            'start_date' => !empty($line_start) ? $line_start : (!empty($global_start) ? $global_start : ''),
                            'end_date' => !empty($line_end) ? $line_end : (!empty($global_end) ? $global_end : '')
                        ];
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'accounts' => $resolved_accounts]);
        exit;
    }

    // 1.5 CSV Upload Handler na may Support sa Custom Subject/Body o Fallback sa Standards
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
                            $email_stmt = $conn->prepare("SELECT email_address FROM account_emails WHERE account_number = ?");
                            $email_stmt->execute([$memo['account_number']]);
                            $email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

                            $db_email = ($email_row && !empty($email_row['email_address'])) ? $email_row['email_address'] : '';
                            
                            $recipient_email = !empty($custom_to) ? $custom_to : $db_email;
                            $cc_emails       = $custom_cc;
                            
                            $resolved_start  = !empty($final_start) ? $final_start : ($memo['min_start'] ?? '');
                            $resolved_end    = !empty($final_end) ? $final_end : ($memo['max_end'] ?? '');

                            $defaults = getDefaultEmailContent($memo['account_number'], $memo['company'], $resolved_start, $resolved_end);

                            $resolved_accounts[] = [
                                'dm_id' => $memo['dm_id'] ?? 0,
                                'account_number' => $memo['account_number'] ?? $account_number,
                                'company' => $memo['company'] ?? 'Walang Rekord',
                                'recipient_email' => $recipient_email,
                                'cc_emails' => $cc_emails,
                                'subject' => !empty($custom_subject) ? $custom_subject : $defaults['subject'],
                                'html_content' => !empty($custom_body) ? $custom_body : $defaults['body'],
                                'start_date' => $resolved_start,
                                'end_date' => $resolved_end
                            ];
                        }
                    } else {
                        $defaults = getDefaultEmailContent($account_number, 'Not Found in Database', $final_start, $final_end);
                        $resolved_accounts[] = [
                            'dm_id' => 0,
                            'account_number' => $account_number,
                            'company' => 'Not Found in Database',
                            'recipient_email' => $custom_to,
                            'cc_emails' => $custom_cc,
                            'subject' => !empty($custom_subject) ? $custom_subject : $defaults['subject'],
                            'html_content' => !empty($custom_body) ? $custom_body : $defaults['body'],
                            'start_date' => $final_start,
                            'end_date' => $final_end
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
        $recipient_name  = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : 'Valued Client';
        $cc_emails       = '';

        $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
        $itemParams = [$dm_id];

        if (!empty($breakdown_item_ids)) {
            $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
            $itemQuery .= " AND id IN ($placeholders)";
            foreach ($breakdown_item_ids as $iid) { $itemParams[] = $iid; }
        }

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
        $total_approved_plan = 0.00;
        $raw_start_dates = [];
        $raw_end_dates = [];

        foreach ($items as $it) {
            $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
            $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
            $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
            $total_approved_plan += isset($it['approved_plan']) ? (float)$it['approved_plan'] : 0.00;

            if (!empty($it['coverage_start'])) $raw_start_dates[] = $it['coverage_start'];
            if (!empty($it['coverage_end'])) $raw_end_dates[] = $it['coverage_end'];
        }

        if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
            $data_coverage = date('M d, Y', strtotime(min($raw_start_dates))) . " to " . date('M d, Y', strtotime(max($raw_end_dates)));
        } else {
            $data_coverage = "As of current billing";
        }

        $defaults = getDefaultEmailContent($account_number, $dm['company'] ?? $recipient_name, min($raw_start_dates ?? [$start_date]), max($raw_end_dates ?? [$end_date]));

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

        $soa_query = "SELECT DISTINCT pdf.filename, pdf.file_link 
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

        $soa_stmt = $conn->prepare($soa_query);
        $soa_stmt->execute($soa_params);
        $soa_files = $soa_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($soa_files as $soa) {
            if (!empty($soa['filename'])) {
                $attachments[] = [
                    'path' => $soa['filename'],
                    'name' => basename($soa['filename'])
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
        
        <!-- Top Header with Back Button -->
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-100">
            <div>
                <h2 class="text-base font-bold text-gray-800 uppercase tracking-wider">Email Hub</h2>
                <p class="text-[11px] text-gray-500">Manage bulk emailing via pasted accounts or CSV upload.</p>
            </div>
            <a href="list_dm.php" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all">
                <i class="las la-arrow-left text-sm"></i> Back to List DM
            </a>
        </div>

        <!-- Tabs Navigation -->
        <div class="flex border-b border-gray-200 mb-6">
            <button type="button" onclick="switchEmailHubTab(1)" id="tabBtn1" class="flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all uppercase tracking-wider">
                Paste Accounts / Emails
            </button>
            <button type="button" onclick="switchEmailHubTab(2)" id="tabBtn2" class="flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all uppercase tracking-wider">
                Upload CSV
            </button>
        </div>

        <!-- TAB 1 CONTENT: Paste Accounts -->
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

        <!-- TAB 2 CONTENT: Upload CSV -->
        <div id="emailHubTab2" class="space-y-4" style="display: none;">
            <div class="p-6 border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 text-center flex flex-col items-center justify-center py-10">
                <i class="las la-cloud-upload-alt text-4xl text-amber-600 mb-2"></i>
                <h4 class="font-bold text-xs text-gray-700 mb-1 uppercase">Upload CSV File</h4>
                <p class="text-[11px] text-gray-500 mb-4">Drag your CSV file here or click to browse from your computer.</p>
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

<!-- MODALS -->
<!-- BULK EMAIL REVIEW & VALIDATION MODAL -->
<div id="bulkEmailReviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-5xl p-6 mx-4 flex flex-col max-h-[90vh]">
        <div class="flex justify-between items-center mb-4 pb-3 border-b">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Bulk Email Review & Validation</h3>
                <p id="bulkSummaryCount" class="text-xs text-gray-500 font-medium"></p>
            </div>
            <button type="button" onclick="closeBulkEmailReviewModal()" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
        </div>
        
        <div class="border rounded-xl overflow-y-auto flex-grow max-h-[50vh]">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-gray-100 sticky top-0 z-10 text-gray-700 font-bold uppercase text-[10px]">
                    <tr>
                        <th class="p-2.5 text-center w-20">Status</th>
                        <th class="p-2.5">Account Number & Company</th>
                        <th class="p-2.5">Coverage Dates (Per Line)</th>
                        <th class="p-2.5">Recipient Email</th>
                        <th class="p-2.5">CC Emails</th>
                        <th class="p-2.5 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="bulkReviewTableBody" class="divide-y"></tbody>
            </table>
        </div>

        <div class="flex justify-end gap-3 mt-4 pt-3 border-t">
            <button type="button" onclick="closeBulkEmailReviewModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-300">Cancel</button>
            <button type="button" onclick="proceedBulkDispatchFromModal()" class="px-5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-md">Confirm & Send Emails</button>
        </div>
    </div>
</div>

<!-- SINGLE EMAIL PREVIEW MODAL -->
<div id="emailPreviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-2xl p-6 mx-4 max-h-[90vh] flex flex-col">
        
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
                <input type="text" id="preview_cc" name="cc_emails" class="w-full p-2 border rounded-xl text-xs bg-gray-50" placeholder="e.g. accounting@company.com, admin@company.com">
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Subject</label>
                <input type="text" id="preview_subject" name="subject" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <div class="flex-grow">
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                <textarea id="preview_body" name="html_content" rows="7" class="w-full p-2 border rounded-xl text-xs bg-gray-50 font-sans" required></textarea>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Attachments to Include</label>
                <div id="preview_attachments_container" class="p-2 border rounded-xl bg-gray-50 text-xs space-y-1 max-h-28 overflow-y-auto"></div>
                <div class="mt-2 pt-2 border-t border-gray-200">
                    <label class="block text-[9px] font-bold text-emerald-600 uppercase mb-1">+ Mag-upload ng Bagong File (Opsyonal)</label>
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

<!-- DISPATCH PROGRESS MODAL -->
<div id="dispatchProgressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-2xl p-6 mx-4">
        <h3 class="text-base font-bold text-gray-800 mb-4">Sending Statement Emails...</h3>
        <div class="mb-4">
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div id="dispatchProgressBar" class="bg-emerald-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
            <p id="dispatchProgressText" class="text-xs font-semibold text-gray-600 mt-2">Starting dispatch process...</p>
        </div>
        <div id="dispatchProgressLog" class="bg-slate-900 text-white text-xs font-mono p-3 rounded-xl max-h-60 overflow-y-auto mb-4 space-y-1"></div>
        <div class="text-right">
            <button type="button" id="closeDispatchModalBtn" onclick="closeDispatchModal()" style="display:none;" class="bg-gray-700 text-white px-4 py-2 rounded-xl text-xs font-semibold">Close</button>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
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
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Raw server response:", text);
                throw new Error("Server returned non-JSON response.");
            }
        })
        .then(data => {
            if (data.status === 'success' && data.accounts && data.accounts.length > 0) {
                openBulkEmailReviewModal(data.accounts);
            } else {
                alert("No matching records found for the pasted list.");
            }
        })
        .catch(err => {
            console.error("Error processing pasted accounts:", err);
            alert("An error occurred: " + err.message);
        });
    }

    function processCsvEmailHub() {
        const fileInput = document.getElementById('csvEmailFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Please select a CSV file to upload first.');
            return;
        }

        const formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);

        fetch('email_hub.php?action=get_dm_ids_by_csv', {
            method: 'POST',
            body: formData
        })
        .then(async res => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Raw server response:", text);
                throw new Error("Server returned non-JSON response.");
            }
        })
        .then(data => {
            if (data.status === 'success' && data.accounts && data.accounts.length > 0) {
                openBulkEmailReviewModal(data.accounts);
            } else {
                alert("No valid records found in the uploaded CSV file.");
            }
        })
        .catch(err => {
            console.error("Error processing CSV upload:", err);
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
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Raw server response:", text);
                throw new Error("Server returned non-JSON response.");
            }
        })
        .then(data => {
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
            console.error("Bulk email preview error:", err);
        });
    }

    function closeBulkEmailReviewModal() {
        document.getElementById('bulkEmailReviewModal').style.display = 'none';
    }

    function renderBulkReviewTable() {
        const tbody = document.getElementById('bulkReviewTableBody');
        tbody.innerHTML = '';
        
        bulkAccountsCache.forEach((acc, idx) => {
            let tr = document.createElement('tr');
            tr.className = "hover:bg-gray-50 border-b";
            
            let statusBadge = (acc.recipient_email && acc.recipient_email.trim() !== '') ?
                '<span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-bold">🟢 Ready</span>' :
                '<span class="px-2 py-0.5 bg-red-100 text-red-700 rounded text-[10px] font-bold">🔴 Missing</span>';
            
            let coverageDisplay = (acc.start_date && acc.end_date) ? 
                `<span class="text-[11px] font-mono text-blue-600">${acc.start_date} to ${acc.end_date}</span>` : 
                `<span class="text-[10px] text-gray-400 italic">Walang Petsa</span>`;

            tr.innerHTML = `
                <td class="p-2.5 text-center">${statusBadge}</td>
                <td class="p-2.5 font-semibold text-gray-800">${acc.account_number} <br><span class="text-[10px] text-gray-500 font-normal">${acc.company || ''}</span></td>
                <td class="p-2.5">${coverageDisplay}</td>
                <td class="p-2.5"><input type="email" value="${acc.recipient_email || ''}" oninput="bulkAccountsCache[${idx}].recipient_email = this.value" class="w-full p-1.5 border rounded text-xs bg-white"></td>
                <td class="p-2.5"><input type="text" value="${acc.cc_emails || ''}" oninput="bulkAccountsCache[${idx}].cc_emails = this.value" class="w-full p-1.5 border rounded text-xs bg-white"></td>
                <td class="p-2.5 text-center"><button type="button" onclick="previewEmailBeforeSend(${acc.dm_id}, '', '${acc.start_date || ''}', '${acc.end_date || ''}')" class="px-2.5 py-1 bg-blue-50 text-blue-600 rounded text-xs font-bold hover:bg-blue-100">Preview</button></td>
            `;
            tbody.appendChild(tr);
        });
    }

    function previewEmailBeforeSend(dmId, itemIds = '', startDate = '', endDate = '') {
        let cachedAccount = bulkAccountsCache.find(acc => acc.dm_id == dmId || acc.account_number == dmId);

        if (cachedAccount) {
            document.getElementById('preview_dm_id').value = cachedAccount.dm_id || dmId;
            document.getElementById('preview_item_ids').value = itemIds;
            document.getElementById('preview_start_date').value = cachedAccount.start_date || startDate;
            document.getElementById('preview_end_date').value = cachedAccount.end_date || endDate;
            document.getElementById('preview_to').value = cachedAccount.recipient_email || '';
            document.getElementById('preview_cc').value = cachedAccount.cc_emails || '';
            document.getElementById('preview_subject').value = cachedAccount.subject || '';
            document.getElementById('preview_body').value = cachedAccount.html_content || '';

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
            })
            .catch(err => {
                console.error("Attachment fetch error:", err);
                document.getElementById('emailPreviewModal').style.display = 'flex';
            });

        } else {
            let url = `email_hub.php?action=get_email_preview&dm_id=${dmId}&item_ids=${itemIds}&start_date=${startDate}&end_date=${endDate}`;
            fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('preview_dm_id').value = dmId;
                    document.getElementById('preview_to').value = data.recipient_email || '';
                    document.getElementById('preview_cc').value = data.cc_emails || '';
                    document.getElementById('preview_subject').value = data.subject || '';
                    document.getElementById('preview_body').value = data.html_content || '';
                    document.getElementById('emailPreviewModal').style.display = 'flex';
                } else {
                    alert("Error: " + data.message);
                }
            });
        }
    }

    function closeEmailPreviewModal() {
        document.getElementById('emailPreviewModal').style.display = 'none';
    }

    function submitConfirmedEmail() {
        const dmId = document.getElementById('preview_dm_id').value;
        const newTo = document.getElementById('preview_to').value;
        const newCc = document.getElementById('preview_cc').value;
        const newSubject = document.getElementById('preview_subject').value;
        const newBody = document.getElementById('preview_body').value;
        const newStart = document.getElementById('preview_start_date').value;
        const newEnd = document.getElementById('preview_end_date').value;

        let index = bulkAccountsCache.findIndex(acc => acc.dm_id == dmId);
        if (index !== -1) {
            bulkAccountsCache[index].recipient_email = newTo;
            bulkAccountsCache[index].cc_emails = newCc;
            bulkAccountsCache[index].subject = newSubject;
            bulkAccountsCache[index].html_content = newBody;
            bulkAccountsCache[index].start_date = newStart;
            bulkAccountsCache[index].end_date = newEnd;
        }

        const form = document.getElementById('emailPreviewForm');
        const formData = new FormData(form);

        const fileInput = document.getElementById('additional_attachments');
        if (fileInput && fileInput.files.length > 0) {
            for (let i = 0; i < fileInput.files.length; i++) {
                formData.append('additional_attachments[]', fileInput.files[i]);
            }
        }

        closeEmailPreviewModal();
        executeDispatchFetch(formData, 1);
    }

    function proceedBulkDispatchFromModal() {
        closeBulkEmailReviewModal();
        
        let dmIds = bulkAccountsCache.map(acc => acc.dm_id);
        let recipients = bulkAccountsCache.map(acc => acc.recipient_email || '');
        let ccs = bulkAccountsCache.map(acc => acc.cc_emails || '');
        let startDates = bulkAccountsCache.map(acc => acc.start_date || '');
        let endDates = bulkAccountsCache.map(acc => acc.end_date || '');
        let subjects = bulkAccountsCache.map(acc => acc.subject || '');
        let bodies = bulkAccountsCache.map(acc => acc.html_content || '');

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

    function executeDispatchFetch(formData, countNum) {
        const progressModal = document.getElementById('dispatchProgressModal');
        const progressBar = document.getElementById('dispatchProgressBar');
        const progressText = document.getElementById('dispatchProgressText');
        const progressLog = document.getElementById('dispatchProgressLog');
        const closeBtn = document.getElementById('closeDispatchModalBtn');

        if (progressModal) progressModal.style.display = 'flex';
        if (progressBar) progressBar.style.width = '15%';
        if (progressText) progressText.innerText = `Preparing email dispatch for ${countNum} account(s)...`;
        if (progressLog) progressLog.innerHTML = `<div>Initializing connection and verifying recipients...</div>`;
        if (closeBtn) closeBtn.style.display = 'none';

        fetch('send_bulk_email.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) { throw new Error(`HTTP error! Status: ${response.status}`); }
            return response.json();
        })
        .then(data => {
            if (progressBar) progressBar.style.width = '100%';
            if (data.status === 'success') {
                if (progressText) {
                    progressText.innerText = `Completed! Successful: ${data.success_count}, Failed: ${data.fail_count}`;
                }
                let logHtml = '';
                if (data.success_details && data.success_details.length > 0) {
                    data.success_details.forEach(succ => { logHtml += `<div class="text-emerald-400">+ ${succ}</div>`; });
                }
                if (data.failed_details && data.failed_details.length > 0) {
                    data.failed_details.forEach(err => { logHtml += `<div class="text-rose-400">- ${err}</div>`; });
                }
                if (progressLog) progressLog.innerHTML = logHtml;
            } else {
                if (progressText) progressText.innerText = "Dispatch encountered an error.";
                if (progressLog) progressLog.innerHTML = `<div class="text-rose-400">Error: ${data.message}</div>`;
            }
            if (closeBtn) closeBtn.style.display = 'block';
        })
        .catch(error => {
            console.error('Email Dispatch Error:', error);
            if (progressBar) progressBar.style.width = '100%';
            if (progressText) progressText.innerText = "An unexpected error occurred.";
            if (progressLog) progressLog.innerHTML = `<div class="text-rose-400">Fetch Exception: ${error.message}</div>`;
            if (closeBtn) closeBtn.style.display = 'block';
        });
    }

    function closeDispatchModal() {
        const modal = document.getElementById('dispatchProgressModal');
        if (modal) modal.style.display = 'none';
        window.location.reload();
    }
</script>
<?php
$content = ob_get_clean();
render_layout($page_title,$content);
?>