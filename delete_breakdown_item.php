<?php
require_once 'config.php';

// Siguraduhing may item_ids at dm_id na ipinasa mula sa iyong AJAX request
if (isset($_POST['item_ids']) && !empty($_POST['item_ids']) && isset($_POST['dm_id'])) {
    
    $dm_id = intval($_POST['dm_id']);
    $ids_string = $_POST['item_ids'];
    $id_array = explode(',', $ids_string);
    $clean_ids = array_map('intval', $id_array);
    
    // Gumamit ng placeholders para sa PDO (Security best practice)
    $placeholders = implode(',', array_fill(0, count($clean_ids), '?'));

    try {
        $conn->beginTransaction();

        // 1. Burahin ang mga napiling items
        $stmt_del = $conn->prepare("DELETE FROM debit_memo_items WHERE id IN ($placeholders)");
        $stmt_del->execute($clean_ids);

        // 2. I-check kung may natira pa sa account na ito
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM debit_memo_items WHERE dm_id = ?");
        $stmt_check->execute([$dm_id]);
        $count = $stmt_check->fetchColumn();

        $account_deleted = false;
        $new_total = 0;

        if ($count == 0) {
            // Burahin ang parent record kung wala na itong child items
            $stmt_del_dm = $conn->prepare("DELETE FROM debit_memos WHERE dm_id = ?");
            $stmt_del_dm->execute([$dm_id]);
            $account_deleted = true;
        } else {
            // 3. I-recompute ang bagong total para sa natirang items
            $stmt_total = $conn->prepare("SELECT SUM(total_amount_due) FROM debit_memo_items WHERE dm_id = ?");
            $stmt_total->execute([$dm_id]);
            $new_total = $stmt_total->fetchColumn();
        }

        $conn->commit();

        // 4. Ibalik ang JSON response sa iyong JavaScript
        echo json_encode([
            'status' => 'success',
            'account_deleted' => $account_deleted,
            'new_total' => number_format((float)$new_total, 2, '.', ',')
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
}
?>