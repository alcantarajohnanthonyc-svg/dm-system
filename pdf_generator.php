<?php
require_once('fpdf/fpdf.php');
require_once __DIR__ . '/dm_period.php';

/** '#RRGGBB' -> array(r,g,b) */
function dm_hex_rgb($hex) {
    $hex = ltrim($hex, '#');
    return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

/**
 * Generates a Debit Memo PDF (Legal landscape). Coverage years <= 2025 use the
 * legacy columns, 2026 and forward use the new template columns.
 *
 * @param int         $dm_id
 * @param PDO         $conn
 * @param array|null  $item_ids  Optional specific item IDs
 * @param string|null $startDate 'YYYY-MM-DD'
 * @param string|null $endDate   'YYYY-MM-DD'
 * @param string|null $period    'new' (2026+), 'legacy' (<=2025) or null (everything)
 * @return array [FPDF, account_number]
 */
function createDebitMemoPDF($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null, $period = null) {
    // 1. Info
    $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
    $stmt->execute(array(':dm_id' => $dm_id));
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. PDF setup
    $pdf = new FPDF('L', 'mm', 'Legal');
    $pdf->SetMargins(5, 10, 5);
    $pdf->SetAutoPageBreak(true, 15);

    $pageWidth      = 355.6;
    $printableWidth = $pageWidth - 10;
    $dateW          = 26;
    $h              = 14;
    $rowH           = 10.5;

    $textRgb = array(
        'plain'  => array(0, 0, 0),
        'red'    => array(220, 38, 38),
        'blue'   => array(37, 99, 235),
        'purple' => array(124, 58, 237),
        'orange' => array(234, 88, 12),
        'green'  => array(22, 163, 74),
    );

    // Header drawer (per layout)
    $drawPageHeader = function($columns) use ($pdf, $acc_num, $company, $dateW, $printableWidth, $h) {
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, 'ACCOUNT NUMBER: ' . $acc_num, 0, 1);
        $pdf->Cell(0, 7, 'COMPANY: ' . $company, 0, 1);
        $pdf->Ln(5);

        $pdf->SetFont('Arial', 'B', 5);
        $startY = $pdf->GetY();
        $w = ($printableWidth - $dateW) / (count($columns) - 1);

        foreach ($columns as $i => $c) {
            $cw  = ($i == 0) ? $dateW : $w;
            $rgb = dm_hex_rgb($c['color']);
            $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);

            $cx = $pdf->GetX();
            $cy = $pdf->GetY();
            $pdf->Rect($cx, $cy, $cw, $h, 'DF');
            $pdf->MultiCell($cw, 2.8, $c['label'], 0, 'C');
            $pdf->SetXY($cx + $cw, $startY);
        }
        $pdf->Ln($h);
    };

    // 3. Items
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = array(':dm_id' => $dm_id);

    if (!empty($item_ids)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $item_ids)) . ")";
    }

    if (!empty($startDate) || !empty($endDate)) {
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
    $all_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 4. Group by year
    $groupedByYear = array();
    foreach ($all_items as $row) {
        $year = !empty($row['coverage_start']) ? date('Y', strtotime($row['coverage_start'])) : 'Unknown';
        $groupedByYear[$year][] = $row;
    }

    if (empty($groupedByYear)) {
        $drawPageHeader(dm_columns('new'));
    }

    // 5. Render
    foreach ($groupedByYear as $year => $items) {
        $columns = dm_columns(dm_layout_for_year($year));
        $w = ($printableWidth - $dateW) / (count($columns) - 1);

        $drawPageHeader($columns);
        $pdf->SetFont('Arial', '', 6);

        foreach ($items as $row) {
            if ($pdf->GetY() + $rowH > ($pdf->GetPageHeight() - 15)) {
                $drawPageHeader($columns);
                $pdf->SetFont('Arial', '', 6);
            }

            foreach ($columns as $i => $c) {
                if ($c['type'] === 'date') {
                    $startX = $pdf->GetX();
                    $curY   = $pdf->GetY();
                    $pdf->MultiCell($dateW, 3.5, dm_date_text($row, "\nto\n"), 1, 'C');
                    $pdf->SetXY($startX + $dateW, $curY);
                } elseif ($c['type'] === 'mobile') {
                    $pdf->Cell($w, $rowH, isset($row['mobile_number']) ? $row['mobile_number'] : '', 1, 0, 'C');
                } else {
                    $rgb = $textRgb[$c['fmt']];
                    $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
                    $pdf->Cell($w, $rowH, number_format(dm_cell_value($row, $c), 2), 1, 0, 'C');
                    $pdf->SetTextColor(0, 0, 0);
                }
            }

            $pdf->Ln($rowH);
        }
    }

    return array($pdf, $acc_num);
}
?>
