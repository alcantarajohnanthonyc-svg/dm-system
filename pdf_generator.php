<?php
require_once('fpdf/fpdf.php');

/**
 * Generates a Debit Memo PDF with dynamic column scaling to fit Legal Landscape.
 * @param int $dm_id The ID of the debit memo
 * @param PDO $conn The database connection
 * @param array|null $item_ids Optional array of specific item IDs
 * @param string|null $startDate 'YYYY-MM-DD' format
 * @param string|null $endDate 'YYYY-MM-DD' format
 */
function createDebitMemoPDF($dm_id, $conn, $item_ids = null, $startDate = null, $endDate = null) {
    // 1. Fetch info
    $stmt = $conn->prepare("SELECT account_number, company FROM debit_memos WHERE dm_id = :dm_id");
    $stmt->execute([':dm_id' => $dm_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $acc_num = isset($info['account_number']) ? $info['account_number'] : 'N/A';
    $company = isset($info['company']) ? $info['company'] : 'N/A';

    // 2. Setup PDF with tighter margins (5mm left/right) to maximize printable space
    $pdf = new FPDF('L', 'mm', 'Legal');
    $pdf->SetMargins(5, 10, 5);
    $pdf->SetAutoPageBreak(true, 15);
    
    // 3. Header array including the 2 new columns
    $headers = [
        "COVERAGE DATE" => "#FFE599",
        "MOBILE NUMBER" => "#93C47D",
        "APPROVED PLAN" => "#93C47D",
        "MSF (GLOBE / MRC (SMART)" => "#4A86E8",
        "DEBIT ADJ" => "#93C47D",
        "CREDIT ADJ" => "#93C47D",
        "OTHER CHARGES / PHONE AMORTIZATION" => "#4A86E8",
        "LOCAL (CALL/TEXT)" => "#4A86E8",
        "NDD (NATIONAL)" => "#4A86E8",
        "IDD (INTERNATIONAL)" => "#4A86E8",
        "ROAM" => "#4A86E8",
        "SMS" => "#4A86E8",
        "GPRS" => "#4A86E8",
        "WIZ USAGE" => "#4A86E8",
        "LOADING CHARGES" => "#4A86E8",
        "VAT" => "#4A86E8",
        "OCT" => "#4A86E8",
        "CURRENT CHARGES" => "#4A86E8",
        "TOTAL AMOUNT DUE" => "#4A86E8",
        "DEBIT MEMO" => "#93C47D",
        "PLAN VARIANCE (APP. - MSF)" => "#93C47D",
        "DM DIFFERENCE (DM - COL 1)" => "#93C47D"
    ];

    // Dynamic width calculation to fit all columns perfectly on Legal Landscape (355.6 mm width)
    $pageWidth = 355.6; 
    $printableWidth = $pageWidth - 10; // Accounting for 5mm left and 5mm right margins
    $dateW = 26; // Fixed width for the date column
    $remainingColumns = count($headers) - 1; // 21 numerical/data columns
    $w = ($printableWidth - $dateW) / $remainingColumns; // Dynamically scales column width (~15.22 mm)
    $h = 10;

    // Helper closure to draw headers and title consistently across pages
    $drawPageHeader = function() use ($pdf, $acc_num, $company, $headers, $dateW, $w, $h) {
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, 'ACCOUNT NUMBER: ' . $acc_num, 0, 1);
        $pdf->Cell(0, 7, 'COMPANY: ' . $company, 0, 1);
        $pdf->Ln(5);

        $pdf->SetFont('Arial', 'B', 5.5); // Slightly smaller font to fit longer header labels
        $startY = $pdf->GetY();

        $index = 0;
        foreach($headers as $col => $colorType) {
            $currentW = ($index == 0) ? $dateW : $w;
            
            if ($colorType === '#FFE599') {
                $pdf->SetFillColor(255, 229, 153); // Soft Yellow
            } elseif ($colorType === '#93C47D') {
                $pdf->SetFillColor(147, 196, 125); // Green
            } else {
                $pdf->SetFillColor(74, 135, 232); // Blue
            }
            
            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            $pdf->Rect($currentX, $currentY, $currentW, $h, 'DF'); 
            $pdf->MultiCell($currentW, 2.8, $col, 0, 'C'); 
            $pdf->SetXY($currentX + $currentW, $startY);
            
            $index++;
        }
        $pdf->Ln($h);
    };

    // 4. Build Query and Data Fetching
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = [':dm_id' => $dm_id];

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
    $all_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 5. Group items by Year based on coverage_start
    $groupedByYear = [];
    foreach ($all_items as $row) {
        $year = !empty($row['coverage_start']) ? date('Y', strtotime($row['coverage_start'])) : 'Unknown';
        $groupedByYear[$year][] = $row;
    }

    if (empty($groupedByYear)) {
        $drawPageHeader();
    }

    $pdf->SetFont('Arial', '', 6);
    $rowH = 10.5;

    // 6. Loop through each Year
    foreach ($groupedByYear as $year => $items) {
        $drawPageHeader();

        foreach ($items as $row) {
            if ($pdf->GetY() + $rowH > ($pdf->GetPageHeight() - 15)) {
                $drawPageHeader();
            }

            $start = isset($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
            $end = isset($row['coverage_end']) ? date('M d, Y', strtotime($row['coverage_end'])) : '';
            
            $dateText = $start . "\nto\n" . $end;
            $startX = $pdf->GetX();
            $currentY = $pdf->GetY();

            $pdf->MultiCell($dateW, 3.5, $dateText, 1, 'C');
            $pdf->SetXY($startX + $dateW, $currentY);
            
            $pdf->Cell($w, $rowH, $row['mobile_number'], 1, 0, 'C');

            $numericFields = [
                'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
                'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
                'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
                'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due'
            ];

            foreach ($numericFields as $field) {
                $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
                $pdf->Cell($w, $rowH, number_format($val, 2), 1, 0, 'C');
            }
            
            // Debit Memo column (Red text)
            $dm_val = isset($row['debit_memo_details']) ? (float)$row['debit_memo_details'] : 0.00;
            $pdf->SetTextColor(220, 38, 38); 
            $pdf->Cell($w, $rowH, number_format($dm_val, 2), 1, 0, 'C');
            
            // Calculations for the 2 new columns matching Excel structure
            $approved_plan_val = isset($row['approved_plan']) ? (float)$row['approved_plan'] : 0.00;
            $msf_mrc_val = isset($row['phone_amortization']) ? (float)$row['phone_amortization'] : 0.00;

            $col1_val = $approved_plan_val - $msf_mrc_val;
            $col2_val = $dm_val - $col1_val;

            // Plan Variance Column (Blue text)
            $pdf->SetTextColor(37, 99, 235);
            $pdf->Cell($w, $rowH, number_format($col1_val, 2), 1, 0, 'C');

            // DM Difference Column (Purple text)
            $pdf->SetTextColor(124, 58, 237);
            $pdf->Cell($w, $rowH, number_format($col2_val, 2), 1, 0, 'C');

            // Reset text color back to black
            $pdf->SetTextColor(0, 0, 0); 
            
            $pdf->Ln($rowH);
        }
    }
    
    return [$pdf, $acc_num];
}
?>