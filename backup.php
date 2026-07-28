<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

session_start();
require_once 'config.php';
require_once 'main.php';

// Restrict access to superadmin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

$database_name = 'admin_telco_dm';
$encryption_salt = 'B0unt7@gr0';
$fixed_password_hash = hash_hmac('sha256', 'B0unt7@gr0', $encryption_salt);

$error_message = '';

// Handle Password Gate Submission
if (isset($_POST['verify_backup_password'])) {
    $entered_password = isset($_POST['backup_password']) ? $_POST['backup_password'] : '';
    $entered_hash = hash_hmac('sha256', $entered_password, $encryption_salt);

    if ($entered_hash === $fixed_password_hash) {
        // Set authorization temporarily for this immediate request/action cycle
        $_SESSION['backup_authorized'] = true;
    } else {
        $error_message = "Invalid backup security password!";
        unset($_SESSION['backup_authorized']);
    }
}

// Handle Manual Lock from Header
if (isset($_GET['lock'])) {
    unset($_SESSION['backup_authorized']);
    header("Location: backup.php");
    exit;
}

// Check if user has passed the backup password gate
$is_authorized = isset($_SESSION['backup_authorized']) && $_SESSION['backup_authorized'] === true;

// Fetch all table names safely (only if authorized)
$tables = [];
if ($is_authorized) {
    try {
        $stmt = $conn->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
    } catch (PDOException $e) {
        $error_message = "Error fetching tables: " . $e->getMessage();
    }
}

// Handle Actual Backup Form Submission (Streams ZIP file directly)
if ($is_authorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_backup') {
    $backup_type = isset($_POST['backup_type']) ? $_POST['backup_type'] : 'full';
    $export_format = isset($_POST['export_format']) ? $_POST['export_format'] : 'sql';
    $selected_tables = isset($_POST['tables']) ? $_POST['tables'] : [];

    if ($backup_type === 'selective' && empty($selected_tables)) {
        $error_message = "Please select at least one table.";
        $is_authorized = true; // Keep form open to show error
    } else {
        $tables_to_export = ($backup_type === 'full') ? $tables : array_intersect($tables, $selected_tables);

        if (!class_exists('ZipArchive')) {
            $error_message = "Fatal Error: PHP ZipArchive extension is not enabled on this server.";
        } else {
            $zip = new ZipArchive();
            $temp_zip_file = tempnam(sys_get_temp_dir(), 'zip');

            if ($zip->open($temp_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                
                foreach ($tables_to_export as $table) {
                    $raw_payload = "-- Database Backup: " . $database_name . "\n";
                    $raw_payload .= "-- Table: " . $table . "\n";
                    $raw_payload .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

                    if ($export_format === 'csv') {
                        $output_handle = fopen('php://temp', 'r+');
                        
                        $columnsQuery = $conn->query("SHOW COLUMNS FROM `$table`");
                        $columns = [];
                        while ($col = $columnsQuery->fetch(PDO::FETCH_ASSOC)) {
                            $columns[] = $col['Field'];
                        }
                        fputcsv($output_handle, $columns);

                        $rowsQuery = $conn->query("SELECT * FROM `$table`");
                        while ($row = $rowsQuery->fetch(PDO::FETCH_ASSOC)) {
                            fputcsv($output_handle, $row);
                        }
                        
                        rewind($output_handle);
                        $raw_payload = stream_get_contents($output_handle);
                        fclose($output_handle);
                        $file_extension = 'csv';
                    } else {
                        $row = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
                        $raw_payload .= $row[1] . ";\n\n";

                        $query = $conn->query("SELECT * FROM `$table`");
                        while ($row = $query->fetch(PDO::FETCH_NUM)) {
                            $raw_payload .= "INSERT INTO `$table` VALUES(";
                            $values = [];
                            foreach ($row as $value) {
                                if ($value === null) {
                                    $values[] = "NULL";
                                } else {
                                    $values[] = $conn->quote($value);
                                }
                            }
                            $raw_payload .= implode(',', $values) . ");\n";
                        }
                        $file_extension = 'sql';
                    }

                    // Cryptographic HMAC Signature
                    $salted_payload = $raw_payload . "\n-- HASH_SIGNATURE: " . hash_hmac('sha256', $raw_payload, $encryption_salt);

                    // Filename: Always prefix with the table name
                    $internal_filename = $table . '_' . date('Y-m-d_H-i-s') . '.' . $file_extension;

                    $zip->addFromString($internal_filename, $salted_payload);
                }

                $zip->close();

                $download_filename = 'db_backup_' . $backup_type . '_' . date('Y-m-d_H-i-s') . '.zip';

                // Immediately lock the session right before sending output headers
                unset($_SESSION['backup_authorized']);

                while (ob_get_level()) {
                    ob_end_clean();
                }

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $download_filename . '"');
                header('Content-Length: ' . filesize($temp_zip_file));
                header('Pragma: no-cache');
                header('Expires: 0');

                readfile($temp_zip_file);
                unlink($temp_zip_file);
                exit;
            } else {
                $error_message = "Failed to create ZIP archive structure.";
            }
        }
    }
} else {
    // If it's a standard page load or refresh (not an active password submission or generation post), lock it immediately!
    if (!isset($_POST['verify_backup_password'])) {
        unset($_SESSION['backup_authorized']);
        $is_authorized = false;
    }
}

