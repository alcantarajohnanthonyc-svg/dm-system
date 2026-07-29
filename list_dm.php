<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

session_start();
require_once 'config.php';
require_once 'main.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

session_start();
require_once 'config.php';



if (isset($_GET['account_number'])) {
    $acc = $_GET['account_number'];
    $stmt = $conn->prepare("SELECT company, assignee_name, mobile_number, carrier_name FROM debit_memos dm 
                            JOIN debit_memo_items dmi ON dm.dm_id = dmi.dm_id 
                            WHERE dm.account_number = ? LIMIT 1");
    $stmt->execute([$acc]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit; // Crucial: Stop execution so the rest of the page doesn't load
}

// 1. Setup Parameters
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit_input = isset($_GET['limit']) ? $_GET['limit'] : '20';
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_by   = isset($_GET['search_by']) ? trim($_GET['search_by']) : 'all'; // Dropdown value: all, account_number, company, carrier, phone_number
$carrier     = isset($_GET['carrier']) ? trim($_GET['carrier']) : '';
$start_date  = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date    = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$eff_start = !empty($start_date) ? $start_date : '1900-01-01';
$eff_end   = !empty($end_date) ? $end_date : '2999-12-31';

$limit  = ($limit_input === 'ALL') ? 999999 : (in_array((int)$limit_input, [20, 50, 100]) ? (int)$limit_input : 20);
$offset = ($page - 1) * $limit;

// 2. Build Unified Subquery (Strict Inclusion)
// Using >= and <= ensures only items within the specific range are summed
$filteredSubQuery = "SELECT dm_id, 
                            GROUP_CONCAT(DISTINCT carrier_name SEPARATOR '|') as carrier_names, 
                            MIN(coverage_start) as start_date, 
                            MAX(coverage_end) as end_date, 
                            SUM(CAST(debit_memo_details AS DECIMAL(10,2))) as filtered_total
                     FROM `debit_memo_items`
                     WHERE coverage_start >= :where_start 
                       AND coverage_end <= :where_end
                     GROUP BY dm_id";

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
        // Carrier search looks inside the aggregated subquery or carrier fields
        $whereClause .= " AND sub.carrier_names LIKE :search";
        $params['search'] = "%$search%";
    } elseif ($search_by === 'phone_number') {
        // Checks if the phone number matches via join items
        $whereClause .= " AND dm.dm_id IN (SELECT dm_id FROM debit_memo_items WHERE mobile_number LIKE :search)";
        $params['search'] = "%$search%";
    } else {
        // 'all' option: searches across account, company, carrier, and phone number simultaneously
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

// Define allowed columns to prevent SQL injection
$sort_map = [
    '1' => 'dm.account_number',
    '2' => 'dm.company',
    '3' => 'sub.carrier_names',
    '4' => 'sub.start_date',
    '5' => 'sub.filtered_total'
];
$order_col = isset($sort_map[$sort_by]) ? $sort_map[$sort_by] : 'dm.created_at';

// 2. Update your Main Query
$sql = "SELECT dm.*, sub.carrier_names, sub.start_date, sub.end_date, sub.filtered_total as sum_debit_memo_details
        FROM `debit_memos` AS dm
        INNER JOIN ($filteredSubQuery) AS sub ON dm.dm_id = sub.dm_id
        $whereClause";

if ($carrier !== '') {
    $sql .= " AND sub.carrier_names LIKE :carrier";
}

// Add dynamic ordering
$sql .= " ORDER BY $order_col $sort_dir LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$memos = $stmt->fetchAll();

ob_start();

?>
<style>
    /* STABILITY FIX: Prevents overlapping and layout jumping on zoom */
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
          <div class="flex gap-1">
        <?php if ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin'): ?>
            <button type="button" onclick="openAddEditModal(activeDmId || '')" class="px-3 py-1.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-md text-[10px] font-bold hover:bg-indigo-100">
                <i class="las la-plus mr-1"></i> Add
            </button>
            <button type="button" onclick="window.location.href='import_dm.php'" class="px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-md text-[10px] font-bold hover:bg-emerald-100">
                <i class="las la-file-import mr-1"></i> Import
            </button>
        <?php endif; ?>

      <button type="button" onclick="bulkExportPDF()" class="px-3 py-1.5 bg-rose-50 text-rose-700 border border-rose-200 rounded-md text-[10px] font-bold hover:bg-rose-100">
      <i class="las la-file-export mr-1"></i> Export
  </button>
    
        <button type="button" 
                onclick="document.getElementById('pasteExportModal').classList.remove('hidden')" 
                class="px-3 py-1.5 bg-blue-50 text-blue-700 border border-blue-200 rounded-md text-[10px] font-bold hover:bg-blue-100">
            <i class="las la-clipboard-list mr-1"></i> Paste Exp
        </button>

        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <button type="button" onclick="deleteSelected()" class="px-3 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-md text-[10px] font-bold hover:bg-red-100">
            <i class="las la-trash mr-1"></i> Delete Selected
        </button>
    <?php endif; ?>
    </div>

<div class="flex gap-1 w-full md:w-auto items-center">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="px-2 py-1.5 border rounded-md text-[10px] w-full md:w-55 bg-gray-50" placeholder="Search by mobile number or details...">

    <div class="flex items-center gap-1">
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="px-2 py-1.5 border rounded-md text-[10px] bg-gray-50">
        <span class="text-[9px] font-bold text-gray-400">TO</span>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="px-2 py-1.5 border rounded-md text-[10px] bg-gray-50">
    </div>

<!-- Dropdown for Search Field Selection -->
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
    // Prepare all current filters to be passed to pagination links
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
        
        <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" 
            onclick="window.location='?sort=1&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
            Account Number &uarr;&darr;
        </th>
        
        <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" 
            onclick="window.location='?sort=2&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
            Company &uarr;&darr;
        </th>
        
        <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" 
            onclick="window.location='?sort=3&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
            Carrier &uarr;&darr;
        </th>
        
        <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 cursor-pointer hover:text-black" 
            onclick="window.location='?sort=4&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
            Date Covered &uarr;&darr;
        </th>
        
        <th class="py-3 px-2 sticky top-0 bg-gray-50 z-10 text-right cursor-pointer hover:text-black" 
            onclick="window.location='?sort=5&dir=<?= ($sort_dir == 'ASC' ? 'desc' : 'asc') . '&' . $base_query ?>'">
            Total Debit Memo &uarr;&darr;
        </th>
        
        <th class="pr-3 py-3 sticky top-0 bg-gray-50 z-10 text-center">Actions</th>
    </tr>
</thead>
<tbody class="divide-y text-sm text-gray-600">
    <?php 
    // Helper function to define the badges
function getCarrierBadge($carrierName) {
    // Convert to uppercase for case-insensitive matching
    $name = strtoupper($carrierName);
    
    $colors = [
        'GLOBE'    => 'bg-blue-100 text-blue-700 border-blue-200',
        'SMART'    => 'bg-green-100 text-green-700 border-green-200',
        'DITO'     => 'bg-red-100 text-red-700 border-red-200',
        'CONVERGE' => 'bg-purple-100 text-purple-700 border-purple-200'
    ];

    // Determine the short display name
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
        <td class="pl-6 py-3"><input type="checkbox" class="dm-checkbox" value="<?= $row['dm_id'] ?>"></td>
      <td class="py-3 px-4 font-semibold text-gray-900">
    <span class="cursor-help border-b border-dashed border-gray-400" 
          title="Company: <?= htmlspecialchars($row['company']) ?>&#10;Assignee: <?= htmlspecialchars($row['assignee_name']) ?>">
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
    <?php 
    // Format: MARCH 02 2026
    echo $row['start_date'] ? date('F d Y', strtotime($row['start_date'])) : '-'; 
    ?> 
    <br> to <br> 
    <?php 
    echo $row['end_date'] ? date('F d Y', strtotime($row['end_date'])) : '-'; 
    ?>
</td>
        
<td class="py-3 px-4 text-right font-bold text-rose-600">
    <?php 
        $total = isset($row['sum_debit_memo_details']) ? $row['sum_debit_memo_details'] : 0;
        echo '₱' . number_format($total, 2); 
    ?>
</td>
        
<td class="pr-6 py-3 text-center">
    <div class="flex items-center justify-center gap-4">
        <button type="button" 
            onclick="openBreakdownModal(<?= $row['dm_id'] ?>, 
                '<?= htmlspecialchars($row['account_number']) ?>', 
                '<?= htmlspecialchars(addslashes($row['company'])) ?>',
                '<?= htmlspecialchars(addslashes($row['assignee_name'])) ?>'
            )" 
            class="text-blue-600 hover:text-blue-800 transition">
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
                
                <button type="button" onclick="exportSelectedItems()" class="bg-green-600 text-white px-4 py-2 rounded text-xs font-bold hover:bg-green-700">
                    EXPORT SELECTED
                </button>
                <button onclick="closeAndRefresh()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-xs font-bold">
                    CLOSE
                </button>
            </div>
        </div>

        <div class="overflow-x-auto border rounded-lg flex-grow">
            <table class="w-full text-left border-collapse text-[10px]">
                <thead class="bg-blue-300 text-black uppercase font-bold sticky top-0 z-10">
                    <tr>
                        <th class="p-2 border border-gray-400 text-center"><input type="checkbox" id="modalSelectAll" onclick="toggleModalCheckboxes(this)"></th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-yellow-300">Coverage Date</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-green-400">Mobile Number</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Approved Plan</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Phone Amortization</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Debit Adj</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Credit Adj</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Other Charges</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Local (Call/Text)</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">NDD (National)</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">IDD (International)</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Roam</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">SMS</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">GPRS</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Wiz Usage</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Loading Charges</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">VAT</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">OCT</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Current Charges</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-blue-300">Total Amount Due</th>
                        <th class="p-2 border border-gray-400 whitespace-normal text-center bg-green-400">Debit Memo</th>
                        <th class="p-2 border border-gray-400 text-center">ACTION</th>
                    </tr>
                </thead>
                <tbody id="modalContentBody">
                    <!-- Dynamic rows loaded via get_breakdown.php -->
                </tbody>
            </table>
        </div>
    </div>
</div>


<div id="pasteExportModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-[90%] max-w-lg p-6">
        
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-bold text-lg">Export Paste (Account/Mobile)</h3>
            <button type="button" onclick="closePasteModal()" class="text-gray-500">✕</button>
        </div>
        
        <form action="export_paste.php" method="POST" id="pasteExportForm">
            
            <span id="accountCount" class="text-xs font-bold text-blue-600 block mb-1">0 items found</span>
            
            <textarea id="exportPasteArea" 
              name="accounts"
              oninput="updateCount()" 
              class="w-full h-40 p-3 border rounded-md text-sm mb-4 bg-gray-50" 
              placeholder="Paste account numbers..." required></textarea>
              
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

            <!-- Hidden input to track whether user chose Excel or PDF -->
            <input type="hidden" name="export_type" id="pasteExportType" value="excel">

            <!-- Trigger Button calls processExportPaste() to trigger choice modal -->
            <button type="button" 
                onclick="processExportPaste()" 
                class="w-full bg-slate-800 text-white py-2.5 rounded-md font-bold text-sm hover:bg-slate-900">
                Export Records
            </button>
        </form>
    </div>
</div>

<!-- Format Choice Sub-Modal -->
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

<script>
function openPasteChoiceModal() {
    const textarea = document.getElementById('exportPasteArea');
    if (!textarea.value.trim()) {
        alert('Please paste account or mobile numbers first.');
        return;
    }
    document.getElementById('pasteChoiceModal').style.display = 'flex';
}

function submitPasteExport(type) {
    document.getElementById('pasteExportType').value = type;
    document.getElementById('pasteChoiceModal').style.display = 'none';
    document.getElementById('pasteExportModal').classList.add('hidden');
    document.getElementById('pasteExportForm').submit();
}
</script>

<div id="addEditModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl p-6 max-h-[90vh] overflow-y-auto">
     <h3 id="modalFormTitle" class="font-bold text-xl">Add Debit Memo Item</h3>
        
<form id="addEditForm" method="POST" action="save_record.php" novalidate>
<input type="hidden" id="form_dm_id" name="id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-5 gap-4 mb-6 bg-gray-50 p-4 rounded-md">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Account #</label>
                   <input type="text" name="account_number" list="acc_list" 
       class="w-full p-2 border rounded-md text-xs" 
       onchange="fetchAccountDetails(this.value)" 
       onblur="fetchAccountDetails(this.value)"
       placeholder="Search/Select..." required>
                    <div id="accFeedback" class="text-[9px] font-bold mt-1" placeholder="Select/Type..."></div>
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
                    'loading' => 'Loading CAHRGES',
                   'vat' => 'VAT', 
                   'oct' => 'Overseas communication Tax',
                    'current_charges' => 'Current Charges', 
                    'total_amount_due' => 'TTotal Amount Due', 
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
    <button type="button" onclick="closeModal()" 
            class="px-8 py-2 bg-gray-200 rounded-md text-xs font-bold hover:bg-gray-300 transition">
            CANCEL
    </button>
    <button type="submit" 
            class="px-8 py-2 bg-slate-800 text-white rounded-md text-xs font-bold hover:bg-slate-900 shadow-md transition">
            SAVE ITEM
    </button>
</div>
        </form>
    </div>
</div><div id="addEditModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl p-6 max-h-[90vh] overflow-y-auto">
     <h3 id="modalFormTitle" class="font-bold text-xl">Add Debit Memo Item</h3>
        
<form id="addEditForm" method="POST" action="save_record.php" novalidate>
<input type="hidden" id="form_dm_id" name="id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-5 gap-4 mb-6 bg-gray-50 p-4 rounded-md">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Account #</label>
                   <input type="text" name="account_number" list="acc_list" 
       class="w-full p-2 border rounded-md text-xs" 
       onchange="fetchAccountDetails(this.value)" 
       onblur="fetchAccountDetails(this.value)"
       placeholder="Search/Select..." required>
                    <div id="accFeedback" class="text-[9px] font-bold mt-1" placeholder="Select/Type..."></div>
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
                    'loading' => 'Loading CAHRGES',
                   'vat' => 'VAT', 
                   'oct' => 'Overseas communication Tax',
                    'current_charges' => 'Current Charges', 
                    'total_amount_due' => 'TTotal Amount Due', 
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
    <button type="button" onclick="closeModal()" 
            class="px-8 py-2 bg-gray-200 rounded-md text-xs font-bold hover:bg-gray-300 transition">
            CANCEL
    </button>
    <button type="submit" 
            class="px-8 py-2 bg-slate-800 text-white rounded-md text-xs font-bold hover:bg-slate-900 shadow-md transition">
            SAVE ITEM
    </button>
</div>
        </form>
    </div>
</div>


<!-- Modern Light Unified Export Choice Modal -->
<div id="unifiedExportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: #ffffff; padding: 30px; border-radius: 16px; width: 360px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; position: relative; border: 1px solid #f1f5f9;">
        
        <!-- Close Button -->
        <button onclick="closeUnifiedExportModal()" style="position: absolute; top: 14px; right: 16px; background: #f8fafc; border: none; color: #64748b; width: 28px; height: 28px; border-radius: 50%; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s;">&times;</button>
        
        <!-- Icon / Header Graphic -->
        <div style="width: 48px; height: 48px; background: #eff6ff; color: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; font-size: 20px;">
            <i class="las la-file-export"></i>
        </div>

        <!-- Title -->
        <h3 style="color: #0f172a; font-size: 16px; font-weight: 600; margin-bottom: 8px;">
            Export Options
        </h3>
        
        <!-- Subtitle -->
        <p style="color: #64748b; font-size: 13px; margin-bottom: 24px;">
            Choose your preferred file format to continue.
        </p>
        
        <!-- Action Buttons -->
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
<script>

///EXPORT

let currentExportContext = ''; 

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

            // Strict validation: check if the first token looks like a valid account/mobile number 
            // (e.g., only numbers, and at least 5 to 15 digits long, adjust length as needed)
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
    // 1. Clear fields completely
    const pasteArea = document.getElementById('exportPasteArea');
    const startDate = document.getElementById('exportStartDate');
    const endDate = document.getElementById('exportEndDate');
    const countSpan = document.getElementById('accountCount');

    if (pasteArea) pasteArea.value = '';
    if (startDate) startDate.value = '';
    if (endDate) endDate.value = '';
    if (countSpan) countSpan.innerText = '0 items found';

    // 2. Show modal
    document.getElementById('pasteExportModal').classList.remove('hidden');
}

function closePasteModal() {
    // Hide modal and clear data on close as well
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


//export end


let activeDmId = 0; 

function openBreakdownModal(dm_id, accountNum, company, assignee) {
    activeDmId = dm_id; 
    const startDate = document.querySelector('input[name="start_date"]').value;
    const endDate = document.querySelector('input[name="end_date"]').value;

    fetch('get_breakdown.php?dm_id=' + dm_id + '&start=' + startDate + '&end=' + endDate)
    .then(response => response.text())
    .then(html => {
        // Kapag nag-inject ka ng HTML, siguraduhin na ang input checkbox ay may class="w-3 h-3"
        document.getElementById('modalContentBody').innerHTML = html;
        document.getElementById('modalAccountNumber').innerText = "Account Number: " + accountNum;
        document.getElementById('modalCompany').innerText = "Company: " + company;
        document.getElementById('breakdownModal').classList.remove('hidden');
    });
}
function toggleModalCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = source.checked);
}
// Function para sa Export button sa loob ng modal


