<?php
session_start();
require_once 'config.php';
require_once 'main.php';

// Access control: Allow only non-superadmins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_pw = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new_pw = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_pw = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute(array($_SESSION['user_id']));
    $user = $stmt->fetch();

    if ($user && password_verify($current_pw, $user['password_hash'])) {
        if ($new_pw === $confirm_pw) {
            $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update->execute(array(password_hash($new_pw, PASSWORD_DEFAULT), $_SESSION['user_id']));
            $message = "<div class='bg-green-100 text-green-700 p-4 mb-4 rounded text-sm'>Password updated successfully!</div>";
        } else {
            $message = "<div class='bg-red-100 text-red-700 p-4 mb-4 rounded text-sm'>New passwords do not match.</div>";
        }
    } else {
        $message = "<div class='bg-red-100 text-red-700 p-4 mb-4 rounded text-sm'>Current password incorrect.</div>";
    }
}

ob_start();
?>

<div class="max-w-md mx-auto mt-10 bg-white p-8 rounded-lg shadow border">
    <h3 class="font-bold text-lg mb-6">Change Your Password</h3>
    <?php echo $message; ?>
    <form method="POST" class="space-y-4">
        <div>
            <label class="block text-xs font-bold text-gray-700">CURRENT PASSWORD</label>
            <div class="relative">
                <input type="password" id="current_pw" name="current_password" required class="w-full p-2 border rounded text-sm pr-16">
                <button type="button" onclick="togglePass('current_pw', this)" class="absolute right-2 top-2 text-xs text-blue-600 font-bold hover:underline">SHOW</button>
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-700">NEW PASSWORD</label>
            <div class="relative">
                <input type="password" id="new_pw" name="new_password" required class="w-full p-2 border rounded text-sm pr-16">
                <button type="button" onclick="togglePass('new_pw', this)" class="absolute right-2 top-2 text-xs text-blue-600 font-bold hover:underline">SHOW</button>
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-700">CONFIRM NEW PASSWORD</label>
            <div class="relative">
                <input type="password" id="confirm_pw" name="confirm_password" required class="w-full p-2 border rounded text-sm pr-16">
                <button type="button" onclick="togglePass('confirm_pw', this)" class="absolute right-2 top-2 text-xs text-blue-600 font-bold hover:underline">SHOW</button>
            </div>
        </div>
        <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-bold text-sm hover:bg-blue-700 transition">UPDATE PASSWORD</button>
    </form>
</div>

<script>
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        btn.textContent = "HIDE";
    } else {
        input.type = "password";
        btn.textContent = "SHOW";
    }
}
</script>

<?php
$content = ob_get_clean();
render_layout("Change Password", $content);
?>