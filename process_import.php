<?php
session_start();
require_once 'config.php';
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

set_time_limit(0);
ini_set('memory_limit', '512M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    header("Location: import_dm.php");
    exit;
}

$file = $_FILES['file']['tmp_name'];
$original_filename = $_FILES['file']['name'];
$import_mode = $_POST['import_mode'];
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
$batch_id = uniqid('imp_'); 
$current_session_id = session_id();

// Close session early to prevent locking issues
session_write_close();

$success_count = 0; $error_count = 0; $total_rows = 0;
$row_results = []; 
$failed_logs = [];
$success_logs = []; // <--- Added: Collects successful event logs

function adjustDate($dateStr) {
    $parts = explode('/', trim($dateStr));
    if (count($parts) !== 3) return null;
    $m = (int)$parts[0]; 
    $d = (int)$parts[1]; 
    $y = (int)$parts[2];

    $lastDay = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    if ($d > $lastDay) {
        $d = $lastDay;
    }

    if (!checkdate($m, $d, $y)) return null;

    $dt = DateTime::createFromFormat('n-j-Y', "$m-$d-$y");
    return $dt ? $dt->format('Y-m-d') : null;
}

function cleanNumber($val) {
    return floatval(str_replace(',', '', trim($val)));
}

// Step 1: Pre-count total valid rows and store them in an array
$all_rows = [];
if (($handle = fopen($file, "r")) !== FALSE) {
    fgetcsv($handle); 
    while (($row = fgetcsv($handle, 4096, ",")) !== FALSE) {
        if (!empty(array_filter($row))) {
            $all_rows[] = $row;
        }
    }
    fclose($handle);
}

$total_rows = count($all_rows);

// Initialize file progress tracking for 1% to 100% calculation
$progressFile = __DIR__ . '/progress_' . $current_session_id . '.json';
file_put_contents($progressFile, json_encode([
    'percent' => 0,
    'processed' => 0,
    'total' => $total_rows
]));

$successful_inserts = []; 
$successful_updates = []; 

