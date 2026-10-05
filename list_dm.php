<?php
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

// === 1. API HANDLER PARA SA SINGLE EMAIL PREVIEW ===
if (isset($_GET['action']) && $_GET['action'] === 'get_email_preview') {
    header('Content-Type: application/json');
    $dm_id = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
    $item_ids_str = isset($_GET['item_ids']) ? trim($_GET['item_ids']) : '';
    $breakdown_item_ids = !empty($item_ids_str) ? array_filter(explode(',', $item_ids_str)) : [];

    // Kunin ang start_date at end_date filter mula sa request
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
    $employee_id     = ($account_email_row && !empty($account_email_row['employee_id'])) ? $account_email_row['employee_id'] : '';
    $cc_emails       = ($account_email_row && !empty($account_email_row['cc_emails'])) ? $account_email_row['cc_emails'] : '';

    $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
    $itemParams = [$dm_id];

    if (!empty($breakdown_item_ids)) {
        $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
        $itemQuery .= " AND id IN ($placeholders)";
        foreach ($breakdown_item_ids as $iid) { $itemParams[] = $iid; }
    }

    // Isama ang date filter kung meron man
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
    $approved_plan_set = false;
    $raw_start_dates = [];
    $raw_end_dates = [];

    foreach ($items as $it) {
        $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
        $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
        $total_final_dm += isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
        if (!$approved_plan_set && isset($it['approved_plan'])) {
            $total_approved_plan = (float)$it['approved_plan'];
            $approved_plan_set = true;
        }

        if (!empty($it['coverage_start'])) $raw_start_dates[] = $it['coverage_start'];
        if (!empty($it['coverage_end'])) $raw_end_dates[] = $it['coverage_end'];
    }

    if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
        $data_coverage = date('M d, Y', strtotime(min($raw_start_dates))) . " to " . date('M d, Y', strtotime(max($raw_end_dates)));
    } else {
        $data_coverage = "As of current billing";
    }

   $dm_number_display = $dm['dm_number'] ?? $dm_id;
    $subject = "Statement of Account / Debit Memo - " . $account_number;
   $identifier = !empty($employee_id) ? $employee_id : $account_number;
    $account_name_display = "{$recipient_name} / {$identifier}";
    $approved_plan_display = number_format($total_approved_plan, 2, '.', ',');
    $final_dm_val = number_format($total_final_dm, 2, '.', ',');

$html_content = "Dear Ma'am/Sir,\n\nPlease find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges:\n\nSummary Details:\nPeriod Covered: " . $data_coverage . "\nAccount Name: " . $account_name_display . "\nApproved Plan (Company Share): ₱ " . $approved_plan_display . "\nTotal Excess Charges Amount: ₱ " . $final_dm_val . "\n\nThis statement outlines the specific breakdown and descriptions of the charges applied to your telco account for your information.\n\nNote: This email provides a detailed breakdown and description of your telco account charges for your reference. If your excess charges is zero (₱0.00), no action is required and you may disregard this notification.\n\nPlease review the attached SOA for full details.\n\nThis is an automated email, please do not reply.\n\nThank you,\nIT Telco Admin Team";    require_once 'pdf_generator.php';
    // Ipasa ang petsa sa PDF generator function mo kung kinakailangan
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

    // Query para sa mga SOA files na pasok sa date range at items
    $soa_query = "SELECT DISTINCT pdf.filename, pdf.file_link FROM debit_memo_items dmi JOIN pdf_extracted_details pdf ON pdf.account_number = ? WHERE dmi.dm_id = ?";
    $soa_params = [$account_number, $dm_id];
    if (!empty($breakdown_item_ids)) {
        $placeholders = implode(',', array_fill(0, count($breakdown_item_ids), '?'));
        $soa_query .= " AND dmi.id IN ($placeholders)";
        foreach ($breakdown_item_ids as $iid) { $soa_params[] = $iid; }
    }
    
    // Idagdag din ang date filter dito kung meron
    if (!empty($start_date) && !empty($end_date)) {
        $soa_query .= " AND dmi.coverage_start >= ? AND dmi.coverage_end <= ?";
        $soa_params[] = $start_date;
        $soa_params[] = $end_date;
    }

    $soa_query .= " AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5";
    
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
        'subject' => $subject,
        'html_content' => $html_content,
        'attachments' => $attachments
    ]);
    exit;
}