function toggleSelectAll(source) {
    document.querySelectorAll('.dm-checkbox').forEach(cb => cb.checked = source.checked);
}



function handleSearch() {
    // I-capture ang values
    const search = document.querySelector('input[name="search"]').value;
    const carrier = document.querySelector('select[name="carrier"]').value;
    
    // Force reload sa main page na may dalang parameters
    window.location.href = 'list_dm.php?search=' + encodeURIComponent(search) + '&carrier=' + encodeURIComponent(carrier);
}
function deleteBreakdownItem(item_id) {
    if (!confirm("Are you sure you want to delete this record?")) return;
    
    fetch('delete_breakdown_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'item_id=' + item_id
    }).then(() => {
        // Refresh the modal content
        openBreakdownModal(activeDmId, '', '', '');
    });
}

function toggleModalCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}

// 2. DELETE SELECTED
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

    // Gamit ang fetch API
    fetch('delete_bulk_dm.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'item_ids=' + ids.join(',') + '&dm_id=' + activeDmId
    })
    .then(response => response.text()) // Basahin muna bilang text para maiwasan ang silent JSON crash
    .then(rawText => {
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error("Invalid JSON response:", rawText);
            alert("Server Error: Response is not valid JSON. Check console.");
            return;
        }

        if (data.status === 'success') {
            if (data.account_deleted) {
                alert("Notification: Account " + accountNum + " has been removed.");
                window.location.reload(); 
            } else {
                // I-update ang total sa screen (RECOMPUTATION)
                const totalDisplay = document.getElementById('totalDebitMemoDisplay');
                if(totalDisplay) {
                    totalDisplay.innerText = '₱' + data.new_total;
                }
                
                // Burahin ang mga rows sa table nang hindi nagre-reload
                checkedBoxes.forEach(cb => cb.closest('tr').remove());
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => console.error('Fetch Error:', error));
}
// 3. EXPORT SELECTED

function closeAndRefresh() {
    // 1. Isara ang modal
    const modal = document.getElementById('breakdownModal');
    if (modal) {
        modal.classList.add('hidden');
    }

    // 2. I-reload ang page para ma-recompute lahat ng totals sa listahan
    // Ito ang pinaka-reliable na paraan para siguro ang accuracy ng data
    window.location.reload();
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
            // New Account: Clear fields to allow user input
            form.querySelector('input[name="company"]').value = '';
            form.querySelector('input[name="assignee"]').value = '';
            form.querySelector('input[name="mobile_number"]').value = '';
            form.querySelector('input[name="carrier"]').value = '';
            
            feedback.innerText = "⚠ New Account - Please enter details";
            feedback.className = "text-[9px] font-bold text-amber-600 mt-1";
        }
    });
}
// Remove the old DOMContentLoaded block and use this:
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

