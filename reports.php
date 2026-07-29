<?php
/**
 * Reports Center Module (reports.php)
 * Updated front-end view table columns to fully match the complete Excel export layout.
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'main.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}






$page_title = "Reports Center";

// Fetch dynamic dropdown options safely
try {
    $companies_stmt = $conn->query("SELECT DISTINCT company FROM debit_memos WHERE company IS NOT NULL AND company != '' ORDER BY company ASC");
    $companies = $companies_stmt ? $companies_stmt->fetchAll(PDO::FETCH_COLUMN) : array();

    $carriers_stmt = $conn->query("SELECT DISTINCT carrier_name FROM debit_memo_items WHERE carrier_name IS NOT NULL AND carrier_name != '' ORDER BY carrier_name ASC");
    $carriers = $carriers_stmt ? $carriers_stmt->fetchAll(PDO::FETCH_COLUMN) : array();
} catch (Exception $e) {
    $companies = array();
    $carriers = array();
}

// Fetch filter parameters safely
$report_type  = isset($_GET['report_type']) ? trim($_GET['report_type']) : 'SUMMARY';
$carrier_name = isset($_GET['carrier_name']) ? trim($_GET['carrier_name']) : '';
$company      = isset($_GET['company']) ? trim($_GET['company']) : '';
$start_date   = isset($_GET['start_date']) && !empty($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$end_date     = isset($_GET['end_date']) && !empty($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-t');
$action       = isset($_GET['action']) ? trim($_GET['action']) : '';
$page         = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page     = 25;
$offset       = ($page > 1) ? ($page - 1) * $per_page : 0;

/**
 * EXPORT ACTION: Generates and downloads an Excel spreadsheet with yearly tabs and complete columns
 */