// === 2. API HANDLER PARA SA BULK EMAIL PREVIEW & VALIDATION TABLE ===
if (isset($_GET['action']) && $_GET['action'] === 'get_bulk_email_preview') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $dm_ids = isset($input['dm_ids']) ? $input['dm_ids'] : [];

    if (empty($dm_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No accounts provided.']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($dm_ids), '?'));
        $stmt = $conn->prepare("SELECT dm_id, account_number, company FROM debit_memos WHERE dm_id IN ($placeholders)");
        $stmt->execute($dm_ids);
        $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $accounts_data = [];
        foreach ($memos as $memo) {
            $acc_num = $memo['account_number'];
            
            // 1. Kunin ang lahat ng detalye kasama ang full_name at employee_id mula sa account_emails
            $email_stmt = $conn->prepare("SELECT * FROM account_emails WHERE account_number = ?");
            $email_stmt->execute([$acc_num]);
            $account_email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);

            $recipient_name = ($account_email_row && !empty($account_email_row['full_name'])) ? $account_email_row['full_name'] : 'Valued Client';
            $employee_id    = ($account_email_row && !empty($account_email_row['employee_id'])) ? $account_email_row['employee_id'] : '';
            
            // 2. I-format ang display ng Account Number / Identifier kung saan uunahin ang employee_id kung meron
            $identifier = !empty($employee_id) ? $employee_id : $acc_num;

            $accounts_data[] = [
                'dm_id' => $memo['dm_id'],
                'account_number' => $acc_num,
                'display_identifier' => $identifier, // Magagamit kung gusto mong ipakita ang employee_id sa table
                'recipient_name' => $recipient_name,
                'company' => $memo['company'],
                'recipient_email' => ($account_email_row && !empty($account_email_row['email_address'])) ? $account_email_row['email_address'] : '',
                'cc_emails' => ($account_email_row && !empty($account_email_row['cc_emails'])) ? $account_email_row['cc_emails'] : ''
            ];
        }

        echo json_encode(['status' => 'success', 'accounts' => $accounts_data]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// === 3. API HANDLER PARA SA PASTE EMAIL HUB MAPPING ===
if (isset($_GET['action']) && $_GET['action'] === 'get_dm_ids_by_paste') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $raw_text = isset($input['pasted_text']) ? $input['pasted_text'] : '';
    
    // Kunin ang start_date at end_date mula sa request
    $start_date = isset($input['start_date']) ? $input['start_date'] : '';
    $end_date = isset($input['end_date']) ? $input['end_date'] : '';
    
    $lines = preg_split('/\r\n|\r|\n/', $raw_text);
    $tokens = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        foreach ($parts as $part) {
            $clean = trim($part);
            if (!empty($clean)) {
                $tokens[] = $clean;
            }
        }
    }
    
    $dm_ids = [];
    if (!empty($tokens)) {
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        
        // Buuin ang base query
        $sql = "SELECT DISTINCT dm.dm_id 
                FROM debit_memos dm 
                LEFT JOIN account_emails ae ON dm.account_number = ae.account_number 
                WHERE (dm.account_number IN ($placeholders) OR ae.email_address IN ($placeholders))";
        
        $params = array_merge($tokens, $tokens);
        
        // Idagdag ang petsa sa filter kung ito ay naka-set
        if (!empty($start_date) && !empty($end_date)) {
            $sql .= " AND dm.created_at BETWEEN ? AND ?"; // Palitan ang 'created_at' ng tamang column name ng petsa sa iyong database kung kinakailangan
            array_push($params, $start_date . ' 00:00:00', $end_date . ' 23:59:59');
        }
                
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $dm_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    echo json_encode(['status' => 'success', 'dm_ids' => $dm_ids]);
    exit;
}

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

// 2. Build Unified Subquery (Strict Inclusion)
$filteredSubQuery = "SELECT dmi.dm_id, 
                            GROUP_CONCAT(DISTINCT dmi.carrier_name SEPARATOR '|') as carrier_names, 
                            MIN(dmi.coverage_start) as start_date, 
                            MAX(dmi.coverage_end) as end_date, 
                            SUM(CAST(dmi.debit_memo_details AS DECIMAL(10,2))) as filtered_total,
                            (
                                SELECT MAX(CAST(dmi_inner.approved_plan AS DECIMAL(10,2)))
                                FROM debit_memo_items dmi_inner
                                WHERE dmi_inner.dm_id = dmi.dm_id
                                  AND dmi_inner.coverage_start >= :where_start 
                                  AND dmi_inner.coverage_end <= :where_end
                            ) as approved_plan_company_share,
                            MIN(
                                EXISTS (
                                    SELECT 1 FROM pdf_extracted_details pdf 
                                    JOIN debit_memos dm ON pdf.account_number = dm.account_number
                                    WHERE dm.dm_id = dmi.dm_id 
                                      AND pdf.mobile_number = dmi.mobile_number 
                                      AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                                )
                            ) as has_soa
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

$sql = "SELECT dm.*, sub.carrier_names, sub.start_date, sub.end_date, sub.filtered_total as sum_debit_memo_details, sub.approved_plan_company_share, sub.has_soa
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

               <div id="mainGearDropdown" class="hidden absolute left-0 mt-1 w-48 bg-white border border-gray-200 rounded-xl shadow-xl z-50 py-1.5 overflow-hidden">
    <?php if ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin'): ?>
        <button type="button" onclick="openAddEditModal(activeDmId || ''); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-indigo-700 font-semibold hover:bg-indigo-50 hover:text-indigo-900 flex items-center transition-colors border-l-4 border-transparent hover:border-indigo-600">
            <i class="las la-plus mr-2.5 text-base text-indigo-500"></i> Add New Entry
        </button>
        <button type="button" onclick="window.location.href='import_dm.php'" class="w-full text-left px-4 py-2.5 text-xs text-emerald-700 font-semibold hover:bg-emerald-50 hover:text-emerald-900 flex items-center transition-colors border-l-4 border-transparent hover:border-emerald-600">
            <i class="las la-file-import mr-2.5 text-base text-emerald-500"></i> Import Data
        </button>
    <?php endif; ?>

    <button type="button" onclick="bulkExportPDF(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-rose-700 font-semibold hover:bg-rose-50 hover:text-rose-900 flex items-center transition-colors border-l-4 border-transparent hover:border-rose-600">
        <i class="las la-file-export mr-2.5 text-base text-rose-500"></i> Export PDF
    </button>
 
    <button type="button" onclick="document.getElementById('pasteExportModal').classList.remove('hidden'); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-blue-700 font-semibold hover:bg-blue-50 hover:text-blue-900 flex items-center transition-colors border-l-4 border-transparent hover:border-blue-600">
        <i class="las la-clipboard-list mr-2.5 text-base text-blue-500"></i> Paste Export
    </button>

    <button type="button" onclick="window.location.href='email_hub.php'; closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-amber-700 font-semibold hover:bg-amber-50 hover:text-amber-900 flex items-center transition-colors border-l-4 border-transparent hover:border-amber-600">
        <i class="las la-envelope mr-2.5 text-base text-amber-500"></i> Email Hub
    </button>

    <button type="button" onclick="sendEmailSelected(); closeMainGearDropdown();" class="w-full text-left px-4 py-2.5 text-xs text-amber-700 font-semibold hover:bg-amber-50 hover:text-amber-900 flex items-center transition-colors border-l-4 border-transparent hover:border-amber-600">
        <i class="las la-paper-plane mr-2.5 text-base text-amber-500"></i> Send Selected Email
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
                        <?php if (isset($row['has_soa']) && $row['has_soa'] == 1): ?>
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-green-100 text-green-700 border border-green-200">WITH SOA</span>
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
                <button type="button" onclick="sendEmailFromBreakdown()" class="px-3 py-1.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-md text-[10px] font-bold hover:bg-amber-100">
                    <i class="las la-envelope mr-1"></i> Send email
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

<!-- FORMAT CHOICE SUB-MODAL -->
<div id="pasteChoiceModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 10000; justify-content: center; align-items: center;">
    <div style="background: #ffffff; padding: 30px; border-radius: 16px; width: 360px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); text-align: center; position: relative; border: 1px solid #f1f5f9;">
        <button onclick="document.getElementById('pasteChoiceModal').style.display='none'" style="position: absolute; top: 14px; right: 16px; background: #f8fafc; border: none; color: #64748b; width: 28px; height: 28px; border-radius: 50%; cursor: pointer;">&times;</button>
        <h3 style="color: #0f172a; font-size: 16px; font-weight: 600; margin-bottom: 8px;">Select Format</h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 20px;">Choose file format for your pasted list.</p>
        <div style="display: flex; gap: 12px;">
            <button type="button" onclick="submitPasteExport('pdf')" style="flex: 1; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 10px; font-weight: 600; border-radius: 8px; cursor: pointer;">PDF</button>
            <button type="button" onclick="submitPasteExport('excel')" style="flex: 1; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 10px; font-weight: 600; border-radius: 8px; cursor: pointer;">Excel</button>
        </div>
    </div>
</div>

<!-- ADD/EDIT MODAL -->
<div id="addEditModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl p-6 max-h-[90vh] overflow-y-auto">
        <h3 id="modalFormTitle" class="font-bold text-xl">Add Debit Memo Item</h3>
        <form id="addEditForm" method="POST" action="save_record.php" novalidate>
            <input type="hidden" id="form_dm_id" name="id" value="">
            <div class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-5 gap-4 mb-6 bg-gray-50 p-4 rounded-md">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Account #</label>
                    <input type="text" name="account_number" list="acc_list" class="w-full p-2 border rounded-md text-xs" onchange="fetchAccountDetails(this.value)" onblur="fetchAccountDetails(this.value)" placeholder="Search/Select..." required>
                    <div id="accFeedback" class="text-[9px] font-bold mt-1"></div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Company</label>
                    <input type="text" name="company" class="w-full p-2 border rounded-md text-xs" required>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Assignee</label>
                    <input type="text" name="assignee" class="w-full p-2 border rounded-md text-xs">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Mobile #</label>
                    <input type="text" name="mobile_number" class="w-full p-2 border rounded-md text-xs" required>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Carrier</label>
                    <input type="text" name="carrier" list="carrier_input" class="w-full p-2 border rounded-md text-xs" required>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-6">
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase">Start Date</label><input type="date" name="coverage_start" class="w-full p-2 border rounded-md text-xs" required></div>
                <div><label class="block text-[10px] font-bold text-gray-500 uppercase">End Date</label><input type="date" name="coverage_end" class="w-full p-2 border rounded-md text-xs" required></div>
            </div>

            <div class="grid grid-cols-3 md:grid-cols-6 gap-3 border-t pt-4">
                <?php 
                $amounts = [
                    'approve_plan' => 'Approved Plan',
                    'phone_amort' => 'Phone Amortization', 
                    'debit_adj' => 'Debit Adj',
                    'credit_adj' => 'Credit Adj',
                    'other_charges' => 'Other Charges', 
                    'local' => 'Local(Call/Text)',
                    'ndd' => 'NDD (NATIONAL)', 
                    'idd' => 'IDD (INTERNATIONAL)', 
                    'roam' => 'Roam', 
                    'sms' => 'SMS',
                    'gprs' => 'GPRS',
                    'wiz_usage' => 'Wiz Usage', 
                    'loading' => 'Loading CHARGES',
                    'vat' => 'VAT', 
                    'oct' => 'Overseas communication Tax',
                    'current_charges' => 'Current Charges', 
                    'total_amount_due' => 'Total Amount Due', 
                    'debit_memo_details' => 'Total Debit Memo'
                ];
                foreach ($amounts as $name => $label): ?>
                    <div>
                        <label class="block text-[9px] font-bold text-gray-400 uppercase"><?= $label ?></label>
                        <input type="number" step="0.01" name="<?= $name ?>" class="w-full p-1.5 border rounded-md text-xs" value="0.00">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-center items-center gap-6 mt-8 border-t pt-6">
                <button type="button" onclick="closeModal()" class="px-8 py-2 bg-gray-200 rounded-md text-xs font-bold hover:bg-gray-300 transition">CANCEL</button>
                <button type="submit" class="px-8 py-2 bg-slate-800 text-white rounded-md text-xs font-bold hover:bg-slate-900 shadow-md transition">SAVE ITEM</button>
            </div>
        </form>
    </div>
</div>

<!-- UNIFIED EXPORT CHOICE MODAL -->
<div id="unifiedExportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: #ffffff; padding: 30px; border-radius: 16px; width: 360px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); text-align: center; position: relative; border: 1px solid #f1f5f9;">
        <button onclick="closeUnifiedExportModal()" style="position: absolute; top: 14px; right: 16px; background: #f8fafc; border: none; color: #64748b; width: 28px; height: 28px; border-radius: 50%; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center;">&times;</button>
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
        
        <div class="flex justify-between items-center mb-3 text-xs">
            <div class="flex items-center gap-2">
                <span>Show</span>
                <select id="bulkRowsPerPageSelect" onchange="changeBulkRowsPerPage(this.value)" class="p-1 border rounded bg-gray-50">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="ALL">All</option>
                </select>
                <span>entries</span>
            </div>
            <div class="text-gray-500 text-[11px]">
                Tip: Red rows indicate missing recipient email addresses. You can type directly in the box to update.
            </div>
        </div>

        <div class="border rounded-xl overflow-y-auto flex-grow max-h-[50vh]">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-gray-100 sticky top-0 z-10 text-gray-700 font-bold uppercase text-[10px]">
                    <tr>
                        <th class="p-2.5 text-center w-20 cursor-pointer" onclick="sortBulkTable('status')">Status ↕</th>
                        <th class="p-2.5 cursor-pointer" onclick="sortBulkTable('account_number')">Account Number & Company ↕</th>
                        <th class="p-2.5 cursor-pointer" onclick="sortBulkTable('recipient_email')">Recipient Email ↕</th>
                        <th class="p-2.5">CC Emails</th>
                        <th class="p-2.5 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="bulkReviewTableBody" class="divide-y">
                    <!-- Dynamic rows inserted here -->
                </tbody>
            </table>
        </div>

        <div class="flex justify-between items-center mt-4 pt-3 border-t">
            <div id="bulkPaginationInfo" class="text-xs text-gray-500">Showing 0 to 0 of 0 accounts</div>
            <div class="flex gap-2">
                <button type="button" onclick="changeBulkPage(-1)" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-bold">Previous</button>
                <button type="button" onclick="changeBulkPage(1)" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-bold">Next</button>
            </div>
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
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-4 pb-2 border-b">
            <h3 class="text-base font-bold text-gray-800">Email Preview & Customization</h3>
            <button type="button" onclick="closeEmailPreviewModal()" class="text-gray-400 hover:text-gray-600 font-bold">✕</button>
        </div>

        <!-- Form (Nilagyan ng enctype para sa file upload) -->
        <form id="emailPreviewForm" class="flex flex-col flex-grow overflow-y-auto space-y-3" enctype="multipart/form-data">
            
            <input type="hidden" id="preview_dm_id" name="dm_ids" value="">
            <input type="hidden" id="preview_item_ids" name="item_ids" value="">
            <input type="hidden" id="preview_start_date" name="start_date" value="">
    <input type="hidden" id="preview_end_date" name="end_date" value="">
            <!-- To Email -->
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">To Email</label>
                <input type="email" id="preview_to" name="recipient_email" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <!-- CC Emails -->
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">CC Emails (Comma-separated)</label>
                <input type="text" id="preview_cc" name="cc_emails" class="w-full p-2 border rounded-xl text-xs bg-gray-50" placeholder="e.g. accounting@company.com, admin@company.com">
            </div>

            <!-- Subject -->
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Subject</label>
                <input type="text" id="preview_subject" name="subject" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <!-- Message Body -->
            <div class="flex-grow">
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                <textarea id="preview_body" name="html_content" rows="7" class="w-full p-2 border rounded-xl text-xs bg-gray-50 font-sans" required></textarea>
            </div>

            <!-- Attachments Section -->
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Attachments to Include</label>
                
                <!-- Dynamic attachment checkboxes galing sa system -->
                <div id="preview_attachments_container" class="p-2 border rounded-xl bg-gray-50 text-xs space-y-1 max-h-28 overflow-y-auto">
                    <!-- Dynamic checkboxes will load here -->
                </div>
                
                <!-- Karagdagang File Input para sa pag-upload ng bagong attachment -->
                <div class="mt-2 pt-2 border-t border-gray-200">
                    <label class="block text-[9px] font-bold text-emerald-600 uppercase mb-1">+ Upload addtional attached file (Opsyonal)</label>
                    <input type="file" name="additional_attachments[]" id="additional_attachments" multiple class="w-full p-1 border rounded-lg text-xs bg-white text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[10px] file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                </div>
            </div>

            <!-- Modal Footer / Buttons -->
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

<!-- EMAIL HUB MODAL -->
<div id="emailHubModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-xl p-6 mx-4 flex flex-col">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-4 pb-3 border-b">
            <div>
                <h3 class="text-lg font-bold text-gray-800" id="emailHubTitle">Email Hub</h3>
                <p class="text-xs text-gray-500 font-medium" id="emailHubSubtitle">Paste accounts/emails or upload a CSV to send emails.</p>
            </div>
            <button type="button" onclick="closeEmailHubModal()" class="text-gray-400 hover:text-gray-600 text-lg font-bold">✕</button>
        </div>

        <!-- Tabs Navigation -->
        <div class="flex border-b border-gray-200 mb-4">
            <button type="button" onclick="switchEmailHubTab(1)" id="tabBtn1" class="flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all">
                Paste Accounts / Emails
            </button>
            <button type="button" onclick="switchEmailHubTab(2)" id="tabBtn2" class="flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all">
                Upload CSV
            </button>
        </div>

        <!-- TAB 1 CONTENT: Paste Accounts -->
        <div id="emailHubTab1" class="space-y-4">
            <div>
                <span id="pasteItemCount" class="text-xs font-semibold text-blue-600 block mb-1">0 items found</span>
                <textarea id="pasteAccountInput" rows="5" class="w-full p-3 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none resize-none" placeholder="Paste account numbers or email addresses here..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">Start Date</label>
                    <input type="date" class="w-full p-2.5 border border-gray-200 rounded-xl text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase mb-1">End Date</label>
                    <input type="date" class="w-full p-2.5 border border-gray-200 rounded-xl text-xs bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>
            </div>

            <button type="button" onclick="processPasteEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-2">
                Process and Send Records
            </button>
        </div>

        <!-- TAB 2 CONTENT: Upload CSV & Download Template -->
        <div id="emailHubTab2" class="space-y-4" style="display: none;">
            <div class="p-4 border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 text-center flex flex-col items-center justify-center py-8">
                <i class="las la-cloud-upload-alt text-3xl text-amber-600 mb-2"></i>
                <h4 class="font-bold text-xs text-gray-700 mb-1">Upload CSV File</h4>
                <p class="text-[11px] text-gray-500 mb-4">Drag your CSV file here or click to browse from your computer.</p>
                <input type="file" id="csvEmailFile" accept=".csv" class="hidden" onchange="handleCsvFileSelect(this)">
                <button type="button" onclick="document.getElementById('csvEmailFile').click()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-xl text-xs font-semibold hover:bg-gray-100 shadow-sm">
                    Browse File
                </button>
                <span id="selectedCsvFileName" class="text-[11px] text-emerald-600 font-medium mt-2"></span>
            </div>

            <div class="flex items-center justify-between p-3 bg-amber-50 border border-amber-200 rounded-xl">
                <div>
                    <h5 class="text-xs font-bold text-amber-900">Need a template?</h5>
                    <p class="text-[10px] text-amber-700">Download the CSV template for the correct email/accounts format.</p>
                </div>
                <button type="button" onclick="downloadEmailCsvTemplate()" class="px-3 py-2 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 shadow-sm whitespace-nowrap">
                    Download Template
                </button>
            </div>

            <button type="button" onclick="processCsvEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-[#0f172a] shadow-md transition-all mt-2">
                Upload and Send CSV
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
let currentBulkPage = 1;
let bulkRowsPerPage = 20;
let currentBulkSortField = '';
let currentBulkSortDir = 'asc';

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

// === EMAIL FRONTEND LOGIC (BULK REVIEW, PREVIEW & DISPATCH) ===

function sendEmailSelected() {
    const checkboxes = document.querySelectorAll('.dm-checkbox:checked');
    if (checkboxes.length === 0) {
        alert("Please select at least one account number to send emails.");
        return;
    }

    if (checkboxes.length === 1) {
        previewEmailBeforeSend(checkboxes[0].value, '');
        return;
    }

    const dmIds = Array.from(checkboxes).map(cb => cb.value);
    openBulkEmailReviewModal(dmIds);
}

function openBulkEmailReviewModal(dmIds) {
    fetch('list_dm.php?action=get_bulk_email_preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ dm_ids: dmIds })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            bulkAccountsCache = data.accounts;
            currentBulkPage = 1;
            currentBulkSortField = '';
            currentBulkSortDir = 'asc';
            document.getElementById('bulkRowsPerPageSelect').value = '20';
            bulkRowsPerPage = 20;
            renderBulkReviewTable();
            document.getElementById('bulkSummaryCount').innerText = `${dmIds.length} accounts loaded for review`;
            document.getElementById('bulkEmailReviewModal').style.display = 'flex';
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(err => {
        console.error("Bulk review error:", err);
        alert("Hindi makuha ang detalye para sa bulk review.");
    });
}

function closeBulkEmailReviewModal() {
    document.getElementById('bulkEmailReviewModal').style.display = 'none';
}

function saveCurrentBulkPageInputs() {
    const rows = document.querySelectorAll('#bulkReviewTableBody tr');
    rows.forEach(row => {
        let globalIndex = row.getAttribute('data-index');
        if (globalIndex !== null && bulkAccountsCache[globalIndex]) {
            let recInput = row.querySelector('.recipient-input');
            let ccInput = row.querySelector('.cc-input');
            if (recInput) bulkAccountsCache[globalIndex].recipient_email = recInput.value;
            if (ccInput) bulkAccountsCache[globalIndex].cc_emails = ccInput.value;
        }
    });
}

function updateCacheField(index, field, value) {
    if (bulkAccountsCache[index]) {
        bulkAccountsCache[index][field] = value;
    }
}

function renderBulkReviewTable() {
    saveCurrentBulkPageInputs();

    const tbody = document.getElementById('bulkReviewTableBody');
    tbody.innerHTML = '';

    let itemsToDisplay = [...bulkAccountsCache];

    if (currentBulkSortField) {
        itemsToDisplay.sort((a, b) => {
            let valA = '', valB = '';
            if (currentBulkSortField === 'status') {
                valA = (a.recipient_email && a.recipient_email.trim() !== '') ? 'Ready' : 'Missing';
                valB = (b.recipient_email && b.recipient_email.trim() !== '') ? 'Ready' : 'Missing';
            } else if (currentBulkSortField === 'account_number') {
                valA = a.account_number || '';
                valB = b.account_number || '';
            } else if (currentBulkSortField === 'recipient_email') {
                valA = a.recipient_email || '';
                valB = b.recipient_email || '';
            }

            if (valA < valB) return currentBulkSortDir === 'asc' ? -1 : 1;
            if (valA > valB) return currentBulkSortDir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    const totalRows = itemsToDisplay.length;
    let effectiveLimit = bulkRowsPerPage === 'ALL' ? totalRows : parseInt(bulkRowsPerPage);
    const totalPages = bulkRowsPerPage === 'ALL' ? 1 : (Math.ceil(totalRows / effectiveLimit) || 1);
    if (currentBulkPage > totalPages) currentBulkPage = totalPages;

    const startIdx = bulkRowsPerPage === 'ALL' ? 0 : (currentBulkPage - 1) * effectiveLimit;
    const endIdx = bulkRowsPerPage === 'ALL' ? totalRows : Math.min(startIdx + effectiveLimit, totalRows);
    const paginatedItems = itemsToDisplay.slice(startIdx, endIdx);

    paginatedItems.forEach((acc) => {
        let globalIndex = bulkAccountsCache.findIndex(item => item.dm_id === acc.dm_id);
        let hasEmail = acc.recipient_email && acc.recipient_email.trim() !== '';
        let statusBadge = hasEmail 
            ? `<span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-[9px] font-bold">🟢 Ready</span>` 
            : `<span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-[9px] font-bold">🔴 Missing</span>`;
        
        let rowBg = hasEmail ? '' : 'bg-red-50/50';

        tbody.innerHTML += `
            <tr class="${rowBg} border-b" data-index="${globalIndex}">
                <td class="p-2.5 text-center">${statusBadge}</td>
                <td class="p-2.5">
                    <input type="hidden" name="bulk_dm_ids[]" value="${acc.dm_id}">
                    <div class="font-bold text-gray-800">${acc.account_number}</div>
                    <div class="text-[10px] text-gray-500">${acc.company}</div>
                </td>
                <td class="p-2.5">
                    <input type="email" name="bulk_recipients[]" value="${acc.recipient_email || ''}" placeholder="Ilagay ang email dito..." class="w-full p-1.5 border rounded text-xs bg-white recipient-input" oninput="updateCacheField(${globalIndex}, 'recipient_email', this.value)">
                </td>
                <td class="p-2.5">
                    <input type="text" name="bulk_ccs[]" value="${acc.cc_emails || ''}" placeholder="CC emails..." class="w-full p-1.5 border rounded text-xs bg-white cc-input" oninput="updateCacheField(${globalIndex}, 'cc_emails', this.value)">
                </td>
                <td class="p-2.5 text-center">
                    <button type="button" onclick="previewEmailBeforeSend('${acc.dm_id}', '')" class="px-2.5 py-1 bg-blue-50 text-blue-600 border border-blue-200 rounded text-[10px] font-bold hover:bg-blue-100">
                        <i class="las la-eye"></i> Preview
                    </button>
                </td>
            </tr>
        `;
    });

    document.getElementById('bulkPaginationInfo').innerText = totalRows > 0 ? `Showing ${startIdx + 1} to ${endIdx} of ${totalRows} accounts` : `Showing 0 to 0 of 0 accounts`;
}

function changeBulkPage(direction) {
    saveCurrentBulkPageInputs();
    let totalRows = bulkAccountsCache.length;
    let effectiveLimit = bulkRowsPerPage === 'ALL' ? totalRows : parseInt(bulkRowsPerPage);
    let totalPages = bulkRowsPerPage === 'ALL' ? 1 : (Math.ceil(totalRows / effectiveLimit) || 1);

    currentBulkPage += direction;
    if (currentBulkPage < 1) currentBulkPage = 1;
    if (currentBulkPage > totalPages) currentBulkPage = totalPages;
    renderBulkReviewTable();
}

function changeBulkRowsPerPage(val) {
    bulkRowsPerPage = val;
    currentBulkPage = 1;
    renderBulkReviewTable();
}

function sortBulkTable(field) {
    if (currentBulkSortField === field) {
        currentBulkSortDir = currentBulkSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        currentBulkSortField = field;
        currentBulkSortDir = 'asc';
    }
    renderBulkReviewTable();
}

function proceedBulkDispatchFromModal() {
    saveCurrentBulkPageInputs();
    closeBulkEmailReviewModal();

    let dmIds = [];
    let recipients = [];
    let ccs = [];

    bulkAccountsCache.forEach(item => {
        dmIds.push(item.dm_id);
        recipients.push(item.recipient_email || '');
        ccs.push(item.cc_emails || '');
    });

    if (dmIds.length === 0) {
        alert("No accounts selected for dispatch.");
        return;
    }
    let startDate = document.querySelector('input[name="start_date"]') ? document.querySelector('input[name="start_date"]').value : '';
    let endDate = document.querySelector('input[name="end_date"]') ? document.querySelector('input[name="end_date"]').value : '';

    let formData = new FormData();
    formData.append('dm_ids', dmIds.join(','));
    formData.append('bulk_recipients', JSON.stringify(recipients));
    formData.append('bulk_ccs', JSON.stringify(ccs));
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);

    executeDispatchFetch(formData, dmIds.length);
}

function sendEmailFromBreakdown() {
    const breakdownModal = document.getElementById('breakdownModal');
    if (!breakdownModal || breakdownModal.classList.contains('hidden') || !activeDmId) {
        alert("Breakdown modal is not open or no active account selected.");
        return;
    }

    let itemIds = [];
    const modalCheckboxes = breakdownModal.querySelectorAll('tbody input.item-checkbox:checked');
    modalCheckboxes.forEach(cb => { itemIds.push(cb.value); });

    if (itemIds.length === 0) {
        alert("Please select at least one coverage row from the breakdown to send.");
        return;
    }

    previewEmailBeforeSend(activeDmId, itemIds.join(','));
}

function previewEmailBeforeSend(dmId, itemIds = '') {
   const startDate = document.querySelector('input[name="start_date"]') ? document.querySelector('input[name="start_date"]').value : '';
    const endDate = document.querySelector('input[name="end_date"]') ? document.querySelector('input[name="end_date"]').value : '';

    let url = `list_dm.php?action=get_email_preview&dm_id=${dmId}&item_ids=${itemIds}&start_date=${startDate}&end_date=${endDate}`;

    fetch(url)
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('preview_dm_id').value = dmId;
            document.getElementById('preview_item_ids').value = itemIds;
            document.getElementById('preview_to').value = data.recipient_email;
            document.getElementById('preview_cc').value = data.cc_emails || '';
            document.getElementById('preview_subject').value = data.subject;
            document.getElementById('preview_body').value = data.html_content;

            let attContainer = document.getElementById('preview_attachments_container');
            attContainer.innerHTML = '';
            
            if (data.attachments && data.attachments.length > 0) {
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
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(err => {
        console.error("Preview fetch error:", err);
        alert("Failed to fetch email preview details.");
    });
}

function closeEmailPreviewModal() {
    document.getElementById('emailPreviewModal').style.display = 'none';
}

function submitConfirmedEmail() {
    const form = document.getElementById('emailPreviewForm');
    const formData = new FormData(form);

    // Kunin ang mga minanong type sa preview modal
    const recipientVal = document.getElementById('preview_to').value;
    const ccVal = document.getElementById('preview_cc').value;
    const dmId = document.getElementById('preview_dm_id').value;

    // I-format para tanggapin ng backend handler kahit walang email mapping sa database
    formData.set('dm_ids', dmId);
    formData.set('bulk_recipients', JSON.stringify([recipientVal]));
    formData.set('bulk_ccs', JSON.stringify([ccVal]));

    const mainStartDate = document.querySelector('input[name="start_date"]') ? document.querySelector('input[name="start_date"]').value : '';
    const mainEndDate = document.querySelector('input[name="end_date"]') ? document.querySelector('input[name="end_date"]').value : '';

    if (!formData.get('start_date') && mainStartDate) {
        formData.append('start_date', mainStartDate);
    }
    if (!formData.get('end_date') && mainEndDate) {
        formData.append('end_date', mainEndDate);
    }

    // Isama ang mga karagdagang in-upload na file kung meron man
    const fileInput = document.getElementById('additional_attachments');
    if (fileInput && fileInput.files.length > 0) {
        for (let i = 0; i < fileInput.files.length; i++) {
            formData.append('additional_attachments[]', fileInput.files[i]);
        }
    }

    closeEmailPreviewModal();
    executeDispatchFetch(formData, 1);
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
    if (modal) {
        modal.style.display = 'none';
    }
    window.location.reload();
}
//email Hub

function openEmailHubModal() {
    const modal = document.getElementById('emailHubModal');
    if (modal) {
        modal.style.display = 'flex';
        switchEmailHubTab(1); // Default to Tab 1 on open
    }
}

function closeEmailHubModal() {
    const modal = document.getElementById('emailHubModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function switchEmailHubTab(tabNumber) {
    const tab1 = document.getElementById('emailHubTab1');
    const tab2 = document.getElementById('emailHubTab2');
    const btn1 = document.getElementById('tabBtn1');
    const btn2 = document.getElementById('tabBtn2');

    if (tabNumber === 1) {
        tab1.style.display = 'block';
        tab2.style.display = 'none';
        
        btn1.className = "flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all";
        btn2.className = "flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all";
    } else {
        tab1.style.display = 'none';
        tab2.style.display = 'block';
        
        btn2.className = "flex-1 pb-2 text-xs font-bold text-amber-600 border-b-2 border-amber-600 transition-all";
        btn1.className = "flex-1 pb-2 text-xs font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition-all";
    }
}

// Auto count items in Tab 1 textarea
document.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('pasteAccountInput');
    if (textarea) {
        textarea.addEventListener('input', function() {
            const lines = this.value.trim().split(/\r*\n/).filter(line => line.trim() !== '');
            document.getElementById('pasteItemCount').innerText = `${lines.length} items found`;
        });
    }
});

function handleCsvFileSelect(input) {
    if (input.files && input.files[0]) {
        document.getElementById('selectedCsvFileName').innerText = `Selected file: ${input.files[0].name}`;
    }
}

function downloadEmailCsvTemplate() {
    // Generate the CSV template based on the custom headers and format discussed
    const csvContent = "data:text/csv;charset=utf-8,account_number,start_date,end_date,custom_to,custom_cc,custom_subject,custom_body\n" +
        "123456,2026-01-01,2026-01-31,clientA@email.com,boss@clientA.com,Notice for Account 123456,\"Hello Client A, eto po ang SOA niyo...\"\n" +
        "789012,2026-01-01,2026-01-31,clientB@email.com,,,\"ito yung napagusapan nten knina on nakalimutan mona\"";
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "email_hub_custom_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function processPasteEmailHub() {
    const rawText = document.getElementById('pasteAccountInput').value;
    if (!rawText.trim()) {
        alert("Mangyaring mag-lagay ng account numbers o emails.");
        return;
    }

    fetch('list_dm.php?action=get_dm_ids_by_paste', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ pasted_text: rawText })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success' && data.dm_ids.length > 0) {
            closeEmailHubModal();
            openBulkEmailReviewModal(data.dm_ids);
        } else {
            alert("Walang nakitang tumutugmang account sa iyong listahan.");
        }
    })
    .catch(err => console.error("Error:", err));
}

function processCsvEmailHub() {
    const fileInput = document.getElementById('csvEmailFile');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a CSV file to upload first.');
        return;
    }
    alert('CSV file successfully uploaded for the Email Hub.');
    closeEmailHubModal();
}


</script>

<?php
$content = ob_get_clean();
render_layout("Debit Memo Statements Management", $content);
?>