if ($total_rows > 0) {
    $current_row = 0;
    foreach ($all_rows as $row) {
        $current_row++;
        try {
            $company = trim($row[1]);
            $acc_num = trim(str_replace(['=', '"', "\r", "\n"], '', $row[3]));
            $mobile  = trim(str_replace(['=', '"', "\r", "\n"], '', $row[4]));
            $formatted_start = adjustDate($row[23]);
            $formatted_end   = adjustDate($row[24]);

            if (empty($company) || empty($acc_num) || empty($mobile) || !$formatted_start || !$formatted_end) 
                throw new Exception("Validation failed: Missing required fields or invalid dates.");

            $conn->beginTransaction();

            // 1. BACKUP PARENT
            $stmtCheckAcc = $conn->prepare("SELECT * FROM debit_memos WHERE account_number = ? FOR UPDATE");
            $stmtCheckAcc->execute([$acc_num]);
            $oldAcc = $stmtCheckAcc->fetch(PDO::FETCH_ASSOC);
            if ($oldAcc) {
                $conn->prepare("INSERT INTO import_backups (batch_id, record_id, table_name, previous_data) VALUES (?, ?, 'debit_memos', ?)")
                     ->execute([$batch_id, $oldAcc['dm_id'], json_encode($oldAcc)]);
            }

            // 2. PARENT UPSERT
            $stmtAcc = $conn->prepare("INSERT INTO debit_memos (account_number, company, assignee_name, created_by, batch_id, created_at, updated_at) 
                                       VALUES (?, ?, ?, ?, ?, NOW(), NOW()) 
                                       ON DUPLICATE KEY UPDATE company=VALUES(company), assignee_name=VALUES(assignee_name), batch_id=VALUES(batch_id), updated_at=NOW()");
            $stmtAcc->execute([$acc_num, $company, trim($row[2]), $user_id, $batch_id]);
            $dm_id = $conn->lastInsertId() ?: $conn->query("SELECT dm_id FROM debit_memos WHERE account_number = '$acc_num'")->fetchColumn();

            // 3. BACKUP CHILD
            $stmtCheck = $conn->prepare("SELECT * FROM debit_memo_items WHERE dm_id=? AND mobile_number=? AND coverage_start=? AND coverage_end=?");
            $stmtCheck->execute([$dm_id, $mobile, $formatted_start, $formatted_end]);
            $old = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $conn->prepare("INSERT INTO import_backups (batch_id, record_id, table_name, previous_data) VALUES (?, ?, 'debit_memo_items', ?)")
                     ->execute([$batch_id, $old['id'], json_encode($old)]);
            }

            // 4. CHILD LOGIC
            $stmtCheck = $conn->prepare("SELECT * FROM debit_memo_items WHERE dm_id = ? AND mobile_number = ? AND coverage_start = ? AND coverage_end = ?");
            $stmtCheck->execute([$dm_id, $mobile, $formatted_start, $formatted_end]);
            $existingRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($import_mode === 'add_new' && $existingRecord) throw new Exception("Record already exists in database.");
            if ($import_mode === 'update' && !$existingRecord) throw new Exception("Record not found for update.");

            // Perform Update or Insert collection
            if ($import_mode === 'update') {
                $newData = [
                    'carrier_name' => $row[25], 
                    'approved_plan' => cleanNumber($row[5]),
                    'phone_amortization' => cleanNumber($row[6]), 
                    'debit_adj' => cleanNumber($row[7]), 
                    'credit_adj' => cleanNumber($row[8]), 
                    'other_charges' => cleanNumber($row[9]), 
                    'local_call_text' => cleanNumber($row[10]), 
                    'ndd_charges' => cleanNumber($row[11]), 
                    'idd_charges' => cleanNumber($row[12]), 
                    'roaming_charges' => cleanNumber($row[13]), 
                    'sms_charges' => cleanNumber($row[14]), 
                    'gprs_charges' => cleanNumber($row[15]), 
                    'wiz_usage' => cleanNumber($row[16]), 
                    'loading_charges' => cleanNumber($row[17]), 
                    'vat' => cleanNumber($row[18]), 
                    'oct' => cleanNumber($row[19]), 
                    'current_charges' => cleanNumber($row[20]), 
                    'total_amount_due' => cleanNumber($row[21]), 
                    'debit_memo_details' => cleanNumber($row[22])
                ];

                $hasChanged = false;
                $numericFields = ['phone_amortization', 'debit_adj', 'credit_adj', 'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 'debit_memo_details', 'approved_plan'];

                foreach ($newData as $key => $value) {
                    $dbVal = $existingRecord[$key];
                    if (in_array($key, $numericFields)) {
                        if ((float)$value !== (float)$dbVal) {
                            $hasChanged = true;
                            break;
                        }
                    } else {
                        if (trim((string)$value) !== trim((string)$dbVal)) {
                            $hasChanged = true;
                            break;
                        }
                    }
                }
                if (!$hasChanged) throw new Exception("No changes detected; record is identical.");

                // Queue for bulk update execution parameters
                $successful_updates[] = [
                    $row[25], cleanNumber($row[5]), cleanNumber($row[6]), cleanNumber($row[7]), 
                    cleanNumber($row[8]), cleanNumber($row[9]), cleanNumber($row[10]), cleanNumber($row[11]), 
                    cleanNumber($row[12]), cleanNumber($row[13]), cleanNumber($row[14]), cleanNumber($row[15]), 
                    cleanNumber($row[16]), cleanNumber($row[17]), cleanNumber($row[18]), cleanNumber($row[19]), 
                    cleanNumber($row[20]), cleanNumber($row[21]), cleanNumber($row[22]), $batch_id, $user_id, $existingRecord['id']
                ];
                
                $status = 'UPDATED';
                $remarks = 'Record updated successfully';
            } else {
                // Queue for bulk insert execution parameters
                $successful_inserts[] = [
                    $dm_id, $row[0], $row[25], $mobile, $formatted_start, $formatted_end,
                    cleanNumber($row[5]), cleanNumber($row[6]), cleanNumber($row[7]), cleanNumber($row[8]),
                    cleanNumber($row[9]), cleanNumber($row[10]), cleanNumber($row[11]), cleanNumber($row[12]),
                    cleanNumber($row[13]), cleanNumber($row[14]), cleanNumber($row[15]), cleanNumber($row[16]),
                    cleanNumber($row[17]), cleanNumber($row[18]), cleanNumber($row[19]), cleanNumber($row[20]),
                    cleanNumber($row[21]), cleanNumber($row[22]), $batch_id, $user_id
                ];
                
                $status = 'ADDED';
                $remarks = 'Record added successfully';
            }

            $conn->commit();
            $success_count++;
            
            // Collect successful log entry
            $success_logs[] = [$batch_id, json_encode($row), $status, $remarks, $user_id];
            
            $row_results[] = ['row' => $current_row, 'status' => $status, 'remarks' => $remarks, 'original_row' => $row];

        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error_count++;
            
            $failed_logs[] = [$batch_id, json_encode($row), $e->getMessage(), $user_id];
            
            $row_results[] = ['row' => $current_row, 'status' => 'FAILED', 'remarks' => $e->getMessage(), 'original_row' => $row];
        }

        // Update temporary file progress dynamically per row processed
        $percent = round(($current_row / $total_rows) * 100);
        file_put_contents($progressFile, json_encode([
            'percent' => $percent,
            'processed' => $current_row,
            'total' => $total_rows
        ]));
    }
}

// BULK INSERT FOR ALL "ADD NEW" ITEMS AT ONCE
if (!empty($successful_inserts)) {
    $chunkedRows = array_chunk($successful_inserts, 500);
    foreach ($chunkedRows as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
        $flatData = [];
        foreach ($chunk as $item) {
            $flatData = array_merge($flatData, $item);
        }
        $bulkSql = "INSERT INTO debit_memo_items (dm_id, line_no, carrier_name, mobile_number, coverage_start, coverage_end, approved_plan, phone_amortization, debit_adj, credit_adj, other_charges, local_call_text, ndd_charges, idd_charges, roaming_charges, sms_charges, gprs_charges, wiz_usage, loading_charges, vat, oct, current_charges, total_amount_due, debit_memo_details, batch_id, created_by) VALUES $placeholders";
        $conn->prepare($bulkSql)->execute($flatData);
    }
}

// BULK UPDATE EXECUTION FOR UPDATES
if (!empty($successful_updates)) {
    $updateStmt = $conn->prepare("UPDATE debit_memo_items SET carrier_name=?, approved_plan=?, phone_amortization=?, debit_adj=?, credit_adj=?, other_charges=?, local_call_text=?, ndd_charges=?, idd_charges=?, roaming_charges=?, sms_charges=?, gprs_charges=?, wiz_usage=?, loading_charges=?, vat=?, oct=?, current_charges=?, total_amount_due=?, debit_memo_details=?, batch_id=?, created_by=? WHERE id=?");
    foreach ($successful_updates as $upd) {
        $updateStmt->execute($upd);
    }
}

// BULK INSERT FOR ALL SUCCESSFUL LOGS AT ONCE
if (!empty($success_logs)) {
    $chunkedSuccess = array_chunk($success_logs, 500);
    foreach ($chunkedSuccess as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'));
        $flatData = [];
        foreach ($chunk as $log) {
            $flatData = array_merge($flatData, $log);
        }
        $bulkSuccessSql = "INSERT INTO import_logs (batch_id, raw_row_data, status, remarks, user_id) VALUES $placeholders";
        $conn->prepare($bulkSuccessSql)->execute($flatData);
    }
}

// BULK INSERT FOR ALL FAILURES AT ONCE
if (!empty($failed_logs)) {
    $chunkedFails = array_chunk($failed_logs, 500);
    foreach ($chunkedFails as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, "FAILED", ?, ?)'));
        $flatData = [];
        foreach ($chunk as $log) {
            $flatData = array_merge($flatData, $log);
        }
        $bulkFailSql = "INSERT INTO import_logs (batch_id, raw_row_data, status, remarks, user_id) VALUES $placeholders";
        $conn->prepare($bulkFailSql)->execute($flatData);
    }
}

$conn->prepare("INSERT IGNORE INTO batch_history (batch_id) VALUES (?)")->execute([$batch_id]);
$stmtHistory = $conn->prepare("INSERT INTO import_history (batch_id, filename, import_mode, total_rows, success_count, error_count, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$stmtHistory->execute([$batch_id, $original_filename, $import_mode, $total_rows, $success_count, $error_count, $user_id]);

// Clean up progress file when done
if (file_exists($progressFile)) {
    @unlink($progressFile);
}

session_start();
$_SESSION['import_result'] = [
    'batch_id' => $batch_id, 'total' => $total_rows, 'success' => $success_count, 
    'failed' => $error_count, 'details' => $row_results, 'mode' => $import_mode
];
header("Location: import_result.php");
exit;