window.lastOpenedDmId = null;

    // 2. Place the function here
function loadBreakdown(dm_id) {
    // Save to global so other functions can use it
    activeDmId = dm_id;
    
    const timestamp = new Date().getTime();
    // We only pass the dm_id. Make sure get_breakdown.php handles 
    // the dates internally or doesn't strictly require them.
    fetch('get_breakdown.php?dm_id=' + dm_id + '&t=' + timestamp)
    .then(response => response.text())
    .then(html => {
        document.getElementById('modalContentBody').innerHTML = html;
        document.getElementById('breakdownModal').classList.remove('hidden');
    });
}
    // 3. IMPORTANT: Update your existing Form Submit handler to use this
document.getElementById('addEditForm').addEventListener('submit', function(e) {
    e.preventDefault(); 
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    
    // Validate Text Fields
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

    // Validate Amount Fields
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
        // Reset button state
        submitBtn.innerText = originalText;
        submitBtn.disabled = false;

        if (data.status === 'success') {
            closeModal();
            
            // 1. Reset all standard form inputs
            formElement.reset();
            
            // 2. CLEAR THE HIDDEN ID FIELD SO FUTURE SAVES DON'T STICK TO THE OLD RECORD
            const formDmIdInput = document.getElementById('form_dm_id');
            if (formDmIdInput) {
                formDmIdInput.value = '';
            }

            // Refresh modal contents dynamically if open, or fallback
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
    
    // Unlock and clear the Account Number for a new entry
    const accInput = form.querySelector('input[name="account_number"]');
    accInput.readOnly = false;
    accInput.value = '';

    document.getElementById('modalFormTitle').innerText = "Add Debit Memo Item";
    document.getElementById('form_dm_id').value = dm_id;
    
    document.getElementById('addEditModal').classList.remove('hidden');
}

// FUNCTION 2: FOR EDITING (Ensures it is populated THEN locked)
function editBreakdownItem(itemId) {
    fetch('get_item_data.php?id=' + itemId)
    .then(res => res.text()) // First read as text to debug what is actually returning
    .then(text => {
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error("Server returned invalid JSON:", text);
            alert("Error: get_item_data.php did not return valid JSON. Check console for details.");
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
            'debit_memo_details': data.debit_memo_details
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
    })
    .catch(err => console.error("Fetch Error:", err));
}

function deleteSelected() {
    // 1. Get all checked checkboxes from the main table
    const checkboxes = document.querySelectorAll('.dm-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);

    if (ids.length === 0) {
        alert("Please select at least one account to delete.");
        return;
    }

    if (!confirm("Are you sure you want to delete these " + ids.length + " accounts? This will delete all associated data.")) {
        return;
    }

    // 2. Send IDs to the new bulk handler
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
            window.location.reload(); // Refresh table
        } else {
            alert("Error: " + data.message);
        }
    });
}



