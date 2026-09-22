<?php
// debit_memo_dispatch.php - Statement Dispatch Module
define('ALLOW_ACCESS', true);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'config.php';
    require_once 'main.php';

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
    // 1. If the current mobile is already valid (not empty and not 'N/A'), use it
    if (!empty($current_mobile) && trim($current_mobile) !== '' && strtoupper(trim($current_mobile)) !== 'N/A') {
        return trim($current_mobile);
    }
    
    // 2. Otherwise, look up the mobile number from the account_emails table using the account number
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
        } catch (Exception $e) {
            // Log or ignore query errors
        }
    }
    
    // 3. Fallback to original value or 'N/A' if nothing found in account_emails
    return !empty($current_mobile) && strtoupper(trim($current_mobile)) !== 'N/A' ? $current_mobile : 'N/A';
}

    function generate_email_body_html($company, $assignee_name, $account_number, $billing_info, $filename = '', $mobile_number = 'N/A') {
        $amount_due = $billing_info['amount_due'] ?? '0.00';
        $period = !empty($billing_info['billing_period']) && $billing_info['billing_period'] !== 'N/A' ? $billing_info['billing_period'] : 'Current Period';
        $account_display = !empty($company) && $company !== 'N/A' ? $company : $account_number;
        $mobile_display = !empty($mobile_number) && $mobile_number !== 'N/A' ? $mobile_number : 'N/A';
        
        $safe_account_num = '<span style="color: #2563eb; text-decoration: none;">' . htmlspecialchars($account_number) . '</span>';

        return '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif; color: #333333; line-height: 1.6; padding: 20px;">'
            . '<div style="max-width: 600px; background: #ffffff; padding: 15px;">'
            . '<p>Dear Ma\'am/Sir,</p>'
            . '<p>Please find attached your Statement of Account (SOA) reflecting the applicable Debit Memo charges, with details below:</p>'
            . '<div style="background: #f9fafb; border-left: 4px solid #2563eb; padding: 15px; margin: 20px 0; border-radius: 4px;">'
            . '<p style="margin: 0 0 10px 0; font-weight: bold; color: #1f2937;">Summary Details:</p>'
            . '<p style="margin: 0 0 5px 0;"><strong>Period Covered:</strong> ' . htmlspecialchars($period) . '</p>'
            . '<p style="margin: 0 0 5px 0;"><strong>Account Name:</strong> ' . htmlspecialchars($account_display) . ' (' . $safe_account_num . ')</p>'
            . '<p style="margin: 0 0 5px 0;"><strong>Mobile Number:</strong> ' . htmlspecialchars($mobile_display) . '</p>'
            . '<p style="margin: 0;"><strong>Total Chargeable Amount:</strong> ' . htmlspecialchars($amount_due) . '</p>'
            . '</div>'
            . '<p>This statement outlines the specific breakdown and descriptions of the charges applied to your telco account.</p>'
            . '<p>Please review the attached SOA (' . htmlspecialchars($filename) . ') for full details.</p>'
            . '<p>If you have any questions or require clarification regarding these charges, please reach out to the IT Telco Admin team thru <a href="mailto:kmmontano@bounty.com.ph" style="color: #2563eb; text-decoration: underline;">kmmontano@bounty.com.ph</a> within 24-48 hours upon receipt of this email.</p>'
            . '<p style="margin-top: 25px;">Thank you,</p>'
            . '</div></body></html>';
    }

    function send_smtp_mail_with_html_body($to, $subject, $filepath, $filename, $html_content, $cc = '', $extra_attachment_path = '', $extra_attachment_name = '') {
        $smtp_host = 'tcp://smtp.gmail.com'; $smtp_port = 587;                    
        $smtp_user = 'jcalcantara@bounty.com.ph';
        $smtp_pass = str_replace(' ', '', 'kowg yhnc dryb uumq'); $boundary = md5(time());

        $headers  = "MIME-Version: 1.0\r\nFrom: IT Telco Admin <{$smtp_user}>\r\nTo: {$to}\r\n";
        if (!empty($cc)) {
            $headers .= "Cc: {$cc}\r\n";
        }
        $headers .= "Subject: {$subject}\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        
        $body  = "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html_content}\r\n\r\n";
        
        if (!empty($filepath) && file_exists($filepath) && filesize($filepath) > 100) {
            $pdfData = chunk_split(base64_encode(file_get_contents($filepath)));
            $body .= "--{$boundary}\r\nContent-Type: application/pdf; name=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n{$pdfData}\r\n\r\n";
        }

        if (!empty($extra_attachment_path) && file_exists($extra_attachment_path)) {
            $extraData = chunk_split(base64_encode(file_get_contents($extra_attachment_path)));
            $exName = !empty($extra_attachment_name) ? $extra_attachment_name : basename($extra_attachment_path);
            $body .= "--{$boundary}\r\nContent-Type: text/csv; name=\"{$exName}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$exName}\"\r\n\r\n{$extraData}\r\n\r\n";
        }

        $body .= "--{$boundary}--";

        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
        if (!is_resource($socket)) return "Connection failed: $errstr";
        fgets($socket, 512);
        $run_cmd = function($cmd, $code) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
            $res = ''; while ($s = fgets($socket, 512)) { $res .= $s; if (substr($s, 3, 1) == ' ') break; }
            return (substr($res, 0, 3) == $code);
        };
        if (!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250) || !$run_cmd("STARTTLS", 220)) return "TLS handshake error";
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        
        if (!$run_cmd("EHLO " . $_SERVER['SERVER_NAME'], 250) || !$run_cmd("AUTH LOGIN", 334) || !$run_cmd(base64_encode($smtp_user), 334) || !$run_cmd(base64_encode($smtp_pass), 235) || !$run_cmd("MAIL FROM: <{$smtp_user}>", 250)) return "SMTP Auth/Command error";
        
        $run_cmd("RCPT TO: <{$to}>", 250);
        
        if (!empty($cc)) {
            $cc_emails = explode(',', $cc);
            foreach ($cc_emails as $cc_email) {
                $cc_email = trim($cc_email);
                if (!empty($cc_email)) {
                    @$run_cmd("RCPT TO: <{$cc_email}>", 250);
                }
            }
        }

        if (!$run_cmd("DATA", 354)) return "DATA command error";
        
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $result = fgets($socket, 512); $run_cmd("QUIT", 221);
        fclose($socket);
        return (substr($result, 0, 3) == '250') ? true : "Failed: " . trim($result);
    }

   function send_dispatch_report_to_admin($all_results_items) {
        if (empty($all_results_items)) return;

        $admin_email = 'jcalcantara@bounty.com.ph';
        $current_date = date('Y-m-d H:i:s');
        $subject = "Statement Dispatch Report (Success & Failed) - " . date('Y-m-d');

        $csv_filename = "dispatch_report_" . date('Ymd_His') . ".csv";
        $csv_filepath = sys_get_temp_dir() . '/' . $csv_filename;
        
        $fp = fopen($csv_filepath, 'w');
        fputcsv($fp, ['Timestamp', 'Status', 'Account Number', 'Telco', 'Mobile Number', 'Email', 'Billing Period', 'Total Charge', 'Filename', 'PDF Link', 'Message']);
        
        foreach ($all_results_items as $item) {
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
                $item['message']
            ]);
        }
        fclose($fp);

        $html_body  = '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif; color: #333333; line-height: 1.5; padding: 20px;">';
        $html_body .= '<h2 style="color: #2563eb;">Statement Email Dispatch Report</h2>';
        $html_body .= '<p>Here is the summary of the email dispatches conducted on <strong>' . htmlspecialchars($current_date) . '</strong>. Attached to this email is a CSV file containing the full details of successful and failed items.</p>';
        
        $html_body .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; font-size: 11px; margin-top: 15px;">';
        $html_body .= '<tr style="background-color: #f3f4f6;"><th>Status</th><th>Date</th><th>Account</th><th>Telco</th><th>Mobile Number</th><th>Email</th><th>Billing Period</th><th>Total Charge</th><th>Filename (Link)</th></tr>';

        foreach ($all_results_items as $item) {
            $status_color = ($item['status'] === 'success') ? 'color: #059669; font-weight: bold;' : 'color: #dc2626; font-weight: bold;';
            
            $file_display_name = htmlspecialchars($item['filename']);
            $target_link = !empty($item['file_link']) ? $item['file_link'] : '#';
            if (!empty($target_link) && $target_link !== '#') {
                $filename_html = '<a href="' . htmlspecialchars($target_link) . '" target="_blank" style="color: #2563eb; text-decoration: underline;">' . $file_display_name . '</a>';
            } else {
                $filename_html = $file_display_name;
            }

            $html_body .= '<tr>';
            $html_body .= '<td style="' . $status_color . '">' . strtoupper($item['status']) . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['date']) . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['account']) . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['telco'] ?? 'Unknown') . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['mobile_number'] ?? 'N/A') . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['email']) . '</td>';
            $html_body .= '<td>' . htmlspecialchars($item['billing_period']) . '</td>';
            $html_body .= '<td style="font-weight: bold; text-align: right;">' . htmlspecialchars($item['total_charge']) . '</td>';
            $html_body .= '<td>' . $filename_html . '</td>';
            $html_body .= '</tr>';
        }
        $html_body .= '</table>';
        $html_body .= '<p style="margin-top: 20px; font-size: 11px; color: #666;">This is an automated system report with attached CSV log.</p>';
        $html_body .= '</body></html>';

        send_smtp_mail_with_html_body($admin_email, $subject, '', '', $html_body, '', $csv_filepath, $csv_filename);
        
        if (file_exists($csv_filepath)) {
            @unlink($csv_filepath);
        }
    }

    function get_or_download_pdf_path($filename, $base_upload_dir, $pdo = null) {
        $filename = basename($filename);
        if ($pdo) {
            try {
                $stmtFile = $pdo->prepare("SELECT file_path FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                $stmtFile->execute([$filename]);
                if ($fRow = $stmtFile->fetch(PDO::FETCH_ASSOC)) {
                    $db_path = !empty($fRow['file_path']) ? __DIR__ . '/' . $fRow['file_path'] : '';
                    if (!empty($db_path) && file_exists($db_path) && filesize($db_path) > 100) return $db_path;
                }
            } catch (Exception $e) {}
        }
        $root_dir = __DIR__ . "/Teclo_Test_Uploads/Uploads/";
        if (!is_dir($root_dir)) $root_dir = $base_upload_dir;
        if (is_dir($root_dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root_dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $path => $info) {
                if ($info->getFilename() === $filename && filesize($path) > 100) return $path; 
            }
        }
        $temp_file_path = sys_get_temp_dir() . '/' . md5($filename) . '.pdf';
        if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) return $temp_file_path;
        
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
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $download_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                            $response = curl_exec($ch);
                            if (strpos($response, 'confirm=') !== false && preg_match('/confirm=([a-zA-Z0-9_\-]+)/', $response, $matches)) {
                                curl_setopt($ch, CURLOPT_URL, "https://drive.google.com/uc?export=download&confirm=" . $matches[1] . "&id=" . $gdrive_id);
                                $response = curl_exec($ch);
                            }
                            curl_close($ch);
                            if (!empty($response) && strlen($response) > 500 && stripos($response, '<html') === false) {
                                file_put_contents($temp_file_path, $response);
                                if (file_exists($temp_file_path) && filesize($temp_file_path) > 100) return $temp_file_path;
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
        if (isset($pdo)) {
            try {
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
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success', 'periods' => $periods]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_single_pdf') {
        $filename = basename($_POST['filename'] ?? '');
        if (!empty($filename)) {
            $filepath = get_or_download_pdf_path($filename, $base_upload_dir, $pdo ?? null);
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_selected_zip') {
        $filenames = $_POST['filenames'] ?? [];
        if (!empty($filenames) && is_array($filenames)) {
            $zip = new ZipArchive();
            $zip_name = sys_get_temp_dir() . '/statements_' . date('Ymd_His') . '.zip';
            if ($zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                foreach ($filenames as $fname) {
                    $fname = basename($fname);
                    $filepath = get_or_download_pdf_path($fname, $base_upload_dir, $pdo ?? null);
                    if (!empty($filepath) && file_exists($filepath)) {
                        $zip->addFile($filepath, $fname);
                    }
                }
                $zip->close();
                if (file_exists($zip_name)) {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="selected_statements_' . date('Ymd_His') . '.zip"');
                    header('Content-Length: ' . filesize($zip_name));
                    readfile($zip_name);
                    @unlink($zip_name);
                    exit;
                }
            }
        }
        header('HTTP/1.1 400 Bad Request');
        echo "Failed to create archive.";
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview_email') {
        header('Content-Type: application/json');
        $filename = basename($_POST['filename'] ?? '');
        $account_number = 'N/A'; $company = 'N/A'; $assignee_name = 'N/A'; $email_address = ''; $mobile_number = 'N/A';
        $billing_info = ['billing_period' => $_POST['billing_period'] ?? 'N/A', 'amount_due' => '0.00'];

        if (isset($pdo)) {
            try {
                $stmtDetails = $pdo->prepare("SELECT * FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                $stmtDetails->execute([$filename]);
                if ($detRow = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                    $account_number = $detRow['account_number'] ?? 'N/A';
                    $raw_mobile = $detRow['mobile_number'] ?? 'N/A';
                    $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);

                    if (!empty($detRow['billing_period'])) $billing_info['billing_period'] = $detRow['billing_period'];
                    
                    $raw_due = floatval(str_replace(['PHP', 'Php', ','], '', $detRow['amount_due'] ?? '0.00'));
                    $billing_info['amount_due'] = 'PHP ' . number_format($raw_due, 2);
                }
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
            } catch (Exception $e) {}
        }
        $html_preview = generate_email_body_html($company, $assignee_name, $account_number, $billing_info, $filename, $mobile_number);
        echo json_encode(['status' => 'success', 'html' => $html_preview, 'email' => $email_address]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_emails') {
        header('Content-Type: application/json');
        $items = $_POST['items'] ?? [];
        $billing_period = $_POST['billing_period'] ?? '';
        $cc_emails = $_POST['cc'] ?? '';
        $results = [];
        $all_report_items = [];

        foreach ($items as $item) {
            $filename = basename($item['filename'] ?? '');
            $email_address = trim($item['email'] ?? '');
            $current_timestamp = date('Y-m-d H:i:s');
            
            if (empty($filename)) continue;

            $filepath = get_or_download_pdf_path($filename, $base_upload_dir, $pdo ?? null);
            $account_number = 'N/A'; $company = 'N/A'; $assignee_name = 'N/A'; $mobile_number = 'N/A';
            $file_link = '';
            $telco_val = 'Unknown';
            $formatted_total_charge = 'PHP 0.00';
            $actual_billing_period = $billing_period;
            $billing_info = ['billing_period' => $billing_period, 'amount_due' => '0.00'];

            try {
                if (isset($pdo)) {
                    $stmtDetails = $pdo->prepare("SELECT account_number, billing_period, amount_due, telco, file_link, mobile_number FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                    $stmtDetails->execute([$filename]);
                    if ($detRow = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                        $account_number = $detRow['account_number'] ?? 'N/A';
                        $telco_val = $detRow['telco'] ?? 'Unknown';
                        $raw_mobile = $detRow['mobile_number'] ?? 'N/A';
                        $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);

                        if (!empty($detRow['billing_period'])) {
                            $actual_billing_period = $detRow['billing_period'];
                            $billing_info['billing_period'] = $detRow['billing_period'];
                        }
                        
                        $raw_due = floatval(str_replace(['PHP', 'Php', ','], '', $detRow['amount_due'] ?? '0.00'));
                        $formatted_total_charge = 'PHP ' . number_format($raw_due, 2);
                        $billing_info['amount_due'] = $formatted_total_charge;
                        $file_link = $detRow['file_link'] ?? '';
                    }
                    $stmtDM = $pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtDM->execute([$account_number]);
                    if ($dmRow = $stmtDM->fetch(PDO::FETCH_ASSOC)) {
                        $company = $dmRow['company'] ?? 'N/A';
                        $assignee_name = $dmRow['assignee_name'] ?? 'N/A';
                    }
                    if (empty($email_address)) {
                        $stmtEmail = $pdo->prepare("SELECT email_address FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                        $stmtEmail->execute([$account_number]);
                        if ($emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                            $email_address = $emailRow['email_address'] ?? '';
                        }
                    }
                }
            } catch (Exception $e) {}

            if (empty($email_address)) {
                $err_msg = "No target email provided.";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => 'None', 'status' => 'error', 'message' => $err_msg];
                
                $all_report_items[] = [
                    'date' => $current_timestamp,
                    'status' => 'error',
                    'account' => $account_number,
                    'telco' => $telco_val,
                    'mobile_number' => $mobile_number,
                    'email' => 'None',
                    'billing_period' => $actual_billing_period,
                    'total_charge' => $formatted_total_charge,
                    'filename' => $filename,
                    'file_link' => $file_link,
                    'message' => $err_msg
                ];
                continue;
            }

            if (!empty($filepath) && file_exists($filepath) && filesize($filepath) > 100) {
                // File exists
            } else {
                $err_msg = "PDF file not found.";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'error', 'message' => $err_msg];
                
                $all_report_items[] = [
                    'date' => $current_timestamp,
                    'status' => 'error',
                    'account' => $account_number,
                    'telco' => $telco_val,
                    'mobile_number' => $mobile_number,
                    'email' => $email_address,
                    'billing_period' => $actual_billing_period,
                    'total_charge' => $formatted_total_charge,
                    'filename' => $filename,
                    'file_link' => $file_link,
                    'message' => $err_msg
                ];
                continue;
            }

            $html_content = generate_email_body_html($company, $assignee_name, $account_number, $billing_info, $filename, $mobile_number);
            $period = !empty($billing_info['billing_period']) && $billing_info['billing_period'] !== 'N/A' ? $billing_info['billing_period'] : (!empty($billing_period) ? $billing_period : 'Current Period');
            $subject = "Statement of Account for {$account_number}: Debit Memo Details for {$period}";
            
            $mail_status = send_smtp_mail_with_html_body($email_address, $subject, $filepath, $filename, $html_content, $cc_emails);
            if ($mail_status === true) {
                $success_msg = "Sent to {$email_address}";
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'success', 'message' => $success_msg];
                
                $all_report_items[] = [
                    'date' => $current_timestamp,
                    'status' => 'success',
                    'account' => $account_number,
                    'telco' => $telco_val,
                    'mobile_number' => $mobile_number,
                    'email' => $email_address,
                    'billing_period' => $actual_billing_period,
                    'total_charge' => $formatted_total_charge,
                    'filename' => $filename,
                    'file_link' => $file_link,
                    'message' => $success_msg
                ];
            } else {
                $results[] = ['file' => $filename, 'account' => $account_number, 'email' => $email_address, 'status' => 'error', 'message' => $mail_status];
                
                $all_report_items[] = [
                    'date' => $current_timestamp,
                    'status' => 'error',
                    'account' => $account_number,
                    'telco' => $telco_val,
                    'mobile_number' => $mobile_number,
                    'email' => $email_address,
                    'billing_period' => $actual_billing_period,
                    'total_charge' => $formatted_total_charge,
                    'filename' => $filename,
                    'file_link' => $file_link,
                    'message' => $mail_status
                ];
            }
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

    // ==========================================
    // PAGE LOAD DATA FETCHING (FILTERS, SEARCH & SORTING)
    // ==========================================

    $available_telcos = ['Smart', 'Globe'];
    $available_billing_periods = [];

    $selected_telco = $_GET['telco'] ?? '';
    $selected_billing_period = $_GET['billing_period'] ?? '';
    $search_query = trim($_GET['search'] ?? '');

    // Sorting parameters
    $sort_by = $_GET['sort'] ?? 'filename';
    $sort_dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    // Map allowed sort columns to prevent SQL injection
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
        if (!empty($search_query)) {
            $whereClauses[] = "(p.filename LIKE ? OR p.account_number LIKE ? OR p.mobile_number LIKE ? OR d.company LIKE ? OR e.email_address LIKE ?)";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
            $params[] = "%{$search_query}%";
        }

        $sqlWhere = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sqlJoins = "FROM pdf_extracted_details p 
                     LEFT JOIN debit_memos d ON TRIM(p.account_number) = TRIM(d.account_number)
                     LEFT JOIN account_emails e ON TRIM(p.account_number) = TRIM(e.account_number)";

        // Count Query
        $stmtCount = $pdo->prepare("SELECT COUNT(p.id) {$sqlJoins} {$sqlWhere}");
        $stmtCount->execute($params);
        $total_files_count = $stmtCount->fetchColumn();

        $orderByClause = "ORDER BY {$sql_sort_column} {$sort_dir}";

        if ($is_all) {
            $total_pages = 1;
            $page = 1;
            $stmtRecords = $pdo->prepare("SELECT p.*, d.company, d.assignee_name, e.email_address AS registered_email {$sqlJoins} {$sqlWhere} {$orderByClause}");
            $stmtRecords->execute($params);
        } else {
            $total_pages = max(1, ceil($total_files_count / $limit));
            if ($page > $total_pages) $page = $total_pages;
            $offset = ($page - 1) * $limit;

            $stmtRecords = $pdo->prepare("SELECT p.*, d.company, d.assignee_name, e.email_address AS registered_email {$sqlJoins} {$sqlWhere} {$orderByClause} LIMIT {$limit} OFFSET {$offset}");
            $stmtRecords->execute($params);
        }

        $db_records = $stmtRecords->fetchAll(PDO::FETCH_ASSOC);

        foreach ($db_records as $rec) {
            $filename = $rec['filename'];
            $account_number = $rec['account_number'] ?? 'N/A';
            $raw_mobile = $rec['mobile_number'] ?? 'N/A';
            $mobile_number = resolve_mobile_number($pdo, $account_number, $raw_mobile);
            $telco = $rec['telco'] ?? 'Unknown';
            
            $raw_amt = floatval(str_replace(['PHP', 'Php', ','], '', $rec['amount_due'] ?? '0.00'));
            $amount_due = 'PHP ' . number_format($raw_amt, 2);

            $company = $rec['company'] ?? 'Unknown'; 
            $assignee_name = $rec['assignee_name'] ?? 'Unassigned'; 
            $email_address = $rec['registered_email'] ?? '';
            $display_file_link = $rec['file_link'] ?? '';

            $pdf_files[] = [
                'filename' => $filename,
                'file_link' => $display_file_link,
                'account_number' => $account_number,
                'mobile_number' => $mobile_number,
                'company' => $company,
                'assignee_name' => $assignee_name,
                'email_address' => $email_address,
                'telco' => $telco,
                'amount_due' => $amount_due
            ];
        }
    }

    function render_sort_th($column_key, $label) {
        global $sort_by, $sort_dir, $selected_telco, $selected_billing_period, $search_query, $raw_limit;
        
        $new_dir = 'asc';
        $arrow = ' ↕';
        if ($sort_by === $column_key) {
            if ($sort_dir === 'ASC') {
                $new_dir = 'desc';
                $arrow = ' ▲';
            } else {
                $new_dir = 'asc';
                $arrow = ' ▼';
            }
        }

        $params = $_GET;
        $params['sort'] = $column_key;
        $params['dir'] = $new_dir;
        $params['telco'] = $selected_telco;
        $params['billing_period'] = $selected_billing_period;
        $params['search'] = $search_query;
        $params['limit'] = $raw_limit;
        unset($params['page']);

        $url = '?' . http_build_query($params);
        return '<th class="py-3 px-4"><a href="' . $url . '" class="flex items-center gap-1 hover:text-blue-600 transition-colors uppercase"><span>' . $label . '</span><span class="text-[10px] text-gray-400">' . $arrow . '</span></a></th>';
    }

    ob_start();
    ?>
    <div class="max-w-7xl mx-auto py-6 px-4">
        <div class="bg-gradient-to-r from-blue-700 to-indigo-800 rounded-2xl shadow-xl p-6 text-white mb-6">
            <h1 class="text-2xl font-extrabold">✉️ Dispatch Statements Dashboard</h1>
            <p class="text-blue-100 text-sm mt-1">Select telco provider first, then billing period and search to send notifications or download statements.</p>
        </div>

        <div class="bg-white shadow-lg rounded-2xl p-6 border border-gray-100 mb-6">
            <form method="GET" action="" id="filterForm" class="flex flex-col lg:flex-row justify-between items-center mb-6 gap-4">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($sort_dir)); ?>">
                
                <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                    <select name="telco" id="telcoSelector" class="text-xs border border-gray-300 rounded-xl p-2.5 bg-white font-medium shadow-sm">
                        <option value="">-- Select Telco --</option>
                        <?php foreach ($available_telcos as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo ($selected_telco === $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="billing_period" id="billingPeriodSelector" class="text-xs border border-gray-300 rounded-xl p-2.5 bg-white font-medium shadow-sm">
                        <option value="">-- Select Billing Period --</option>
                        <?php foreach ($available_billing_periods as $period_item): ?>
                            <option value="<?php echo htmlspecialchars($period_item); ?>" <?php echo ($selected_billing_period === $period_item) ? 'selected' : ''; ?>><?php echo htmlspecialchars($period_item); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="relative flex items-center">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search filename / account / mobile..." class="text-xs border border-gray-300 rounded-xl pl-3 pr-8 py-2.5 bg-white font-medium shadow-sm w-48 focus:w-64 transition-all">
                        <button type="submit" class="absolute right-2.5 text-gray-400 hover:text-gray-600">🔍</button>
                    </div>
                </div>

                <div class="flex items-center gap-3 w-full lg:w-auto justify-end">
                    <select name="limit" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded-xl p-2.5 bg-white font-medium shadow-sm">
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                        <option value="all" <?php echo $is_all ? 'selected' : ''; ?>>All</option>
                    </select>

                    <button type="button" id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-xl text-xs transition-colors shadow-sm">📥 Download Selected PDF</button>
                    <button type="button" id="sendSelectedBtn" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2.5 rounded-xl text-xs transition-colors shadow-sm">📤 Send Selected</button>
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
                            <?php echo render_sort_th('amount_due', 'Amount Due'); ?>
                            <?php echo render_sort_th('company', 'Company & Assignee'); ?>
                            <?php echo render_sort_th('email_address', 'Registered Email'); ?>
                            <th class="py-3 px-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs text-gray-700">
                        <?php if (empty($pdf_files)): ?>
                            <tr><td colspan="8" class="text-center py-12 text-gray-400 font-medium">No statements found matching your criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pdf_files as $file): ?>
                                <?php $has_email = !empty(trim($file['email_address'])); ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-4 text-center">
                                        <input type="checkbox" name="file_checkbox" value="<?php echo htmlspecialchars($file['filename']); ?>" class="file-checkbox rounded border-gray-300 text-blue-600" data-email="<?php echo htmlspecialchars($file['email_address']); ?>" <?php echo !$has_email ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($file['filename']); ?></td>
                                    <td class="py-3 px-4"><span class="px-2.5 py-1 rounded-full font-bold <?php echo $file['telco'] === 'Smart' ? 'text-emerald-700 bg-emerald-50' : 'text-blue-700 bg-blue-50'; ?>"><?php echo htmlspecialchars($file['telco']); ?></span></td>
                                    <td class="py-3 px-4 font-mono font-semibold text-blue-600"><?php echo htmlspecialchars($file['account_number']); ?></td>
                                    <td class="py-3 px-4 font-bold text-slate-800"><?php echo htmlspecialchars($file['amount_due']); ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($file['company']); ?></div>
                                        <div class="text-gray-500 text-[11px]"><?php echo htmlspecialchars($file['assignee_name']); ?></div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 font-medium">
                                        <?php echo $has_email ? htmlspecialchars($file['email_address']) : '<span class="text-gray-400 italic">No email</span>'; ?>
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

            <?php if (!$is_all && $total_pages > 1): ?>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-2 border-t border-gray-100 text-xs">
                    <div class="text-gray-500 font-medium">
                        Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (Total records: <?php echo $total_files_count; ?>)
                    </div>
                    <div class="flex items-center gap-1.5">
                        <?php 
                        $query_params = $_GET;
                        $query_params['telco'] = $selected_telco;
                        $query_params['billing_period'] = $selected_billing_period;
                        $query_params['search'] = $search_query;
                        $query_params['limit'] = $raw_limit;
                        $query_params['sort'] = $sort_by;
                        $query_params['dir'] = strtolower($sort_dir);

                        if ($page > 1): 
                            $query_params['page'] = 1;
                        ?>
                            <a href="?<?php echo http_build_query($query_params); ?>" class="px-3 py-1.5 rounded-lg font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200">First</a>
                        <?php endif; ?>

                        <?php 
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        for ($p = $start_p; $p <= $end_p; $p++): 
                            $query_params['page'] = $p;
                        ?>
                            <a href="?<?php echo http_build_query($query_params); ?>" class="px-3 py-1.5 rounded-lg font-semibold <?php echo $p === $page ? 'bg-blue-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): 
                            $query_params['page'] = $total_pages;
                        ?>
                            <a href="?<?php echo http_build_query($query_params); ?>" class="px-3 py-1.5 rounded-lg font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PREVIEW & EDIT MODAL -->
    <div id="previewModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center bg-gray-50 px-6 py-4 border-b border-gray-200">
                <h3 class="font-bold text-gray-800 text-sm">Email Preview & Send Settings</h3>
                <button type="button" onclick="closePreview()" class="text-gray-400 hover:text-gray-600 font-bold text-lg">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-50 flex-grow space-y-4">
                <input type="hidden" id="modalFilename" value="">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">To (Email):</label>
                        <input type="email" id="modalToEmail" class="w-full text-xs border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="recipient@example.com">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">CC (Optional, comma-separated):</label>
                        <input type="text" id="modalCcEmail" class="w-full text-xs border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="cc1@example.com, cc2@example.com">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Email Body Preview:</label>
                    <iframe id="previewIframe" class="w-full h-[380px] bg-white border border-gray-200 rounded-xl shadow-sm"></iframe>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-between items-center">
                <button type="button" onclick="closePreview()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-xl text-xs font-semibold">Close</button>
                <button type="button" onclick="sendEmailFromModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm">📤 Send Email Now</button>
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

    <script>
    document.getElementById('telcoSelector').addEventListener('change', async function() {
        const telco = this.value;
        const billingPeriodSelect = document.getElementById('billingPeriodSelector');
        billingPeriodSelect.innerHTML = '<option value="">-- Loading Periods --</option>';

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
            }
        } catch (e) {
            billingPeriodSelect.innerHTML = '<option value="">-- Error Loading Periods --</option>';
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
        
        const iframe = document.getElementById('previewIframe');
        iframe.srcdoc = 'Loading email preview...';
        
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
            } else {
                iframe.srcdoc = `Error: ${data.message}`;
            }
        } catch (err) {
            iframe.srcdoc = 'Failed to load preview.';
        }
    }
    
    function closePreview() { document.getElementById('previewModal').classList.add('hidden'); }

    async function sendEmailFromModal() {
        let filename = document.getElementById('modalFilename').value;
        let toEmail = document.getElementById('modalToEmail').value.trim();
        let ccEmail = document.getElementById('modalCcEmail').value.trim();

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
        formData.append('items[0][filename]', filename);
        formData.append('items[0][email]', toEmail);

        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            
            let allCombinedResults = [];
            if (data.results && data.results.length > 0) {
                data.results.forEach(r => {
                    allCombinedResults.push(r);
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

        const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
        if (downloadSelectedBtn) {
            downloadSelectedBtn.addEventListener('click', () => {
                const selectedCheckboxes = Array.from(document.querySelectorAll('.file-checkbox:checked'));
                if (selectedCheckboxes.length === 0) {
                    alert('Please select at least one item to download.');
                    return;
                }

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'download_selected_zip';
                form.appendChild(actionInput);

                selectedCheckboxes.forEach((cb, index) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `filenames[${index}]`;
                    input.value = cb.value;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
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
                let allCombinedResults = [];
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
                                allCombinedResults.push(r);
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
                }

                document.getElementById('dispatchProgressBar').style.width = '95%';
                document.getElementById('dispatchProgressText').textContent = `All batches finished. Sending final summary report to admin...`;

                if (allCombinedReportItems.length > 0) {
                    const finalFormData = new FormData();
                    finalFormData.append('action', 'send_summary_report');
                    finalFormData.append('results_summary', JSON.stringify(allCombinedReportItems));

                    try {
                        const finalRes = await fetch('', { method: 'POST', body: finalFormData });
                        const finalData = await finalRes.json();
                        if (finalData.status === 'success') {
                            emailLogContent.innerHTML += `<div class="text-blue-300 font-bold mt-2">[INFO] Statement Dispatch Report successfully sent to jcalcantara!</div>`;
                        } else {
                            emailLogContent.innerHTML += `<div class="text-rose-400 font-bold mt-2">[WARNING] Failed to send summary report to admin.</div>`;
                        }
                    } catch (err) {
                        emailLogContent.innerHTML += `<div class="text-rose-400">[NETWORK ERROR] Failed to send final summary report.</div>`;
                    }
                }

                document.getElementById('dispatchProgressBar').style.width = '100%';
                document.getElementById('dispatchProgressText').textContent = `Completed! Total Success: ${totalSuccess}, Total Failed: ${totalFail}`;
                emailLogContent.innerHTML += `<div class="text-emerald-300 font-bold mt-2">[INFO] Process fully completed.</div>`;
                document.getElementById('closeDispatchModalBtn').style.display = 'inline-block';
            });
        }
    });
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