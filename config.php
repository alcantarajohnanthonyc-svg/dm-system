<?php

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

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

// 2. DATABASE CONFIGURATION SETTINGS (Moved up so $conn is ready)
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

// 3. GLOBAL INACTIVITY TIMEOUT & LIVE STATUS CHECK
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && isset($_SESSION['user_id'])) {
    
    $inactive_timeout = 3600; // 1hr in seconds

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive_timeout)) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }
    
    // Refresh session timestamp
    $_SESSION['last_activity'] = time();

    // Update database last_activity timestamp for live status
    try {
        $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // Silently catch error
    }
}
?>