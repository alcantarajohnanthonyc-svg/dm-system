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
        $is_soa_active = in_array($current_script, ['debit_memo_upload.php', 'upload.php', 'debit_memo_dispatch.php', 'dispatch.php']);
        $is_super           = (($_SESSION['role'] ?? '') === 'superadmin');
        $is_settings_active = in_array($current_script, ['users.php', 'logs.php', 'backup.php']);

        // Top-level sidebar link
        $nav_item = function ($href, $icon, $label, $active) {
            $cls = $active ? 'bg-blue-600 text-white shadow-md' : 'text-gray-400 hover:bg-slate-800 hover:text-white';
            echo '<a href="' . $href . '" class="flex items-center space-x-3 px-4 py-2.5 rounded-md transition ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>'
               . '<i class="las ' . $icon . ' text-xl"></i> <span>' . $label . '</span></a>';
        };

        // Link inside a dropdown group
        $nav_child = function ($href, $icon, $label, $active) {
            $cls = $active ? 'bg-blue-500/15 text-blue-300 font-semibold' : 'text-gray-400 hover:bg-slate-800 hover:text-white';
            echo '<a href="' . $href . '" class="flex items-center space-x-2.5 px-3 py-2 rounded-md text-sm transition ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>'
               . '<i class="las ' . $icon . ' text-lg"></i> <span>' . $label . '</span></a>';
        };

        // Dropdown group header + wrapper open
        $nav_group_open = function ($key, $icon, $label, $active) {
            $btn = $active ? 'text-white font-semibold' : 'text-gray-400 hover:bg-slate-800 hover:text-white';
            echo '<div>'
               . '<button type="button" id="navBtn-' . $key . '" onclick="toggleNavGroup(\'' . $key . '\')" aria-expanded="' . ($active ? 'true' : 'false') . '" aria-controls="navSub-' . $key . '" '
               . 'class="w-full flex items-center justify-between px-4 py-2.5 rounded-md transition ' . $btn . '">'
               . '<span class="flex items-center space-x-3"><i class="las ' . $icon . ' text-xl"></i><span>' . $label . '</span></span>'
               . '<i class="las la-angle-down text-sm transition-transform duration-200 nav-arrow ' . ($active ? 'rotate-180' : '') . '"></i>'
               . '</button>'
               . '<div id="navSub-' . $key . '" class="nav-sub ' . ($active ? 'open' : '') . '"><div>'
               . '<div class="ml-6 mt-1 mb-1 pl-3 border-l border-slate-700 space-y-1">';
        };
        $nav_group_close = function () {
            echo '</div></div></div></div>';
        };
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

            /* Sidebar dropdown groups: smooth open/close, hidden links are not tabbable */
            .nav-sub { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .2s ease; }
            .nav-sub.open { grid-template-rows: 1fr; }
            .nav-sub > div { overflow: hidden; visibility: hidden; transition: visibility 0s linear .2s; }
            .nav-sub.open > div { visibility: visible; transition-delay: 0s; }
            @media (prefers-reduced-motion: reduce) {
                .nav-sub, .nav-sub > div, .nav-arrow { transition: none !important; }
            }
        </style>

        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://maxst.icons8.com/vue-static/landings/line-awesome/line-awesome/1.3.0/css/line-awesome.min.css">
    </head>
    <body class="bg-gray-100 font-sans flex h-screen overflow-hidden">

        <div class="w-64 bg-slate-900 text-white flex flex-col justify-between hidden md:flex shadow-xl">
            <div>
                <div class="p-5 bg-slate-950 border-b border-slate-800 text-center">
                    <h1 class="text-lg font-bold text-blue-400 tracking-wider uppercase">Telco Charge DM</h1>
                    <p class="text-xs text-gray-400">Management Portal</p>
                </div>
        
                <nav class="mt-6 px-4 space-y-1.5" aria-label="Main navigation">

                    <?php
                    // Everyday pages
                    $nav_item('dashboard.php', 'la-th-large',            'Overview',          $current_script === 'dashboard.php');
                    $nav_item('list_dm.php',   'la-file-invoice-dollar', 'Debit Masterfile',  $current_script === 'list_dm.php');
                    $nav_item('reports.php',   'la-chart-bar',           'Reports',           $current_script === 'reports.php');
                    ?>

                    <?php if ($is_super): ?>
                        <div class="my-3 border-t border-slate-800" role="separator"></div>

                        <?php
                        // Maps accounts to their email recipients (set up once, used by Email SOA)
                        $nav_item('email_mapping.php', 'la-sitemap', 'Account Mapping', in_array($current_script, ['email_mapping.php', 'mappings.php']));
                        ?>

                        <!-- Manage SOA: import statements, then email them -->
                        <?php
                        $nav_group_open('soa', 'la-address-book', 'Manage SOA', $is_soa_active);
                            $nav_child('debit_memo_upload.php',   'la-file-upload', 'Import SOA', in_array($current_script, ['debit_memo_upload.php', 'upload.php']));
                            $nav_child('debit_memo_dispatch.php', 'la-mail-bulk',   'Email SOA',  in_array($current_script, ['debit_memo_dispatch.php', 'dispatch.php']));
                        $nav_group_close();
                        ?>

                        <!-- Settings: system administration -->
                        <?php
                        $nav_group_open('settings', 'la-cog', 'Settings', $is_settings_active);
                            $nav_child('users.php',  'la-users',    'User Management', $current_script === 'users.php');
                            $nav_child('logs.php',   'la-history',  'System Logs',     $current_script === 'logs.php');
                            $nav_child('backup.php', 'la-database', 'Database Backup', $current_script === 'backup.php');
                        $nav_group_close();
                        ?>
                    <?php else: ?>
                        <div class="my-3 border-t border-slate-800" role="separator"></div>
                        <?php $nav_item('change_password.php', 'la-key', 'Change Password', $current_script === 'change_password.php'); ?>
                    <?php endif; ?>

                </nav>
            </div>

            <div class="p-4 bg-slate-950 border-t border-slate-800">
                <!-- Blank/Placeholder area at the bottom sidebar -->
            </div>
        </div>

        <div class="flex-1 flex flex-col overflow-y-auto">
            <header class="bg-white border-b px-8 py-3 flex items-center justify-between shadow-sm">
                <h2 class="text-xl font-semibold text-gray-800"><?php echo $page_title; ?></h2>
            
                <div class="flex items-center bg-gradient-to-r from-slate-50 to-white border border-slate-200 rounded-xl p-1 shadow-sm space-x-3">
                    <div class="flex items-center space-x-2.5 pl-2">
                        <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white text-xs font-bold uppercase shadow-sm">
                            <?php echo substr($current_user_username, 0, 2); ?>
                        </div>
                        <div class="flex flex-col text-left">
                            <span class="text-xs font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($current_user_fullname); ?></span>
                            <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[8px] font-bold uppercase tracking-wider w-max
                                <?php 
                                    if ($_SESSION['role'] === 'superadmin') echo 'bg-amber-500 text-white';
                                    elseif ($_SESSION['role'] === 'admin') echo 'bg-blue-600 text-white';
                                    else echo 'bg-slate-600 text-slate-100';
                                ?>">
                                <?php echo htmlspecialchars($_SESSION['role']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="h-6 w-px bg-slate-200"></div>

                    <a href="logout.php" class="flex items-center space-x-1.5 bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition duration-150 shadow-sm">
                        <i class="las la-sign-out-alt text-base"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </header>

            <main class="px-2 py-0 w-full">
                <?php echo $content; ?>
            </main>
        </div>

        <!-- DROPDOWN TOGGLE & 5-MINUTE HEARTBEAT SCRIPT -->
        <!-- ========================================== -->
       <script>
        function toggleNavGroup(key) {
            const btn = document.getElementById('navBtn-' + key);
            const sub = document.getElementById('navSub-' + key);
            if (!btn || !sub) return;
            const isOpen = sub.classList.toggle('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            btn.querySelector('.nav-arrow').classList.toggle('rotate-180', isOpen);
        }

        function sendSharedHeartbeat() {
            let lastPing = sessionStorage.getItem('last_heartbeat_time');
            let now = Date.now();

            // If no ping was sent in the last 5 minutes (300,000 ms), send one
            if (!lastPing || (now - lastPing > 300000)) {
                let formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'heartbeat');

                // Dynamically target the current page instead of hardcoding 'users.php'
                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        sessionStorage.setItem('last_heartbeat_time', Date.now());
                    }
                })
                .catch(err => console.error("Heartbeat error:", err));
            }
        }

        // Send immediately on page load, then check/ping every minute
        sendSharedHeartbeat();
        setInterval(sendSharedHeartbeat, 60000); // 1 minute = 60000 ms
        </script>
    </body>
    </html>
    <?php
    }
    ?>