let downloadTimer;

function checkDownloadCookie() {
    if (document.cookie.indexOf('fileDownloadToken=success') !== -1) {
        // Clear the cookie
        document.cookie = 'fileDownloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';

        // 1. Uncheck main table row checkboxes
        document.querySelectorAll('.dm-checkbox').forEach(chk => {
            chk.checked = false;
        });
        const selectAllChk = document.getElementById('selectAll');
        if (selectAllChk) selectAllChk.checked = false;

        // 2. Uncheck breakdown modal row checkboxes and master checkbox
        document.querySelectorAll('.item-checkbox').forEach(chk => {
            chk.checked = false;
        });
        const modalSelectAllChk = document.getElementById('modalSelectAll');
        if (modalSelectAllChk) modalSelectAllChk.checked = false;

        // Stop polling
        clearInterval(downloadTimer);
    }
}

// Modify your executeUnifiedExport function or trigger it when export starts
const originalExecuteUnifiedExport = window.executeUnifiedExport;
if (typeof executeUnifiedExport === 'function') {
    window.executeUnifiedExport = function(format) {
        originalExecuteUnifiedExport(format);
        // Start polling every 500ms for the download cookie response
        downloadTimer = setInterval(checkDownloadCookie, 500);
    };
}


</script>

<?php
$content = ob_get_clean();
render_layout("Debit Memo Statements Management", $content);
?>