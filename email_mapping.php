<?php
// email_mapping.php  -  Account Mapping (table: account_emails)

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

// 0a. Add the group / reporting-line columns if they do not exist yet (safe to run every time)
if ($db_connection) {
    try {
        $existing_cols = $db_connection->query("SHOW COLUMNS FROM account_emails")->fetchAll(PDO::FETCH_COLUMN);
        $new_cols = [
            'employee_status'            => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'group_name'                 => "VARCHAR(150) NULL",
            'department'                 => "VARCHAR(150) NULL",
            'president'                  => "VARCHAR(255) NULL",
            'president_email'            => "VARCHAR(255) NULL",
            'section_head'               => "VARCHAR(255) NULL",
            'section_head_email'         => "VARCHAR(255) NULL",
            'bu_head'                    => "VARCHAR(255) NULL",
            'bu_head_email'              => "VARCHAR(255) NULL",
            'bu_admin_coordinator'       => "VARCHAR(255) NULL",
            'bu_admin_coordinator_email' => "VARCHAR(255) NULL",
            'other_cc'                   => "VARCHAR(500) NULL",
        ];
        foreach ($new_cols as $col_name => $col_def) {
            if (!in_array($col_name, $existing_cols, true)) {
                $db_connection->exec("ALTER TABLE account_emails ADD COLUMN `{$col_name}` {$col_def}");
            }
        }
    } catch (Exception $e) {
        // table missing or no ALTER permission: run the ALTER TABLE manually
    }
}

// Split pasted account numbers (comma, semicolon, space or new line separated)
if (!function_exists('split_emails_or_tokens')) {
    function split_emails_or_tokens($raw)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$raw)), function ($v) { return $v !== ''; }));
    }
}

// Split "a@x.com; b@y.com, c@z.com" into a clean array of tokens
if (!function_exists('split_emails')) {
    function split_emails($raw)
    {
        $parts = preg_split('/[;,\s]+/', (string)$raw);
        return array_values(array_filter(array_map('trim', $parts), function ($v) { return $v !== ''; }));
    }
}

/**
 * Decide who receives the email for one account_emails row.
 *   Active employee   -> TO: employee               CC: other_cc (+ BU Admin & President if $cc_management)
 *   Resigned employee -> TO: BU Admin (fallback: President)   CC: President + other_cc
 * Returns ['to' => [...], 'cc' => [...], 'mode' => 'active'|'resigned'|'fallback']
 */
if (!function_exists('get_email_recipients')) {
    function get_email_recipients(array $row, $cc_management = false)
    {
        $employee  = trim($row['email_address'] ?? '');
        // BU Admin = TELCO BU ADMIN COORDINATOR (old column bu_admin_email still read if present)
        $bu_admin  = trim($row['bu_admin_coordinator_email'] ?? ($row['bu_admin_email'] ?? ''));
        $president = trim($row['president_email'] ?? '');
        $others    = split_emails($row['other_cc'] ?? '');
        $resigned  = strtolower(trim($row['employee_status'] ?? 'active')) === 'resigned';

        if ($resigned) {
            $mode = 'resigned';
            $to   = [$bu_admin !== '' ? $bu_admin : $president];
            $cc   = array_merge([$president], $others);
        } else {
            $mode = ($employee !== '') ? 'active' : 'fallback';
            $to   = [$employee !== '' ? $employee : ($bu_admin !== '' ? $bu_admin : $president)];
            $cc   = $cc_management ? array_merge([$bu_admin, $president], $others) : $others;
        }

        // keep only valid addresses, no duplicates, and never repeat a TO address in CC
        $seen  = [];
        $clean = function (array $list) use (&$seen) {
            $out = [];
            foreach ($list as $addr) {
                $addr = trim($addr);
                $key  = strtolower($addr);
                if ($addr !== '' && !isset($seen[$key]) && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $seen[$key] = true;
                    $out[] = $addr;
                }
            }
            return $out;
        };
        $to = $clean($to);
        $cc = $clean($cc);

        return ['to' => $to, 'cc' => $cc, 'mode' => $mode];
    }
}

