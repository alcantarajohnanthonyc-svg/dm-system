<?php
session_start();
require_once 'config.php';

if (isset($_GET['action']) && $_GET['action'] == 'revert' && isset($_GET['batch_id'])) {
    $batch_id = $_GET['batch_id'];
    // Use isset() to avoid "unexpected ?" error on older PHP versions
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    
    try {
        // 0. Fetch batch history info beforehand so we have access to filename and mode for the summary page
        $historyStmt = $conn->prepare("SELECT filename, import_mode FROM import_history WHERE batch_id = ?");
        $historyStmt->execute([$batch_id]);
        $batchInfo = $historyStmt->fetch(PDO::FETCH_ASSOC);
        
        $filename = $batchInfo ? $batchInfo['filename'] : 'Unknown File';
        $importMode = $batchInfo ? $batchInfo['import_mode'] : 'add_new';

        $conn->beginTransaction();
        
        // 1. Count records for the log
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM import_backups WHERE batch_id = ?");
        $countStmt->execute([$batch_id]);
        $restoreCount = $countStmt->fetchColumn();

        // 2. Fetch backups
        $stmt = $conn->prepare("SELECT * FROM import_backups WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        
        foreach ($stmt as $b) {
            $data = json_decode($b['previous_data'], true);
            
            if ($b['table_name'] === 'debit_memos') {
                // Restore Parent
                $update = $conn->prepare("UPDATE debit_memos SET company=?, assignee_name=?, batch_id=?, updated_at=NOW() WHERE dm_id=?");
                $update->execute([$data['company'], $data['assignee_name'], $data['batch_id'], $b['record_id']]);
                
            } elseif ($b['table_name'] === 'debit_memo_items') {
                // Restore Child: All columns mapped precisely
                $sql = "UPDATE debit_memo_items SET 
                        carrier_name=?, mobile_number=?, approved_plan=?, phone_amortization=?, 
                        debit_adj=?, credit_adj=?, other_charges=?, local_call_text=?, 
                        ndd_charges=?, idd_charges=?, roaming_charges=?, sms_charges=?, 
                        gprs_charges=?, wiz_usage=?, loading_charges=?, vat=?, oct=?, 
                        current_charges=?, total_amount_due=?, debit_memo_details=?, 
                        coverage_start=?, coverage_end=?, line_no=?, batch_id=?, created_by=? 
                        WHERE id=?";
                        
                $conn->prepare($sql)->execute([
                    $data['carrier_name'], $data['mobile_number'], $data['approved_plan'], $data['phone_amortization'],
                    $data['debit_adj'], $data['credit_adj'], $data['other_charges'], $data['local_call_text'],
                    $data['ndd_charges'], $data['idd_charges'], $data['roaming_charges'], $data['sms_charges'],
                    $data['gprs_charges'], $data['wiz_usage'], $data['loading_charges'], $data['vat'], $data['oct'],
                    $data['current_charges'], $data['total_amount_due'], $data['debit_memo_details'],
                    $data['coverage_start'], $data['coverage_end'], $data['line_no'], 
                    $data['batch_id'], $data['created_by'], $b['record_id']
                ]);
            }
        }
        
        // 3. Log the revert action
        $conn->prepare("INSERT INTO revert_logs (batch_id, user_id, reverted_at, records_restored, remarks) VALUES (?, ?, NOW(), ?, ?)")
             ->execute([$batch_id, $user_id, $restoreCount, 'Batch successfully reverted to previous state.']);
        
        // 4. Cleanup: Remove only records strictly created by this batch that weren't backed up
        $conn->prepare("DELETE FROM debit_memo_items WHERE batch_id = ? AND id NOT IN (SELECT record_id FROM import_backups WHERE batch_id = ? AND table_name = 'debit_memo_items')")
             ->execute([$batch_id, $batch_id]);
             
        $conn->prepare("DELETE FROM debit_memos WHERE batch_id = ? AND dm_id NOT IN (SELECT record_id FROM import_backups WHERE batch_id = ? AND table_name = 'debit_memos')")
             ->execute([$batch_id, $batch_id]);
        
        // 5. Finalize Cleanup
        $conn->prepare("DELETE FROM import_backups WHERE batch_id = ?")->execute([$batch_id]);
        $conn->prepare("DELETE FROM import_history WHERE batch_id = ?")->execute([$batch_id]);
        
        $conn->commit();
        
        // Save revert result data for the dedicated revert summary view page
        unset($_SESSION['import_progress']);
        $_SESSION['revert_result'] = [
            'batch_id' => $batch_id,
            'filename' => $filename,
            'mode' => $importMode,
            'total_reverted' => $restoreCount,
            'reverted_at' => date('Y-m-d H:i:s')
        ];
        session_write_close();

        // Redirect to the summary page successfully
        header("Location: revert_result.php");
        exit;
        
    } catch (Exception $e) { 
        if ($conn->inTransaction()) $conn->rollBack();
        header("Location: import_dm.php?status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
?>