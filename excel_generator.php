<?php
/**
 * Generates and downloads a Debit Memo Excel spreadsheet with yearly tabs, custom widths, and design formatting.
 * @param int $dm_id The ID of the debit memo
 * @param PDO $conn The database connection
 * @param array|null $item_ids Optional array of specific item IDs
 * @param string|null $startDate 'YYYY-MM-DD' format
 * @param string|null $endDate 'YYYY-MM-DD' format
 */
function createDebitMemoExcel($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null) {
    // 1. Fetch info
    $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
    $stmt->execute(array(':dm_id' => $dm_id));
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. Build Query and Data Fetching
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = array(':dm_id' => $dm_id);

    if (!empty($item_ids)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $item_ids)) . ")";
    }

    if (!empty($startDate) || !empty($endDate)) {
        $effective_start = !empty($startDate) ? $startDate : '1900-01-01';
        $effective_end   = !empty($endDate) ? $endDate : '2999-12-31';

        $sql .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date 
                       AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date)";
        
        $params[':start_date'] = $effective_start;
        $params[':end_date']   = $effective_end;
    }

    $sql .= " ORDER BY coverage_start ASC";

    $stmtItems = $conn->prepare($sql);
    $stmtItems->execute($params);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Group items by year
    $grouped_by_year = array();
    foreach ($items as $row) {
        $year = 'Unknown';
        if (!empty($row['coverage_start'])) {
            $year = date('Y', strtotime($row['coverage_start']));
        }
        $grouped_by_year[$year][] = $row;
    }

    if (empty($grouped_by_year)) {
        $grouped_by_year[date('Y')] = array();
    }

    // 3. Set Headers for Excel XML Download
    $filename = 'Account_' . $acc_num . '.xls'; 
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 4. Output Office XML Spreadsheet with Styling Definitions
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:html="http://www.w3.org/TR/REC-html40">';

    // Define Styles
    echo '<Styles>';
    echo '<Style ss:ID="HeaderYellow"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#fde047" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
    echo '<Style ss:ID="HeaderGreen"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#4ade80" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
    echo '<Style ss:ID="HeaderBlue"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Interior ss:Color="#93c5fd" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
    echo '<Style ss:ID="DataCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
    echo '<Style ss:ID="RedCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#dc2626"/></Style>';
    echo '</Styles>';

    // Loop through each year to create a separate sheet tab
    foreach ($grouped_by_year as $year => $year_items) {
        echo '<Worksheet ss:Name="Year ' . htmlspecialchars($year) . '">';
        echo '<Table>';

        // Explicit column widths to ensure long headers fit completely without truncation
        $colWidths = [150, 130, 110, 130, 100, 100, 180, 130, 130, 140, 90, 80, 80, 90, 120, 80, 80, 130, 130, 100];
        foreach ($colWidths as $w) {
            echo '<Column ss:Width="' . $w . '"/>';
        }

        // Account Number Row
        echo '<Row>';
        echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">ACCOUNT NUMBER: ' . htmlspecialchars($acc_num) . '</Data></Cell>';
        echo '</Row>';

        // Company Row
        echo '<Row>';
        echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">COMPANY: ' . htmlspecialchars($company) . '</Data></Cell>';
        echo '</Row>';

        // Empty Spacer Row
        echo '<Row></Row>';

        // Headers Row
        $headers = array(
            "COVERAGE DATE", "MOBILE NUMBER", "APPROVED PLAN", "PHONE AMORTIZATION", 
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
            } elseif ($index == 1 || $index == 19) {
                $styleID = 'HeaderGreen';
            }
            echo '<Cell ss:StyleID="' . $styleID . '"><Data ss:Type="String">' . htmlspecialchars($col) . '</Data></Cell>';
        }
        echo '</Row>';

        // Data Rows
        foreach ($year_items as $row) {
            $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
            $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
            $dateText = $start . ' to ' . $end;

            echo '<Row>';
            echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($dateText) . '</Data></Cell>';
            echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($row['mobile_number']) . '</Data></Cell>';

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
}
?>