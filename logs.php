<?php
session_start();
require_once 'config.php';
require_once 'main.php';

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

/**
 * Function to fetch logs with LEFT JOIN and optional status filter
 */
function getLogs($conn, $table, $filterStatus = null) {
    try {
        $sql = "SELECT l.*, u.full_name 
                FROM $table l 
                LEFT JOIN users u ON l.user_id = u.user_id";
        
        if ($filterStatus !== null && $table === 'import_history') {
            $sql .= " WHERE l.status = ?";
            $stmt = $conn->prepare($sql . " ORDER BY 1 DESC");
            $stmt->execute([$filterStatus]);
            return $stmt->fetchAll();
        }

        $sql .= " ORDER BY 1 DESC";
        return $conn->query($sql)->fetchAll();
    } catch (PDOException $e) {
        return []; 
    }
}

$login_logs = getLogs($conn, 'login_logs');
$import_logs = getLogs($conn, 'import_history', 1);
$revert_logs = getLogs($conn, 'revert_logs');

ob_start();
?>

<div class="space-y-6">
    <h2 class="text-2xl font-bold text-gray-800">System Activity Logs</h2>
    
    <div class="flex border-b border-gray-300">
        <button class="tab-btn px-6 py-2 border-b-2 border-blue-600 text-blue-600 font-medium" onclick="showTab(event, 'login')">Login Logs</button>
        <button class="tab-btn px-6 py-2 text-gray-600 font-medium" onclick="showTab(event, 'import')">Import History</button>
        <button class="tab-btn px-6 py-2 text-gray-600 font-medium" onclick="showTab(event, 'revert')">Revert Logs</button>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <!-- Login Logs -->
        <div id="login" class="tab-content">
            <table class="w-full text-center">
                <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
                    <tr><th class="p-4">User</th><th class="p-4">IP Address</th><th class="p-4">Status</th><th class="p-4">Time</th></tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($login_logs as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-4"><?php echo htmlspecialchars(isset($row['full_name']) ? $row['full_name'] : 'Unknown'); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($row['ip_address']); ?></td>
                        <td class="p-4 font-bold <?php echo ($row['status'] == 'SUCCESS') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($row['status']); ?></td>
                        <td class="p-4 text-gray-500"><?php echo htmlspecialchars($row['login_time']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Import Logs -->
        <div id="import" class="tab-content hidden">
            <table class="w-full text-center">
                <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-4">User</th>
                        <th class="p-4">Batch ID</th>
                        <th class="p-4">Filename</th>
                        <th class="p-4">Mode</th>
                        <th class="p-4">Total Rows</th>
                        <th class="p-4">Success</th>
                        <th class="p-4">Errors</th>
                        <th class="p-4">Date / Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y text-sm">
                    <?php foreach($import_logs as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-4"><?php echo htmlspecialchars(isset($row['full_name']) ? $row['full_name'] : 'System'); ?></td>
                        <td class="p-4 font-mono text-xs"><?php echo htmlspecialchars($row['batch_id']); ?></td>
                        <td class="p-4 font-medium text-blue-600"><?php echo htmlspecialchars($row['filename']); ?></td>
                        <td class="p-4 capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $row['import_mode'])); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($row['total_rows']); ?></td>
                        <td class="p-4 text-green-600 font-semibold"><?php echo htmlspecialchars($row['success_count']); ?></td>
                        <td class="p-4 text-red-600 font-semibold"><?php echo htmlspecialchars($row['error_count']); ?></td>
                        <td class="p-4 text-gray-500 text-xs"><?php echo htmlspecialchars($row['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Revert Logs -->
        <div id="revert" class="tab-content hidden">
            <table class="w-full text-center">
                <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-4">User</th>
                        <th class="p-4">Batch ID</th>
                        <th class="p-4">Restored</th>
                        <th class="p-4">Total Imported</th>
                        <th class="p-4">Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($revert_logs as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-4"><?php echo htmlspecialchars(isset($row['full_name']) ? $row['full_name'] : 'Unknown'); ?></td>
                        <td class="p-4 font-mono text-xs"><?php echo htmlspecialchars($row['batch_id']); ?></td>
                        <td class="p-4 font-semibold text-blue-600"><?php echo htmlspecialchars(isset($row['records_restored']) ? $row['records_restored'] : '0'); ?></td>
                        <td class="p-4 font-semibold text-gray-700"><?php echo htmlspecialchars(isset($row['total_imported']) ? $row['total_imported'] : '0'); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($row['remarks']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showTab(evt, id) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('border-b-2', 'border-blue-600', 'text-blue-600');
        btn.classList.add('text-gray-600');
    });
    document.getElementById(id).classList.remove('hidden');
    evt.currentTarget.classList.add('border-b-2', 'border-blue-600', 'text-blue-600');
}
</script>

<?php
$content = ob_get_clean();
render_layout("System Logs", $content);
?>