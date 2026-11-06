<?php
require_once __DIR__ . '/dm_period.php';

/**
 * Builds the Debit Memo Excel (SpreadsheetML) as a string.
 * One worksheet per coverage year. Years <= 2025 use the legacy columns,
 * 2026 and forward use the new template columns.
 *
 * @param int         $dm_id
 * @param PDO         $conn
 * @param array|null  $item_ids   Optional specific item IDs
 * @param string|null $startDate  'YYYY-MM-DD'
 * @param string|null $endDate    'YYYY-MM-DD'
 * @param string|null $period     'new' (2026+), 'legacy' (<=2025) or null (everything)
 * @param string      $dateMode   'contain' (row must be inside the range) or 'overlap' (row touches the range - used by Export Paste)
 * @return array ['xml' => string, 'acc_num' => string, 'item_count' => int]
 */
function buildDebitMemoExcelXml($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null, $period = null, $dateMode = 'contain') {
    // 1. Account info
    $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
    $stmt->execute(array(':dm_id' => $dm_id));
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. Items
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = array(':dm_id' => $dm_id);

    if (!empty($item_ids)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $item_ids)) . ")";
    }

    if ($dateMode === 'overlap') {
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date
                           AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date)";
            $params[':start_date'] = $startDate;
            $params[':end_date']   = $endDate;
        } elseif (!empty($startDate)) {
            $sql .= " AND STR_TO_DATE(coverage_end, '%Y-%m-%d') >= :start_date";
            $params[':start_date'] = $startDate;
        } elseif (!empty($endDate)) {
            $sql .= " AND STR_TO_DATE(coverage_start, '%Y-%m-%d') <= :end_date";
            $params[':end_date'] = $endDate;
        }
    } elseif (!empty($startDate) || !empty($endDate)) {
        $effective_start = !empty($startDate) ? $startDate : '1900-01-01';
        $effective_end   = !empty($endDate) ? $endDate : '2999-12-31';

        $sql .= " AND (STR_TO_DATE(coverage_start, '%Y-%m-%d') >= :start_date
                       AND STR_TO_DATE(coverage_end, '%Y-%m-%d') <= :end_date)";

        $params[':start_date'] = $effective_start;
        $params[':end_date']   = $effective_end;
    }

    $sql .= dm_period_clause($period, 'coverage_start');
    $sql .= " ORDER BY coverage_start ASC";

    $stmtItems = $conn->prepare($sql);
    $stmtItems->execute($params);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Group by year
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

    // 3. XML
    $border = '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>';

    $x  = '<?xml version="1.0" encoding="UTF-8"?>';
    $x .= '<?mso-application progid="Excel.Sheet"?>';
    $x .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    $x .= ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    $x .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    $x .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    $x .= ' xmlns:html="http://www.w3.org/TR/REC-html40">';

    // Styles
    $x .= '<Styles>';
    $x .= '<Style ss:ID="plain"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border . '</Style>';
    $textColors = array('red' => '#dc2626', 'blue' => '#2563eb', 'purple' => '#7c3aed', 'orange' => '#ea580c', 'green' => '#16a34a');
    foreach ($textColors as $name => $hex) {
        $x .= '<Style ss:ID="' . $name . '"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border . '<Font ss:Bold="1" ss:Color="' . $hex . '"/></Style>';
    }
    $headerColors = array('#FFE599', '#93C47D', '#4A86E8', '#6D9EEB', '#F7F700');
    foreach ($headerColors as $color) {
        $x .= '<Style ss:ID="Header_' . md5($color) . '">';
        $x .= '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . $border;
        $x .= '<Interior ss:Color="' . $color . '" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
    }
    $x .= '</Styles>';

    // One sheet per year
    foreach ($grouped_by_year as $year => $year_items) {
        $layout  = dm_layout_for_year($year);
        $columns = dm_columns($layout);

        $x .= '<Worksheet ss:Name="Year ' . htmlspecialchars($year) . '">';
        $x .= '<Table>';

        foreach ($columns as $c) {
            if ($c['type'] === 'date')        $w = 130;
            elseif ($c['type'] === 'mobile')  $w = 110;
            elseif (strlen($c['label']) > 28) $w = 150;
            elseif (strlen($c['label']) > 14) $w = 115;
            else                              $w = 90;
            $x .= '<Column ss:Width="' . $w . '"/>';
        }

        $x .= '<Row><Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">ACCOUNT NUMBER: ' . htmlspecialchars($acc_num) . '</Data></Cell></Row>';
        $x .= '<Row><Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">COMPANY: ' . htmlspecialchars($company) . '</Data></Cell></Row>';
        $x .= '<Row></Row>';

        // Header row
        $x .= '<Row ss:Height="45">';
        foreach ($columns as $c) {
            $lines = array_map('htmlspecialchars', explode("\n", $c['label']));
            $x .= '<Cell ss:StyleID="Header_' . md5($c['color']) . '"><Data ss:Type="String">' . implode('&#10;', $lines) . '</Data></Cell>';
        }
        $x .= '</Row>';

        // Data rows
        foreach ($year_items as $row) {
            $x .= '<Row>';
            foreach ($columns as $c) {
                if ($c['type'] === 'date') {
                    $x .= '<Cell ss:StyleID="plain"><Data ss:Type="String">' . htmlspecialchars(dm_date_text($row)) . '</Data></Cell>';
                } elseif ($c['type'] === 'mobile') {
                    $x .= '<Cell ss:StyleID="plain"><Data ss:Type="String">' . htmlspecialchars(isset($row['mobile_number']) ? $row['mobile_number'] : '') . '</Data></Cell>';
                } else {
                    $x .= '<Cell ss:StyleID="' . $c['fmt'] . '"><Data ss:Type="Number">' . number_format(dm_cell_value($row, $c), 2, '.', '') . '</Data></Cell>';
                }
            }
            $x .= '</Row>';
        }

        $x .= '</Table>';
        $x .= '</Worksheet>';
    }

    $x .= '</Workbook>';

    return array('xml' => $x, 'acc_num' => $acc_num, 'item_count' => count($items));
}

/**
 * Original entry point (download straight to the browser). Signature kept,
 * $period added as the last optional argument.
 */
function createDebitMemoExcel($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null, $period = null) {
    $res = buildDebitMemoExcelXml($dm_id, $conn, $item_ids, $startDate, $endDate, $period);

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Account_' . $res['acc_num'] . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $res['xml'];
    exit;
}
?>
