<?php
// Initialize session to access $_SESSION['role']
session_start();

require_once 'config.php';
require_once __DIR__ . '/dm_period.php';

// Verify the user is logged in
if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized access');
}

$dm_id      = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
$start_date = isset($_GET['start']) ? $_GET['start'] : '';
$end_date   = isset($_GET['end']) ? $_GET['end'] : '';

// ---- Row counts per tab (used by the modal to auto-select the tab that has data) ----
if (isset($_GET['counts'])) {
    $csql = "SELECT COALESCE(SUM(coverage_start >= '" . DM_NEW_TEMPLATE_START . "'), 0) AS new_cnt,
                    COALESCE(SUM(coverage_start <  '" . DM_NEW_TEMPLATE_START . "'), 0) AS legacy_cnt
             FROM debit_memo_items WHERE dm_id = :dm_id";
    $cparams = [':dm_id' => $dm_id];
    if (!empty($start_date) && !empty($end_date)) {
        $csql .= " AND coverage_start >= :start AND coverage_end <= :end";
        $cparams[':start'] = $start_date;
        $cparams[':end']   = $end_date;
    }
    $cst = $conn->prepare($csql);
    $cst->execute($cparams);
    $crow = $cst->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['new' => (int)$crow['new_cnt'], 'legacy' => (int)$crow['legacy_cnt']]);
    exit;
}

// Tab: 'new' = 2026 and forward (new template), 'legacy' = 2025 and backward (old columns)
$period = (isset($_GET['period']) && $_GET['period'] === 'legacy') ? 'legacy' : 'new';

