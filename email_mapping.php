<?php
// email_mapping.php

// Safe inclusions relative to script directory
$config_file = __DIR__ . '/config.php';
$main_file = __DIR__ . '/main.php';

if (file_exists($config_file)) {
    require_once $config_file;
}
if (file_exists($main_file)) {
    require_once $main_file;
}

$db_connection = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $db_connection = $pdo;
} elseif (isset($conn) && $conn instanceof PDO) {
    $db_connection = $conn;
}

// 0. Handle CSV Export (Download Full Data)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if ($db_connection) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=account_emails_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['account_number', 'email_address', 'mobile_number']);
        
        $stmt = $db_connection->query("SELECT account_number, email_address, mobile_number FROM account_emails");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$row['account_number'], $row['email_address'], $row['mobile_number'] ?? '']);
        }
        fclose($output);
        exit;
    }
}

// 0.1 Handle Download CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=account_emails_template.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['account_number', 'email_address', 'mobile_number']);
    fputcsv($output, ['1000040712', 'client@company.com', '09123456789']);
    fclose($output);
    exit;
}

// Handle Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    if ($db_connection) {
        try {
            $stmtDel = $db_connection->prepare("DELETE FROM account_emails WHERE id = ?");
            $stmtDel->execute([$delete_id]);
            header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?deleted=1");
            exit;
        } catch (Exception $e) {
            $error_message = "Error deleting record: " . $e->getMessage();
        }
    }
}

// 1. Handle Form Submissions (Save, Sync, Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_mapping') {
        $record_id = trim($_POST['record_id'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $email_address = trim($_POST['email_address'] ?? '');
        $mobile_number = trim($_POST['mobile_number'] ?? '');

        if (!empty($account_number) && !empty($email_address) && $db_connection) {
            try {
                if (!empty($record_id)) {
                    $stmtUpdate = $db_connection->prepare("UPDATE account_emails SET account_number = ?, email_address = ?, mobile_number = ? WHERE id = ?");
                    $stmtUpdate->execute([$account_number, $email_address, $mobile_number, $record_id]);
                    header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?updated=1");
                    exit;
                } else {
                    $stmtCheck = $db_connection->prepare("SELECT id FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtCheck->execute([$account_number]);
                    if ($stmtCheck->fetch()) {
                        $stmtUpdate = $db_connection->prepare("UPDATE account_emails SET email_address = ?, mobile_number = ? WHERE TRIM(account_number) = TRIM(?)");
                        $stmtUpdate->execute([$email_address, $mobile_number, $account_number]);
                    } else {
                        $stmt = $db_connection->prepare("INSERT INTO account_emails (account_number, email_address, mobile_number, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$account_number, $email_address, $mobile_number]);
                    }
                    header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?success=1");
                    exit;
                }
            } catch (Exception $e) {
                $error_message = "Error saving mapping: " . $e->getMessage();
            }
        } else {
            $error_message = "Please fill in the required fields (Account Number & Email Address).";
        }
    } elseif ($action === 'sync_accounts') {
        if ($db_connection) {
            try {
                $sqlSync = "INSERT INTO account_emails (account_number, email_address, mobile_number, created_at)
                            SELECT DISTINCT dm.account_number, '', '', NOW()
                            FROM debit_memos dm
                            WHERE TRIM(dm.account_number) NOT IN (SELECT TRIM(ae.account_number) FROM account_emails ae)";
                $countAdded = $db_connection->exec($sqlSync);

                $sqlUpdateMobiles = "UPDATE account_emails ae
                                    JOIN (
                                        SELECT dm.account_number, dmi.mobile_number
                                        FROM debit_memo_items dmi
                                        JOIN debit_memos dm ON dmi.dm_id = dm.dm_id
                                        WHERE dmi.mobile_number IS NOT NULL AND dmi.mobile_number != ''
                                        ORDER BY dmi.id DESC
                                    ) latest_mob ON TRIM(ae.account_number) = TRIM(latest_mob.account_number)
                                    SET ae.mobile_number = latest_mob.mobile_number
                                    WHERE ae.mobile_number IS NULL OR ae.mobile_number = ''";
                $db_connection->exec($sqlUpdateMobiles);

                header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?synced=" . intval($countAdded));
                exit;
            } catch (Exception $e) {
                $error_message = "Error syncing accounts and mobile numbers: " . $e->getMessage();
            }
        }
    } elseif ($action === 'upload_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($fileTmpPath, 'r')) !== FALSE) {
                $rowNum = 0;
                $importedCount = 0;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rowNum++;
                    if ($rowNum === 1 && (stripos($data[0], 'account') !== false)) {
                        continue;
                    }
                    $acc_num = trim($data[0] ?? '');
                    $email = trim($data[1] ?? '');
                    $mobile = trim($data[2] ?? '');

                    if (!empty($acc_num)) {
                        try {
                            $stmtCheck = $db_connection->prepare("SELECT id FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtCheck->execute([$acc_num]);
                            if ($stmtCheck->fetch()) {
                                $stmtUp = $db_connection->prepare("UPDATE account_emails SET email_address = COALESCE(NULLIF(?, ''), email_address), mobile_number = COALESCE(NULLIF(?, ''), mobile_number) WHERE TRIM(account_number) = TRIM(?)");
                                $stmtUp->execute([$email, $mobile, $acc_num]);
                            } else {
                                $stmtIns = $db_connection->prepare("INSERT INTO account_emails (account_number, email_address, mobile_number, created_at) VALUES (?, ?, ?, NOW())");
                                $stmtIns->execute([$acc_num, $email, $mobile]);
                            }
                            $importedCount++;
                        } catch (Exception $ex) {}
                    }
                }
                fclose($handle);
                header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?imported=" . $importedCount);
                exit;
            } else {
                $error_message = "Failed to open uploaded CSV file.";
            }
        } else {
            $error_message = "Please upload a valid CSV file.";
        }
    }
}

