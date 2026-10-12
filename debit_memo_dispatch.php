<?php
// debit_memo_dispatch.php - Statement Dispatch Module
define('ALLOW_ACCESS', true);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once 'config.php';
    require_once 'main.php';
    require_once 'email_template.php';
    require_once 'pdf_generator.php';

    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }

    $base_upload_dir = __DIR__ . "/uploads/debit_memos/";

    // ==========================================
    // HELPER FUNCTIONS
    // ==========================================

    function parse_billing_period_dates($billing_period) {
        $start_date_sql = '';
        $end_date_sql = '';
        
        if (!empty($billing_period)) {
            // Split specifically by ' - ' or ' to ' without breaking YYYY-MM-DD
            $parts = preg_split('/\s+(?:-|to)\s+/i', trim($billing_period));
            if (count($parts) >= 2) {
                $parsed_start = strtotime(trim($parts[0]));
                $parsed_end   = strtotime(trim($parts[1]));
                if ($parsed_start !== false && $parsed_end !== false) {
                    $start_date_sql = date('Y-m-d', $parsed_start);
                    $end_date_sql   = date('Y-m-d', $parsed_end);
                }
            }
        }
        return [$start_date_sql, $end_date_sql];
    }

    function extract_pdf_billing_details($filepath) {
        $data = ['telco' => 'Unknown', 'amount_due' => '0.00', 'corporate_id' => 'N/A', 'invoice_date' => 'N/A', 'billing_period' => 'N/A', 'mobile_number' => 'N/A', 'invoice_number' => 'N/A', 'credit_limit' => 'N/A', 'due_date' => 'N/A', 'customer_tin' => 'N/A'];
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
        } catch (Exception $e) { $text = @file_get_contents($filepath); }

        $clean_text = preg_replace('/[\|\t\r]+/', ' ', $text);
        $clean_text = preg_replace('/\s+/', ' ', $clean_text);

        if (stripos($text, 'Smart') !== false || stripos($text, 'SMTBI') !== false) {
            $data['telco'] = 'Smart';
            if (preg_match('/Billing\s*Period\s*([A-Za-z0-9,\s\-]+)/i', $clean_text, $m)) $data['billing_period'] = trim($m[1]);
            if (preg_match('/TOTAL\s+AMOUNT\s+DUE[^\d\-]*?(-?\s*[0-9,]+\.[0-9]{2})/i', $clean_text, $m)) {
                $raw_val = floatval(str_replace([' ', ','], '', $m[1]));
                $data['amount_due'] = 'PHP ' . number_format($raw_val, 2);
            }
        } elseif (stripos($text, 'Globe') !== false || stripos($text, 'CTGI') !== false) {
            $data['telco'] = 'Globe';
            if (preg_match('/Amount\s*to\s*Pay[^\d\-]*(-?\s*[0-9,]+\.[0-9]{2})/i', $clean_text, $m)) {
                $raw_val = floatval(str_replace([' ', ','], '', $m[1]));
                $data['amount_due'] = 'Php ' . number_format($raw_val, 2);
            }
        }
        return $data;
    }

    function resolve_mobile_number($pdo, $account_number, $current_mobile) {
        if (!empty($current_mobile) && trim($current_mobile) !== '' && strtoupper(trim($current_mobile)) !== 'N/A') {
            return trim($current_mobile);
        }
        
        if ($pdo && !empty($account_number) && trim($account_number) !== 'N/A') {
            try {
                $stmt = $pdo->prepare("
                    SELECT mobile_number 
                    FROM account_emails 
                    WHERE TRIM(account_number) = TRIM(?) 
                      AND mobile_number IS NOT NULL 
                      AND TRIM(mobile_number) != '' 
                      AND UPPER(TRIM(mobile_number)) != 'N/A'
                    ORDER BY id DESC 
                    LIMIT 1
                ");
                $stmt->execute([$account_number]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['mobile_number'])) {
                        return trim($row['mobile_number']);
                    }
                }
            } catch (Exception $e) {}
        }
        
        return !empty($current_mobile) && strtoupper(trim($current_mobile)) !== 'N/A' ? $current_mobile : 'N/A';
    }

    // HELPER TO CALCULATE EXACT SUMMARY METRICS FROM DEBIT MEMO ITEMS
    function get_debit_memo_summary_metrics($pdo, $dm_id, $start_date = '', $end_date = '') {
        $metrics = [
            'current_charges' => '₱ 0.00',
            'approved_plan'   => '₱ 0.00',
            'final_dm'        => '0.00',
            'has_dm_items'    => false,
            'coverage_period' => 'As of current billing'
        ];

        if (!$pdo || empty($dm_id) || $dm_id <= 0) {
            return $metrics;
        }

        try {
            $itemQuery = "SELECT * FROM debit_memo_items WHERE dm_id = ?";
            $itemParams = [$dm_id];

            // STRICT FILTER BY SELECTED COVERAGE RANGE
            if (!empty($start_date) && !empty($end_date)) {
                $itemQuery .= " AND coverage_start >= ? AND coverage_end <= ?";
                $itemParams[] = $start_date;
                $itemParams[] = $end_date;
            }
            $itemQuery .= " ORDER BY coverage_start ASC";

            $stmtItems = $pdo->prepare($itemQuery);
            $stmtItems->execute($itemParams);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $total_final_dm = 0.00;
            $total_current_charges = 0.00;
            $total_approved_plan = 0.00;
            $raw_start_dates = [];
            $raw_end_dates = [];

            if (!empty($items)) {
                $metrics['has_dm_items'] = true;
                foreach ($items as $it) {
                    $curr_v = isset($it['current_charges']) ? (float)$it['current_charges'] : 0.00;
                    $total_current_charges += $curr_v;

                    $dm_v = isset($it['debit_memo_details']) ? (float)$it['debit_memo_details'] : 0.00;
                    $ao_v = isset($it['add_ons']) ? (float)$it['add_ons'] : 0.00;
                    $final_val = isset($it['final_dm']) ? (float)$it['final_dm'] : ($dm_v - $ao_v);
                    $total_final_dm += $final_val;

                    if (isset($it['approved_plan'])) {
                        $total_approved_plan += (float)$it['approved_plan'];
                    }

                    if (!empty($it['coverage_start'])) $raw_start_dates[] = $it['coverage_start'];
                    if (!empty($it['coverage_end'])) $raw_end_dates[] = $it['coverage_end'];
                }

                if (!empty($raw_start_dates) && !empty($raw_end_dates)) {
                    $earliest_start = date('M d, Y', strtotime(min($raw_start_dates)));
                    $latest_end = date('M d, Y', strtotime(max($raw_end_dates)));
                    $metrics['coverage_period'] = "{$earliest_start} - {$latest_end}";
                }
            }

            $metrics['current_charges'] = '₱ ' . number_format($total_current_charges, 2, '.', ',');
            $metrics['approved_plan']   = '₱ ' . number_format($total_approved_plan, 2, '.', ',');
            $metrics['final_dm']        = number_format($total_final_dm, 2, '.', ',');
        } catch (Exception $e) {}

        return $metrics;
    }

    // MULTI-ATTACHMENT SMTP DISPATCHER
    function send_smtp_mail_with_multi_attachments($to, $subject, $attachments, $html_content, $cc = '') {
        $smtp_host = 'tcp://smtp.gmail.com'; 
        $smtp_port = 587;                    
        $auth_user = 'itnotifications@bounty.com.ph';
        $auth_pass = str_replace(' ', '', 'qvnk odfr gubq idac'); 
        $from_email = 'telcoreports@bounty.com.ph';
        $from_name  = 'Admin Telco';
        $boundary = md5(time());

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_name} <{$from_email}>\r\n";
        $headers .= "To: {$to}\r\n";
        if (!empty($cc)) $headers .= "Cc: {$cc}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= "{$html_content}\r\n\r\n";
        
        foreach ($attachments as $att) {
            if (!empty($att['path']) && file_exists($att['path']) && filesize($att['path']) > 50) {
                $fileData = chunk_split(base64_encode(file_get_contents($att['path'])));
                $type = $att['type'] ?? 'application/pdf';
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Type: {$type}; name=\"{$att['name']}\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n\r\n";
                $body .= "{$fileData}\r\n\r\n";
            }
        }
        $body .= "--{$boundary}--";

        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
        if (!is_resource($socket)) return "Connection failed: $errstr";
        
        fgets($socket, 512);
        $run_cmd = function($cmd, $code) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
            $res = ''; 
            while ($s = fgets($socket, 512)) { 
                $res .= $s; 
                if (substr($s, 3, 1) == ' ') break; 
            }
            return (substr($res, 0, 3) == $code);
        };

        if (!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250) || !$run_cmd("STARTTLS", 220)) return "TLS handshake error";
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        
        if ((!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250)) || 
            !$run_cmd("AUTH LOGIN", 334) || 
            !$run_cmd(base64_encode($auth_user), 334) || 
            !$run_cmd(base64_encode($auth_pass), 235) || 
            !$run_cmd("MAIL FROM: <{$from_email}>", 250)) {
            return "SMTP Auth/Command error";
        }
        
        $all_recipients = explode(',', $to);
        if (!empty($cc)) {
            $all_recipients = array_merge($all_recipients, explode(',', $cc));
        }

        foreach ($all_recipients as $recipient) {
            $recipient = trim($recipient);
            if (!empty($recipient)) {
                @$run_cmd("RCPT TO: <{$recipient}>", 250);
            }
        }

        if (!$run_cmd("DATA", 354)) return "DATA command error";
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $result = fgets($socket, 512); 
        $run_cmd("QUIT", 221);
        fclose($socket);

        return (substr($result, 0, 3) == '250') ? true : "Failed: " . trim($result);
    }

    function send_dispatch_report_to_admin($all_results_items) {
        if (empty($all_results_items)) return;
        global $pdo;

        $admin_emails = [];
        $sender_fullname = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'System Administrator';

        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT recipient_email FROM email_report WHERE report_type = ?");
                $stmt->execute(['statement_dispatch']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        if (!empty($row['recipient_email'])) {
                            $admin_emails[] = trim($row['recipient_email']);
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        
        if (empty($admin_emails)) return;

        $current_date = date('Y-m-d H:i:s');
        $subject = "Statement Dispatch Report (Success & Failed) - " . date('Y-m-d');

        $csv_filename = "dispatch_report_" . date('Ymd_His') . ".csv";
        $csv_filepath = sys_get_temp_dir() . '/' . $csv_filename;
        
        $fp = fopen($csv_filepath, 'w');
        fputcsv($fp, ['Timestamp', 'Status', 'Account Number', 'Telco', 'Mobile Number', 'Email', 'Billing Period', 'Total Charge', 'Filename', 'PDF Link', 'Sent By', 'Message']);
        
        $total_count = count($all_results_items);
        $success_count = 0;
        $failed_count = 0;

        foreach ($all_results_items as $item) {
            if (strtolower($item['status']) === 'success') {
                $success_count++;
            } else {
                $failed_count++;
            }

            fputcsv($fp, [
                $item['date'],
                strtoupper($item['status']),
                $item['account'],
                $item['telco'] ?? 'Unknown',
                $item['mobile_number'] ?? 'N/A',
                $item['email'],
                $item['billing_period'],
                $item['total_charge'],
                $item['filename'],
                $item['file_link'],
                $sender_fullname,
                $item['message']
            ]);
        }
        fclose($fp);

        $html_body  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: Arial, sans-serif; color: #333333; line-height: 1.5; padding: 20px; background-color: #f8fafc;">';
        $html_body .= '<div style="max-width: 800px; margin: 0 auto; background: #ffffff; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0;">';
        $html_body .= '<p style="font-size: 14px; margin-bottom: 10px; color: #475569;">Good day Ma\'am/Sir,</p>';
        $html_body .= '<h2 style="color: #1e40af; margin-top: 0; margin-bottom: 6px; font-size: 20px;">Statement Email Dispatch Report</h2>';
        $html_body .= '<p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 20px;">Here is the summary of the email dispatches conducted on <strong>' . htmlspecialchars($current_date) . '</strong> by <strong>' . htmlspecialchars($sender_fullname) . '</strong>.</p>';

        $html_body .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;"><tr>';
        $html_body .= '<td style="padding: 10px; background: #eff6ff; border: 1px solid #bfdbfe; text-align: center; border-radius: 6px;"><div style="font-size: 11px; color: #1e40af; font-weight: bold;">TOTAL PROCESSED</div><div style="font-size: 18px; color: #1e3a8a; font-weight: bold;">' . $total_count . '</div></td><td style="width: 10px;"></td>';
        $html_body .= '<td style="padding: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; text-align: center; border-radius: 6px;"><div style="font-size: 11px; color: #047857; font-weight: bold;">SUCCESSFUL</div><div style="font-size: 18px; color: #065f46; font-weight: bold;">' . $success_count . '</div></td><td style="width: 10px;"></td>';
        $html_body .= '<td style="padding: 10px; background: #fef2f2; border: 1px solid #fecaca; text-align: center; border-radius: 6px;"><div style="font-size: 11px; color: #b91c1c; font-weight: bold;">FAILED</div><div style="font-size: 18px; color: #991b1b; font-weight: bold;">' . $failed_count . '</div></td>';
        $html_body .= '</tr></table>';

        $html_body .= '<h3 style="font-size: 14px; color: #334155; margin-bottom: 8px;">Dispatch Details Breakdown</h3>';
        $html_body .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px; border: 1px solid #cbd5e1;"><thead><tr style="background-color: #f1f5f9;">';
        $html_body .= '<th style="padding: 8px; border: 1px solid #cbd5e1;">Status</th><th style="padding: 8px; border: 1px solid #cbd5e1;">Account No.</th><th style="padding: 8px; border: 1px solid #cbd5e1;">Telco</th><th style="padding: 8px; border: 1px solid #cbd5e1;">Email</th><th style="padding: 8px; border: 1px solid #cbd5e1;">Final DM</th><th style="padding: 8px; border: 1px solid #cbd5e1;">Message</th></tr></thead><tbody>';

        foreach ($all_results_items as $item) {
            $is_ok = (strtolower($item['status']) === 'success');
            $html_body .= '<tr>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1; text-align: center;"><span style="background:' . ($is_ok ? '#d1fae5' : '#fee2e2') . '; color:' . ($is_ok ? '#065f46' : '#991b1b') . '; font-weight:bold; padding:2px 6px; border-radius:4px;">' . ($is_ok ? 'SUCCESS' : 'FAILED') . '</span></td>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1; font-weight:bold;">' . htmlspecialchars($item['account']) . '</td>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1;">' . htmlspecialchars($item['telco'] ?? 'N/A') . '</td>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1;">' . htmlspecialchars($item['email']) . '</td>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1; font-weight:bold;">' . htmlspecialchars($item['total_charge']) . '</td>';
            $html_body .= '<td style="padding: 6px; border: 1px solid #cbd5e1;">' . htmlspecialchars($item['message']) . '</td>';
            $html_body .= '</tr>';
        }
        $html_body .= '</tbody></table></div></body></html>';

        $admin_attachments = [['path' => $csv_filepath, 'name' => $csv_filename, 'type' => 'text/csv']];
        foreach ($admin_emails as $single_admin_email) {
            $single_admin_email = trim($single_admin_email);
            if (!empty($single_admin_email)) {
                send_smtp_mail_with_multi_attachments($single_admin_email, $subject, $admin_attachments, $html_body);
            }
        }
        if (file_exists($csv_filepath)) @unlink($csv_filepath);
    }

    function get_or_download_pdf_path($filename, $pdo = null) {
        $filename = basename($filename);
        $temp_file_path = sys_get_temp_dir() . '/' . md5($filename) . '.pdf';
        
        if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) {
            return $temp_file_path;
        }

        usleep(500000); 
        
        if ($pdo) {
            try {
                $stmtFile = $pdo->prepare("SELECT file_link FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                $stmtFile->execute([$filename]);
                if ($fRow = $stmtFile->fetch(PDO::FETCH_ASSOC)) {
                    $link = $fRow['file_link'] ?? '';
                    if (!empty($link)) {
                        $gdrive_id = '';
                        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $link, $m)) $gdrive_id = $m[1];
                        elseif (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $link, $m)) $gdrive_id = $m[1];
                        
                        if (!empty($gdrive_id)) {
                            $download_url = "https://drive.google.com/uc?export=download&id=" . $gdrive_id;
                            
                            $options = [
                                "http" => [
                                    "method" => "GET",
                                    "header" => "User-Agent: Mozilla/5.0\r\n",
                                    "follow_location" => 1,
                                    "max_redirects" => 5
                                ],
                                "ssl" => [
                                    "verify_peer" => false,
                                    "verify_peer_name" => false
                                ]
                            ];
                            $context = stream_context_create($options);
                            $response = @file_get_contents($download_url, false, $context);
                            
                            if ($response !== false && (stripos($response, 'confirm=') !== false || stripos($response, 'download-warning') !== false)) {
                                if (preg_match('/confirm=([a-zA-Z0-9_\-]+)/', $response, $matches)) {
                                    $confirm_url = "https://drive.google.com/uc?export=download&confirm=" . $matches[1] . "&id=" . $gdrive_id;
                                    $response = @file_get_contents($confirm_url, false, $context);
                                }
                            }
                            
                            if (!empty($response) && strlen($response) > 500 && stripos($response, '<html') === false) {
                                file_put_contents($temp_file_path, $response);
                                if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) {
                                    return $temp_file_path;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        return '';
    }

    // ==========================================
    // AJAX REQUEST HANDLERS
    // ==========================================

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_periods_by_telco') {
        header('Content-Type: application/json');
        $telco = $_POST['telco'] ?? '';
        $periods = [];
        $companies = [];
        if (isset($pdo)) {
            try {
                // Fetch Periods
                $sql = "SELECT DISTINCT billing_period FROM pdf_extracted_details WHERE billing_period IS NOT NULL AND billing_period != ''";
                $params = [];
                if (!empty($telco)) {
                    $sql .= " AND telco = ?";
                    $params[] = $telco;
                }
                $sql .= " ORDER BY billing_period DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $periods[] = $row['billing_period'];
                }

                // Fetch Companies
                $sqlComp = "SELECT DISTINCT d.company FROM debit_memos d JOIN pdf_extracted_details p ON TRIM(p.account_number) = TRIM(d.account_number) WHERE d.company IS NOT NULL AND d.company != ''";
                $paramsComp = [];
                if (!empty($telco)) {
                    $sqlComp .= " AND p.telco = ?";
                    $paramsComp[] = $telco;
                }
                $sqlComp .= " ORDER BY d.company ASC";
                $stmtComp = $pdo->prepare($sqlComp);
                $stmtComp->execute($paramsComp);
                while ($cRow = $stmtComp->fetch(PDO::FETCH_ASSOC)) {
                    $companies[] = $cRow['company'];
                }
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success', 'periods' => $periods, 'companies' => $companies]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_single_pdf') {
        $filename = basename($_POST['filename'] ?? '');
        if (!empty($filename)) {
            $filepath = get_or_download_pdf_path($filename, $pdo ?? null);
            if (!empty($filepath) && file_exists($filepath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));
                flush();
                readfile($filepath);
                exit;
            }
        }
        header('HTTP/1.1 404 Not Found');
        echo "File not found.";
        exit;
    }

    // PREVIEW_EMAIL HANDLER
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview_email') {
        header('Content-Type: application/json');
        $filename = basename($_POST['filename'] ?? '');
        $selected_bp = trim($_POST['billing_period'] ?? '');
        
        $account_number = 'N/A'; $company = 'N/A'; $email_address = ''; $mobile_number = 'N/A';
        $recipient_name = 'N/A'; $employee_id = 'N/A';
        $billing_period = !empty($selected_bp) ? $selected_bp : 'As of current billing';
        $start_date_sql = '';
        $end_date_sql = '';
        $dm_id = 0;

        if (isset($pdo)) {
            try {
                $stmtDetails = $pdo->prepare("SELECT * FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                $stmtDetails->execute([$filename]);
                if ($detRow = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                    $account_number = $detRow['account_number'] ?? 'N/A';
                    $raw_mobile = $detRow['mobile_number'] ?? 'N/A';
                    $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);

                    if (empty($selected_bp) && !empty($detRow['billing_period'])) {
                        $billing_period = $detRow['billing_period'];
                    }
                }

                // Parse dates using regex helper
                list($start_date_sql, $end_date_sql) = parse_billing_period_dates($billing_period);

                $stmtDM = $pdo->prepare("SELECT dm_id, company FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                $stmtDM->execute([$account_number]);
                if ($dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC)) {
                    $company = $dmRow['company'] ?? 'N/A';
                    $dm_id = $dmRow['dm_id'] ?? 0;
                }

                $stmtEmail = $pdo->prepare("SELECT * FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                $stmtEmail->execute([$account_number]);
                if ($emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                    $email_address = $emailRow['email_address'] ?? '';
                    $recipient_name = !empty(trim($emailRow['full_name'])) ? trim($emailRow['full_name']) : ((!empty($company) && $company !== 'N/A') ? $company : 'Valued Customer');
                    $employee_id = !empty(trim($emailRow['employee_id'])) ? trim($emailRow['employee_id']) : '';
                } else {
                    $recipient_name = (!empty($company) && $company !== 'N/A') ? $company : 'Valued Customer';
                }

                if (empty($employee_id) || $employee_id === 'N/A') {
                    $employee_id = (!empty($mobile_number) && $mobile_number !== 'N/A') ? $mobile_number : $account_number;
                }
            } catch (Exception $e) {}
        }

        $summary_metrics = get_debit_memo_summary_metrics($pdo, $dm_id, $start_date_sql, $end_date_sql);
        $approved_plan    = $summary_metrics['approved_plan'];
        $current_charges  = $summary_metrics['current_charges'];
        $final_dm         = $summary_metrics['final_dm'];
        if ($summary_metrics['coverage_period'] !== 'As of current billing') {
            $billing_period = $summary_metrics['coverage_period'];
        }

        $generated_dm_pdf_name = ($dm_id > 0) ? 'Debit_Memo_' . $account_number . '.pdf' : '';

        $pdf_filepath = '';
        $local_path = __DIR__ . "/uploads/debit_memos/" . $filename;
        if (file_exists($local_path)) {
            $pdf_filepath = $local_path;
        } else {
            $pdf_filepath = get_or_download_pdf_path($filename, $pdo ?? null);
        }

        $html_preview = '';
        $email_subject = '';
        if (function_exists('getEmailTemplate')) {
            $template = getEmailTemplate($account_number, $company, $recipient_name, $employee_id, $mobile_number, $billing_period, $approved_plan, $current_charges, $final_dm, $pdf_filepath);
            $html_preview = $template['body'];
            $email_subject = $template['subject'];
        } else {
            $html_preview = "Template not found.";
            $email_subject = "Statement of Account - " . $account_number;
        }

        echo json_encode([
            'status' => 'success', 
            'html' => $html_preview, 
            'subject' => $email_subject,
            'email' => $email_address,
            'attachment_name' => $filename,
            'generated_dm_pdf' => $generated_dm_pdf_name
        ]);
        exit;
    }

    // SEND_EMAILS HANDLER
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_emails') {
        header('Content-Type: application/json');
        $items = $_POST['items'] ?? [];
        $billing_period = $_POST['billing_period'] ?? '';
        $cc_emails = $_POST['cc'] ?? '';
        $custom_subject = $_POST['subject'] ?? '';
        $results = [];
        $all_report_items = [];

        foreach ($items as $item) {
            $filename = basename($item['filename'] ?? '');
            $email_address = trim($item['email'] ?? '');
            $current_timestamp = date('Y-m-d H:i:s');
            
            if (empty($filename)) continue;

            $filepath = '';
            $local_path = __DIR__ . "/uploads/debit_memos/" . $filename;
            if (file_exists($local_path)) {
                $filepath = $local_path;
            } else {
                $filepath = get_or_download_pdf_path($filename, $pdo ?? null);
            }

            $account_number = 'N/A'; $company = 'N/A'; $mobile_number = 'N/A';
            $recipient_name = 'N/A'; $employee_id = 'N/A';
            $file_link = '';
            $telco_val = 'Unknown';
            $actual_billing_period = $billing_period;
            $start_date_sql = '';
            $end_date_sql = '';
            $dm_id = 0;

            try {
                if (isset($pdo)) {
                    $stmtDetails = $pdo->prepare("SELECT account_number, billing_period, amount_due, telco, file_link, mobile_number FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                    $stmtDetails->execute([$filename]);
                    if ($detRow = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                        $account_number = $detRow['account_number'] ?? 'N/A';
                        $telco_val = $detRow['telco'] ?? 'Unknown';
                        $raw_mobile = $detRow['mobile_number'] ?? 'N/A';
                        $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);

                        if (empty($actual_billing_period) && !empty($detRow['billing_period'])) {
                            $actual_billing_period = $detRow['billing_period'];
                        }
                        $file_link = $detRow['file_link'] ?? '';
                    }

                    list($start_date_sql, $end_date_sql) = parse_billing_period_dates($actual_billing_period);

                    $stmtDM = $pdo->prepare("SELECT dm_id, company FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtDM->execute([$account_number]);
                    if ($dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC)) {
                        $company = $dmRow['company'] ?? 'N/A';
                        $dm_id = $dmRow['dm_id'] ?? 0;
                    }

                    $stmtEmail = $pdo->prepare("SELECT * FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtEmail->execute([$account_number]);
                    if ($emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                        if (empty($email_address)) {
                            $email_address = $emailRow['email_address'] ?? '';
                        }
                        $recipient_name = !empty(trim($emailRow['full_name'])) ? trim($emailRow['full_name']) : ((!empty($company) && $company !== 'N/A') ? $company : 'Valued Customer');
                        $employee_id = !empty(trim($emailRow['employee_id'])) ? trim($emailRow['employee_id']) : '';
                    } else {
                        $recipient_name = (!empty($company) && $company !== 'N/A') ? $company : 'Valued Customer';
                    }

                    if (empty($employee_id) || $employee_id === 'N/A') {
                        $employee_id = (!empty($mobile_number) && $mobile_number !== 'N/A') ? $mobile_number : $account_number;
                    }
                }
            } catch (Exception $e) {}

            $summary_metrics = get_debit_memo_summary_metrics($pdo, $dm_id, $start_date_sql, $end_date_sql);
            $approved_plan    = $summary_metrics['approved_plan'];
            $current_charges  = $summary_metrics['current_charges'];
            $final_dm         = $summary_metrics['final_dm'];
            if ($summary_metrics['coverage_period'] !== 'As of current billing') {
                $actual_billing_period = $summary_metrics['coverage_period'];
            }
            $formatted_total_charge = 'PHP ' . $final_dm;

            if (empty($email_address)) {
                $err_msg = "No target email provided.";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => 'None', 'status' => 'error', 'message' => $err_msg];
                $all_report_items[] = [
                    'date' => $current_timestamp, 'status' => 'error', 'account' => $account_number, 'telco' => $telco_val, 'mobile_number' => $mobile_number, 'email' => 'None', 'billing_period' => $actual_billing_period, 'total_charge' => $formatted_total_charge, 'filename' => $filename, 'file_link' => $file_link, 'message' => $err_msg
                ];
                continue;
            }

            // ATTACHMENTS PREPARATION: GENERATED DEBIT MEMO PDF + SOA FILE
            $attachments = [];
            $dm_pdf_temp_path = '';

            if (!empty($dm_id) && $dm_id > 0 && function_exists('createDebitMemoPDF')) {
                $pdf_result = createDebitMemoPDF($dm_id, $pdo, [], $start_date_sql, $end_date_sql);
                if (is_array($pdf_result) && count($pdf_result) == 2) {
                    $pdf_obj = $pdf_result[0];
                    $acc_num = $pdf_result[1];
                    $dm_pdf_temp_path = tempnam(sys_get_temp_dir(), 'dm_pdf_');
                    $pdf_obj->Output('F', $dm_pdf_temp_path);
                    $attachments[] = [
                        'path' => $dm_pdf_temp_path,
                        'name' => 'Debit_Memo_' . $acc_num . '.pdf',
                        'type' => 'application/pdf'
                    ];
                }
            }

            if (!empty($filepath) && file_exists($filepath) && filesize($filepath) > 100) {
                $attachments[] = [
                    'path' => $filepath,
                    'name' => basename($filepath),
                    'type' => 'application/pdf'
                ];
            }

            if (empty($attachments)) {
                $err_msg = "Neither Generated Debit Memo PDF nor SOA file could be found.";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'error', 'message' => $err_msg];
                $all_report_items[] = [
                    'date' => $current_timestamp, 'status' => 'error', 'account' => $account_number, 'telco' => $telco_val, 'mobile_number' => $mobile_number, 'email' => $email_address, 'billing_period' => $actual_billing_period, 'total_charge' => $formatted_total_charge, 'filename' => $filename, 'file_link' => $file_link, 'message' => $err_msg
                ];
                if (!empty($dm_pdf_temp_path) && file_exists($dm_pdf_temp_path)) @unlink($dm_pdf_temp_path);
                continue;
            }

            $template = getEmailTemplate($account_number, $company, $recipient_name, $employee_id, $mobile_number, $actual_billing_period, $approved_plan, $current_charges, $final_dm, $filepath);
            $html_content = $template['body'];
            $subject = !empty($custom_subject) ? $custom_subject : $template['subject'];
            
            $mail_status = send_smtp_mail_with_multi_attachments($email_address, $subject, $attachments, $html_content, $cc_emails);
            
            if (!empty($dm_pdf_temp_path) && file_exists($dm_pdf_temp_path)) @unlink($dm_pdf_temp_path);

            if ($mail_status === true) {
                $success_msg = "Sent to {$email_address}";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'success', 'message' => $success_msg];
                $all_report_items[] = [
                    'date' => $current_timestamp, 'status' => 'success', 'account' => $account_number, 'telco' => $telco_val, 'mobile_number' => $mobile_number, 'email' => $email_address, 'billing_period' => $actual_billing_period, 'total_charge' => $formatted_total_charge, 'filename' => $filename, 'file_link' => $file_link, 'message' => $success_msg
                ];
            } else {
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'error', 'message' => $mail_status];
                $all_report_items[] = [
                    'date' => $current_timestamp, 'status' => 'error', 'account' => $account_number, 'telco' => $telco_val, 'mobile_number' => $mobile_number, 'email' => $email_address, 'billing_period' => $actual_billing_period, 'total_charge' => $formatted_total_charge, 'filename' => $filename, 'file_link' => $file_link, 'message' => $mail_status
                ];
            }
             sleep(2);
        }

        echo json_encode(['status' => 'completed', 'results' => $results, 'report_items' => $all_report_items]);
        exit;
       
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_summary_report') {
        header('Content-Type: application/json');
        $results_summary_json = $_POST['results_summary'] ?? '[]';
        $all_report_items = json_decode($results_summary_json, true);

        if (!empty($all_report_items) && is_array($all_report_items)) {
            send_dispatch_report_to_admin($all_report_items);
            echo json_encode(['status' => 'success', 'message' => 'Summary report sent successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No items found for summary report.']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_email_reports') {
        header('Content-Type: application/json');
        $reports = [];
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT id, full_name, recipient_email as email FROM email_report WHERE report_type = 'statement_dispatch' ORDER BY id DESC");
                $stmt->execute();
                $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success', 'reports' => $reports]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email_report') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'Recipient email is required.']);
            exit;
        }

        if (isset($pdo)) {
            try {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE email_report SET full_name = ?, recipient_email = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO email_report (report_type, full_name, recipient_email) VALUES ('statement_dispatch', ?, ?)");
                    $stmt->execute([$full_name, $email]);
                }
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_email_report') {
        header('Content-Type: application/json');
        $id = $_POST['id'] ?? '';
        if (!empty($id) && isset($pdo)) {
            try {
                $stmt = $pdo->prepare("DELETE FROM email_report WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        }
        exit;
    }

    $available_telcos = ['Smart', 'Globe'];
    $available_billing_periods = [];
    $available_companies = [];

    $selected_telco = $_GET['telco'] ?? '';
    $selected_billing_period = $_GET['billing_period'] ?? '';
    $selected_company = $_GET['company'] ?? '';
    $search_query = trim($_GET['search'] ?? '');

    $sort_by = $_GET['sort'] ?? 'filename';
    $sort_dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    $allowed_sort_columns = [
        'filename' => 'p.filename',
        'provider' => 'p.telco',
        'account_number' => 'p.account_number',
        'amount_due' => 'p.amount_due',
        'company' => 'd.company',
        'email_address' => 'e.email_address'
    ];
    $sql_sort_column = $allowed_sort_columns[$sort_by] ?? 'p.filename';

    if (isset($pdo)) {
        try {
            // Fetch Billing Periods
            $sqlBP = "SELECT DISTINCT billing_period FROM pdf_extracted_details WHERE billing_period IS NOT NULL AND billing_period != ''";
            $paramsBP = [];
            if (!empty($selected_telco)) {
                $sqlBP .= " AND telco = ?";
                $paramsBP[] = $selected_telco;
            }
            $sqlBP .= " ORDER BY billing_period DESC";
            $stmtBP = $pdo->prepare($sqlBP);
            $stmtBP->execute($paramsBP);
            while ($bpRow = $stmtBP->fetch(PDO::FETCH_ASSOC)) {
                $available_billing_periods[] = $bpRow['billing_period'];
            }

            // Fetch Companies
            $sqlC = "SELECT DISTINCT d.company FROM debit_memos d JOIN pdf_extracted_details p ON TRIM(p.account_number) = TRIM(d.account_number) WHERE d.company IS NOT NULL AND d.company != ''";
            $paramsC = [];
            if (!empty($selected_telco)) {
                $sqlC .= " AND p.telco = ?";
                $paramsC[] = $selected_telco;
            }
            $sqlC .= " ORDER BY d.company ASC";
            $stmtC = $pdo->prepare($sqlC);
            $stmtC->execute($paramsC);
            while ($cRow = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                $available_companies[] = $cRow['company'];
            }
        } catch (Exception $e) {}
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $raw_limit = $_GET['limit'] ?? 25;
    $is_all = ($raw_limit === 'all');
    $limit = $is_all ? 0 : intval($raw_limit);
    if (!$is_all && !in_array($limit, [10, 25, 50, 100])) $limit = 25;

    $pdf_files = []; 
    $total_files_count = 0;
    $total_pages = 1;

    if (isset($pdo)) {
        $whereClauses = [];
        $params = [];

        if (!empty($selected_telco)) {
            $whereClauses[] = "p.telco = ?";
            $params[] = $selected_telco;
        }
        if (!empty($selected_billing_period)) {
            $whereClauses[] = "p.billing_period = ?";
            $params[] = $selected_billing_period;
        }
        if (!empty($selected_company)) {
            $whereClauses[] = "d.company = ?";
            $params[] = $selected_company;
        }
        if (!empty($search_query)) {
            $whereClauses[] = "(p.filename LIKE ? OR p.account_number LIKE ? OR p.mobile_number LIKE ? OR d.company LIKE ? OR e.email_address LIKE ?)";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
        }

        $sqlWhere = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";
        $sqlJoins = "FROM pdf_extracted_details p LEFT JOIN debit_memos d ON TRIM(p.account_number) = TRIM(d.account_number) LEFT JOIN account_emails e ON TRIM(p.account_number) = TRIM(e.account_number)";

        $stmtCount = $pdo->prepare("SELECT COUNT(p.id) {$sqlJoins} {$sqlWhere}");
        $stmtCount->execute($params);
        $total_files_count = $stmtCount->fetchColumn();

        $orderByClause = "ORDER BY {$sql_sort_column} {$sort_dir}";

        if ($is_all) {
            $total_pages = 1;
            $page = 1;
            $stmtRecords = $pdo->prepare("SELECT p.*, d.dm_id, d.company, d.assignee_name, e.email_address AS registered_email {$sqlJoins} {$sqlWhere} {$orderByClause}");
            $stmtRecords->execute($params);
        } else {
            $total_pages = max(1, ceil($total_files_count / $limit));
            if ($page > $total_pages) $page = $total_pages;
            $offset = ($page - 1) * $limit;
            $stmtRecords = $pdo->prepare("SELECT p.*, d.dm_id, d.company, d.assignee_name, e.email_address AS registered_email {$sqlJoins} {$sqlWhere} {$orderByClause} LIMIT {$limit} OFFSET {$offset}");
            $stmtRecords->execute($params);
        }

        $db_records = $stmtRecords->fetchAll(PDO::FETCH_ASSOC);

        foreach ($db_records as $rec) {
            $filename = $rec['filename'];
            $account_number = $rec['account_number'] ?? 'N/A';
            $raw_mobile = $rec['mobile_number'] ?? 'N/A';
            $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);
            $telco = $rec['telco'] ?? 'Unknown';
            $dm_id = $rec['dm_id'] ?? 0;

            // Strict date range parsing from selected billing period or record billing period
            $rec_bp = !empty($selected_billing_period) ? $selected_billing_period : ($rec['billing_period'] ?? '');
            list($start_date_sql, $end_date_sql) = parse_billing_period_dates($rec_bp);

            $summary_metrics = get_debit_memo_summary_metrics($pdo, $dm_id, $start_date_sql, $end_date_sql);
            
            $pdf_files[] = [
                'filename' => $filename,
                'file_link' => $rec['file_link'] ?? '',
                'account_number' => $account_number,
                'mobile_number' => $mobile_number,
                'company' => $rec['company'] ?? 'Unknown',
                'assignee_name' => $rec['assignee_name'] ?? 'Unassigned',
                'email_address' => $rec['registered_email'] ?? '',
                'telco' => $telco,
                'dm_id' => $dm_id,
                'final_dm' => 'PHP ' . $summary_metrics['final_dm'],
                'has_dm_items' => $summary_metrics['has_dm_items']
            ];
        }
    }

    function render_sort_th($column_key, $label) {
        global $sort_by, $sort_dir, $selected_telco, $selected_billing_period, $selected_company, $search_query, $raw_limit;
        $new_dir = ($sort_by === $column_key && $sort_dir === 'ASC') ? 'desc' : 'asc';
        $arrow = ($sort_by === $column_key) ? ($sort_dir === 'ASC' ? ' ▲' : ' ▼') : ' ↕';

        $params = $_GET;
        $params['sort'] = $column_key;
        $params['dir'] = $new_dir;
        unset($params['page']);

        $url = '?' . http_build_query($params);
        return '<th class="py-3 px-4"><a href="' . $url . '" class="flex items-center gap-1 hover:text-blue-600 transition-colors uppercase"><span>' . $label . '</span><span class="text-[10px] text-gray-400">' . $arrow . '</span></a></th>';
    }

    ob_start();
    ?>
    <div class="max-w-7xl mx-auto py-6 px-4">
        <div class="bg-gradient-to-r from-blue-700 to-indigo-800 rounded-2xl shadow-xl p-6 text-white mb-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold">✉️ Dispatch Statements Dashboard</h1>
                    <p class="text-blue-100 text-sm mt-1">Select telco provider, billing period, and company to filter statements, send notifications, or download files.</p>
                </div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                    <button type="button" onclick="openEmailReportListModal()" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 font-semibold px-4 py-2.5 rounded-xl text-xs transition-colors backdrop-blur-sm flex items-center gap-1.5 shadow-sm shrink-0">
                        ⚙️ Manage Email Report
                    </button>
                <?php endif; ?>
            </div>
        </div>

      <div class="bg-white shadow-lg rounded-2xl p-4 border border-gray-100 mb-6">
    <form method="GET" action="" id="filterForm" class="flex items-center justify-between gap-2 overflow-x-auto whitespace-nowrap pb-1">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($sort_dir)); ?>">
        
        <!-- Left Group: Dropdowns & Search in exact order requested -->
        <div class="flex items-center gap-2 shrink-0">
            <!-- 1. Select Billing Period First -->
            <select name="billing_period" id="billingPeriodSelector" class="text-xs border border-gray-300 rounded-lg p-2 bg-white font-medium shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none w-44">
                <option value="">-- Select Billing Period --</option>
                <?php foreach ($available_billing_periods as $period_item): ?>
                    <option value="<?php echo htmlspecialchars($period_item); ?>" <?php echo ($selected_billing_period === $period_item) ? 'selected' : ''; ?>><?php echo htmlspecialchars($period_item); ?></option>
                <?php endforeach; ?>
            </select>

            <!-- 2. Select Company Second -->
            <select name="company" id="companySelector" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded-lg p-2 bg-white font-medium shadow-sm w-36 truncate focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <option value="">-- Select Company --</option>
                <?php foreach ($available_companies as $comp): ?>
                    <option value="<?php echo htmlspecialchars($comp); ?>" <?php echo ($selected_company === $comp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($comp); ?></option>
                <?php endforeach; ?>
            </select>

            <!-- 3. Select Telco Third -->
            <select name="telco" id="telcoSelector" class="text-xs border border-gray-300 rounded-lg p-2 bg-white font-medium shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none w-32">
                <option value="">-- Select Telco --</option>
                <?php foreach ($available_telcos as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo ($selected_telco === $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>

            <!-- 4. Search Bar Fourth -->
            <div class="relative flex items-center shrink-0">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search filename / account..." class="text-xs border border-gray-300 rounded-lg pl-3 pr-7 py-2 bg-white font-medium shadow-sm w-40 focus:w-48 transition-all focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <button type="submit" class="absolute right-2 text-gray-400 hover:text-gray-600">🔍</button>
            </div>
        </div>

        <!-- Right Group: Page Limit & Bulk Buttons -->
        <div class="flex items-center gap-2 shrink-0">
            <!-- 5. Per Page Limit Dropdown -->
            <select name="limit" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded-lg p-2 bg-white font-medium shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 / page</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 / page</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 / page</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 / page</option>
                <option value="all" <?php echo $is_all ? 'selected' : ''; ?>>All</option>
            </select>

            <!-- 6. Download Selected PDF Button -->
            <button type="button" id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-3 py-2 rounded-lg text-xs transition-colors shadow-sm shrink-0 flex items-center gap-1">
                📥 Download Selected PDF
            </button>

            <!-- 7. Send Selected Button -->
            <button type="button" id="sendSelectedBtn" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-3 py-2 rounded-lg text-xs transition-colors shadow-sm shrink-0 flex items-center gap-1">
                📤 Send Selected
            </button>
        </div>
    </form>

           <div class="border border-gray-200 rounded-xl overflow-hidden mb-6 shadow-sm">
    <table class="w-full text-left border-collapse">
        <thead>
            <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-semibold border-b border-gray-200">
                <th class="py-3 px-4 w-10 text-center"><input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600"></th>
                <?php echo render_sort_th('filename', 'Filename'); ?>
                <?php echo render_sort_th('provider', 'Provider'); ?>
                <?php echo render_sort_th('account_number', 'Account Number'); ?>
                <?php echo render_sort_th('company', 'Company & Assignee'); ?>
                <th class="py-3 px-4 uppercase font-semibold text-gray-600">Final DM</th>
                <?php echo render_sort_th('email_address', 'Registered Email'); ?>
                <th class="py-3 px-4 text-center">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 text-xs text-gray-700">
            <?php if (empty($pdf_files)): ?>
                <tr><td colspan="8" class="text-center py-12 text-gray-400 font-medium">No statements found matching your criteria.</td></tr>
            <?php else: ?>
                <?php foreach ($pdf_files as $file): ?>
                    <?php 
                        $has_email = !empty(trim($file['email_address'])); 
                        $has_dm = ($file['dm_id'] > 0 && $file['has_dm_items']);
                        // CHECKBOX IS ONLY ENABLED IF BOTH EMAIL AND DM SUMMARY EXIST
                        $can_check = ($has_email && $has_dm);
                    ?>
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-3 px-4 text-center">
                            <input type="checkbox" name="file_checkbox" value="<?php echo htmlspecialchars($file['filename']); ?>" class="file-checkbox rounded border-gray-300 text-blue-600" data-email="<?php echo htmlspecialchars($file['email_address']); ?>" <?php echo !$can_check ? 'disabled' : ''; ?>>
                        </td>
                        <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($file['filename']); ?></td>
                        <td class="py-3 px-4"><span class="px-2.5 py-1 rounded-full font-bold <?php echo $file['telco'] === 'Smart' ? 'text-emerald-700 bg-emerald-50' : 'text-blue-700 bg-blue-50'; ?>"><?php echo htmlspecialchars($file['telco']); ?></span></td>
                        <td class="py-3 px-4 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($file['account_number']); ?></td>
                        <td class="py-3 px-4">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($file['company']); ?></div>
                            <div class="text-gray-500 text-[11px]"><?php echo htmlspecialchars($file['assignee_name']); ?></div>
                        </td>
                        <td class="py-3 px-4 font-bold text-slate-800">
                            <?php if ($has_dm): ?>
                                <span class="text-slate-900 font-extrabold"><?php echo htmlspecialchars($file['final_dm']); ?></span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-[10px] bg-rose-50 text-rose-600 border border-rose-200 font-semibold">No DM Record</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-gray-600 font-medium">
                            <?php echo $has_email ? htmlspecialchars($file['email_address']) : '<span class="text-rose-500 text-[10px] bg-rose-50 px-2 py-0.5 rounded border border-rose-200 font-semibold">No email</span>'; ?>
                        </td>
                        <td class="py-3 px-4 text-center flex items-center justify-center gap-1.5">
                            <button type="button" onclick="openPreview('<?php echo htmlspecialchars($file['filename']); ?>')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-xs font-semibold">👁️ Preview</button>
                            <button type="button" onclick="downloadSingleFile('<?php echo htmlspecialchars($file['filename']); ?>')" class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-2.5 py-1.5 rounded-lg text-xs font-semibold">📥 Download</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
        </div>
    </div>

    <!-- PREVIEW & EDIT MODAL -->
    <div id="previewModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center bg-gray-50 px-6 py-4 border-b border-gray-200">
                <h3 class="font-bold text-gray-800 text-sm">Email Preview & Send Settings</h3>
                <button type="button" onclick="closePreview()" class="text-gray-400 hover:text-gray-600 font-bold text-lg">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-50 flex-grow space-y-4 text-xs">
                <input type="hidden" id="modalFilename" value="">
                
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">To Email:</label>
                    <input type="email" id="modalToEmail" class="w-full text-xs border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white" placeholder="recipient@example.com">
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">CC Emails (Comma-separated):</label>
                    <input type="text" id="modalCcEmail" class="w-full text-xs border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white" placeholder="cc1@example.com, cc2@example.com">
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Subject:</label>
                    <input type="text" id="modalSubject" class="w-full text-xs border border-gray-300 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white" placeholder="Email Subject">
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Message Body:</label>
                    <iframe id="previewIframe" class="w-full h-[300px] bg-white border border-gray-200 rounded-xl shadow-inner"></iframe>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Attachments to Include:</label>
                    <div id="modalAttachmentsContainer" class="p-2 border rounded-xl bg-white text-xs space-y-1">
                        <!-- Dynamic Attachments render here -->
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
                <button type="button" onclick="closePreview()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-300">Cancel</button>
                <button type="button" onclick="sendEmailFromModal()" class="px-5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-md">Send Email Now</button>
            </div>
        </div>
    </div>

    <!-- DISPATCH PROGRESS MODAL -->
    <div id="dispatchProgressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-2xl p-6 mx-4">
            <h3 class="text-base font-bold text-gray-800 mb-4">Sending Statement Emails...</h3>
            <div class="mb-4">
                <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                    <div id="dispatchProgressBar" class="bg-emerald-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p id="dispatchProgressText" class="text-xs font-semibold text-gray-600 mt-2">Starting dispatch process...</p>
            </div>
            <div id="dispatchProgressLog" class="bg-slate-900 text-white text-xs font-mono p-3 rounded-xl max-h-60 overflow-y-auto mb-4 space-y-1"></div>
            <div class="text-right">
                <button type="button" id="closeDispatchModalBtn" onclick="closeDispatchModal()" style="display:none;" class="bg-gray-700 text-white px-4 py-2 rounded-xl text-xs font-semibold">Close</button>
            </div>
        </div>
    </div>

    <!-- Email Report Settings Management Modal -->
    <div id="emailReportListModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl overflow-hidden">
            <div class="px-6 py-4 bg-slate-800 text-white flex justify-between items-center">
                <h3 class="text-lg font-semibold">Manage Email Report Settings (Statement Dispatch)</h3>
                <button type="button" onclick="closeEmailReportListModal()" class="text-slate-300 hover:text-white text-xl font-bold">&times;</button>
            </div>
            <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="flex justify-between items-center">
                    <p class="text-sm text-slate-600">Configure recipients who receive automated Statement Dispatch reports.</p>
                    <button type="button" onclick="openEmailReportFormModal()" class="px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded hover:bg-emerald-700 transition">+ Add New Setting</button>
                </div>
                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700 font-semibold">
                            <tr>
                                <th class="px-4 py-3">Full Name / Additional Info</th>
                                <th class="px-4 py-3">Recipient Email</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="emailReportTableBody" class="divide-y divide-slate-200 bg-white"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script>
    document.getElementById('telcoSelector').addEventListener('change', async function() {
        const telco = this.value;
        const billingPeriodSelect = document.getElementById('billingPeriodSelector');
        const companySelect = document.getElementById('companySelector');
        
        billingPeriodSelect.innerHTML = '<option value="">-- Loading Periods --</option>';
        companySelect.innerHTML = '<option value="">-- Loading Companies --</option>';

        const formData = new FormData();
        formData.append('action', 'get_periods_by_telco');
        formData.append('telco', telco);

        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                let options = '<option value="">-- Select Billing Period --</option>';
                data.periods.forEach(period => {
                    options += `<option value="${period}">${period}</option>`;
                });
                billingPeriodSelect.innerHTML = options;

                let compOptions = '<option value="">-- Select Company --</option>';
                data.companies.forEach(comp => {
                    compOptions += `<option value="${comp}">${comp}</option>`;
                });
                companySelect.innerHTML = compOptions;
            }
        } catch (e) {
            billingPeriodSelect.innerHTML = '<option value="">-- Error Loading Periods --</option>';
            companySelect.innerHTML = '<option value="">-- Error Loading Companies --</option>';
        }
        document.getElementById('filterForm').submit();
    });

    document.getElementById('billingPeriodSelector').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });

    function downloadSingleFile(filename) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'download_single_pdf';
        form.appendChild(actionInput);

        const fileInput = document.createElement('input');
        fileInput.type = 'hidden';
        fileInput.name = 'filename';
        fileInput.value = filename;
        form.appendChild(fileInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    async function openPreview(filename) {
        document.getElementById('previewModal').classList.remove('hidden');
        document.getElementById('modalFilename').value = filename;
        document.getElementById('modalToEmail').value = '';
        document.getElementById('modalCcEmail').value = '';
        document.getElementById('modalSubject').value = '';
        
        const iframe = document.getElementById('previewIframe');
        iframe.srcdoc = 'Loading email preview...';
        
        const attContainer = document.getElementById('modalAttachmentsContainer');
        attContainer.innerHTML = `<span class="text-gray-400 italic">Checking attachments (Generated Debit Memo & Local/Shared Drive SOA)...</span>`;

        const formData = new FormData();
        formData.append('action', 'preview_email'); 
        formData.append('filename', filename); 
        formData.append('billing_period', document.getElementById('billingPeriodSelector').value);
        
        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                iframe.srcdoc = data.html;
                document.getElementById('modalToEmail').value = data.email || '';
                document.getElementById('modalSubject').value = data.subject || '';
                
                let attachmentsHtml = '';
                if (data.generated_dm_pdf && data.generated_dm_pdf !== '') {
                    attachmentsHtml += `
                        <label class="flex items-center gap-2 py-0.5 px-1 hover:bg-gray-100 rounded cursor-pointer">
                            <input type="checkbox" checked disabled class="rounded border-gray-300 text-blue-600">
                            <span class="font-medium text-gray-700">${data.generated_dm_pdf} (Generated Debit Memo)</span>
                        </label>`;
                }
                attachmentsHtml += `
                    <label class="flex items-center gap-2 py-0.5 px-1 hover:bg-gray-100 rounded cursor-pointer">
                        <input type="checkbox" checked disabled class="rounded border-gray-300 text-blue-600">
                        <span class="font-medium text-gray-700">${data.attachment_name} (Auto-fetched from Local/Shared Drive)</span>
                    </label>`;
                
                attContainer.innerHTML = attachmentsHtml;
            } else {
                iframe.srcdoc = `Error: ${data.message}`;
                attContainer.innerHTML = `<span class="text-rose-500">Attachment not found.</span>`;
            }
        } catch (err) {
            iframe.srcdoc = 'Failed to load preview.';
            attContainer.innerHTML = `<span class="text-rose-500">Failed to check attachments.</span>`;
        }
    }
    
    function closePreview() { document.getElementById('previewModal').classList.add('hidden'); }

    async function sendEmailFromModal() {
        let filename = document.getElementById('modalFilename').value;
        let toEmail = document.getElementById('modalToEmail').value.trim();
        let ccEmail = document.getElementById('modalCcEmail').value.trim();
        let customSubject = document.getElementById('modalSubject').value.trim();

        if (!toEmail) {
            alert('Please specify a recipient email address (To).');
            return;
        }

        if (!confirm(`Are you sure you want to send this statement to ${toEmail}?`)) return;

        closePreview();
        showDispatchProgressModal();
        const emailLogContent = document.getElementById('dispatchProgressLog');
        emailLogContent.innerHTML = '';

        document.getElementById('dispatchProgressText').textContent = `Sending to ${toEmail}...`;
        document.getElementById('dispatchProgressBar').style.width = '50%';

        const formData = new FormData();
        formData.append('action', 'send_emails');
        formData.append('billing_period', document.getElementById('billingPeriodSelector').value);
        formData.append('cc', ccEmail);
        formData.append('subject', customSubject);
        formData.append('items[0][filename]', filename);
        formData.append('items[0][email]', toEmail);

        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            
            if (data.results && data.results.length > 0) {
                data.results.forEach(r => {
                    if (r.status === 'success') {
                        emailLogContent.innerHTML += `<div class="text-emerald-400">[SUCCESS] ${r.file} -> ${r.message}</div>`;
                    } else {
                        emailLogContent.innerHTML += `<div class="text-rose-400">[ERROR] ${r.file} -> ${r.message}</div>`;
                    }
                });
            }

            if (data.report_items && data.report_items.length > 0) {
                const finalFormData = new FormData();
                finalFormData.append('action', 'send_summary_report');
                finalFormData.append('results_summary', JSON.stringify(data.report_items));
                await fetch('', { method: 'POST', body: finalFormData });
            }

            document.getElementById('dispatchProgressBar').style.width = '100%';
            document.getElementById('dispatchProgressText').textContent = 'Completed and summary report sent!';
        } catch (err) {
            emailLogContent.innerHTML += `<div class="text-rose-400">[NETWORK ERROR] Request failed.</div>`;
            document.getElementById('dispatchProgressText').textContent = 'Failed with error.';
        }
        document.getElementById('closeDispatchModalBtn').style.display = 'inline-block';
    }

    function showDispatchProgressModal() {
        document.getElementById('dispatchProgressModal').style.display = 'flex';
        document.getElementById('dispatchProgressBar').style.width = '0%';
        document.getElementById('closeDispatchModalBtn').style.display = 'none';
    }

    function closeDispatchModal() {
        document.getElementById('dispatchProgressModal').style.display = 'none';
        location.reload();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function() { 
                document.querySelectorAll('.file-checkbox:not(:disabled)').forEach(cb => {
                    cb.checked = selectAll.checked;
                }); 
            });
        }

        const sendSelectedBtn = document.getElementById('sendSelectedBtn');
        if (sendSelectedBtn) {
            sendSelectedBtn.addEventListener('click', async () => {
                const selectedCheckboxes = Array.from(document.querySelectorAll('.file-checkbox:checked'));
                if (selectedCheckboxes.length === 0) return alert('Please select at least one item with a valid email address.');
                
                let allItemsToSend = [];
                selectedCheckboxes.forEach(cb => {
                    let filename = cb.value;
                    let emailVal = cb.getAttribute('data-email') || '';
                    if (emailVal.trim() !== '') {
                        allItemsToSend.push({ filename: filename, email: emailVal });
                    }
                });

                if (allItemsToSend.length === 0) {
                    return alert('None of the selected items have a valid email address.');
                }

                if (!confirm(`Are you sure you want to send emails for ${allItemsToSend.length} selected item(s)?`)) return;

                showDispatchProgressModal();
                const emailLogContent = document.getElementById('dispatchProgressLog');
                emailLogContent.innerHTML = '<div>Starting bulk batch dispatch... Please wait.</div>';
                
                const chunkSize = 10;
                let totalSuccess = 0;
                let totalFail = 0;
                let allCombinedReportItems = [];

                for (let i = 0; i < allItemsToSend.length; i += chunkSize) {
                    let chunk = allItemsToSend.slice(i, i + chunkSize);
                    let progressPercent = Math.round((i / allItemsToSend.length) * 100);
                    
                    document.getElementById('dispatchProgressBar').style.width = progressPercent + '%';
                    document.getElementById('dispatchProgressText').textContent = `Processing items ${i + 1} to ${Math.min(i + chunkSize, allItemsToSend.length)} of ${allItemsToSend.length}...`;

                    const formData = new FormData();
                    formData.append('action', 'send_emails');
                    formData.append('billing_period', document.getElementById('billingPeriodSelector').value);
                    
                    chunk.forEach((item, index) => {
                        formData.append(`items[${index}][filename]`, item.filename);
                        formData.append(`items[${index}][email]`, item.email);
                    });

                    try {
                        const res = await fetch('', { method: 'POST', body: formData });
                        const data = await res.json();
                        
                        if (data.results && data.results.length > 0) {
                            data.results.forEach(r => {
                                if (r.status === 'success') {
                                    totalSuccess++;
                                    emailLogContent.innerHTML += `<div class="text-emerald-400">[SUCCESS] ${r.file} -> ${r.message}</div>`;
                                } else {
                                    totalFail++;
                                    emailLogContent.innerHTML += `<div class="text-rose-400">[ERROR] ${r.file} -> ${r.message}</div>`;
                                }
                            });
                        }

                        if (data.report_items && data.report_items.length > 0) {
                            data.report_items.forEach(ri => {
                                allCombinedReportItems.push(ri);
                            });
                        }
                    } catch (err) {
                        emailLogContent.innerHTML += `<div class="text-rose-400">[NETWORK ERROR] Batch request failed at index ${i}.</div>`;
                    }
                    
                    emailLogContent.scrollTop = emailLogContent.scrollHeight;
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }

                document.getElementById('dispatchProgressBar').style.width = '95%';
                document.getElementById('dispatchProgressText').textContent = `All batches finished. Sending final summary report to admin recipients...`;

                if (allCombinedReportItems.length > 0) {
                    const finalFormData = new FormData();
                    finalFormData.append('action', 'send_summary_report');
                    finalFormData.append('results_summary', JSON.stringify(allCombinedReportItems));

                    try {
                        const finalRes = await fetch('', { method: 'POST', body: finalFormData });
                        const finalData = await finalRes.json();
                        if (finalData.status === 'success') {
                            emailLogContent.innerHTML += `<div class="text-blue-300 font-bold mt-2">[INFO] Statement Dispatch Report successfully sent to all admin recipients!</div>`;
                        }
                    } catch (err) {}
                }

                document.getElementById('dispatchProgressBar').style.width = '100%';
                document.getElementById('dispatchProgressText').textContent = `Completed! Total Success: ${totalSuccess}, Total Failed: ${totalFail}`;
                document.getElementById('closeDispatchModalBtn').style.display = 'inline-block';
            });
        }
    });

    function openEmailReportListModal() {
        document.getElementById('emailReportListModal').classList.remove('hidden');
    }
</script>
    <?php
    $content = ob_get_clean();
    if (function_exists('render_layout')) {
        render_layout("Dispatch Statements", $content);
    } else {
        echo $content;
    }
} catch (Exception $e) {
    echo "System Error: " . $e->getMessage();
}
?>