<?php
// 1. INITIALIZE SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. REQUIRE CONFIG (Instead of requiring login.php which re-runs the login logic)
require_once 'config.php';

// 3. LOG OUT EVENT IF USER WAS LOGGED IN
if (isset($_SESSION['user_id'])) {
    $ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
    
    try {
        $stmt = $conn->prepare("INSERT INTO login_logs (user_id, username, ip_address, status) VALUES (?, ?, ?, 'LOGOUT')");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['username'], $ip]);
    } catch (PDOException $e) {
        // Silently catch or handle error if logging fails, so logout still completes
    }
}

// 4. DESTROY ALL ACTIVE SESSIONS
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// 5. REDIRECT BACK TO LOGIN SCREEN
header("Location: login.php");
exit;
?>