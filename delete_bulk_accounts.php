<?php
// delete_bulk_accounts.php
require_once 'config.php';
session_start();

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Security Check: Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// 3. Input Sanitization (Compatible with older PHP)
$ids_string = isset($_POST['dm_ids']) ? $_POST['dm_ids'] : '';
$id_array = explode(',', $ids_string);
$clean_ids = array_map('intval', array_filter($id_array));

if (empty($clean_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
    exit;
}

try {
    $conn->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($clean_ids), '?'));

    // 4. Delete child items
    $stmt_items = $conn->prepare("DELETE FROM debit_memo_items WHERE dm_id IN ($placeholders)");
    $stmt_items->execute($clean_ids);

    // 5. Delete parent accounts
    $stmt_dm = $conn->prepare("DELETE FROM debit_memos WHERE dm_id IN ($placeholders)");
    $stmt_dm->execute($clean_ids);

    $conn->commit();
    
    echo json_encode(['status' => 'success', 'message' => 'Accounts deleted']);

} catch (PDOException $e) {
    if (isset($conn) && method_exists($conn, 'inTransaction') && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Bulk Delete Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}