$sql = "SELECT dmi.*, pdf.file_link, pdf.filename 
        FROM `debit_memo_items` dmi 
        JOIN debit_memos dm ON dm.dm_id = dmi.dm_id
        LEFT JOIN pdf_extracted_details pdf ON pdf.id = (
                 -- exactly ONE SOA per breakdown row (best match), so a row never shows twice
                 SELECT p.id
                 FROM pdf_extracted_details p
                 WHERE p.account_number = dm.account_number
                   AND p.file_link IS NOT NULL AND p.file_link <> ''
                   AND ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(p.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
                   AND (
                       p.mobile_number IS NULL
                       OR p.mobile_number = ''
                       OR p.mobile_number = 'N/A'
                       OR RIGHT(REGEXP_REPLACE(p.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)
                   )
                 ORDER BY
                   (RIGHT(REGEXP_REPLACE(p.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(dmi.mobile_number, '[^0-9]', ''), 10)) DESC,
                   ABS(DATEDIFF(dmi.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(p.billing_period, ' - ', -1), '%Y-%m-%d'))) ASC,
                   p.id DESC
                 LIMIT 1
             )
        WHERE dmi.dm_id = :dm_id";

$params = [':dm_id' => $dm_id];

// Tab filter (2025 & backward vs 2026 & forward)
$sql .= dm_period_clause($period, 'dmi.coverage_start');

// Filter base sa coverage dates
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND dmi.coverage_start >= :start AND dmi.coverage_end <= :end";
    $params[':start'] = $start_date;
    $params[':end'] = $end_date;
}

$sql .= " ORDER BY dmi.coverage_start ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Number of columns in each layout (incl. checkbox + SOA + action)
$colspan = ($period === 'legacy') ? 27 : 22;
$is_admin = (isset($_SESSION['role']) && ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin'));

// Render the Table Body
if (empty($items)) {
    echo "<tr><td colspan='" . $colspan . "' class='text-center py-10 text-gray-400'>No records found.</td></tr>";
} else {
    foreach ($items as $row) {
        $id_val  = isset($row['id']) ? $row['id'] : 0;
        $mobile  = isset($row['mobile_number']) ? $row['mobile_number'] : '-';
        $has_soa = !empty($row['file_link']);

        // Light green highlight when an SOA is matched
        $row_style = $has_soa ? "background-color: #d7f7e3 !important;" : "";
        $row_class = $has_soa ? "border-b hover:bg-green-300 text-[10px] text-center" : "border-b hover:bg-gray-50 text-[10px] text-center";

        echo "<tr class='" . $row_class . "' style='" . $row_style . "' id='row-" . $id_val . "'>";

        // Checkbox
        echo "<td class='p-2 border'><input type='checkbox' class='item-checkbox' value='" . $id_val . "'></td>";

        // Date Covered
        // Three lines for easy reading:  Dec 16 2024 / TO / Jan 15 2025
        $start_f = !empty($row['coverage_start']) ? date('M d Y', strtotime($row['coverage_start'])) : 'N/A';
        $end_f   = !empty($row['coverage_end'])   ? date('M d Y', strtotime($row['coverage_end']))   : 'N/A';
        echo "<td class='p-2 border font-bold bd-date'>" . $start_f . "<span class='bd-to'>TO</span>" . $end_f . "</td>";

        // Mobile Number
        echo "<td class='p-2 border text-center font-bold'><span>" . htmlspecialchars($mobile) . "</span></td>";

        // Amount columns (everything between Mobile Number and the SOA column) come from the shared definition
        $columns = dm_columns($period);
        $css = array(
            'plain'  => '',
            'red'    => ' font-bold text-red-600',
            'blue'   => ' font-bold text-blue-600',
            'purple' => ' font-bold text-purple-600',
            'orange' => ' font-bold text-orange-600',
            'green'  => ' font-bold text-green-600',
        );
        // Same colour language on both tabs:  green = excess / processed, blue = add ons, amber = final / difference
        $pill = array(
            'excess'             => 'bd-pill-green',   // 2026: Excess Charges
            'debit_memo_details' => 'bd-pill-green',   // 2025: Processed DM
            'sys_dm'             => 'bd-pill-green',   // 2025: System Generated DM
            'add_ons'            => 'bd-pill-blue',    // both: Add Ons
            'diff'               => 'bd-pill-amber',   // 2025: Difference
            'final_excess'       => 'bd-pill-amber',   // 2026: Final Excess Charges
            'final_dm'           => 'bd-pill-amber',   // 2025: Final DM
        );
        foreach ($columns as $c) {
            if ($c['type'] === 'date' || $c['type'] === 'mobile') continue;
            $val_txt = number_format(dm_cell_value($row, $c), 2);
            if (isset($pill[$c['key']])) {
                echo "<td class='p-2 border'><span class='bd-pill " . $pill[$c['key']] . "'>" . $val_txt . "</span></td>";
            } else {
                echo "<td class='p-2 border'>" . $val_txt . "</td>";
            }
        }

        // SOA column (WITH SOA badge or blank)
        echo "<td class='p-2 border text-center font-bold text-xs'>";
        if ($has_soa) {
            $file_link = htmlspecialchars($row['file_link']);
            $filename  = !empty($row['filename']) ? htmlspecialchars($row['filename']) : 'View SOA File';
            echo "<a href='" . $file_link . "' target='_blank' title='" . $filename . "' class='inline-block px-2 py-0.5 rounded text-[9px] font-bold bg-green-100 text-green-700 border border-green-300 shadow-xs hover:bg-green-200 transition-colors'>WITH SOA</a>";
        }
        echo "</td>";

        // ACTION column - Edit only for Admin/Superadmin
        echo "<td class='p-2 border'>";
        echo "<div class='flex items-center justify-center gap-4'>";
        if ($is_admin) {
            echo "<button type='button' onclick='editBreakdownItem(" . $id_val . ")' class='text-blue-600 hover:text-blue-800 text-2xl' title='Edit Record'>";
            echo "<i class='las la-pencil-alt'></i>";
            echo "</button>";
        } else {
            echo "<span class='text-gray-300 text-xl'>-</span>";
        }
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
}
?>