// Convenience: look up by account number (use this from your other email pages)
if (!function_exists('get_recipients_by_account')) {
    function get_recipients_by_account(PDO $pdo, $account_number, $cc_management = false)
    {
        $stmt = $pdo->prepare("SELECT * FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
        $stmt->execute([$account_number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? get_email_recipients($row, $cc_management) : ['to' => [], 'cc' => [], 'mode' => 'not_found'];
    }
}

// Shared form fields for the Add / Edit modals ($p = id prefix: 'add_' or 'edit_')
if (!function_exists('render_mapping_fields')) {
    function render_mapping_fields($p)
    {
        $inp = 'w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500';
        $lbl = 'block text-xs font-semibold text-gray-600 mb-1';
        $field = function ($name, $label, $type = 'text', $ph = '', $req = false, $span = false) use ($p, $inp, $lbl) {
            return '<div' . ($span ? ' class="sm:col-span-2"' : '') . '><label class="' . $lbl . '" for="' . $p . $name . '">' . $label . '</label>'
                 . '<input type="' . $type . '" name="' . $name . '" id="' . $p . $name . '" placeholder="' . htmlspecialchars($ph) . '"' . ($req ? ' required' : '')
                 . ' class="' . $inp . '"></div>';
        };
        echo '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">';
        echo '<div class="sm:col-span-2 text-[11px] font-bold text-gray-400 uppercase tracking-wider">Account &amp; Employee</div>';
        echo $field('account_number', 'Account Number', 'text', 'e.g. 1000040712', true);
        echo $field('full_name', 'Full Name', 'text', 'e.g. John Doe');
        echo $field('employee_id', 'Employee ID', 'text', 'e.g. EMP12345');
        echo $field('email_address', 'Employee Email', 'email', 'client@company.com');
        echo $field('mobile_number', 'Mobile Number', 'text', '09123456789');
        echo '<div><label class="' . $lbl . '" for="' . $p . 'employee_status">Employee Status</label>'
           . '<select name="employee_status" id="' . $p . 'employee_status" class="' . $inp . '">'
           . '<option value="active">Active</option><option value="resigned">Resigned</option></select></div>';
        echo '<div class="sm:col-span-2 text-[11px] font-bold text-gray-400 uppercase tracking-wider pt-1">Group &amp; Department</div>';
        echo $field('group_name', 'Group', 'text', 'e.g. AGRIBUSINESS');
        echo $field('department', 'Department', 'text', 'e.g. FEEDMILL COMMERCIAL');
        echo '<div class="sm:col-span-2 text-[11px] font-bold text-gray-400 uppercase tracking-wider pt-1">Reporting Line (who receives the email / reports)</div>';
        echo $field('president', 'President', 'text', 'e.g. Juan Dela Cruz');
        echo $field('president_email', 'President Email', 'email', 'president@company.com');
        echo $field('section_head', 'Section Head', 'text', 'e.g. Pedro Reyes');
        echo $field('section_head_email', 'Section Head Email', 'email', 'section.head@company.com');
        echo $field('bu_head', 'BU Head', 'text', 'e.g. Ana Cruz');
        echo $field('bu_head_email', 'BU Head Email', 'email', 'bu.head@company.com');
        echo $field('bu_admin_coordinator', 'Telco BU Admin Coordinator', 'text', 'e.g. Maria Santos');
        echo $field('bu_admin_coordinator_email', 'Telco BU Admin Coordinator Email', 'email', 'bu.admin@company.com');
        echo $field('other_cc', 'Other Personnel (CC)', 'text', 'comma or ; separated emails', false, true);
        echo '<p class="sm:col-span-2 text-[11px] text-gray-500 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2">'
           . 'If status is <strong>Resigned</strong>, emails go to the <strong>Telco BU Admin Coordinator</strong> (President + Other Personnel in CC) instead of the employee.</p>';
        echo '</div>';
    }
}

// Columns used by the form, CSV export, template and import (account_number is the key)
$data_fields = ['full_name', 'employee_id', 'email_address', 'mobile_number', 'group_name', 'department', 'president', 'president_email', 'section_head', 'section_head_email', 'bu_head', 'bu_head_email', 'bu_admin_coordinator', 'bu_admin_coordinator_email', 'employee_status', 'other_cc'];
$csv_columns = array_merge(['account_number'], $data_fields);

// CSV header names used by Export and the Template (Import accepts these AND the database names)
$csv_headers = [
    'account_number'             => 'account_number',
    'full_name'                  => 'full_name',
    'employee_id'                => 'employee_id',
    'email_address'              => 'email_address',
    'mobile_number'              => 'mobile_number',
    'group_name'                 => 'GROUP',
    'department'                 => 'DEPARTMENT',
    'president'                  => 'PRESIDENT',
    'president_email'            => 'PRESIDENT email',
    'section_head'               => 'SECTION HEAD',
    'section_head_email'         => 'SECTION HEAD email',
    'bu_head'                    => 'BU HEAD',
    'bu_head_email'              => 'BU HEAD EMAIL',
    'bu_admin_coordinator'       => 'TELCO BU ADMIN COORDINATOR',
    'bu_admin_coordinator_email' => 'TELCO BU ADMIN COORDINATOR email',
    'employee_status'            => 'employee_status',
    'other_cc'                   => 'other_cc',
];

// header text (lower case, single spaces) -> column name, for Import
$csv_normalize = function ($h) {
    $h = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$h)));
    return preg_replace('/\s+/', ' ', $h);
};
$csv_alias = [];
foreach ($csv_columns as $c) { $csv_alias[$csv_normalize($c)] = $c; }
foreach ($csv_headers as $c => $label) { $csv_alias[$csv_normalize($label)] = $c; }
foreach ([
    'status' => 'employee_status', 'employee status' => 'employee_status', 'other cc' => 'other_cc',
    'email' => 'email_address', 'employee email' => 'email_address',
    // older exports of this page
    'president_name' => 'president', 'bu_admin_name' => 'bu_admin_coordinator', 'bu_admin_email' => 'bu_admin_coordinator_email',
] as $alias => $c) { $csv_alias[$alias] = $c; }
$search_cols = ['account_number', 'full_name', 'employee_id', 'email_address', 'mobile_number', 'group_name', 'department', 'president', 'president_email', 'section_head', 'section_head_email', 'bu_head', 'bu_head_email', 'bu_admin_coordinator', 'bu_admin_coordinator_email', 'other_cc'];

