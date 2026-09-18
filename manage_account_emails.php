<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'main.php';

// Ensure database connection variable compatibility ($pdo or $conn)
if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
}

$message = '';
$error = '';

// Handle Form Submissions (Add, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['id'] ?? '';
        $account_number = trim($_POST['account_number']);
        $email_address = trim($_POST['email_address']);

        if (!empty($account_number) && !empty($email_address)) {
            try {
                if (!empty($id)) {
                    // Update existing record
                    $stmt = $pdo->prepare("UPDATE account_emails SET account_number = ?, email_address = ? WHERE id = ?");
                    $stmt->execute([$account_number, $email_address, $id]);
                    $message = "Email mapping successfully updated!";
                } else {
                    // Insert new record
                    $stmt = $pdo->prepare("INSERT INTO account_emails (account_number, email_address) VALUES (?, ?)");
                    $stmt->execute([$account_number, $email_address]);
                    $message = "Email mapping successfully added!";
                }
            } catch (Exception $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        } else {
            $error = "Both Account Number and Email Address are required.";
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        if (!empty($id)) {
            $stmt = $pdo->prepare("DELETE FROM account_emails WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Email mapping successfully deleted!";
        }
    }
}

// Fetch all saved email mappings safely
try {
    $stmt = $pdo->query("SELECT * FROM account_emails ORDER BY id DESC");
    $email_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $email_records = [];
    $error = "Could not fetch records: " . $e->getMessage();
}

ob_start();
?>

<div class="max-w-5xl mx-auto py-6 px-4">
    <div class="bg-white shadow-lg rounded-xl p-6 border border-gray-100 mb-6">
        
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-xl font-bold text-gray-800 flex items-center space-x-2">
                    <span>manage</span>
                    <span>Manage Account Email Addresses</span>
                </h1>
                <p class="text-sm text-gray-500 mt-1">Map account numbers to their respective email addresses for automated debit memo dispatch.</p>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if (!empty($message)): ?>
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-xs font-medium">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-xs font-medium">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Add/Edit Form -->
        <form action="manage_account_emails.php" method="POST" id="emailForm" class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6 flex flex-col md:flex-row gap-4 items-end">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="recordId">

            <div class="w-full md:w-1/3">
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Account Number</label>
                <input type="text" name="account_number" id="accountNumber" required placeholder="e.g. 6012050532" class="w-full text-xs border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
            </div>

            <div class="w-full md:w-1/2">
                <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Email Address</label>
                <input type="email" name="email_address" id="emailAddress" required placeholder="client@company.com" class="w-full text-xs border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
            </div>

            <div class="w-full md:w-auto flex space-x-2">
                <button type="submit" id="saveBtn" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium px-5 py-2 rounded-md shadow transition-colors">
                    Save Entry
                </button>
                <button type="button" onclick="resetForm()" id="cancelBtn" class="hidden bg-gray-300 hover:bg-gray-400 text-gray-700 text-xs font-medium px-3 py-2 rounded-md transition-colors">
                    Cancel
                </button>
            </div>
        </form>

        <!-- Table List -->
        <div class="border border-gray-200 rounded-lg overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-semibold border-b border-gray-200">
                        <th class="py-3 px-4 w-16">ID</th>
                        <th class="py-3 px-4">Account Number</th>
                        <th class="py-3 px-4">Email Address</th>
                        <th class="py-3 px-4">Date Added</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-gray-700">
                    <?php if (empty($email_records)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-8 text-gray-400">No email records found. Add your first mapping above.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($email_records as $rec): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="py-3 px-4 text-gray-400 font-mono"><?php echo $rec['id']; ?></td>
                                <td class="py-3 px-4 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($rec['account_number']); ?></td>
                                <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($rec['email_address']); ?></td>
                                <td class="py-3 px-4 text-gray-400"><?php echo $rec['created_at']; ?></td>
                                <td class="py-3 px-4 text-right space-x-2">
                                    <button type="button" onclick="editRecord(<?php echo $rec['id']; ?>, '<?php echo htmlspecialchars($rec['account_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rec['email_address'], ENT_QUOTES); ?>')" class="text-blue-600 hover:underline font-medium">Edit</button>
                                    
                                    <form action="manage_account_emails.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this email record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
function editRecord(id, account_number, email_address) {
    document.getElementById('recordId').value = id;
    document.getElementById('accountNumber').value = account_number;
    document.getElementById('emailAddress').value = email_address;
    document.getElementById('saveBtn').textContent = 'Update Entry';
    document.getElementById('cancelBtn').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('emailForm').reset();
    document.getElementById('recordId').value = '';
    document.getElementById('saveBtn').textContent = 'Save Entry';
    document.getElementById('cancelBtn').classList.add('hidden');
}
</script>

<?php
$content = ob_get_clean();
render_layout("Manage Account Emails", $content);
?>