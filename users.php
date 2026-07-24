<?php
session_start();
require_once 'config.php';
require_once 'main.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

// Handle AJAX Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Compatible syntax for older PHP versions
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $fn = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $user = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : 'user';
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    try {
        $checkSql = ($action === 'create') 
            ? "SELECT COUNT(*) FROM users WHERE username = ? OR email = ?"
            : "SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
        
        $checkParams = ($action === 'create') ? array($user, $email) : array($user, $email, $uid);
        $stmt = $conn->prepare($checkSql);
        $stmt->execute($checkParams);
        
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(array('status' => 'error', 'message' => 'Error: That Username or Email is already taken.'));
        } else {
            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, is_active, role) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute(array($user, $fn, $email, password_hash($password, PASSWORD_DEFAULT), $is_active, $role));
            } 
            
             elseif ($action === 'delete') {
    // SECURITY: Prevent Super Admin from deleting themselves
    if ($uid === $_SESSION['user_id']) {
        echo json_encode(array('status' => 'error', 'message' => 'Cannot delete your own account.'));
        exit;
    }
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute(array($uid));
    echo json_encode(array('status' => 'success', 'message' => 'Account deleted successfully!'));
    exit;
}

else {
                if (!empty($password)) {
                    $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, is_active=?, password_hash=? WHERE user_id=?");
                    $stmt->execute(array($fn, $user, $email, $role, $is_active, password_hash($password, PASSWORD_DEFAULT), $uid));
                } else {
                    $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, is_active=? WHERE user_id=?");
                    $stmt->execute(array($fn, $user, $email, $role, $is_active, $uid));
                }
            }
            $msg = ($action === 'create' ? 'created' : 'updated');
            echo json_encode(array('status' => 'success', 'message' => 'Account ' . $msg . ' successfully!'));
        }
    } catch (PDOException $e) {
        echo json_encode(array('status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()));
    }
    exit;
}
$all_users = $conn->query("SELECT * FROM users ORDER BY user_id DESC")->fetchAll();
ob_start();
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <h3 class="text-gray-700 font-bold text-lg">System Access Personnel Accounts</h3>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 text-white text-sm px-4 py-2 rounded shadow">Add Account</button>
    </div>
    
    <div class="bg-white rounded-lg shadow-sm border overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-500">
                <tr><th class="px-6 py-3">Full Name</th><th class="px-6 py-3">Username</th><th class="px-6 py-3">Role</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Actions</th></tr>
            </thead>
            <tbody class="text-sm divide-y">
                <?php foreach ($all_users as $u): ?>
                    <tr>
                        <td class="px-6 py-4"><?php echo htmlspecialchars($u['full_name']); ?></td>
                        <td class="px-6 py-4"><?php echo htmlspecialchars($u['username']); ?></td>
                        <td class="px-6 py-4 capitalize"><?php echo htmlspecialchars($u['role']); ?></td>
                        <td class="px-6 py-4"><?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td class="px-6 py-4 flex gap-5">
    <button type="button" 
            onclick="editUser(<?php echo $u['user_id']; ?>, '<?php echo htmlspecialchars($u['full_name']); ?>', '<?php echo htmlspecialchars($u['username']); ?>', '<?php echo htmlspecialchars($u['email']); ?>', '<?php echo $u['role']; ?>', <?php echo $u['is_active']; ?>)" 
            class="text-blue-600 hover:text-blue-800 transition-colors"
            title="Edit">
        <i class="las la-edit text-xl"></i>
    </button>

    <button type="button" 
            onclick="deleteUser(<?php echo $u['user_id']; ?>)" 
            class="text-red-600 hover:text-red-800 transition-colors"
            title="Delete">
        <i class="las la-trash text-xl"></i>
    </button>
</td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="addModal" class="fixed inset-0 bg-slate-900/50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg w-full max-w-lg p-6">
        <h3 class="font-bold mb-4">Add New Account</h3>
        <div id="add_error_box" class="hidden bg-red-100 text-red-700 p-3 mb-4 rounded text-sm"></div>
        <form class="ajax-form space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="ajax" value="1">
            <div><label class="block text-xs font-bold text-gray-700">FULL NAME</label><input type="text" name="full_name" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">EMAIL</label><input type="email" name="email" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">USERNAME</label><input type="text" name="username" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">PASSWORD</label><input type="password" name="password" required class="w-full p-2 border rounded text-sm"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-xs font-bold text-gray-700">STATUS</label><select name="is_active" class="w-full p-2 border rounded text-sm"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                <div><label class="block text-xs font-bold text-gray-700">ROLE</label><select name="role" class="w-full p-2 border rounded text-sm"><option value="user">User</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
            </div>
            <div class="flex justify-end gap-2 pt-2"><button type="button" onclick="closeModal('addModal', 'add_error_box')" class="px-4 py-2 bg-gray-200 rounded text-sm">Cancel</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-bold">CREATE</button></div>
        </form>
    </div>
</div>

<div id="editModal" class="fixed inset-0 bg-slate-900/50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg w-full max-w-lg p-6">
        <h3 class="font-bold mb-4">Edit Account</h3>
        <div id="edit_error_box" class="hidden bg-red-100 text-red-700 p-3 mb-4 rounded text-sm"></div>
        <form class="ajax-form space-y-4">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div><label class="block text-xs font-bold text-gray-700">FULL NAME</label><input type="text" name="full_name" id="edit_fn" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">EMAIL</label><input type="email" name="email" id="edit_email" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">USERNAME</label><input type="text" name="username" id="edit_user" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-700">NEW PASSWORD</label><input type="password" name="password" placeholder="Leave blank to keep current" class="w-full p-2 border rounded text-sm"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-xs font-bold text-gray-700">STATUS</label><select name="is_active" id="edit_active" class="w-full p-2 border rounded text-sm"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                <div><label class="block text-xs font-bold text-gray-700">ROLE</label><select name="role" id="edit_role" class="w-full p-2 border rounded text-sm"><option value="user">User</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
            </div>
            <div class="flex justify-end gap-2 pt-2"><button type="button" onclick="closeModal('editModal', 'edit_error_box')" class="px-4 py-2 bg-gray-200 rounded text-sm">Cancel</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-bold">UPDATE</button></div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.ajax-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        let errorBox = this.parentElement.querySelector('[id$="_error_box"]');
        fetch('users.php', { method: 'POST', body: new FormData(this) })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message);
                location.reload();
            } else {
                errorBox.textContent = data.message;
                errorBox.classList.remove('hidden');
            }
        });
    });
});

function editUser(id, name, user, mail, role, active) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_fn').value = name;
    document.getElementById('edit_user').value = user;
    document.getElementById('edit_email').value = mail;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_active').value = active;
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal(modalId, errorBoxId) {
    document.getElementById(modalId).classList.add('hidden');
    document.getElementById(errorBoxId).classList.add('hidden');
}

function deleteUser(id) {
    if (!confirm("Are you sure you want to delete this user? This cannot be undone.")) {
        return;
    }

    let formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete');
    formData.append('user_id', id);

    fetch('users.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => console.error("Error:", err));
}
</script>
<?php
$content = ob_get_clean();
render_layout("User Management", $content);
?>