if ($action === 'export') {
    try {
        $sql = "SELECT 
                    dm.account_number, 
                    dm.company AS dm_company, 
                    dm.assignee_name, 
                    dmi.carrier_name, 
                    dmi.mobile_number, 
                    dmi.coverage_start, 
                    dmi.coverage_end, 
                    dmi.approved_plan, 
                    dmi.phone_amortization, 
                    dmi.debit_adj, 
                    dmi.credit_adj, 
                    dmi.other_charges, 
                    dmi.local_call_text, 
                    dmi.ndd_charges, 
                    dmi.idd_charges, 
                    dmi.roaming_charges, 
                    dmi.sms_charges, 
                    dmi.gprs_charges, 
                    dmi.wiz_usage, 
                    dmi.loading_charges, 
                    dmi.vat, 
                    dmi.oct, 
                    dmi.current_charges, 
                    dmi.total_amount_due, 
                    dmi.debit_memo_details 
                FROM `debit_memo_items` AS dmi 
                INNER JOIN debit_memos AS dm 
                    ON dm.dm_id = dmi.dm_id 
                WHERE 1=1";
        $params = array();

        if (!empty($carrier_name)) {
            $sql .= " AND dmi.carrier_name = ?";
            $params[] = $carrier_name;
        }
        if (!empty($company)) {
            $sql .= " AND dm.company = ?";
            $params[] = $company;
        }
        if (!empty($start_date) && !empty($end_date)) {
            $sql .= " AND (dmi.coverage_start <= ? AND dmi.coverage_end >= ?)";
            $params[] = $end_date;
            $params[] = $start_date;
        }

        $sql .= " ORDER BY dmi.coverage_start ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group items by year
        $grouped_by_year = array();
        foreach ($results as $row) {
            $year = 'Unknown';
            if (!empty($row['coverage_start'])) {
                $parsed_year = date('Y', strtotime($row['coverage_start']));
                if ($parsed_year) {
                    $year = $parsed_year;
                }
            }
            if (!isset($grouped_by_year[$year])) {
                $grouped_by_year[$year] = array();
            }
            $grouped_by_year[$year][] = $row;
        }

        if (empty($grouped_by_year)) {
            $grouped_by_year[date('Y')] = array();
        }

        $filename = 'Debit_Memo_Report_' . date('Y-m-d_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<?mso-application progid="Excel.Sheet"?>';
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
        echo ' xmlns:html="http://www.w3.org/TR/REC-html40">';

        // Define Workbook Styles
        echo '<Styles>';
        echo '<Style ss:ID="HeaderYellow"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#fde047" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="HeaderGreen"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#4ade80" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="HeaderBlue"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#93c5fd" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="DataCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        echo '<Style ss:ID="RedCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#dc2626"/></Style>';
        echo '</Styles>';

        // Loop through each year to create separate sheets
        foreach ($grouped_by_year as $year => $year_items) {
            echo '<Worksheet ss:Name="Year ' . htmlspecialchars($year) . '">';
            echo '<Table>';

            $colWidths = [150, 140, 150, 130, 110, 130, 100, 100, 180, 130, 130, 140, 90, 80, 80, 90, 120, 80, 80, 130, 130, 100];
            foreach ($colWidths as $w) {
                echo '<Column ss:Width="' . $w . '"/>';
            }

            // Filter Information Rows
            echo '<Row>';
            echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">COMPANY FILTER: ' . (!empty($company) ? htmlspecialchars($company) : 'ALL COMPANIES') . '</Data></Cell>';
            echo '</Row>';
            echo '<Row>';
            echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">CARRIER FILTER: ' . (!empty($carrier_name) ? htmlspecialchars($carrier_name) : 'ALL CARRIERS') . '</Data></Cell>';
            echo '</Row>';
            echo '<Row></Row>';

            $headers = array(
                "COVERAGE DATE", "ACCOUNT NUMBER", "COMPANY", "ASSIGNEE", "CARRIER / TELCO", "MOBILE NUMBER", "APPROVED PLAN", "PHONE AMORTIZATION", 
                "DEBIT ADJ", "CREDIT ADJ", "OTHER CHARGES (PRE-TERM)", "LOCAL (CALL/TEXT)", 
                "NDD (NATIONAL)", "IDD (INTERNATIONAL)", "ROAM", "SMS", "GPRS", 
                "WIZ USAGE", "LOADING CHARGES", "VAT", "OCT", "CURRENT CHARGES", 
                "TOTAL AMOUNT DUE", "DEBIT MEMO"
            );

            echo '<Row>';
            foreach($headers as $index => $col) {
                $styleID = 'HeaderBlue';
                if ($index == 0) {
                    $styleID = 'HeaderYellow';
                } elseif ($index >= 1 && $index <= 5) {
                    $styleID = 'HeaderGreen';
                } elseif ($index == 23) {
                    $styleID = 'HeaderGreen';
                }
                echo '<Cell ss:StyleID="' . $styleID . '"><Data ss:Type="String">' . htmlspecialchars($col) . '</Data></Cell>';
            }
            echo '</Row>';

            foreach ($year_items as $row) {
                $start  = isset($row['coverage_start']) && $row['coverage_start'] ? date('M d, Y', strtotime($row['coverage_start'])) : '';
                $end    = isset($row['coverage_end']) && $row['coverage_end'] ? date('M d, Y', strtotime($row['coverage_end'])) : '';
                $dateText = ($start && $end) ? ($start . ' to ' . $end) : '';
                $acc    = isset($row['account_number']) ? $row['account_number'] : '';
                $comp   = isset($row['dm_company']) ? $row['dm_company'] : '';
                $assign = isset($row['assignee_name']) ? $row['assignee_name'] : '';
                $carrier= isset($row['carrier_name']) ? $row['carrier_name'] : '';
                $mob    = isset($row['mobile_number']) ? $row['mobile_number'] : '';

                echo '<Row>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($dateText) . '</Data></Cell>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($acc) . '</Data></Cell>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($comp) . '</Data></Cell>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($assign) . '</Data></Cell>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($carrier) . '</Data></Cell>';
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($mob) . '</Data></Cell>';

                $numericFields = array(
                    'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
                    'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
                    'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
                    'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 
                    'debit_memo_details'
                );

                foreach ($numericFields as $field) {
                    $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
                    $cellStyle = ($field === 'debit_memo_details') ? 'RedCell' : 'DataCell';
                    echo '<Cell ss:StyleID="' . $cellStyle . '"><Data ss:Type="Number">' . number_format($val, 2, '.', '') . '</Data></Cell>';
                }
                echo '</Row>';
            }

            echo '</Table>';
            echo '</Worksheet>';
        }

        echo '</Workbook>';
        exit;
    } catch (Exception $e) {
        echo "Export Error: " . $e->getMessage();
        exit;
    }
}

// Fetch preview listing and count when "View Report" is clicked
$reports = array();
$total_records = 0;
$total_pages = 1;

if ($action === 'view') {
    try {
        $base_sql = "FROM `debit_memo_items` AS dmi 
                     INNER JOIN debit_memos AS dm 
                         ON dm.dm_id = dmi.dm_id 
                     WHERE 1=1";
        $params = array();

        if (!empty($carrier_name)) {
            $base_sql .= " AND dmi.carrier_name = ?";
            $params[] = $carrier_name;
        }
        if (!empty($company)) {
            $base_sql .= " AND dm.company = ?";
            $params[] = $company;
        }
        if (!empty($start_date) && !empty($end_date)) {
            $base_sql .= " AND (dmi.coverage_start <= ? AND dmi.coverage_end >= ?)";
            $params[] = $end_date;
            $params[] = $start_date;
        }

        // Count total matching records for pagination
        $count_stmt = $conn->prepare("SELECT COUNT(*) " . $base_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ($total_records > 0) ? ceil($total_records / $per_page) : 1;

        // Fetch paginated records with all comprehensive item columns matching Excel
        $sql = "SELECT 
                    dm.account_number, 
                    dm.company AS dm_company, 
                    dm.assignee_name, 
                    dmi.carrier_name, 
                    dmi.mobile_number, 
                    dmi.coverage_start, 
                    dmi.coverage_end, 
                    dmi.approved_plan, 
                    dmi.phone_amortization, 
                    dmi.debit_adj, 
                    dmi.credit_adj, 
                    dmi.other_charges, 
                    dmi.local_call_text, 
                    dmi.ndd_charges, 
                    dmi.idd_charges, 
                    dmi.roaming_charges, 
                    dmi.sms_charges, 
                    dmi.gprs_charges, 
                    dmi.wiz_usage, 
                    dmi.loading_charges, 
                    dmi.vat, 
                    dmi.oct, 
                    dmi.current_charges, 
                    dmi.total_amount_due, 
                    dmi.debit_memo_details 
                " . $base_sql . " ORDER BY dmi.coverage_start DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reports = array();
    }
}

// Capture layout content
ob_start();
?>

<!-- Full width layout container without max-width restrictions -->
<div class="p-6 w-full space-y-6">
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-800 mb-6 uppercase tracking-wider border-b pb-2">Sales Report Center</h3>
        
        <form method="GET" action="reports.php" id="reportForm" class="space-y-4">
            <input type="hidden" name="page" value="1">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex items-center">
                    <label class="w-40 text-xs font-bold text-slate-700 uppercase">Sales Report :</label>
                    <select name="report_type" class="flex-1 text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="SUMMARY" <?php echo $report_type === 'SUMMARY' ? 'selected' : ''; ?>>ALL IN DM</option>
                    </select>
                </div>
                
                <div class="flex items-center">
                    <label class="w-40 text-xs font-bold text-slate-700 uppercase">Carrier / Telco :</label>
                    <select name="carrier_name" class="flex-1 text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- ALL CARRIER / TELCO --</option>
                        <?php foreach ($carriers as $carrier): ?>
                            <option value="<?php echo htmlspecialchars($carrier); ?>" <?php echo $carrier_name === $carrier ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($carrier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex items-center">
                    <label class="w-40 text-xs font-bold text-slate-700 uppercase">Company / Branch :</label>
                    <select name="company" class="flex-1 text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- ALL COMPANY --</option>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?php echo htmlspecialchars($comp); ?>" <?php echo $company === $comp ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($comp); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center">
                    <label class="w-40 text-xs font-bold text-slate-700 uppercase">Coverage Date :</label>
                    <div class="flex-1 flex items-center space-x-2">
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <span class="text-xs text-slate-500 font-bold">to</span>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <button type="submit" name="action" value="view" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-6 py-2.5 rounded-lg shadow transition duration-150 flex items-center space-x-1.5">
                        <i class="las la-search text-base"></i>
                        <span>VIEW REPORT</span>
                    </button>
                    <a href="reports.php" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-semibold text-xs px-4 py-2.5 rounded-lg transition duration-150">
                        RESET
                    </a>
                </div>
                <button type="submit" name="action" value="export" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-6 py-2.5 rounded-lg shadow transition duration-150 flex items-center space-x-1.5">
                    <i class="las la-file-excel text-base"></i>
                    <span>DOWNLOAD XLSX</span>
                </button>
            </div>
        </form>
    </div>

    <?php if ($action === 'view'): ?>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
            <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Report Preview Results (Total Matching Records: <?php echo $total_records; ?>)</h4>
        </div>
        <!-- Scrollable container for full comprehensive data view -->
        <div class="w-full overflow-x-auto scrollbar-thin scrollbar-thumb-slate-300 scrollbar-track-slate-100">
            <table class="w-full min-w-[2000px] text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-slate-100 border-b border-slate-200 text-[11px] font-bold text-slate-600 uppercase tracking-wider">
                        <th class="px-4 py-3">Coverage Date</th>
                        <th class="px-4 py-3">Account Number</th>
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Assignee</th>
                        <th class="px-4 py-3">Carrier / Telco</th>
                        <th class="px-4 py-3">Mobile Number</th>
                        <th class="px-4 py-3 text-right">Approved Plan</th>
                        <th class="px-4 py-3 text-right">Phone Amortization</th>
                        <th class="px-4 py-3 text-right">Debit Adj</th>
                        <th class="px-4 py-3 text-right">Credit Adj</th>
                        <th class="px-4 py-3 text-right">Other Charges (Pre-Term)</th>
                        <th class="px-4 py-3 text-right">Local (Call/Text)</th>
                        <th class="px-4 py-3 text-right">NDD (National)</th>
                        <th class="px-4 py-3 text-right">IDD (International)</th>
                        <th class="px-4 py-3 text-right">Roam</th>
                        <th class="px-4 py-3 text-right">SMS</th>
                        <th class="px-4 py-3 text-right">GPRS</th>
                        <th class="px-4 py-3 text-right">Wiz Usage</th>
                        <th class="px-4 py-3 text-right">Loading Charges</th>
                        <th class="px-4 py-3 text-right">VAT</th>
                        <th class="px-4 py-3 text-right">OCT</th>
                        <th class="px-4 py-3 text-right">Current Charges</th>
                        <th class="px-4 py-3 text-right">Total Amount Due</th>
                        <th class="px-4 py-3 text-right">Debit Memo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-xs text-slate-700">
                    <?php if (count($reports) > 0): ?>
                        <?php foreach ($reports as $row): 
                            $start = isset($row['coverage_start']) && $row['coverage_start'] ? date('M d, Y', strtotime($row['coverage_start'])) : '';
                            $end = isset($row['coverage_end']) && $row['coverage_end'] ? date('M d, Y', strtotime($row['coverage_end'])) : '';
                            $coverage_text = ($start && $end) ? ($start . ' to ' . $end) : '';
                            
                            $row_acc = isset($row['account_number']) ? $row['account_number'] : 'N/A';
                            $row_company = isset($row['dm_company']) ? $row['dm_company'] : 'N/A';
                            $row_assignee = isset($row['assignee_name']) ? $row['assignee_name'] : 'N/A';
                            $row_carrier = isset($row['carrier_name']) ? $row['carrier_name'] : 'N/A';
                            $row_mobile = isset($row['mobile_number']) ? $row['mobile_number'] : 'N/A';

                            $plan = number_format((float)(isset($row['approved_plan']) ? $row['approved_plan'] : 0), 2);
                            $amort = number_format((float)(isset($row['phone_amortization']) ? $row['phone_amortization'] : 0), 2);
                            $dadj = number_format((float)(isset($row['debit_adj']) ? $row['debit_adj'] : 0), 2);
                            $cadj = number_format((float)(isset($row['credit_adj']) ? $row['credit_adj'] : 0), 2);
                            $other = number_format((float)(isset($row['other_charges']) ? $row['other_charges'] : 0), 2);
                            $local = number_format((float)(isset($row['local_call_text']) ? $row['local_call_text'] : 0), 2);
                            $ndd = number_format((float)(isset($row['ndd_charges']) ? $row['ndd_charges'] : 0), 2);
                            $idd = number_format((float)(isset($row['idd_charges']) ? $row['idd_charges'] : 0), 2);
                            $roam = number_format((float)(isset($row['roaming_charges']) ? $row['roaming_charges'] : 0), 2);
                            $sms = number_format((float)(isset($row['sms_charges']) ? $row['sms_charges'] : 0), 2);
                            $gprs = number_format((float)(isset($row['gprs_charges']) ? $row['gprs_charges'] : 0), 2);
                            $wiz = number_format((float)(isset($row['wiz_usage']) ? $row['wiz_usage'] : 0), 2);
                            $load = number_format((float)(isset($row['loading_charges']) ? $row['loading_charges'] : 0), 2);
                            $vat = number_format((float)(isset($row['vat']) ? $row['vat'] : 0), 2);
                            $oct = number_format((float)(isset($row['oct']) ? $row['oct'] : 0), 2);
                            $current = number_format((float)(isset($row['current_charges']) ? $row['current_charges'] : 0), 2);
                            $amount = number_format((float)(isset($row['total_amount_due']) ? $row['total_amount_due'] : 0), 2);
                            $dm_details = number_format((float)(isset($row['debit_memo_details']) ? $row['debit_memo_details'] : 0), 2);
                        ?>
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="px-4 py-3.5 text-slate-500 font-medium"><?php echo htmlspecialchars($coverage_text); ?></td>
                                <td class="px-4 py-3.5 font-bold text-slate-800"><?php echo htmlspecialchars($row_acc); ?></td>
                                <td class="px-4 py-3.5 font-semibold text-blue-600"><?php echo htmlspecialchars($row_company); ?></td>
                                <td class="px-4 py-3.5"><?php echo htmlspecialchars($row_assignee); ?></td>
                                <td class="px-4 py-3.5"><?php echo htmlspecialchars($row_carrier); ?></td>
                                <td class="px-4 py-3.5"><?php echo htmlspecialchars($row_mobile); ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $plan; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $amort; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $dadj; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $cadj; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $other; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $local; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $ndd; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $idd; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $roam; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $sms; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $gprs; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $wiz; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $load; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $vat; ?></td>
                                <td class="px-4 py-3.5 text-right"><?php echo $oct; ?></td>
                                <td class="px-4 py-3.5 text-right font-medium"><?php echo $current; ?></td>
                                <td class="px-4 py-3.5 text-right font-bold text-emerald-600"><?php echo $amount; ?></td>
                                <td class="px-4 py-3.5 text-right font-bold text-red-600"><?php echo $dm_details; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="24" class="px-6 py-10 text-center text-slate-400">No records found matching the criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pill-Style Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-[11px] text-slate-500 uppercase tracking-wider font-semibold">
                Page <span class="text-slate-800 font-bold"><?php echo $page; ?></span> of <span class="text-slate-800 font-bold"><?php echo $total_pages; ?></span>
            </div>
            <div class="flex items-center space-x-1.5 overflow-x-auto w-full sm:w-auto py-1">
                <?php 
                $query_params = $_GET;
                
                // First Page Link
                $query_params['page'] = 1;
                $query_params['action'] = 'view';
                $first_url = 'reports.php?' . http_build_query($query_params);
                ?>
                <a href="<?php echo $first_url; ?>" class="px-3 py-1.5 text-xs font-semibold rounded-full border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition shadow-sm whitespace-nowrap">First</a>

                <?php
                $range = 10;
                $start_p = max(1, $page - 5);
                $end_p = min($total_pages, $start_p + $range - 1);
                if (($end_p - $start_p) < ($range - 1)) {
                    $start_p = max(1, $end_p - $range + 1);
                }

                for ($i = $start_p; $i <= $end_p; $i++): 
                    $query_params['page'] = $i;
                    $query_params['action'] = 'view';
                    $page_url = 'reports.php?' . http_build_query($query_params);
                    $isActive = ($i === $page);
                ?>
                    <a href="<?php echo $page_url; ?>" class="px-3 py-1.5 text-xs font-bold rounded-full border transition shadow-sm whitespace-nowrap <?php echo $isActive ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php 
                // Last Page Link
                $query_params['page'] = $total_pages;
                $query_params['action'] = 'view';
                $last_url = 'reports.php?' . http_build_query($query_params);
                ?>
                <a href="<?php echo $last_url; ?>" class="px-3 py-1.5 text-xs font-semibold rounded-full border border-slate-300 bg-white text-slate-700 hover:bg-slate-100 transition shadow-sm whitespace-nowrap">Last</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
render_layout($page_title, $content);
?>