ob_start();
?>

<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="bg-white rounded-lg shadow-sm border p-6">
        
        <?php if (!$is_authorized): ?>
            <!-- PASSWORD GATE SCREEN -->
            <div class="max-w-md mx-auto py-8">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-3">
                        <i class="las la-lock text-2xl"></i>
                    </div>
                    <h3 class="text-gray-800 font-bold text-xl">Backup Center Security Gate</h3>
                    <p class="text-sm text-gray-500 mt-1">Please enter the security password to unlock the database backup panel.</p>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm flex items-center space-x-2">
                        <i class="las la-exclamation-triangle text-lg"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form action="backup.php" method="POST" class="space-y-4">
                    <div>
                        <input type="password" name="backup_password" required placeholder="Enter security password" class="w-full px-4 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <button type="submit" name="verify_backup_password" value="1" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg text-sm transition shadow-sm">
                        Unlock Backup Panel
                    </button>
                </form>
            </div>
        <?php else: ?>
            <!-- MAIN BACKUP INTERFACE -->
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <div>
                    <h3 class="text-gray-800 font-bold text-lg">Database Backup Center (Encrypted ZIP)</h3>
                    <p class="text-sm text-gray-500">Select your export settings. Tables will be exported individually using their table names and signed securely.</p>
                </div>
                <a href="backup.php?lock=1" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded border transition">
                    <i class="las la-lock mr-1"></i> Lock Panel
                </a>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm flex items-center space-x-2">
                    <i class="las la-exclamation-triangle text-lg"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <form action="backup.php" method="POST" class="space-y-6" id="backupForm">
                <input type="hidden" name="action" value="generate_backup">
                
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-2">Export Format (Inside ZIP)</label>
                    <div class="flex gap-4">
                        <label class="flex items-center space-x-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="radio" name="export_format" value="sql" checked class="text-blue-600 focus:ring-blue-500">
                            <span>SQL Files (.sql)</span>
                        </label>
                        <label class="flex items-center space-x-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="radio" name="export_format" value="csv" class="text-blue-600 focus:ring-blue-500">
                            <span>CSV Files (.csv)</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="border rounded-lg p-4 flex items-start space-x-3 cursor-pointer hover:bg-gray-50 transition">
                        <input type="radio" name="backup_type" value="full" checked onclick="toggleTableSelection(false)" class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="block font-semibold text-gray-800">Full Database Backup</span>
                            <span class="block text-xs text-gray-500 mt-0.5">Back up all tables into separate table-named files.</span>
                        </div>
                    </label>

                    <label class="border rounded-lg p-4 flex items-start space-x-3 cursor-pointer hover:bg-gray-50 transition">
                        <input type="radio" name="backup_type" value="selective" onclick="toggleTableSelection(true)" class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div>
                            <span class="block font-semibold text-gray-800">Selective Table Backup</span>
                            <span class="block text-xs text-gray-500 mt-0.5">Choose specific table files to include.</span>
                        </div>
                    </label>
                </div>

                <div id="tableSelectionBox" class="hidden border rounded-lg p-4 bg-gray-50 space-y-3">
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Select Tables to Export</span>
                        <button type="button" onclick="selectAllTables(true)" class="text-xs text-blue-600 hover:underline">Select All</button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 max-h-60 overflow-y-auto p-1">
                        <?php foreach ($tables as $tbl): ?>
                            <label class="flex items-center space-x-2 text-sm text-gray-700 bg-white p-2 rounded border cursor-pointer hover:bg-blue-50">
                                <input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($tbl); ?>" class="table-checkbox rounded text-blue-600 focus:ring-blue-500">
                                <span class="truncate font-mono text-xs"><?php echo htmlspecialchars($tbl); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t">
                    <button type="submit" onclick="return validateBackupForm()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2 rounded text-sm transition shadow-sm">
                        <i class="las la-file-archive mr-1"></i> Generate Signed ZIP Download
                    </button>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleTableSelection(show) {
    let box = document.getElementById('tableSelectionBox');
    if (show) {
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function selectAllTables(select) {
    document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = select);
}

function validateBackupForm() {
    const backupType = document.querySelector('input[name="backup_type"]:checked').value;
    if (backupType === 'selective') {
        const checkedTables = document.querySelectorAll('.table-checkbox:checked');
        if (checkedTables.length === 0) {
            alert('Please select at least one table for selective backup.');
            return false;
        }
    }

    const passwordInput = document.querySelector('input[name="backup_password"]');
    if (passwordInput && passwordInput.value.trim() === '') {
        alert('Please enter the security password to generate the backup.');
        passwordInput.focus();
        return false;
    }

    return true;
}
</script>

<?php
$content = ob_get_clean();
render_layout("Database Backup Center", $content);
?>