// 2. Backend database fetching logic with Search, Sorting & Pagination Options
$search_query = trim($_GET['search'] ?? '');
$sort_col = $_GET['sort'] ?? 'id';
$sort_dir = strtoupper($_GET['dir'] ?? 'DESC');
$page = max(1, intval($_GET['page'] ?? 1));

// Handle rows per page selection (10, 25, 50, 100, or All)
$limit_input = $_GET['limit'] ?? '25';
if ($limit_input === 'all') {
    $limit = 999999; // Effectively all records
} else {
    $limit = intval($limit_input);
    if (!in_array($limit, [10, 25, 50, 100])) {
        $limit = 25;
    }
}
$offset = ($page - 1) * $limit;

// Validate sort columns and directions to prevent SQL injection
$allowed_cols = ['id', 'account_number', 'email_address', 'mobile_number', 'created_at'];
if (!in_array($sort_col, $allowed_cols)) {
    $sort_col = 'id';
}
if ($sort_dir !== 'ASC' && $sort_dir !== 'DESC') {
    $sort_dir = 'DESC';
}

$email_records = [];
$total_records = 0;
$total_pages = 1;

try {
    if ($db_connection) {
        $whereSql = "";
        $params = [];
        if (!empty($search_query)) {
            $whereSql = "WHERE account_number LIKE ? OR email_address LIKE ? OR mobile_number LIKE ?";
            $params = ["%{$search_query}%", "%{$search_query}%", "%{$search_query}%"];
        }

        // Get total records count for pagination
        $stmtCount = $db_connection->prepare("SELECT COUNT(*) FROM account_emails {$whereSql}");
        $stmtCount->execute($params);
        $total_records = $stmtCount->fetchColumn();
        $total_pages = max(1, ceil($total_records / $limit));

        // Ensure page doesn't exceed total pages
        if ($page > $total_pages) {
            $page = $total_pages;
            $offset = ($page - 1) * $limit;
        }

        // Fetch paginated records
        $stmtEmailRecords = $db_connection->prepare("SELECT * FROM account_emails {$whereSql} ORDER BY {$sort_col} {$sort_dir} LIMIT {$limit} OFFSET {$offset}");
        $stmtEmailRecords->execute($params);
        $email_records = $stmtEmailRecords->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $email_records = [];
}

// Helper function to generate query strings for pagination and sorting
function getQueryUrl($params_to_change) {
    $current_params = $_GET;
    foreach ($params_to_change as $key => $value) {
        if ($value === null) {
            unset($current_params[$key]);
        } else {
            $current_params[$key] = $value;
        }
    }
    return '?' . http_build_query($current_params);
}

// Helper to display sorting indicator arrows
function getSortIcon($column, $current_col, $current_dir) {
    if ($current_col !== $column) {
        return '<span class="text-gray-300 ml-1">↕</span>';
    }
    return $current_dir === 'ASC' ? '<span class="text-blue-600 ml-1">↑</span>' : '<span class="text-blue-600 ml-1">↓</span>';
}

// 3. Guard clause
if (!empty($load_functions_only)) {
    return;
}

// Layout Rendering Block
ob_start();
?>

<!-- EMAIL MAPPINGS VIEW CONTAINER -->
<div class="bg-white shadow-lg rounded-2xl p-6 border border-gray-100 max-w-7xl mx-auto mt-6">
    <!-- SHORTENED HEADER & ACTIONS BAR -->
    <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Manage Account Email & Mobile Mappings</h2>
        </div>
        
        <!-- TOP CONSOLIDATED ACTION BUTTONS -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Add Email Button (Triggers Modal) -->
            <button type="button" onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                ➕ Add Email Mapping
            </button>

            <!-- Sync Button -->
            <form method="POST" action="" onsubmit="return confirm('Do you want to sync all missing account numbers and update latest mobile numbers from debit memos?');">
                <input type="hidden" name="action" value="sync_accounts">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                    🔄 Sync Accounts
                </button>
            </form>

            <!-- Export CSV Button -->
            <a href="?action=export_csv" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                📥 Export CSV
            </a>

            <!-- Trigger Import Modal Button -->
            <button type="button" onclick="openImportModal()" class="bg-slate-700 hover:bg-slate-800 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                📁 Import via CSV
            </button>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
            Email mapping saved successfully!
        </div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-sm">
            Email mapping updated successfully!
        </div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 text-yellow-700 rounded-xl text-sm">
            Email mapping deleted successfully!
        </div>
    <?php elseif (isset($_GET['synced'])): ?>
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-sm">
            Successfully synced <strong><?php echo intval($_GET['synced']); ?></strong> new account(s) and updated mobile numbers!
        </div>
    <?php elseif (isset($_GET['imported'])): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
            Successfully imported/updated <strong><?php echo intval($_GET['imported']); ?></strong> record(s) from CSV!
        </div>
    <?php endif; ?>

    <!-- SEARCH & PER-PAGE FILTER BAR -->
    <form method="GET" action="" class="flex flex-col sm:flex-row items-center justify-between mb-4 gap-3">
        <?php if (!empty($sort_col)): ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_col); ?>">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sort_dir); ?>">
        <?php endif; ?>
        
        <div class="flex items-center gap-3 w-full sm:w-auto">
            <!-- Search Input -->
            <div class="relative flex items-center w-full sm:max-w-xs">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search account, email, or mobile..." class="w-full text-xs border border-gray-300 rounded-xl pl-3 pr-8 py-2 bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="absolute right-2.5 text-gray-400 hover:text-gray-600 text-xs">🔍</button>
            </div>

            <!-- Per-Page Dropdown Selector -->
            <div class="flex items-center gap-1.5 text-xs text-gray-600 whitespace-nowrap">
                <select name="limit" onchange="this.form.submit()" class="border border-gray-300 rounded-xl px-2.5 py-2 bg-white shadow-sm text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer">
                    <option value="10" <?php echo ($limit_input == 10) ? 'selected' : ''; ?>>10 per page</option>
                    <option value="25" <?php echo ($limit_input == 25) ? 'selected' : ''; ?>>25 per page</option>
                    <option value="50" <?php echo ($limit_input == 50) ? 'selected' : ''; ?>>50 per page</option>
                    <option value="100" <?php echo ($limit_input == 100) ? 'selected' : ''; ?>>100 per page</option>
                    <option value="all" <?php echo ($limit_input === 'all') ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
        </div>

        <div class="text-xs text-gray-500 font-medium">
            Total Records: <strong><?php echo $total_records; ?></strong>
        </div>
    </form>
    
    <!-- DATA TABLE -->
    <div class="overflow-x-auto border border-gray-200 rounded-2xl shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-gray-600 font-semibold uppercase">
                <tr>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'id', 'dir' => ($sort_col === 'id' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            ID <?php echo getSortIcon('id', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'account_number', 'dir' => ($sort_col === 'account_number' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Account Number <?php echo getSortIcon('account_number', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'email_address', 'dir' => ($sort_col === 'email_address' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Email Address <?php echo getSortIcon('email_address', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'mobile_number', 'dir' => ($sort_col === 'mobile_number' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Mobile Number <?php echo getSortIcon('mobile_number', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'created_at', 'dir' => ($sort_col === 'created_at' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Date Added <?php echo getSortIcon('created_at', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100 text-gray-700">
                <?php if (!empty($email_records)): ?>
                    <?php foreach ($email_records as $row): ?>
                        <tr class="hover:bg-gray-50/50 transition">
                            <td class="px-6 py-3.5 text-gray-500"><?php echo htmlspecialchars($row['id'] ?? ''); ?></td>
                            <td class="px-6 py-3.5 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($row['account_number'] ?? ''); ?></td>
                            <td class="px-6 py-3.5 text-gray-800 font-medium"><?php echo htmlspecialchars($row['email_address'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-gray-800 font-medium"><?php echo htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-gray-500"><?php echo htmlspecialchars($row['created_at'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-center font-medium space-x-3">
                                <button type="button" 
                                    onclick="openEditModal(
                                        '<?php echo $row['id']; ?>', 
                                        '<?php echo htmlspecialchars($row['account_number'], ENT_QUOTES); ?>', 
                                        '<?php echo htmlspecialchars($row['email_address'], ENT_QUOTES); ?>', 
                                        '<?php echo htmlspecialchars($row['mobile_number'], ENT_QUOTES); ?>'
                                    )" 
                                    class="text-blue-600 hover:text-blue-900 font-semibold">Edit</button>
                                <a href="?action=delete&id=<?php echo $row['id']; ?>" class="text-red-600 hover:text-red-900 font-semibold" onclick="return confirm('Are you sure you want to delete this record?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-gray-400 italic">No mapping records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION CONTROLS -->
    <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between mt-5 gap-3">
            <div class="text-xs text-gray-500">
                Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_records); ?></strong> of <strong><?php echo $total_records; ?></strong> entries
            </div>
            <div class="flex items-center space-x-1">
                <!-- First Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo getQueryUrl(['page' => 1]); ?>" class="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">First</a>
                <?php endif; ?>

                <!-- Previous Page -->
                <?php if ($page > 1): ?>
                    <a href="<?php echo getQueryUrl(['page' => $page - 1]); ?>" class="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Prev</a>
                <?php endif; ?>

                <!-- Numbered Pagination links (Window of nearby pages) -->
                <?php
                $start_p = max(1, $page - 2);
                $end_p = min($total_pages, $page + 2);
                for ($i = $start_p; $i <= $end_p; $i++):
                ?>
                    <a href="<?php echo getQueryUrl(['page' => $i]); ?>" class="px-3 py-1.5 text-xs border rounded-lg transition font-medium <?php echo ($i === $page) ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <!-- Next Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo getQueryUrl(['page' => $page + 1]); ?>" class="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Next</a>
                <?php endif; ?>

                <!-- Last Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo getQueryUrl(['page' => $total_pages]); ?>" class="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Last</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL: ADD NEW MAPPING -->
<div id="addModal" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-gray-100">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Add New Single Mapping</h3>
            <button type="button" onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_mapping">
            
            <div class="space-y-4 mb-5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Account Number</label>
                    <input type="text" name="account_number" placeholder="e.g. 1000040712" required
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Email Address</label>
                    <input type="email" name="email_address" placeholder="client@company.com" required
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Mobile Number</label>
                    <input type="text" name="mobile_number" placeholder="09123456789"
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeAddModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Save Mapping</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: IMPORT CSV -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Import Mappings via CSV</h3>
            <button type="button" onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        
        <div class="mb-4 flex items-center justify-between bg-blue-50 border border-blue-100 p-3 rounded-xl">
            <div>
                <p class="text-[11px] font-semibold text-blue-800">Need a format guide?</p>
                <p class="text-[10px] text-blue-600">Download the template to start correctly.</p>
            </div>
            <a href="?action=download_template" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1.5 px-3 rounded-lg text-[11px] transition shadow-sm flex items-center gap-1">
                📥 Download Template
            </a>
        </div>

        <p class="text-xs text-gray-500 mb-2">Format required: <code class="bg-gray-100 px-1 py-0.5 rounded text-gray-700">account_number, email_address, mobile_number</code></p>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_csv">
            <div class="mb-4">
                <input type="file" name="csv_file" accept=".csv" required class="w-full text-xs text-gray-600 file:mr-2 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-700 file:text-white hover:file:bg-slate-800 cursor-pointer border border-gray-200 rounded-xl p-2 bg-gray-50">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeImportModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Upload & Import</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: EDIT MAPPING -->
<div id="editModal" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-gray-100">
        <div class="flex justify-between items-center mb-4">
            <h3 id="editModalTitle" class="text-sm font-bold text-gray-900 uppercase tracking-wider">Edit Mapping</h3>
            <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_mapping">
            <input type="hidden" name="record_id" id="edit_record_id">
            
            <div class="space-y-4 mb-5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Account Number</label>
                    <input type="text" name="account_number" id="edit_account_number" required
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Email Address</label>
                    <input type="email" name="email_address" id="edit_email_address" required
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Mobile Number</label>
                    <input type="text" name="mobile_number" id="edit_mobile_number"
                        class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeEditModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Update Mapping</button>
            </div>
        </form>
    </div>
</div>

<!-- JAVASCRIPT FUNCTIONS FOR MODALS -->
<script>
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
}
function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

function openImportModal() {
    document.getElementById('importModal').classList.remove('hidden');
}
function closeImportModal() {
    document.getElementById('importModal').classList.add('hidden');
}

function openEditModal(id, accountNo, email, mobile) {
    document.getElementById('edit_record_id').value = id;
    document.getElementById('edit_account_number').value = accountNo;
    document.getElementById('edit_email_address').value = email;
    document.getElementById('edit_mobile_number').value = mobile === 'N/A' ? '' : mobile;
    document.getElementById('editModalTitle').innerText = 'Edit Mapping (ID: ' + id + ')';
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}
</script>

<?php
$content = ob_get_clean();
if (function_exists('render_layout')) {
    render_layout("Email Mappings", $content);
} else {
    echo $content;
}
?>