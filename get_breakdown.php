<?php
// Initialize session to access $_SESSION['role']
session_start();

require_once 'config.php';

// Verify the user is logged in
if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized access');
}

$dm_id = isset($_GET['dm_id']) ? intval($_GET['dm_id']) : 0;
$start_date = isset($_GET['start']) ? $_GET['start'] : '';
$end_date = isset($_GET['end']) ? $_GET['end'] : '';

$sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
$params = [':dm_id' => $dm_id];

// Filter base sa coverage dates
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND coverage_start >= :start AND coverage_end <= :end";
    $params[':start'] = $start_date;
    $params[':end'] = $end_date;
}

$sql .= " ORDER BY coverage_start ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Render the Table Body
if (empty($items)) {
    echo "<tr><td colspan='21' class='text-center py-10 text-gray-400'>No records found.</td></tr>";
} else {
    foreach ($items as $row) {
        $id_val = isset($row['id']) ? $row['id'] : 0;

        echo "<tr class='border-b hover:bg-gray-50 text-[10px] text-center' id='row-".$id_val."'>";
        
        // Checkbox
        echo "<td class='p-2 border'><input type='checkbox' class='item-checkbox' value='".$id_val."'></td>";
        
        // Date Covered
        $start_f = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : 'N/A';
        $end_f = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : 'N/A';
        echo "<td class='p-2 border font-bold'>" . $start_f . " to " . $end_f . "</td>";
        
        // Mobile
        echo "<td class='p-2 border'>" . (isset($row['mobile_number']) ? $row['mobile_number'] : '-') . "</td>";
        
        // Numeric Columns Mapping
        $cols = [
            'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
            'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
            'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
            'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due'
        ];
        
        foreach ($cols as $col) {
            $val = isset($row[$col]) ? $row[$col] : 0;
            echo "<td class='p-2 border'>" . number_format((float)$val, 2) . "</td>";
        }
        
        // Debit Memo Details
        $dm_val = isset($row['debit_memo_details']) ? $row['debit_memo_details'] : 0;
        echo "<td class='p-2 border font-bold text-red-600'>" . number_format((float)$dm_val, 2) . "</td>";

        // CALCULATIONS FOR NEW COLUMNS
        $approved_plan_val = isset($row['approved_plan']) ? (float)$row['approved_plan'] : 0;
        $msf_mrc_val = isset($row['current_charges']) ? (float)$row['current_charges'] : 0; // Adjust database key if your MSF/MRC column has a different name
        
        // Column 1: Approved Plan - MSF (GLOBE / MRC (SMART))
        $col1_val =   $msf_mrc_val - $approved_plan_val;
        echo "<td class='p-2 border font-bold text-blue-600'>" . number_format($col1_val, 2) . "</td>";

        // Column 2: Debit Memo - Column 1
        $col2_val = (float)$dm_val - $col1_val;
        echo "<td class='p-2 border font-bold text-purple-600'>" . number_format($col2_val, 2) . "</td>";


        // ACTION COLUMN
        echo "<td class='p-2 border'>";
        
        // Only show the Edit button to Admin/Superadmin
        if ($_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'admin') {
            echo "<button type='button' onclick='editBreakdownItem(" . $id_val . ")' class='text-blue-600 hover:text-blue-800 text-lg'>";
            echo "<i class='las la-pencil-alt'></i>";
            echo "</button>";
        } else {
            // Visual indicator for non-admins
            echo "<span class='text-gray-300'>-</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
}
?>