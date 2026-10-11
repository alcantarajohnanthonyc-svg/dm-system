<?php
// 1. INITIALIZE SESSION (Dapat laging una)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. REQUIRE CONFIG (Dito galing ang $conn)
require_once 'config.php';

$errorMessage = "";

// Check if a remembered cookie exists to pre-fill the username
$rememberedUsername = isset($_COOKIE['remembered_username']) ? $_COOKIE['remembered_username'] : '';
$isRemembered = !empty($rememberedUsername);

// 3. PROCESS THE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = isset($_POST['username']) ? trim($_POST['username']) : '';
    $passwordInput = isset($_POST['password']) ? trim($_POST['password']) : '';
    $rememberMe    = isset($_POST['remember_me']) ? true : false;

    if (!empty($usernameInput) && !empty($passwordInput)) {
        try {
            // Gamitin ang $conn mula sa config.php
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = :user OR email = :user LIMIT 1");
            $stmt->execute(array('user' => $usernameInput));
            $user = $stmt->fetch();

            $ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];

            if ($user) {
                if ((int)$user['is_active'] !== 1) {
                    $errorMessage = "This account has been deactivated. Please contact your administrator.";
                } else {
                    // Password Verification
                    $isPlainMatch  = ($user['password_hash'] === $passwordInput);
                    $isBcryptMatch = password_verify($passwordInput, $user['password_hash']);
                    $isMd5Match    = ($user['password_hash'] === md5($passwordInput));

                    if ($isPlainMatch || $isBcryptMatch || $isMd5Match) {
                        // SUCCESS LOG (Wrapped in explicit try-catch to debug missing logs)
                        try {
                            $logStmt = $conn->prepare("INSERT INTO login_logs (user_id, username, ip_address, status) VALUES (?, ?, ?, 'SUCCESS')");
                            $logStmt->execute([$user['user_id'], $user['username'], $ip]);
                        } catch (PDOException $logEx) {
                            die("Database Logging Error: " . $logEx->getMessage());
                        }

                        // Handle Remember Me Cookie (stores username securely for 30 days)
                        if ($rememberMe) {
                            setcookie('remembered_username', $user['username'], time() + (86400 * 30), "/", "", false, true);
                        } else {
                            // Clear cookie if unchecked
                            if (isset($_COOKIE['remembered_username'])) {
                                setcookie('remembered_username', '', time() - 3600, "/");
                            }
                        }

                        // Set Session
                        $_SESSION['user_id']   = $user['user_id'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['username']  = $user['username'];
                        $_SESSION['role']      = $user['role'];

                        try {
    $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
} catch (PDOException $e) {
    // Handle error if needed
}

                        header("Location: dashboard.php");
                        exit;
                    } else {
                        // FAILED LOG
                        try {
                            $logStmt = $conn->prepare("INSERT INTO login_logs (username, ip_address, status) VALUES (?, ?, 'FAILED')");
                            $logStmt->execute([$usernameInput, $ip]);
                        } catch (PDOException $logEx) {
                            // Non-blocking for failed attempts, but keeps trace clean
                        }
                        
                        $errorMessage = "Invalid username or password.";
                    }
                }
            } else {
                $errorMessage = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            $errorMessage = "System Database Error: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill out both username and password fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Telco Charge DM System</title>
    <link rel="icon" type="image/png" href="https://i.ibb.co/Lxy0sQJ/pdf-14361750.png">
    <link rel="shortcut icon" href="https://upload.wikimedia.org/wikipedia/commons/8/87/PDF_file_icon.svg" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center">

    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-blue-600 tracking-wide uppercase">Telco Charge DM</h1>
            <p class="text-gray-500 text-sm mt-1">Debit Memo Management Portal</p>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm relative" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username or Email</label>
                <input type="text" id="username" name="username" required 
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : htmlspecialchars($rememberedUsername); ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 outline-none transition"
                    placeholder="Enter admin or email">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" id="password" name="password" required 
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 outline-none transition"
                    placeholder="••••••••">
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center text-gray-600 cursor-pointer">
                    <input type="checkbox" name="remember_me" <?php echo $isRemembered ? 'checked' : ''; ?> class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <span class="ml-2">Remember Me</span>
                </label>
            </div>

            <div>
                <button type="submit" 
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    Sign In
                </button>
            </div>
        </form>

        <div class="mt-6 text-center border-t pt-4">
            <p class="text-xs text-gray-400">&copy; 2026 Telco Billing Operations. Master Wrapper Configured.</p>
        </div>
    </div>

</body>
</html>