// 0. Handle CSV Export (Download Full Data)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if ($db_connection) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=account_mapping_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows the characters correctly
        fputcsv($output, array_map(function ($c) use ($csv_headers) { return $csv_headers[$c]; }, $csv_columns));

        $stmt = $db_connection->query("SELECT " . implode(', ', $csv_columns) . " FROM account_emails");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($csv_columns as $c) {
                $line[] = $row[$c] ?? '';
            }
            fputcsv($output, $line);
        }
        fclose($output);
        exit;
    }
}

// 0.1 Handle Download CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=account_mapping_template.csv');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_map(function ($c) use ($csv_headers) { return $csv_headers[$c]; }, $csv_columns));
    $samples = [
        ['account_number' => '1000040712', 'full_name' => 'John Doe', 'employee_id' => 'EMP12345', 'email_address' => 'client@company.com', 'mobile_number' => '09123456789',
         'group_name' => 'AGRIBUSINESS', 'department' => 'FEEDMILL COMMERCIAL', 'president' => 'Juan Dela Cruz', 'president_email' => 'president@company.com',
         'section_head' => 'Pedro Reyes', 'section_head_email' => 'section.head@company.com', 'bu_head' => 'Ana Cruz', 'bu_head_email' => 'bu.head@company.com',
         'bu_admin_coordinator' => 'Maria Santos', 'bu_admin_coordinator_email' => 'bu.admin@company.com', 'employee_status' => 'active', 'other_cc' => 'hr@company.com; finance@company.com'],
        ['account_number' => '1000040713', 'full_name' => 'Ana Reyes', 'employee_id' => 'EMP12346', 'email_address' => '', 'mobile_number' => '',
         'group_name' => 'AGRIBUSINESS', 'department' => 'GRAINS TRADING', 'president' => 'Juan Dela Cruz', 'president_email' => 'president@company.com',
         'section_head' => 'Pedro Reyes', 'section_head_email' => 'section.head@company.com', 'bu_head' => 'Ana Cruz', 'bu_head_email' => 'bu.head@company.com',
         'bu_admin_coordinator' => 'Maria Santos', 'bu_admin_coordinator_email' => 'bu.admin@company.com', 'employee_status' => 'resigned', 'other_cc' => ''],
    ];
    foreach ($samples as $sample) {
        $line = [];
        foreach ($csv_columns as $c) { $line[] = $sample[$c] ?? ''; }
        fputcsv($output, $line);
    }
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
        $record_id      = trim($_POST['record_id'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $vals = [];
        foreach ($data_fields as $f) {
            $vals[$f] = trim($_POST[$f] ?? '');
        }
        if (!in_array($vals['employee_status'], ['active', 'resigned'], true)) {
            $vals['employee_status'] = 'active';
        }
        $vals['other_cc'] = implode('; ', split_emails($vals['other_cc']));

        $has_email = $vals['email_address'] !== '' || $vals['bu_admin_coordinator_email'] !== '' || $vals['president_email'] !== '';
        $bad_email = '';
        foreach (['email_address', 'bu_admin_coordinator_email', 'president_email', 'section_head_email', 'bu_head_email'] as $ef) {
            if ($vals[$ef] !== '' && !filter_var($vals[$ef], FILTER_VALIDATE_EMAIL)) {
                $bad_email = $vals[$ef];
            }
        }
        foreach (split_emails($vals['other_cc']) as $tok) {
            if (!filter_var($tok, FILTER_VALIDATE_EMAIL)) {
                $bad_email = $tok;
            }
        }

        if ($account_number === '' || !$has_email) {
            $error_message = "Please fill in the Account Number and at least one email (employee, BU Admin Coordinator or President).";
        } elseif ($bad_email !== '') {
            $error_message = "Invalid email address: " . $bad_email;
        } elseif ($db_connection) {
            try {
                $set_sql = implode(', ', array_map(function ($f) { return "{$f} = ?"; }, $data_fields));
                $params  = array_values($vals);

                if ($record_id !== '') {
                    $stmtUpdate = $db_connection->prepare("UPDATE account_emails SET account_number = ?, {$set_sql} WHERE id = ?");
                    $stmtUpdate->execute(array_merge([$account_number], $params, [$record_id]));
                    header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?updated=1");
                    exit;
                } else {
                    $stmtCheck = $db_connection->prepare("SELECT id FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtCheck->execute([$account_number]);
                    if ($stmtCheck->fetch()) {
                        $stmtUpdate = $db_connection->prepare("UPDATE account_emails SET {$set_sql} WHERE TRIM(account_number) = TRIM(?)");
                        $stmtUpdate->execute(array_merge($params, [$account_number]));
                    } else {
                        $cols = implode(', ', $data_fields);
                        $ph   = implode(', ', array_fill(0, count($data_fields), '?'));
                        $stmt = $db_connection->prepare("INSERT INTO account_emails (account_number, {$cols}, created_at) VALUES (?, {$ph}, NOW())");
                        $stmt->execute(array_merge([$account_number], $params));
                    }
                    header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?success=1");
                    exit;
                }
            } catch (Exception $e) {
                $error_message = "Error saving mapping: " . $e->getMessage();
            }
        }
    } elseif ($action === 'sync_accounts') {
        if ($db_connection) {
            try {
                // 1. Insert missing accounts and pull assignee_name as full_name from debit_memos
                $sqlSync = "INSERT INTO account_emails (account_number, full_name, employee_id, email_address, mobile_number, created_at)
                            SELECT DISTINCT dm.account_number, COALESCE(dm.assignee_name, ''), '', '', '', NOW()
                            FROM debit_memos dm
                            WHERE TRIM(dm.account_number) NOT IN (SELECT TRIM(ae.account_number) FROM account_emails ae)";
                $countAdded = $db_connection->exec($sqlSync);

                // 2. Update empty full_names from debit_memos assignee_name if matching existing records
                $sqlUpdateNames = "UPDATE account_emails ae
                                    JOIN (
                                        SELECT dm.account_number, dm.assignee_name
                                        FROM debit_memos dm
                                        WHERE dm.assignee_name IS NOT NULL AND dm.assignee_name != ''
                                        ORDER BY dm.dm_id DESC
                                    ) latest_name ON TRIM(ae.account_number) = TRIM(latest_name.account_number)
                                    SET ae.full_name = latest_name.assignee_name
                                    WHERE (ae.full_name IS NULL OR ae.full_name = '' OR ae.full_name = 'N/A')";
                $db_connection->exec($sqlUpdateNames);

                // 3. Update mobile numbers from debit_memo_items
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
                $error_message = "Error syncing accounts, names, and mobile numbers: " . $e->getMessage();
            }
        }
    } elseif ($action === 'bulk_assign') {
        $scope = $_POST['scope'] ?? 'selected';
        $bulk_fields = ['employee_status', 'group_name', 'department', 'president', 'president_email', 'section_head', 'section_head_email', 'bu_head', 'bu_head_email', 'bu_admin_coordinator', 'bu_admin_coordinator_email', 'other_cc'];
        $bvals = [];
        foreach ($bulk_fields as $f) {
            $bvals[$f] = trim($_POST[$f] ?? '');
        }
        if (!in_array($bvals['employee_status'], ['active', 'resigned'], true)) {
            $bvals['employee_status'] = '';
        }
        $bvals['other_cc'] = implode('; ', split_emails($bvals['other_cc']));

        $bad_email = '';
        foreach (['bu_admin_coordinator_email', 'president_email', 'section_head_email', 'bu_head_email'] as $ef) {
            if ($bvals[$ef] !== '' && !filter_var($bvals[$ef], FILTER_VALIDATE_EMAIL)) {
                $bad_email = $bvals[$ef];
            }
        }
        foreach (split_emails($bvals['other_cc']) as $tok) {
            if (!filter_var($tok, FILTER_VALIDATE_EMAIL)) {
                $bad_email = $tok;
            }
        }

        // only fields that were filled in are applied; blank = leave unchanged
        $apply = array_filter($bvals, function ($v) { return $v !== ''; });

        // which accounts to update
        $where = '';
        $wparams = [];
        if ($scope === 'selected') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $_POST['bulk_ids'] ?? ''))));
            if (!empty($ids)) {
                $where = 'id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                $wparams = $ids;
            }
        } elseif ($scope === 'list') {
            $accs = array_values(array_unique(split_emails_or_tokens($_POST['account_list'] ?? '')));
            if (!empty($accs)) {
                $where = 'TRIM(account_number) IN (' . implode(', ', array_fill(0, count($accs), '?')) . ')';
                $wparams = $accs;
            }
        } elseif ($scope === 'filtered') {
            $f_search = trim($_POST['f_search'] ?? '');
            $f_status = $_POST['f_status'] ?? '';
            $conds = [];
            if ($f_search !== '') {
                $parts = [];
                foreach ($search_cols as $c) {
                    $parts[] = "{$c} LIKE ?";
                    $wparams[] = "%{$f_search}%";
                }
                $conds[] = '(' . implode(' OR ', $parts) . ')';
            }
            if (in_array($f_status, ['active', 'resigned'], true)) {
                $conds[] = 'employee_status = ?';
                $wparams[] = $f_status;
            }
            if (!empty($conds)) {
                $where = implode(' AND ', $conds);
            }
        }

        if (empty($apply)) {
            $error_message = "Bulk assign: fill in at least one field to apply.";
        } elseif ($bad_email !== '') {
            $error_message = "Invalid email address: " . $bad_email;
        } elseif ($where === '') {
            $error_message = "Bulk assign: no accounts to update. Select rows, paste account numbers, or search/filter first.";
        } elseif ($db_connection) {
            try {
                $stmtCnt = $db_connection->prepare("SELECT COUNT(*) FROM account_emails WHERE {$where}");
                $stmtCnt->execute($wparams);
                $matched = (int)$stmtCnt->fetchColumn();

                $set_sql = implode(', ', array_map(function ($f) { return "{$f} = ?"; }, array_keys($apply)));
                $stmtBulk = $db_connection->prepare("UPDATE account_emails SET {$set_sql} WHERE {$where}");
                $stmtBulk->execute(array_merge(array_values($apply), $wparams));

                header("Location: " . strtok($_SERVER['PHP_SELF'], '?') . "?bulk=" . $matched);
                exit;
            } catch (Exception $e) {
                $error_message = "Error in bulk assign: " . $e->getMessage();
            }
        }
    } elseif ($action === 'upload_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($fileTmpPath, 'r')) !== FALSE) {
                $rowNum = 0;
                $importedCount = 0;
                $colIndex = array_flip($csv_columns); // default: positional order of $csv_columns
                while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
                    $rowNum++;
                    // Header row: columns are matched by header name (GROUP, DEPARTMENT, TELCO BU ADMIN COORDINATOR ... or the database names)
                    if ($rowNum === 1 && stripos($data[0] ?? '', 'account') !== false) {
                        $map = [];
                        foreach ($data as $i => $h) {
                            $h = $csv_normalize($h);
                            if (isset($csv_alias[$h]) && !isset($map[$csv_alias[$h]])) {
                                $map[$csv_alias[$h]] = $i;
                            }
                        }
                        if (!empty($map)) {
                            $colIndex = $map;
                        }
                        continue;
                    }
                    $acc_num = trim($data[$colIndex['account_number'] ?? 0] ?? '');
                    $vals = [];
                    foreach ($data_fields as $f) {
                        $vals[$f] = isset($colIndex[$f]) ? trim($data[$colIndex[$f]] ?? '') : '';
                    }
                    $vals['employee_status'] = strtolower($vals['employee_status']);
                    if (!in_array($vals['employee_status'], ['active', 'resigned'], true)) {
                        $vals['employee_status'] = '';
                    }
                    $vals['other_cc'] = implode('; ', split_emails($vals['other_cc']));

                    if ($acc_num !== '') {
                        try {
                            $stmtCheck = $db_connection->prepare("SELECT id FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtCheck->execute([$acc_num]);
                            if ($stmtCheck->fetch()) {
                                $set_sql = implode(', ', array_map(function ($f) { return "{$f} = COALESCE(NULLIF(?, ''), {$f})"; }, $data_fields));
                                $stmtUp = $db_connection->prepare("UPDATE account_emails SET {$set_sql} WHERE TRIM(account_number) = TRIM(?)");
                                $stmtUp->execute(array_merge(array_values($vals), [$acc_num]));
                            } else {
                                if ($vals['employee_status'] === '') {
                                    $vals['employee_status'] = 'active';
                                }
                                $cols = implode(', ', $data_fields);
                                $ph   = implode(', ', array_fill(0, count($data_fields), '?'));
                                $stmtIns = $db_connection->prepare("INSERT INTO account_emails (account_number, {$cols}, created_at) VALUES (?, {$ph}, NOW())");
                                $stmtIns->execute(array_merge([$acc_num], array_values($vals)));
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

// 2. Backend database fetching logic with Search, Filter, Sorting & Pagination Options
$search_query  = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, ['active', 'resigned'], true)) {
    $status_filter = '';
}
$sort_col = $_GET['sort'] ?? 'id';
$sort_dir = strtoupper($_GET['dir'] ?? 'DESC');
$page = max(1, intval($_GET['page'] ?? 1));

// Handle rows per page selection (10, 25, 50, 100, or All)
$limit_input = $_GET['limit'] ?? '25';
if ($limit_input === 'all') {
    $limit = 999999; 
} else {
    $limit = intval($limit_input);
    if (!in_array($limit, [10, 25, 50, 100])) {
        $limit = 25;
    }
}
$offset = ($page - 1) * $limit;

// Validate sort columns and directions to prevent SQL injection
$allowed_cols = ['id', 'account_number', 'full_name', 'employee_id', 'email_address', 'mobile_number', 'employee_status', 'group_name', 'department', 'bu_admin_coordinator', 'president', 'created_at'];
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
        $conditions = [];
        $params = [];
        if ($search_query !== '') {
            $like = "%{$search_query}%";
            $search_cols = ['account_number', 'full_name', 'employee_id', 'email_address', 'mobile_number', 'group_name', 'department', 'president', 'president_email', 'section_head', 'section_head_email', 'bu_head', 'bu_head_email', 'bu_admin_coordinator', 'bu_admin_coordinator_email', 'other_cc'];
            $parts = [];
            foreach ($search_cols as $c) {
                $parts[] = "{$c} LIKE ?";
                $params[] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($status_filter !== '') {
            $conditions[] = "employee_status = ?";
            $params[] = $status_filter;
        }
        $whereSql = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

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

<!-- ACCOUNT MAPPING VIEW CONTAINER -->
<div class="bg-white shadow-lg rounded-2xl p-6 border border-gray-100 max-w-7xl mx-auto mt-6">
    <!-- SHORTENED HEADER & ACTIONS BAR -->
    <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Account Mapping</h2>
        </div>
        
        <!-- TOP CONSOLIDATED ACTION BUTTONS -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Add Mapping Button (Triggers Modal) -->
            <button type="button" onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                ➕ Add Account Mapping
            </button>

            <!-- Sync Button -->
            <form method="POST" action="" onsubmit="return confirm('Do you want to sync all missing account numbers, pull latest full names, and update mobile numbers from debit memos?');">
                <input type="hidden" name="action" value="sync_accounts">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                    🔄 Sync Accounts
                </button>
            </form>

            <!-- Bulk Assign Button -->
            <button type="button" onclick="openBulkModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-3.5 rounded-xl text-xs transition shadow-sm flex items-center gap-1.5">
                🧩 Bulk Assign
            </button>

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
            Account mapping saved successfully!
        </div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-sm">
            Account mapping updated successfully!
        </div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 text-yellow-700 rounded-xl text-sm">
            Account mapping deleted successfully!
        </div>
    <?php elseif (isset($_GET['synced'])): ?>
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-sm">
            Successfully synced <strong><?php echo intval($_GET['synced']); ?></strong> new account(s), updated full names and mobile numbers!
        </div>
    <?php elseif (isset($_GET['bulk'])): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
            Bulk assign applied to <strong><?php echo intval($_GET['bulk']); ?></strong> account(s)!
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
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search account, name, employee ID, email, group, department, BU admin, president..." class="w-full text-xs border border-gray-300 rounded-xl pl-3 pr-8 py-2 bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
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
            <div class="flex items-center gap-1.5 text-xs text-gray-600 whitespace-nowrap">
                <select name="status" onchange="this.form.submit()" class="border border-gray-300 rounded-xl px-2.5 py-2 bg-white shadow-sm text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer">
                    <option value="" <?php echo ($status_filter === '') ? 'selected' : ''; ?>>All status</option>
                    <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="resigned" <?php echo ($status_filter === 'resigned') ? 'selected' : ''; ?>>Resigned</option>
                </select>
            </div>
        </div>

        <div class="text-xs text-gray-500 font-medium">
            Total Records: <strong><?php echo $total_records; ?></strong>
        </div>
    </form>
    
    <!-- SELECTION BAR -->
    <div id="bulkBar" class="hidden mb-3 flex items-center justify-between bg-purple-50 border border-purple-200 rounded-xl px-4 py-2 text-xs text-purple-800">
        <span><strong id="bulkCount">0</strong> account(s) selected</span>
        <span class="flex gap-2">
            <button type="button" onclick="openBulkModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-1.5 px-3 rounded-lg transition">Assign Group / BU Admin / President</button>
            <button type="button" onclick="clearSelection()" class="bg-white border border-purple-200 text-purple-700 font-medium py-1.5 px-3 rounded-lg hover:bg-purple-100 transition">Clear</button>
        </span>
    </div>

    <!-- DATA TABLE -->
    <div class="overflow-x-auto border border-gray-200 rounded-2xl shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-gray-600 font-semibold uppercase">
                <tr>
                    <th class="px-4 py-3 text-left"><input type="checkbox" id="checkAllRows" onclick="toggleAllRows(this)" class="cursor-pointer" title="Select all on this page"></th>
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
                        <a href="<?php echo getQueryUrl(['sort' => 'full_name', 'dir' => ($sort_col === 'full_name' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Full Name <?php echo getSortIcon('full_name', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'employee_id', 'dir' => ($sort_col === 'employee_id' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Employee ID <?php echo getSortIcon('employee_id', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => 'email_address', 'dir' => ($sort_col === 'email_address' && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            Email Address <?php echo getSortIcon('email_address', $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <?php foreach ([['employee_status', 'Status'], ['group_name', 'Group'], ['department', 'Department'], ['bu_admin_coordinator', 'BU Admin'], ['president', 'President']] as $th): ?>
                    <th class="px-6 py-3 text-left">
                        <a href="<?php echo getQueryUrl(['sort' => $th[0], 'dir' => ($sort_col === $th[0] && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1]); ?>" class="flex items-center hover:text-blue-600 transition">
                            <?php echo $th[1]; ?> <?php echo getSortIcon($th[0], $sort_col, $sort_dir); ?>
                        </a>
                    </th>
                    <?php endforeach; ?>
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
                            <td class="px-4 py-3.5"><input type="checkbox" class="row-check cursor-pointer" value="<?php echo (int)$row['id']; ?>" onclick="updateBulkBar()"></td>
                            <td class="px-6 py-3.5 text-gray-500"><?php echo htmlspecialchars($row['id'] ?? ''); ?></td>
                            <td class="px-6 py-3.5 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($row['account_number'] ?? ''); ?></td>
                            <td class="px-6 py-3.5 text-gray-800 font-medium"><?php echo htmlspecialchars($row['full_name'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-gray-800 font-mono"><?php echo htmlspecialchars($row['employee_id'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-gray-800 font-medium"><?php echo htmlspecialchars($row['email_address'] ?? 'N/A'); ?></td>
                            <?php
                            $is_resigned = strtolower($row['employee_status'] ?? 'active') === 'resigned';
                            $rcp = get_email_recipients($row);
                            ?>
                            <td class="px-6 py-3.5 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $is_resigned ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>"><?php echo $is_resigned ? 'Resigned' : 'Active'; ?></span>
                                <?php if ($is_resigned): ?>
                                    <div class="text-[10px] mt-1 <?php echo empty($rcp['to']) ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                                        emails → <?php echo empty($rcp['to']) ? 'NO RECIPIENT' : htmlspecialchars($rcp['to'][0]); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-3.5 text-gray-800"><?php echo htmlspecialchars($row['group_name'] ?? ''); ?></td>
                            <td class="px-6 py-3.5 text-gray-800"><?php echo htmlspecialchars($row['department'] ?? ''); ?></td>
                            <td class="px-6 py-3.5">
                                <div class="text-gray-800 font-medium"><?php echo htmlspecialchars($row['bu_admin_coordinator'] ?? ''); ?></div>
                                <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($row['bu_admin_coordinator_email'] ?? ''); ?></div>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="text-gray-800 font-medium"><?php echo htmlspecialchars($row['president'] ?? ''); ?></div>
                                <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($row['president_email'] ?? ''); ?></div>
                            </td>
                            <td class="px-6 py-3.5 text-gray-800 font-medium"><?php echo htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-gray-500"><?php echo htmlspecialchars($row['created_at'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-3.5 text-center font-medium space-x-3">
                                <button type="button"
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>)"
                                    class="text-blue-600 hover:text-blue-900 font-semibold">Edit</button>
                                <a href="?action=delete&id=<?php echo $row['id']; ?>" class="text-red-600 hover:text-red-900 font-semibold" onclick="return confirm('Are you sure you want to delete this record?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="14" class="px-6 py-10 text-center text-gray-400 italic">No account mapping records found.</td>
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

                <!-- Numbered Pagination links -->
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
    <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-gray-100" style="max-height:90vh;overflow-y:auto;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Add New Account Mapping</h3>
            <button type="button" onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_mapping">
            
            <?php render_mapping_fields('add_'); ?>
            
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeAddModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Save Account Mapping</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: BULK ASSIGN -->
<div id="bulkModal" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-gray-100" style="max-height:90vh;overflow-y:auto;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Bulk Assign Group / BU Admin / President</h3>
            <button type="button" onclick="closeBulkModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        <form method="POST" action="" onsubmit="return confirmBulk();">
            <input type="hidden" name="action" value="bulk_assign">
            <input type="hidden" name="bulk_ids" id="bulk_ids">
            <input type="hidden" name="f_search" value="<?php echo htmlspecialchars($search_query); ?>">
            <input type="hidden" name="f_status" value="<?php echo htmlspecialchars($status_filter); ?>">

            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">1. Which accounts?</p>
            <div class="space-y-2 mb-4 text-xs text-gray-700">
                <label class="flex items-center gap-2"><input type="radio" name="scope" value="selected" onchange="onScopeChange()"> Selected rows (<strong id="bulkSelCount">0</strong>)</label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="scope" value="filtered" onchange="onScopeChange()">
                    All results of the current search/filter (<strong><?php echo (int)$total_records; ?></strong>)
                    <?php if ($search_query === '' && $status_filter === ''): ?><span class="text-gray-400">— search or filter first</span><?php endif; ?>
                </label>
                <label class="flex items-center gap-2"><input type="radio" name="scope" value="list" onchange="onScopeChange()"> Paste account numbers</label>
                <div id="bulkListBox" class="hidden">
                    <textarea name="account_list" rows="4" placeholder="One per line, or separated by comma / space" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs font-mono focus:outline-none focus:ring-2 focus:ring-purple-500"></textarea>
                </div>
            </div>

            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">2. What to set? <span class="normal-case font-normal">(blank fields are left unchanged)</span></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Group</label>
                    <input type="text" name="group_name" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Department</label>
                    <input type="text" name="department" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Telco BU Admin Coordinator</label>
                    <input type="text" name="bu_admin_coordinator" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Telco BU Admin Coordinator Email</label>
                    <input type="email" name="bu_admin_coordinator_email" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">President</label>
                    <input type="text" name="president" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">President Email</label>
                    <input type="email" name="president_email" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Section Head</label>
                    <input type="text" name="section_head" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Section Head Email</label>
                    <input type="email" name="section_head_email" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">BU Head</label>
                    <input type="text" name="bu_head" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">BU Head Email</label>
                    <input type="email" name="bu_head_email" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Employee Status</label>
                    <select name="employee_status" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="">— no change —</option><option value="active">Active</option><option value="resigned">Resigned</option>
                    </select></div>
                <div><label class="block text-xs font-semibold text-gray-600 mb-1">Other Personnel (CC) <span class="font-normal text-gray-400">replaces existing</span></label>
                    <input type="text" name="other_cc" placeholder="comma or ; separated emails" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-purple-500"></div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeBulkModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Apply to Accounts</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: IMPORT CSV -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Import Account Mapping via CSV</h3>
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

        <p class="text-xs text-gray-500 mb-2">Format required: <code class="bg-gray-100 px-1 py-0.5 rounded text-gray-700 break-words"><?php echo implode(', ', $csv_headers); ?></code><br><span class="text-[10px]">Columns are matched by header name (these names or the database names); <code>employee_status</code> = active / resigned. Blank cells keep the existing value.</span></p>
        
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
    <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-gray-100" style="max-height:90vh;overflow-y:auto;">
        <div class="flex justify-between items-center mb-4">
            <h3 id="editModalTitle" class="text-sm font-bold text-gray-900 uppercase tracking-wider">Edit Account Mapping</h3>
            <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-sm font-bold">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_mapping">
            <input type="hidden" name="record_id" id="edit_record_id">
            
            <?php render_mapping_fields('edit_'); ?>
            
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeEditModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-xl text-xs transition">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-xl text-xs transition shadow-sm">Update Account Mapping</button>
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

function getCheckedIds() {
    return Array.prototype.map.call(document.querySelectorAll('.row-check:checked'), function (c) { return c.value; });
}
function updateBulkBar() {
    var n = getCheckedIds().length;
    document.getElementById('bulkCount').innerText = n;
    document.getElementById('bulkBar').classList.toggle('hidden', n === 0);
}
function toggleAllRows(master) {
    document.querySelectorAll('.row-check').forEach(function (c) { c.checked = master.checked; });
    updateBulkBar();
}
function clearSelection() {
    document.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
    document.getElementById('checkAllRows').checked = false;
    updateBulkBar();
}
function onScopeChange() {
    var checked = document.querySelector('input[name="scope"]:checked');
    document.getElementById('bulkListBox').classList.toggle('hidden', !checked || checked.value !== 'list');
}
function openBulkModal() {
    var ids = getCheckedIds();
    document.getElementById('bulk_ids').value = ids.join(',');
    document.getElementById('bulkSelCount').innerText = ids.length;
    var radios = document.querySelectorAll('input[name="scope"]');
    radios.forEach(function (r) { r.checked = false; });
    var pick = ids.length > 0 ? 'selected' : '<?php echo ($search_query !== '' || $status_filter !== '') ? 'filtered' : 'list'; ?>';
    document.querySelector('input[name="scope"][value="' + pick + '"]').checked = true;
    onScopeChange();
    document.getElementById('bulkModal').classList.remove('hidden');
}
function closeBulkModal() {
    document.getElementById('bulkModal').classList.add('hidden');
}
function confirmBulk() {
    var scope = document.querySelector('input[name="scope"]:checked');
    if (!scope) { alert('Choose which accounts to update.'); return false; }
    var label = scope.value === 'selected' ? getCheckedIds().length + ' selected account(s)'
              : scope.value === 'filtered' ? 'ALL accounts in the current search/filter (<?php echo (int)$total_records; ?>)'
              : 'the pasted account numbers';
    return confirm('Apply these changes to ' + label + '?');
}

const MAPPING_FIELDS = ['account_number', 'full_name', 'employee_id', 'email_address', 'mobile_number', 'employee_status', 'group_name', 'department', 'president', 'president_email', 'section_head', 'section_head_email', 'bu_head', 'bu_head_email', 'bu_admin_coordinator', 'bu_admin_coordinator_email', 'other_cc'];
function openEditModal(row) {
    document.getElementById('edit_record_id').value = row.id;
    MAPPING_FIELDS.forEach(function (f) {
        var v = (row[f] === null || row[f] === undefined || row[f] === 'N/A') ? '' : row[f];
        if (f === 'employee_status' && v === '') { v = 'active'; }
        document.getElementById('edit_' + f).value = v;
    });
    document.getElementById('editModalTitle').innerText = 'Edit Account Mapping (ID: ' + row.id + ')';
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}
</script>

<?php
$content = ob_get_clean();
if (function_exists('render_layout')) {
    render_layout("Account Mapping", $content);
} else {
    echo $content;
}
?>