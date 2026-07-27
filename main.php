    <?php
    // THIS FILE IS THE CORE TEMPLATE LAYOUT WRAPPER
    function render_layout($page_title, $content) {

    global $conn;

        // Safely check authorization sessions
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    
       try {
    $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
} catch (PDOException $e) {
    // Silently catch error
}


        $current_user_fullname = $_SESSION['full_name'];
        $current_user_username = $_SESSION['username'];
    
        // Determine which menu tab to highlight based on active filename context
        $current_script = basename($_SERVER['SCRIPT_NAME']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $page_title; ?> - Telco Charge DM System</title>
<link rel="icon" type="image/png" href="https://i.ibb.co/Lxy0sQJ/pdf-14361750.png">
<link rel="shortcut icon" href="https://upload.wikimedia.org/wikipedia/commons/8/87/PDF_file_icon.svg" type="image/x-icon">

<style>
            /* Force a standard base size to prevent browser/OS scaling inconsistency */
            html {
                font-size: 14px !important;
            }
            body {
                font-size: 1rem;
                -webkit-text-size-adjust: 100%;
            }
        </style>

        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://maxst.icons8.com/vue-static/landings/line-awesome/line-awesome/1.3.0/css/line-awesome.min.css">
    </head>
    <body class="bg-gray-100 font-sans flex h-screen overflow-hidden">

        <div class="w-64 bg-slate-900 text-white flex flex-col justify-between hidden md:flex">
            <div>
                <div class="p-5 bg-slate-950 border-b border-slate-800 text-center">
                    <h1 class="text-lg font-bold text-blue-400 tracking-wider uppercase">Telco Charge DM</h1>
                    <p class="text-xs text-gray-400">Management Portal</p>
                </div>
            
                <nav class="mt-6 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-md transition <?php echo $current_script === 'dashboard.php' ? 'bg-blue-600' : 'text-gray-400 hover:bg-slate-800'; ?>">
                        <i class="las la-chart-pie text-xl"></i> <span>Dashboard</span>
                    </a>
                
                    <a href="list_dm.php" class="flex items-center space-x-3 px-4 py-2.5 rounded transition <?php echo $current_script === 'list_dm.php' ? 'bg-blue-600' : 'text-gray-400 hover:bg-slate-800'; ?>">
                        <i class="las la-file-invoice-dollar text-xl"></i> <span>Debit Memos List</span>
                    </a>

                    <?php if ($_SESSION['role'] !== 'superadmin'): ?>
                    <a href="change_password.php" class="flex items-center space-x-3 px-4 py-2.5 rounded transition <?php echo $current_script === 'change_password.php' ? 'bg-blue-600' : 'text-gray-400 hover:bg-slate-800'; ?>">
                        <i class="las la-key text-xl"></i> <span>Change Password</span>
                    </a>
                    <?php endif; ?>
                
                    <?php if ($_SESSION['role'] === 'superadmin'): ?>
                        <a href="users.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-md transition <?php echo $current_script === 'users.php' ? 'bg-blue-600' : 'text-gray-400 hover:bg-slate-800'; ?>">
                            <i class="las la-users text-xl"></i> <span>User Management</span>
                        </a>
                    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'superadmin'): ?>
    <a href="logs.php" class="flex items-center space-x-3 px-4 py-2.5 rounded-md transition <?php echo $current_script === 'logs.php' ? 'bg-blue-600' : 'text-gray-400 hover:bg-slate-800'; ?>">
        <i class="las la-history text-xl"></i> <span>System Logs</span>
    </a>
<?php endif; ?>
                </nav>
            </div>

            <div class="p-4 bg-slate-950 border-t border-slate-800">
                <div class="flex items-center space-x-3 mb-3">
                    <div class="w-9 h-9 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold uppercase">
                        <?php echo substr($current_user_username, 0, 2); ?>
                    </div>
                    <div class="overflow-hidden">
                        <h4 class="text-sm font-semibold truncate"><?php echo htmlspecialchars($current_user_fullname); ?></h4>
                        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider 
                            <?php 
                                if ($_SESSION['role'] === 'superadmin') echo 'bg-yellow-600 text-white';
                                elseif ($_SESSION['role'] === 'admin') echo 'bg-blue-600 text-white';
                                else echo 'bg-gray-600 text-gray-200';
                            ?>">
                            <?php echo htmlspecialchars($_SESSION['role']); ?>
                        </span>
                    </div>
                </div>
                <a href="logout.php" class="block text-center w-full bg-red-600 hover:bg-red-700 text-white font-medium py-1.5 rounded text-xs transition">
                    <i class="las la-sign-out-alt mr-1"></i> Sign Out
                </a>
            </div>
        </div>

        <div class="flex-1 flex flex-col overflow-y-auto">
            <header class="bg-white border-b px-8 py-4 flex items-center justify-between shadow-sm">
                <h2 class="text-xl font-semibold text-gray-800"><?php echo $page_title; ?></h2>
                <div class="text-sm text-gray-500 font-medium">
                    <i class="las la-calendar mr-1"></i> Connected Session Mode
                </div>
            </header>

           <main class="px-2 py-0 w-full">
        <?php echo $content; ?>
    </main>
        </div>

    </body>
    </html>
    <?php
    }
    ?>