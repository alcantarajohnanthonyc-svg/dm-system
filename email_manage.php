<?php
// Force display errors directly into the response text
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'config.php';
    require_once 'main.php';

    // Auto-load Smalot PdfParser if available in vendor directory
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }

    // Base upload directory
    $base_upload_dir = __DIR__ . "/uploads/debit_memos/";
    if (!is_dir($base_upload_dir)) {
        mkdir($base_upload_dir, 0777, true);
    }

    $current_page_script = basename($_SERVER['PHP_SELF']);
    $message = '';
    $error = '';

    // ==========================================
    // HELPER FUNCTIONS FOR FILENAME PARSING
    // ==========================================

    function extract_date_from_filename($filename) {
        if (preg_match('/(20\d{2})(0[1-9]|1[0-2])([0-2][0-9]|3[01])/', $filename, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/(20\d{2})-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])/', $filename, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return date('Y-m-d');
    }

    function extract_account_from_filename($filename) {
        if (preg_match('/\b\d{8,15}\b/', $filename, $m)) {
            return $m[0];
        }
        if (preg_match('/\d+/', $filename, $m)) {
            return $m[0];
        }
        return 'Unknown';
    }

    // ==========================================
    // BACKEND REQUEST ROUTING & HANDLERS
    // ==========================================

    $available_date_folders = [];
    if (is_dir($base_upload_dir)) {
        $folder_scan = scandir($base_upload_dir);
        foreach ($folder_scan as $dir_item) {
            if ($dir_item !== '.' && $dir_item !== '..' && is_dir($base_upload_dir . $dir_item)) {
                $available_date_folders[] = $dir_item;
            }
        }
        rsort($available_date_folders);
    }

    // Do not select any folder by default unless explicitly requested via GET
    $selected_date_folder = $_GET['date_folder'] ?? '';
    $has_selected_folder = !empty($selected_date_folder) && in_array($selected_date_folder, $available_date_folders);
    
    $current_folder_path = $has_selected_folder ? $base_upload_dir . basename($selected_date_folder) . '/' : '';

    // Pagination Parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = intval($_GET['limit'] ?? 25);
    if (!in_array($limit, [10, 25, 50, 100])) {
        $limit = 25;
    }

    // 1. Handle PDF Batch Uploads
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_files'])) {
        header('Content-Type: application/json');
        $success_files = [];
        $failed_files = [];
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

        foreach ($_FILES['pdf_files']['name'] as $i => $name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $tmp_name = $_FILES['pdf_files']['tmp_name'][$i];
                $basename = basename($name);

                $extracted_date = extract_date_from_filename($basename);
                $account_num = extract_account_from_filename($basename);

                $target_folder = $base_upload_dir . $extracted_date . '/';
                if (!is_dir($target_folder)) {
                    mkdir($target_folder, 0777, true);
                }

                $destination = $target_folder . $basename;
                
                if (file_exists($destination)) {
                    if ($overwrite) {
                        if (move_uploaded_file($tmp_name, $destination)) {
                            $success_files[] = "$basename (Overwritten to folder $extracted_date)";
                        } else {
                            $failed_files[] = "$basename (Failed to overwrite)";
                        }
                    } else {
                        $failed_files[] = "$basename (Skipped: Duplicate in $extracted_date)";
                    }
                } else {
                    if (move_uploaded_file($tmp_name, $destination)) {
                        $success_files[] = "$basename (Saved to folder $extracted_date)";
                    } else {
                        $failed_files[] = "$basename (Upload failed)";
                    }
                }
            } else {
                $failed_files[] = "$name (Invalid file type)";
            }
        }

        echo json_encode([
            'status' => 'success',
            'success_files' => $success_files,
            'failed_files' => $failed_files
        ]);
        exit;
    }

    // 2. Handle Email Mappings CRUD
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_email', 'delete_email'])) {
        $action = $_POST['action'];

        if ($action === 'save_email') {
            $id = $_POST['id'] ?? '';
            $account_number = trim($_POST['account_number'] ?? '');
            $email_address = trim($_POST['email_address'] ?? '');

            if (!empty($account_number) && !empty($email_address)) {
                try {
                    if (!empty($id)) {
                        $stmt = $pdo->prepare("UPDATE account_emails SET account_number = ?, email_address = ? WHERE id = ?");
                        $stmt->execute([$account_number, $email_address, $id]);
                        $message = "Email mapping successfully updated!";
                    } else {
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
        } elseif ($action === 'delete_email') {
            $id = $_POST['id'] ?? '';
            if (!empty($id)) {
                $stmt = $pdo->prepare("DELETE FROM account_emails WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Email mapping successfully deleted!";
            }
        }
    }

    // 3. Handle Email Preview Request (AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview_email') {
        header('Content-Type: application/json');
        $filename = basename($_POST['filename'] ?? '');
        $date_folder = preg_replace('/[^0-9\-]/', '', $_POST['date_folder'] ?? '');
        $filepath = $base_upload_dir . $date_folder . '/' . $filename;

        if (!file_exists($filepath)) {
            echo json_encode(['status' => 'error', 'message' => 'File not found. Path checked: ' . $filepath]);
            exit;
        }

        $account_number = extract_account_from_filename($filename);

        $company = 'N/A';
        $assignee_name = 'N/A';
        if (isset($pdo)) {
            $stmtDM = $pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
            $stmtDM->execute([$account_number]);
            if ($dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC)) {
                $company = $dmRow['company'] ?? 'N/A';
                $assignee_name = $dmRow['assignee_name'] ?? 'N/A';
            }
        }

        $billing_info = extract_pdf_billing_details($filepath);
        $html_preview = generate_email_body_html($company, $assignee_name, $account_number, $billing_info);

        echo json_encode(['status' => 'success', 'html' => $html_preview]);
        exit;
    }

    // 4. Handle Batch Email Dispatch & Report Download Request (AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_emails') {
        header('Content-Type: application/json');
        $selected_files = $_POST['files'] ?? [];
        $date_folder = preg_replace('/[^0-9\-]/', '', $_POST['date_folder'] ?? '');
        $results = [];

        foreach ($selected_files as $filename) {
            $filename = basename($filename);
            $filepath = $base_upload_dir . $date_folder . '/' . $filename;
            
            if (!file_exists($filepath)) {
                $results[] = ['file' => $filename, 'account' => 'N/A', 'email' => 'N/A', 'status' => 'error', 'message' => 'File not found on server.'];
                continue;
            }

            $account_number = extract_account_from_filename($filename);

            $company = 'N/A';
            $assignee_name = 'N/A';
            $email_address = '';

            try {
                if (isset($pdo)) {
                    $stmtDM = $pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtDM->execute([$account_number]);
                    if ($dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC)) {
                        $company = $dmRow['company'] ?? 'N/A';
                        $assignee_name = $dmRow['assignee_name'] ?? 'N/A';
                    }

                    $stmtEmail = $pdo->prepare("SELECT email_address FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtEmail->execute([$account_number]);
                    if ($emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                        $email_address = $emailRow['email_address'] ?? '';
                    }
                }
            } catch (Exception $e) {}

            if (empty($email_address)) {
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => 'None', 'status' => 'error', 'message' => "No email registered for account: {$account_number}"];
                continue;
            }

            $billing_info = extract_pdf_billing_details($filepath);
            $html_content = generate_email_body_html($company, $assignee_name, $account_number, $billing_info);

            $to = $email_address;
            $period = !empty($billing_info['billing_period']) && $billing_info['billing_period'] !== 'N/A' ? $billing_info['billing_period'] : 'Current Period';
            $subject = "Statement of Account for {$account_number}: Debit Memo Details for {$period}";
            
            $mail_status = send_smtp_mail_with_html_body($to, $subject, $filepath, $filename, $html_content);

            if ($mail_status === true) {
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $to, 'status' => 'success', 'message' => "Successfully sent to {$to}"];
            } else {
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $to, 'status' => 'error', 'message' => $mail_status];
            }
        }

        echo json_encode(['status' => 'completed', 'results' => $results]);
        exit;
    }

    // ==========================================
    // HELPER FUNCTIONS
    // ==========================================

    function extract_pdf_billing_details($filepath) {
        $data = [
            'telco' => 'Unknown',
            'amount_due' => '0.00',
            'corporate_id' => 'N/A',
            'invoice_date' => 'N/A',
            'billing_period' => 'N/A',
            'mobile_number' => 'N/A',
            'invoice_number' => 'N/A',
            'credit_limit' => 'N/A',
            'due_date' => 'N/A',
            'customer_tin' => 'N/A',
            'summary_html' => ''
        ];

        if (!file_exists($filepath)) return $data;

        $text = '';
        try {
            if (class_exists('\\Smalot\\PdfParser\\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filepath);
                $text = $pdf->getText();
            } else {
                $text = @file_get_contents($filepath);
            }
        } catch (Exception $e) {
            $text = @file_get_contents($filepath);
        }

        if (stripos($text, 'Smart Communications') !== false || stripos($text, 'SMART') !== false || stripos($text, 'SMTBI') !== false) {
            $data['telco'] = 'Smart';
            
            if (preg_match('/Invoice\s*Date\s*([A-Za-z0-9,\s]+)/i', $text, $m)) $data['invoice_date'] = trim($m[1]);
            if (preg_match('/Billing\s*Period\s*([A-Za-z0-9,\s\-]+)/i', $text, $m)) $data['billing_period'] = trim($m[1]);
            if (preg_match('/Mobile\s*Number\s*([0-9\s]+)/i', $text, $m)) $data['mobile_number'] = trim($m[1]);
            if (preg_match('/Invoice\s*Number\s*(SMTBI[0-9]+)/i', $text, $m)) $data['invoice_number'] = trim($m[1]);
            if (preg_match('/Credit\s*Limit\s*([0-9,\.]+)/i', $text, $m)) $data['credit_limit'] = trim($m[1]);
            if (preg_match('/DUE\s*DATE[:]?\s*([A-Za-z0-9,\s]+)/i', $text, $m)) $data['due_date'] = trim($m[1]);
            
            if (preg_match('/TOTAL\s*AMOUNT\s*DU[EE]\s*[:]?\s*(?:PHP)?\s*([0-9,\.\(\)\sCR]+)/i', $text, $m)) {
                $raw_amount = trim($m[1]);
                if (!empty($raw_amount)) {
                    $data['amount_due'] = $raw_amount;
                }
            } elseif (preg_match('/Total\s*Amount\s*Due\s*(?:PHP)?\s*([0-9,\.\(\)\sCR]+)/i', $text, $m)) {
                $data['amount_due'] = trim($m[1]);
            }
        } elseif (stripos($text, 'Globe Telecom') !== false || stripos($text, 'GLOBE BUSINESS') !== false || stripos($text, 'CTGI') !== false) {
            $data['telco'] = 'Globe';

            if (preg_match('/Amount\s*to\s*Pay[^\d]*([0-9,]+\.[0-9]{2})/i', $text, $m)) {
                $data['amount_due'] = 'Php ' . trim($m[1]);
            } elseif (preg_match('/Php\s*([0-9,]+\.[0-9]{2})/i', $text, $m)) {
                $data['amount_due'] = 'Php ' . trim($m[1]);
            }

            if (preg_match('/(CTGI[0-9]+)/i', $text, $m)) $data['corporate_id'] = trim($m[1]);
            if (preg_match('/Invoice\s*Date\s*([0-9\/]+)/i', $text, $m)) $data['invoice_date'] = trim($m[1]);
            if (preg_match('/Billing\s*Period\s*([0-9\/]+\s*to\s*[0-9\/]+)/i', $text, $m)) $data['billing_period'] = trim($m[1]);
            if (preg_match('/Due\s*Date\s*([0-9\/]+)/i', $text, $m)) $data['due_date'] = trim($m[1]);
            if (preg_match('/Primary\s*Number\s*([0-9]+)/i', $text, $m)) $data['mobile_number'] = trim($m[1]);
        }

        return $data;
    }

    function generate_email_body_html($company, $assignee_name, $account_number, $billing_info) {
        $amount_due = $billing_info['amount_due'];
        $period = !empty($billing_info['billing_period']) && $billing_info['billing_period'] !== 'N/A' ? $billing_info['billing_period'] : 'Current Period';
        $employee_identifier = !empty($company) && $company !== 'N/A' ? $company : $account_number;

        return '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif; color: #333333; line-height: 1.6; background: #f4f4f4; margin: 0; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #ddd; padding: 30px; border-radius: 8px;">
                <p style="margin-top: 0;">Dear Ma\'am/Sir,</p>
                
                <p>Please find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges, with details below:</p>
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 6px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #1e293b; font-size: 15px; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px;">Summary Details:</h3>
                    <p style="margin: 6px 0;"><strong>Period Covered:</strong> ' . htmlspecialchars($period) . '</p>
                    <p style="margin: 6px 0;"><strong>Account Name / ID:</strong> ' . htmlspecialchars($employee_identifier) . ' (' . htmlspecialchars($account_number) . ')</p>
                    <p style="margin: 6px 0;"><strong>Total Chargeable Amount:</strong> <span style="color: #0d9488; font-weight: bold;">' . htmlspecialchars($amount_due) . '</span> (Refer to Attachment)</p>
                </div>
                
                <p>This statement outlines the specific breakdown and descriptions of the charges applied to your telco account.</p>
                
                <p>Please review the attached SOA for full details.</p>
                
                <p style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px; font-size: 13px; color: #92400e;">
                    If you have any questions or require clarification regarding these charges, please reach out to the IT Telco Admin team thru <strong>jcalcantara@bounty.com.ph</strong> within 24-48 hours upon receipt of this email.
                </p>
                
                <p style="margin-top: 30px;">Thank you,</p>
            </div>
        </body>
        </html>';
    }

    function send_smtp_mail_with_html_body($to, $subject, $filepath, $filename, $html_content) {
        $smtp_host = 'tcp://smtp.gmail.com';
        $smtp_port = 587;                    
        $smtp_user = 'jcalcantara@bounty.com.ph';
        $smtp_pass = str_replace(' ', '', 'kowg yhnc dryb uumq'); 

        $boundary_mixed = md5(time() . 'mixed');

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: IT Telco Admin <{$smtp_user}>\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary_mixed}\"\r\n\r\n";

        $body  = "--{$boundary_mixed}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= "{$html_content}\r\n\r\n";

        if (file_exists($filepath)) {
            $pdfData = chunk_split(base64_encode(file_get_contents($filepath)));
            $body .= "--{$boundary_mixed}\r\n";
            $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $body .= "{$pdfData}\r\n\r\n";
        }
        
        $body .= "--{$boundary_mixed}--";

        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
        if (!is_resource($socket)) return "Connection failed: $errstr ($errno)";

        fgets($socket, 512);
        $run_command = function($cmd, $expect_code) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
            $response = '';
            while ($str = fgets($socket, 512)) {
                $response .= $str;
                if (substr($str, 3, 1) == ' ') break;
            }
            return (substr($response, 0, 3) == $expect_code);
        };

        if (!$run_command("EHLO " . $_SERVER['SERVER_NAME'], 250)) return "EHLO error";
        if (!$run_command("STARTTLS", 220)) return "STARTTLS error";
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$run_command("EHLO " . $_SERVER['SERVER_NAME'], 250)) return "EHLO 2 error";
        if (!$run_command("AUTH LOGIN", 334)) return "AUTH not supported";
        if (!$run_command(base64_encode($smtp_user), 334)) return "Username rejected";
        if (!$run_command(base64_encode($smtp_pass), 235)) return "Password rejected";
        if (!$run_command("MAIL FROM: <{$smtp_user}>", 250)) return "MAIL FROM error";
        if (!$run_command("RCPT TO: <{$to}>", 250)) return "RCPT TO error";
        if (!$run_command("DATA", 354)) return "DATA error";
        
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $result = fgets($socket, 512);
        $run_command("QUIT", 221);
        fclose($socket);

        return (substr($result, 0, 3) == '250') ? true : "Failed to send: " . trim($result);
    }

    // Fetch email records for Mappings tab
    try {
        $stmtEmailRecords = $pdo->query("SELECT * FROM account_emails ORDER BY id DESC");
        $email_records = $stmtEmailRecords->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $email_records = [];
    }

    // Scan uploaded files and apply server-side pagination for the Dispatch tab
    $all_pdf_files = [];
    if ($has_selected_folder && is_dir($current_folder_path)) {
        $scan = scandir($current_folder_path);
        if ($scan !== false) {
            foreach ($scan as $file) {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
                    $filepath = $current_folder_path . $file;
                    $account_number = extract_account_from_filename($file);

                    $billing_info = extract_pdf_billing_details($filepath);

                    $company = 'Unknown';
                    $assignee_name = 'Unassigned';
                    $email_address = 'Not Registered';

                    if ($account_number !== 'Unknown' && isset($pdo)) {
                        try {
                            $stmtDM = $pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtDM->execute([$account_number]);
                            $dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC);
                            if ($dmRow) {
                                $company = $dmRow['company'] ?? 'Unknown';
                                $assignee_name = $dmRow['assignee_name'] ?? 'Unassigned';
                            }
                        } catch (Exception $e) {}

                        try {
                            $stmtEmailCheck = $pdo->prepare("SELECT email_address FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtEmailCheck->execute([$account_number]);
                            $emailRow = $stmtEmailCheck->fetch(PDO::FETCH_ASSOC);
                            if ($emailRow && !empty($emailRow['email_address'])) {
                                $email_address = $emailRow['email_address'];
                            }
                        } catch (Exception $e) {}
                    }

                    $all_pdf_files[] = [
                        'filename' => $file,
                        'size' => file_exists($filepath) ? filesize($filepath) : 0,                             
                        'account_number' => $account_number,
                        'company' => $company,
                        'assignee_name' => $assignee_name,
                        'email_address' => $email_address,
                        'telco' => $billing_info['telco'],
                        'amount_due' => $billing_info['amount_due']
                    ];
                }
            }
        }
    }

    $total_files_count = count($all_pdf_files);
    $total_pages = $total_files_count > 0 ? ceil($total_files_count / $limit) : 1;
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $limit;
    $pdf_files = array_slice($all_pdf_files, $offset, $limit);

    ob_start();
    ?>

    <div class="max-w-7xl mx-auto py-6 px-4">
        <!-- Unified Dashboard Header -->
        <div class="bg-gradient-to-r from-blue-700 to-indigo-800 rounded-2xl shadow-xl p-6 text-white mb-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold flex items-center space-x-3">
                        <span>⚡</span>
                        <span>Statement of Account Management Hub</span>
                    </h1>
                    <p class="text-blue-100 text-sm mt-1">Organized by Date Folders • Asynchronous Upload/Dispatch Controls • Smart & Globe Support</p>
                </div>
                <div class="flex bg-blue-900/60 p-1 rounded-xl backdrop-blur-md border border-blue-600/50">
                    <button type="button" onclick="switchTab('upload')" id="tabBtnUpload" class="tab-btn px-4 py-2 rounded-lg text-xs font-bold transition-all bg-white text-blue-900 shadow">
                        📤 Upload Queue
                    </button>
                    <button type="button" onclick="switchTab('dispatch')" id="tabBtnDispatch" class="tab-btn px-4 py-2 rounded-lg text-xs font-bold transition-all text-white hover:bg-blue-800">
                        ✉️ Dispatch by Date
                    </button>
                    <button type="button" onclick="switchTab('mappings')" id="tabBtnMappings" class="tab-btn px-4 py-2 rounded-lg text-xs font-bold transition-all text-white hover:bg-blue-800">
                        📇 Email Mappings
                    </button>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($message)): ?>
            <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-xs font-semibold shadow-sm flex items-center space-x-2">
                <span>✓</span><span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl text-xs font-semibold shadow-sm flex items-center space-x-2">
                <span>⚠</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- TAB 1: UPLOAD QUEUE -->
        <div id="tabContentUpload" class="tab-content bg-white shadow-lg rounded-2xl p-6 border border-gray-100 mb-6">
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-800">Upload Statements (Auto-routed by Filename Date)</h2>
                <p class="text-xs text-gray-500 mt-0.5">The system automatically reads the date and 8–15 digit account number from each PDF filename to categorize folders.</p>
            </div>

            <div class="flex justify-between items-center mb-2">
                <span class="text-xs font-bold tracking-wider text-rose-600 uppercase">Select PDF Files (Accumulative Batching)</span>
                <button type="button" id="clearBtn" class="text-xs text-gray-400 hover:text-rose-600 flex items-center space-x-1 transition-colors font-medium">
                    <span>🗑️ Clear Queue</span>
                </button>
            </div>

            <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-blue-500 transition-all bg-gray-50/50 cursor-pointer mb-4 relative">
                <input type="file" id="pdf_files" name="pdf_files[]" multiple accept=".pdf" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                <div class="flex flex-col items-center pointer-events-none">
                    <svg class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                    </svg>
                    <p id="queueCountText" class="text-sm font-medium text-gray-600 mb-3">0 file(s) loaded in queue.</p>
                    <span class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm">
                        Browse PDF Files
                    </span>
                </div>
            </div>

            <div class="mb-6 flex items-center bg-blue-50/60 border border-blue-100 rounded-xl p-3.5">
                <input type="checkbox" id="overwriteCheckbox" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                <label for="overwriteCheckbox" class="ml-2.5 text-xs font-medium text-blue-900 cursor-pointer">
                    <strong>Overwrite existing files</strong> if they already exist in the target folder (Leave unchecked to skip duplicates).
                </label>
            </div>

            <div class="mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2" id="queueHeaderLabel">Selected Files Queue (0)</div>
                <div id="fileListContainer" class="border border-gray-200 rounded-xl bg-gray-50 min-h-[80px] max-h-48 overflow-y-auto p-3 divide-y divide-gray-100">
                    <p class="text-xs text-gray-400 text-center py-4" id="emptyQueueMsg">No files selected yet.</p>
                </div>
            </div>

            <div id="progressContainer" class="mb-6 hidden">
                <div class="flex justify-between text-xs font-semibold text-gray-600 mb-1">
                    <span id="progressText">Uploading batches...</span>
                    <span id="progressPercentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                    <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>

            <div id="logContainer" class="mb-6 hidden border border-gray-800 rounded-xl p-4 bg-slate-900 text-white text-xs font-mono max-h-40 overflow-y-auto">
                <div class="font-bold text-gray-300 mb-2 border-b border-slate-700 pb-1">Upload Result Logs:</div>
                <div id="logContent" class="space-y-1"></div>
            </div>

            <div class="flex space-x-3">
                <button type="button" id="uploadBtn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-xl shadow-md transition-colors flex items-center justify-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <span>🚀</span><span>Start Batch Upload</span>
                </button>
                <button type="button" id="stopUploadBtn" class="hidden bg-rose-600 hover:bg-rose-700 text-white font-semibold py-3 px-4 rounded-xl shadow-md transition-colors flex items-center justify-center space-x-2">
                    <span>🛑</span><span>Stop Upload</span>
                </button>
            </div>
        </div>

        <!-- TAB 2: STATEMENT DISPATCH -->
        <div id="tabContentDispatch" class="tab-content hidden bg-white shadow-lg rounded-2xl p-6 border border-gray-100 mb-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">Dispatch Statements by Date Folder</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Select a date folder to load statements, dispatch emails, and export dispatch logs.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <select id="dateFolderSelector" onchange="changeDispatchParams()" class="text-xs border border-gray-300 rounded-xl p-2.5 bg-white font-medium shadow-sm">
                        <option value="" disabled <?php echo !$has_selected_folder ? 'selected' : ''; ?>>-- Select Date Folder --</option>
                        <?php foreach ($available_date_folders as $folder): ?>
                            <option value="<?php echo $folder; ?>" <?php echo ($selected_date_folder === $folder) ? 'selected' : ''; ?>><?php echo $folder; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="limitSelector" onchange="changeDispatchParams()" class="text-xs border border-gray-300 rounded-xl p-2.5 bg-white font-medium shadow-sm">
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                    </select>

                    <button type="button" id="sendSelectedBtn" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow-md text-xs transition-colors flex items-center space-x-2">
                        <span>📤</span><span>Send Selected Emails</span>
                    </button>
                    <button type="button" id="downloadReportBtn" onclick="downloadReportCSV()" class="hidden bg-slate-700 hover:bg-slate-800 text-white font-semibold px-4 py-2.5 rounded-xl shadow-md text-xs transition-colors flex items-center space-x-2">
                        <span>📥</span><span>Download Send Report</span>
                    </button>
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden mb-4 shadow-sm">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-semibold border-b border-gray-200">
                            <th class="py-3 px-4 w-10 text-center">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="py-3 px-4">Filename</th>
                            <th class="py-3 px-4">Provider</th>
                            <th class="py-3 px-4">Account Number</th>
                            <th class="py-3 px-4">Amount Due</th>
                            <th class="py-3 px-4">Company & Assignee</th>
                            <th class="py-3 px-4">Registered Email</th>
                            <th class="py-3 px-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs text-gray-700">
                        <?php if (!$has_selected_folder): ?>
                            <tr>
                                <td colspan="8" class="text-center py-10 text-gray-400 font-medium">Please select a date folder from the dropdown above to load statements.</td>
                            </tr>
                        <?php elseif (empty($pdf_files)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-10 text-gray-400 font-medium">No PDF statements found in date folder: <strong><?php echo htmlspecialchars($selected_date_folder); ?></strong></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pdf_files as $file): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-4 text-center">
                                        <input type="checkbox" name="file_checkbox" value="<?php echo htmlspecialchars($file['filename']); ?>" class="file-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo ($file['email_address'] === 'Not Registered') ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($file['filename']); ?></td>
                                    <td class="py-3 px-4">
                                        <?php if ($file['telco'] === 'Smart'): ?>
                                            <span class="px-2.5 py-1 rounded-full text-emerald-700 bg-emerald-50 font-bold">Smart</span>
                                        <?php elseif ($file['telco'] === 'Globe'): ?>
                                            <span class="px-2.5 py-1 rounded-full text-blue-700 bg-blue-50 font-bold">Globe</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-full text-gray-600 bg-gray-100 font-medium">Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($file['account_number']); ?></td>
                                    <td class="py-3 px-4 font-bold text-slate-800"><?php echo htmlspecialchars($file['amount_due']); ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($file['company']); ?></div>
                                        <div class="text-gray-500 text-[11px]"><?php echo htmlspecialchars($file['assignee_name']); ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($file['email_address'] === 'Not Registered'): ?>
                                            <span class="px-2.5 py-1 rounded-full text-rose-600 bg-rose-50 font-medium">Not Registered</span>
                                        <?php else: ?>
                                            <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($file['email_address']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <button type="button" onclick="openPreview('<?php echo htmlspecialchars($file['filename']); ?>')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">
                                            👁️ Preview
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($has_selected_folder && $total_files_count > 0): ?>
                <div class="flex flex-col md:flex-row justify-between items-center text-xs text-gray-600 mb-6 gap-3">
                    <div>
                        Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_files_count); ?></strong> of <strong><?php echo $total_files_count; ?></strong> statements
                    </div>
                    <div class="flex items-center space-x-1">
                        <button type="button" onclick="changePage(1)" <?php echo ($page <= 1) ? 'disabled class="px-3 py-1.5 border rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed"' : 'class="px-3 py-1.5 border rounded-lg hover:bg-gray-50 font-semibold"'; ?>>First</button>
                        <button type="button" onclick="changePage(<?php echo $page - 1; ?>)" <?php echo ($page <= 1) ? 'disabled class="px-3 py-1.5 border rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed"' : 'class="px-3 py-1.5 border rounded-lg hover:bg-gray-50 font-semibold"'; ?>>Previous</button>
                        
                        <span class="px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 font-bold rounded-lg">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>

                        <button type="button" onclick="changePage(<?php echo $page + 1; ?>)" <?php echo ($page >= $total_pages) ? 'disabled class="px-3 py-1.5 border rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed"' : 'class="px-3 py-1.5 border rounded-lg hover:bg-gray-50 font-semibold"'; ?>>Next</button>
                        <button type="button" onclick="changePage(<?php echo $total_pages; ?>)" <?php echo ($page >= $total_pages) ? 'disabled class="px-3 py-1.5 border rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed"' : 'class="px-3 py-1.5 border rounded-lg hover:bg-gray-50 font-semibold"'; ?>>Last</button>
                    </div>
                </div>
            <?php endif; ?>

            <div id="emailLogContainer" class="hidden border border-gray-800 rounded-xl p-4 bg-slate-900 text-white text-xs font-mono max-h-48 overflow-y-auto">
                <div class="font-bold text-gray-300 mb-2 border-b border-slate-700 pb-1 flex justify-between items-center">
                    <span>Email Dispatch Log:</span>
                </div>
                <div id="emailLogContent" class="space-y-1"></div>
            </div>
        </div>

        <!-- TAB 3: EMAIL MAPPINGS -->
        <div id="tabContentMappings" class="tab-content hidden bg-white shadow-lg rounded-2xl p-6 border border-gray-100 mb-6">
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-800">Manage Account Email Mappings</h2>
                <p class="text-xs text-gray-500 mt-0.5">Map account numbers to client email addresses.</p>
            </div>

            <form action="<?php echo htmlspecialchars($current_page_script); ?>" method="POST" id="emailForm" class="bg-gray-50 p-4 rounded-xl border border-gray-200 mb-6 flex flex-col md:flex-row gap-4 items-end">
                <input type="hidden" name="action" value="save_email">
                <input type="hidden" name="id" id="recordId">

                <div class="w-full md:w-1/3">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Account Number</label>
                    <input type="text" name="account_number" id="accountNumber" required placeholder="e.g. 6012050532" class="w-full text-xs border border-gray-300 rounded-lg p-2.5 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm">
                </div>

                <div class="w-full md:w-1/2">
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Email Address</label>
                    <input type="email" name="email_address" id="emailAddress" required placeholder="client@company.com" class="w-full text-xs border border-gray-300 rounded-lg p-2.5 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm">
                </div>

                <div class="w-full md:w-auto flex space-x-2">
                    <button type="submit" id="saveBtn" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-5 py-2.5 rounded-lg shadow transition-colors">
                        Save Mapping
                    </button>
                    <button type="button" onclick="resetForm()" id="cancelBtn" class="hidden bg-gray-300 hover:bg-gray-400 text-gray-700 text-xs font-semibold px-3 py-2.5 rounded-lg transition-colors">
                        Cancel
                    </button>
                </div>
            </form>

            <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm">
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
                                <td colspan="5" class="text-center py-10 text-gray-400 font-medium">No email records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($email_records as $rec): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-4 text-gray-400 font-mono"><?php echo $rec['id']; ?></td>
                                    <td class="py-3 px-4 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($rec['account_number']); ?></td>
                                    <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($rec['email_address']); ?></td>
                                    <td class="py-3 px-4 text-gray-400"><?php echo $rec['created_at']; ?></td>
                                    <td class="py-3 px-4 text-right space-x-3">
                                        <button type="button" onclick="editRecord(<?php echo $rec['id']; ?>, '<?php echo htmlspecialchars($rec['account_number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rec['email_address'], ENT_QUOTES); ?>')" class="text-blue-600 hover:underline font-semibold">Edit</button>
                                        
                                        <form action="<?php echo htmlspecialchars($current_page_script); ?>" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this email record?');">
                                            <input type="hidden" name="action" value="delete_email">
                                            <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                            <button type="submit" class="text-rose-600 hover:underline font-semibold">Delete</button>
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

    <!-- PREVIEW MODAL -->
    <div id="previewModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center bg-gray-50 px-6 py-4 border-b border-gray-200">
                <h3 class="font-bold text-gray-800 text-sm">Email Content Preview</h3>
                <button type="button" onclick="closePreview()" class="text-gray-400 hover:text-gray-600 font-bold text-lg">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-50 flex-grow">
                <iframe id="previewIframe" class="w-full h-[500px] bg-white border border-gray-200 rounded-xl shadow-sm"></iframe>
            </div>
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 text-right">
                <button type="button" onclick="closePreview()" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-xl text-xs font-semibold">Close Preview</button>
            </div>
        </div>
    </div>

    <script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('bg-white', 'text-blue-900', 'shadow');
            el.classList.add('text-white', 'hover:bg-blue-800');
        });

        document.getElementById('tabContent' + capitalize(tabName)).classList.remove('hidden');
        const activeBtn = document.getElementById('tabBtn' + capitalize(tabName));
        activeBtn.classList.remove('text-white', 'hover:bg-blue-800');
        activeBtn.classList.add('bg-white', 'text-blue-900', 'shadow');
    }

    function capitalize(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    function changeDispatchParams() {
        const folder = document.getElementById('dateFolderSelector').value;
        const limit = document.getElementById('limitSelector').value;
        if(folder) {
            window.location.href = `?date_folder=${folder}&limit=${limit}&page=1`;
        }
    }

    function changePage(targetPage) {
        const folder = document.getElementById('dateFolderSelector').value;
        const limit = document.getElementById('limitSelector').value;
        if(folder) {
            window.location.href = `?date_folder=${folder}&limit=${limit}&page=${targetPage}`;
        }
    }

    // Upload Queue JS
    let accumulatedFiles = [];
    let isUploading = false;
    let abortUploadFlag = false;
    const fileInput = document.getElementById('pdf_files');
    const fileListContainer = document.getElementById('fileListContainer');
    const emptyQueueMsg = document.getElementById('emptyQueueMsg');
    const queueCountText = document.getElementById('queueCountText');
    const queueHeaderLabel = document.getElementById('queueHeaderLabel');
    const uploadBtn = document.getElementById('uploadBtn');
    const stopUploadBtn = document.getElementById('stopUploadBtn');
    const clearBtn = document.getElementById('clearBtn');
    const dropZone = document.getElementById('dropZone');
    const overwriteCheckbox = document.getElementById('overwriteCheckbox');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressPercentage = document.getElementById('progressPercentage');
    const logContainer = document.getElementById('logContainer');
    const logContent = document.getElementById('logContent');

    if (fileInput) {
        fileInput.addEventListener('change', (e) => {
            handleNewFiles(e.target.files);
            fileInput.value = '';
        });
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        if (dropZone) {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.add('border-blue-500', 'bg-blue-50/20');
            }, false);
        }
    });

    ['dragleave', 'drop'].forEach(eventName => {
        if (dropZone) {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.remove('border-blue-500', 'bg-blue-50/20');
            }, false);
        }
    });

    if (dropZone) {
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            handleNewFiles(e.dataTransfer.files);
        });
    }

    function handleNewFiles(files) {
        for (let i = 0; i < files.length; i++) {
            let file = files[i];
            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                let exists = accumulatedFiles.some(f => f.name === file.name && f.size === file.size);
                if (!exists) {
                    accumulatedFiles.push(file);
                }
            }
        }
        updateQueueUI();
    }

    function updateQueueUI() {
        fileListContainer.innerHTML = '';
        if (accumulatedFiles.length > 0) {
            emptyQueueMsg.style.display = 'none';
            if (!isUploading) uploadBtn.removeAttribute('disabled');
            queueCountText.textContent = `${accumulatedFiles.length} file(s) loaded in queue.`;
            queueHeaderLabel.textContent = `SELECTED FILES QUEUE (${accumulatedFiles.length})`;

            accumulatedFiles.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'flex justify-between items-center py-2 px-3 text-xs text-gray-700 hover:bg-white rounded transition-colors';
                item.innerHTML = `
                    <div class="flex items-center space-x-2 truncate">
                        <span class="text-rose-500 font-bold">📄</span>
                        <span class="truncate font-medium">${file.name}</span>
                    </div>
                    <div class="flex items-center space-x-3 shrink-0">
                        <span class="text-gray-400">${(file.size / 1024).toFixed(1)} KB</span>
                        <button type="button" onclick="removeFile(${index})" class="text-gray-400 hover:text-rose-600 font-bold">✕</button>
                    </div>
                `;
                fileListContainer.appendChild(item);
            });
        } else {
            emptyQueueMsg.style.display = 'block';
            uploadBtn.setAttribute('disabled', 'true');
            queueCountText.textContent = '0 file(s) loaded in queue.';
            queueHeaderLabel.textContent = 'SELECTED FILES QUEUE (0)';
        }
    }

    function removeFile(index) {
        if (isUploading) return;
        accumulatedFiles.splice(index, 1);
        updateQueueUI();
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (isUploading) return;
            accumulatedFiles = [];
            updateQueueUI();
            logContainer.classList.add('hidden');
            progressContainer.classList.add('hidden');
        });
    }

    if (stopUploadBtn) {
        stopUploadBtn.addEventListener('click', () => {
            abortUploadFlag = true;
            stopUploadBtn.setAttribute('disabled', 'true');
            progressText.textContent = 'Stopping upload queue...';
        });
    }

    if (uploadBtn) {
        uploadBtn.addEventListener('click', async function() {
            if (accumulatedFiles.length === 0 || isUploading) return;

            isUploading = true;
            abortUploadFlag = false;
            uploadBtn.setAttribute('disabled', 'true');
            clearBtn.setAttribute('disabled', 'true');
            stopUploadBtn.classList.remove('hidden');
            progressContainer.classList.remove('hidden');
            logContainer.classList.remove('hidden');
            logContent.innerHTML = '';

            const batchSize = 25; 
            const totalFiles = accumulatedFiles.length;
            let uploadedCount = 0;
            const shouldOverwrite = overwriteCheckbox.checked ? '1' : '0';

            for (let i = 0; i < totalFiles; i += batchSize) {
                if (abortUploadFlag) {
                    logContent.innerHTML += `<div class="text-amber-400">⚠ Upload cancelled by user.</div>`;
                    break;
                }

                const batch = accumulatedFiles.slice(i, i + batchSize);
                const formData = new FormData();
                
                batch.forEach(file => {
                    formData.append('pdf_files[]', file);
                });
                formData.append('overwrite', shouldOverwrite);

                progressText.textContent = `Uploading batch ${Math.floor(i / batchSize) + 1} of ${Math.ceil(totalFiles / batchSize)}...`;

                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    const data = await response.json();

                    if (data.status === 'success') {
                        if (data.success_files) {
                            data.success_files.forEach(f => {
                                logContent.innerHTML += `<div class="text-emerald-400">✓ SUCCESS: ${f}</div>`;
                            });
                        }
                        if (data.failed_files) {
                            data.failed_files.forEach(f => {
                                logContent.innerHTML += `<div class="text-amber-400">⚠ NOTICE: ${f}</div>`;
                            });
                        }
                    }
                } catch (err) {
                    logContent.innerHTML += `<div class="text-rose-400">✗ Network/Server Error on batch starting at index ${i}</div>`;
                }

                uploadedCount += batch.length;
                let percentage = Math.round((uploadedCount / totalFiles) * 100);
                progressBar.style.width = `${percentage}%`;
                progressPercentage.textContent = `${percentage}%`;
                logContainer.scrollTop = logContainer.scrollHeight;
            }

            isUploading = false;
            stopUploadBtn.classList.add('hidden');
            stopUploadBtn.removeAttribute('disabled');
            progressText.textContent = 'Upload sequence completed! Refreshing view...';
            uploadBtn.innerHTML = '<span>🚀</span><span>Start Batch Upload</span>';
            clearBtn.removeAttribute('disabled');
            accumulatedFiles = [];
            updateQueueUI();
            setTimeout(() => { window.location.reload(); }, 1500);
        });
    }

    // Dispatch & Preview JS
    const selectAll = document.getElementById('selectAll');
    const sendSelectedBtn = document.getElementById('sendSelectedBtn');
    const downloadReportBtn = document.getElementById('downloadReportBtn');
    const emailLogContainer = document.getElementById('emailLogContainer');
    const emailLogContent = document.getElementById('emailLogContent');
    let lastDispatchResults = [];

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.file-checkbox:not(:disabled)').forEach(cb => cb.checked = this.checked);
        });
    }

    async function openPreview(filename) {
        const modal = document.getElementById('previewModal');
        const iframe = document.getElementById('previewIframe');
        modal.classList.remove('hidden');
        iframe.srcdoc = '<div style="padding: 20px; text-align: center; font-family: sans-serif; color: #64748b;">Loading email preview...</div>';

        const activeDateFolder = document.getElementById('dateFolderSelector').value;
        const formData = new FormData();
        formData.append('action', 'preview_email');
        formData.append('filename', filename);
        formData.append('date_folder', activeDateFolder);

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.status === 'success') {
                iframe.srcdoc = data.html;
            } else {
                iframe.srcdoc = `<div style="padding: 20px; text-align: center; font-family: sans-serif; color: #ef4444;">Error: ${data.message}</div>`;
            }
        } catch (err) {
            iframe.srcdoc = `<div style="padding: 20px; text-align: center; font-family: sans-serif; color: #ef4444;">Failed to load preview.</div>`;
        }
    }

    function closePreview() {
        document.getElementById('previewModal').classList.add('hidden');
    }

    if (sendSelectedBtn) {
        sendSelectedBtn.addEventListener('click', async function() {
            const selectedFiles = [];
            document.querySelectorAll('.file-checkbox:checked').forEach(cb => {
                selectedFiles.push(cb.value);
            });

            if (selectedFiles.length === 0) {
                alert('Please select at least one file with a registered email address.');
                return;
            }

            if (!confirm(`Are you sure you want to send statements of account emails for ${selectedFiles.length} file(s)?`)) {
                return;
            }

            sendSelectedBtn.setAttribute('disabled', 'true');
            sendSelectedBtn.textContent = 'Sending Emails...';
            emailLogContainer.classList.remove('hidden');
            emailLogContent.innerHTML = '';
            lastDispatchResults = [];

            const activeDateFolder = document.getElementById('dateFolderSelector').value;
            const formData = new FormData();
            formData.append('action', 'send_emails');
            formData.append('date_folder', activeDateFolder);
            selectedFiles.forEach(file => {
                formData.append('files[]', file);
            });

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const textResponse = await response.text();
                
                let data;
                try {
                    data = JSON.parse(textResponse);
                } catch (e) {
                    throw new Error("Server returned invalid JSON response.");
                }

                if (data.results) {
                    lastDispatchResults = data.results;
                    data.results.forEach(res => {
                        if (res.status === 'success') {
                            emailLogContent.innerHTML += `<div class="text-emerald-400">✓ ${res.file}: ${res.message}</div>`;
                        } else {
                            emailLogContent.innerHTML += `<div class="text-rose-400">✗ ${res.file}: ${res.message}</div>`;
                        }
                    });
                    downloadReportBtn.classList.remove('hidden');
                }
            } catch (err) {
                emailLogContent.innerHTML += `<div class="text-rose-400">✗ ${err.message}</div>`;
            }

            sendSelectedBtn.removeAttribute('disabled');
            sendSelectedBtn.innerHTML = '<span>📤</span><span>Send Selected Emails</span>';
            emailLogContainer.scrollTop = emailLogContainer.scrollHeight;
        });
    }

    function downloadReportCSV() {
        if (lastDispatchResults.length === 0) return;

        let csvContent = "data:text/csv;charset=utf-8,Filename,Account Number,Recipient Email,Status,Details\r\n";
        lastDispatchResults.forEach(row => {
            let sanitizedMsg = row.message.replace(/,/g, " ");
            csvContent += `"${row.file}","${row.account}","${row.email}","${row.status}","${sanitizedMsg}"\r\n`;
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Email_Dispatch_Report_${document.getElementById('dateFolderSelector').value}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Email Mappings JS
    function editRecord(id, account_number, email_address) {
        switchTab('mappings');
        document.getElementById('recordId').value = id;
        document.getElementById('accountNumber').value = account_number;
        document.getElementById('emailAddress').value = email_address;
        document.getElementById('saveBtn').textContent = 'Update Mapping';
        document.getElementById('cancelBtn').classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('emailForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('saveBtn').textContent = 'Save Mapping';
        document.getElementById('cancelBtn').classList.add('hidden');
    }
    </script>

    <?php
    $content = ob_get_clean();
    if (function_exists('render_layout')) {
        render_layout("Statement Hub & Dispatcher", $content);
    } else {
        echo $content;
    }

} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'results' => [
            ['file' => 'System', 'status' => 'error', 'message' => 'Fatal PHP Error: ' . $e->getMessage() . ' on line ' .$e->getLine()]
        ]
    ]);
}
?>