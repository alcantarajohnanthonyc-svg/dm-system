<?php
session_start();
require_once 'config.php';
require_once 'main.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 1. Capture Date Filters (Backward compatible)
$start_date = (isset($_GET['start_date']) && !empty($_GET['start_date'])) ? $_GET['start_date'] : null;
$end_date   = (isset($_GET['end_date']) && !empty($_GET['end_date'])) ? $_GET['end_date'] : null;

// 2. Build Dynamic Date Clause
$date_clause = "";
$params = [];
if ($start_date && $end_date) {
    $date_clause = " WHERE m.coverage_start >= :start AND m.coverage_end <= :end ";
    $params = ['start' => $start_date, 'end' => $end_date];
}

// 3. Fetch Aggregated Data
// Total Accounts & Total Amount
$sql_summary = "SELECT COUNT(DISTINCT m.account_number) as total_accounts, 
                       SUM(i.debit_memo_details) as total_value 
                FROM debit_memos m 
                JOIN debit_memo_items i ON m.dm_id = i.dm_id $date_clause";
$stmt = $conn->prepare($sql_summary);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Carrier Stats
$sql_carrier = "SELECT UPPER(carrier_name) as carrier_name, 
                       COUNT(*) as memo_count, 
                       SUM(debit_memo_details) as total_amt 
                FROM debit_memo_items i 
                JOIN debit_memos m ON i.dm_id = m.dm_id $date_clause 
                GROUP BY carrier_name";
$stmt = $conn->prepare($sql_carrier);
$stmt->execute($params);
$carrierStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Company Stats
$sql_company = "SELECT UPPER(m.company) as company, 
                       COUNT(*) as memo_count, 
                       SUM(i.debit_memo_details) as total_amt 
                FROM debit_memos m 
                JOIN debit_memo_items i ON m.dm_id = i.dm_id $date_clause 
                GROUP BY m.company";
$stmt = $conn->prepare($sql_company);
$stmt->execute($params);
$companyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="mb-8 w-full">
    <div class="flex justify-end">
        <form method="GET" class="flex items-end gap-3 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase">From Date</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="px-3 py-2 border rounded-md text-xs">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase">To Date</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="px-3 py-2 border rounded-md text-xs">
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-md text-xs font-bold hover:bg-indigo-700">Filter</button>
            <a href="dashboard.php" class="px-3 py-2 bg-gray-100 text-gray-600 border border-gray-300 rounded-md text-xs font-bold hover:bg-gray-200">RESET</a>
        </form>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
    <div class="bg-indigo-600 p-10 rounded-3xl shadow-2xl text-white">
        <p class="text-indigo-200 uppercase tracking-widest font-bold">Total Unique Accounts</p>
        <h3 class="text-7xl font-extrabold mt-4"><?= number_format($summary['total_accounts']) ?></h3>
    </div>
    <div class="bg-rose-500 p-10 rounded-3xl shadow-2xl text-white">
        <p class="text-rose-100 uppercase tracking-widest font-bold">Total DM Amount</p>
        <h3 class="text-7xl font-extrabold mt-4">₱<?= number_format($summary['total_value'], 2) ?></h3>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-8">
    <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
        <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4">Breakdown per Telco</h3>
        <div class="space-y-6">
            <?php foreach ($carrierStats as $s): ?>
            <div class="flex justify-between items-center bg-blue-50 p-6 rounded-2xl">
                <p class="text-xl font-bold text-blue-900"><?= htmlspecialchars(isset($s['carrier_name']) ? $s['carrier_name'] : 'N/A') ?></p>
                <div class="text-right">
                    <p class="text-sm text-blue-600 font-bold"><?= number_format($s['memo_count']) ?> Memos</p>
                    <p class="text-2xl font-black text-blue-900">₱<?= number_format($s['total_amt'], 2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
        <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4">Breakdown per Company</h3>
        <div class="space-y-6">
            <?php foreach ($companyStats as $s): ?>
            <div class="flex justify-between items-center bg-rose-50 p-6 rounded-2xl">
                <div class="truncate w-1/2">
                    <p class="text-xl font-bold text-rose-900"><?= htmlspecialchars(isset($s['company']) ? $s['company'] : 'N/A') ?></p>
                    <p class="text-sm text-rose-600 font-bold"><?= number_format($s['memo_count']) ?> Memos</p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-black text-rose-900">₱<?= number_format($s['total_amt'], 2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
render_layout("Dashboard Overview", $content);
?>