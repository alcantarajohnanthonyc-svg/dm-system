<?php
require_once('fpdf/fpdf.php');

/**
 * Generates a Debit Memo PDF.
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

    // 2. Setup PDF with automatic page breaking enabled (margin bottom set to 15mm)
    $pdf = new FPDF('L', 'mm', 'Legal');
    $pdf->SetAutoPageBreak(true, 15);
    
    // 3. Header array and dimensions
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
    "DEBIT MEMO" => "#93C47D"
];

    $w = 16.5; 
    $dateW = 30;
    $h = 10; 

    // Helper closure to draw headers and title consistently across pages
    $drawPageHeader = function() use ($pdf, $acc_num, $company, $headers, $dateW, $w, $h) {
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, 'ACCOUNT NUMBER: ' . $acc_num, 0, 1);
        $pdf->Cell(0, 7, 'COMPANY: ' . $company, 0, 1);
        $pdf->Ln(5);

        $pdf->SetFont('Arial', 'B', 6);
        $startY = $pdf->GetY();

        $index = 0;
        foreach($headers as $col => $colorType) {
            $currentW = ($index == 0) ? $dateW : $w;
            
            // Set background color based on your specific mapping
            if ($colorType === '#FFE599') {
                $pdf->SetFillColor(255, 229, 153); // Soft Yellow
            } elseif ($colorType === '#93C47D') {
                $pdf->SetFillColor(147, 196, 125); // Green
            } else {
                $pdf->SetFillColor(74, 135, 232); // #4A86E8-300 equivalent
            }
            
            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            $pdf->Rect($currentX, $currentY, $currentW, $h, 'DF'); 
            $pdf->MultiCell($currentW, 3, $col, 0, 'C'); 
            $pdf->SetXY($currentX + $currentW, $startY);
            
            $index++;
        }
        $pdf->Ln($h);
    };

    // 4. Build Query and Data Fetching
    $sql = "SELECT * FROM `debit_memo_items` WHERE dm_id = :dm_id";
    $params = [':dm_id' => $dm_id];

    // Apply specific ID filters if exporting from the modal breakdown
    if (!empty($item_ids)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $item_ids)) . ")";
    }

    // Apply date range filters if provided
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

    // If no data exists, generate at least one empty page frame
    if (empty($groupedByYear)) {
        $drawPageHeader();
    }

    $pdf->SetFont('Arial', '', 6);
    $rowH = 10.5;

    // 6. Loop through each Year to start on a new page
    $isFirstYear = true;
    foreach ($groupedByYear as $year => $items) {
        // Force a new page for every distinct year group
        $drawPageHeader();

        foreach ($items as $row) {
            // Check if the current row overflows the page boundary; if so, trigger a new page and redraw headers
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
            $pdf->Cell($w, $rowH, number_format((float)$row['approved_plan'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['phone_amortization'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['debit_adj'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['credit_adj'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['other_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['local_call_text'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['ndd_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['idd_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['roaming_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['sms_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['gprs_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['wiz_usage'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['loading_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['vat'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['oct'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['current_charges'], 2), 1, 0, 'C');
            $pdf->Cell($w, $rowH, number_format((float)$row['total_amount_due'], 2), 1, 0, 'C');
            
            $pdf->SetTextColor(200, 0, 0); 
            $pdf->Cell($w, $rowH, number_format((float)$row['debit_memo_details'], 2), 1, 0, 'C');
            $pdf->SetTextColor(0, 0, 0); 
            
            $pdf->Ln($rowH);
        }
    }
    
    // Return standard array format
    return [$pdf, $acc_num];
}
?>