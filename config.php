<?php
// 1. SECURE SESSION CONFIGURATION
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                      // Expire session cookie when browser closes
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
}

// 2. GLOBAL INACTIVITY TIMEOUT CHECK (Runs on every page that includes config.php)
// Skip check if the user is currently on the login page to avoid redirect loops
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && isset($_SESSION['user_id'])) {
    
    $inactive_timeout = 3600; // 1hr in seconds

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive_timeout)) {
        // Clear session data and destroy session
        session_unset();
        session_destroy();
        
        // Redirect to login with a timeout notice
        header("Location: login.php?timeout=1");
        exit;
    }
    
    // Refresh the last activity timestamp on every active page load
    $_SESSION['last_activity'] = time();
}

// 3. DATABASE CONFIGURATION SETTINGS
$server_name   = 'localhost';
$server_user   = 'root';
$server_pass   = ''; 
$database_name = 'admin_dm';

try {
    $conn = new PDO("mysql:host=$server_name;dbname=$database_name;charset=utf8", $server_user, $server_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>