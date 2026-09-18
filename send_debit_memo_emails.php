<?php
// Force display errors directly into the response text
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'config.php';
    require_once 'main.php';

    // Auto-load Smalot PdfParser kung nasa vendor directory na
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }

    /**
     * Function para basahin ang PDF at i-extract ang lahat ng detalye para sa Smart at Globe
     */
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
            'prev_balance' => 'N/A',
            'payment' => 'N/A',
            'adjustment' => 'N/A',
            'rem_prev_balance' => 'N/A',
            'recurring_charges' => 'N/A',
            'usage_charges' => 'N/A',
            'non_recurring_charges' => 'N/A',
            'total_current_charges' => 'N/A',
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

        // 1. Detect Telco (Smart vs Globe)
        if (stripos($text, 'Smart Communications') !== false || stripos($text, 'SMART') !== false || stripos($text, 'SMTBI') !== false) {
            $data['telco'] = 'Smart';
            
            // --- EXTRACT SMART UPPER BOX DETAILS ---
            if (preg_match('/Invoice\s*Date\s*([A-Za-z0-9,\s]+)/i', $text, $m)) {
                $data['invoice_date'] = trim($m[1]);
            }
            if (preg_match('/Billing\s*Period\s*([A-Za-z0-9,\s\-]+)/i', $text, $m)) {
                $data['billing_period'] = trim($m[1]);
            }
            if (preg_match('/Mobile\s*Number\s*([0-9\s]+)/i', $text, $m)) {
                $data['mobile_number'] = trim($m[1]);
            }
            if (preg_match('/Invoice\s*Number\s*(SMTBI[0-9]+)/i', $text, $m)) {
                $data['invoice_number'] = trim($m[1]);
            }
            if (preg_match('/Credit\s*Limit\s*([0-9,\.]+)/i', $text, $m)) {
                $data['credit_limit'] = trim($m[1]);
            }
            if (preg_match('/DUE\s*DATE[:]?\s*([A-Za-z0-9,\s]+)/i', $text, $m)) {
                $data['due_date'] = trim($m[1]);
            }
            if (preg_match('/TOTAL\s*AMOUNT\s*DU[EE]\s*[:]?\s*([A-Z0-9\.,\(\)\s]+)/i', $text, $m)) {
                $data['amount_due'] = trim($m[1]);
            } elseif (preg_match('/Total\s*Amount\s*Due\s*([0-9,\.\(\)\sCR]+)/i', $text, $m)) {
                $data['amount_due'] = trim($m[1]);
            }

            // --- EXTRACT SMART INVOICE SUMMARY TABLE DETAILS ---
            if (preg_match('/Balance\s*from\s*Previous\s*Charges\s*([0-9,\.\(\)\sCR]+)/i', $text, $m)) {
                $data['prev_balance'] = trim($m[1]);
            }
            if (preg_match('/Payment\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['payment'] = trim($m[1]);
            }
            if (preg_match('/Adjustment\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['adjustment'] = trim($m[1]);
            }
            if (preg_match('/Remaining\s*Balance\s*from\s*Previous\s*Invoice\s*([0-9,\.\(\)\sCR]+)/i', $text, $m)) {
                $data['rem_prev_balance'] = trim($m[1]);
            }
            if (preg_match('/Recurring\s*Charges\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['recurring_charges'] = trim($m[1]);
            }
            if (preg_match('/Usage\s*Charges\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['usage_charges'] = trim($m[1]);
            }
            if (preg_match('/Non\s*Recurring\s*Charges\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['non_recurring_charges'] = trim($m[1]);
            }
            if (preg_match('/Total\s*Current\s*Charges\s*([0-9,\.\(\)\s]+)/i', $text, $m)) {
                $data['total_current_charges'] = trim($m[1]);
            }

            // Build detailed HTML matching Smart Reference Image[cite: 22]
            $data['summary_html'] = '
            <div style="border: 1px solid #16a34a; margin-top: 20px; font-family: Arial, sans-serif;">
                <div style="background-color: #16a34a; color: white; padding: 10px 12px; font-weight: bold; font-size: 14px;">Invoice Summary</div>
                
                <div style="padding: 10px 12px; font-weight: bold; font-size: 13px; color: #333;">Previous Charges</div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Balance from Previous Charges</span><span>' . htmlspecialchars($data['prev_balance']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Payment</span><span>' . htmlspecialchars($data['payment']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Adjustment</span><span>' . htmlspecialchars($data['adjustment']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid #ddd; font-size: 13px; font-weight: bold; color: #16a34a;"><span>Remaining Balance from Previous Invoice</span><span>' . htmlspecialchars($data['rem_prev_balance']) . '</span></div>

                <div style="padding: 10px 12px; font-weight: bold; font-size: 13px; color: #333; margin-top: 5px;">Current Charges</div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Recurring Charges</span><span>' . htmlspecialchars($data['recurring_charges']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Usage Charges</span><span>' . htmlspecialchars($data['usage_charges']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Non Recurring Charges</span><span>' . htmlspecialchars($data['non_recurring_charges']) . '</span></div>
                <div style="display: flex; justify-content: space-between; padding: 8px 12px; font-size: 13px; font-weight: bold; background-color: #f9f9f9; color: #16a34a;"><span>Total Current Charges</span><span>' . htmlspecialchars($data['total_current_charges']) . '</span></div>
                <div style="padding: 6px 12px; font-style: italic; font-size: 11px; color: #666; border-top: 1px solid #eee;">Inclusive of Taxes</div>
            </div>
            <div style="background-color: #16a34a; color: white; padding: 12px; font-weight: bold; font-size: 15px; display: flex; justify-content: space-between; margin-top: 2px;">
                <span>TOTAL AMOUNT DUE</span>
                <span>' . htmlspecialchars($data['amount_due']) . '</span>
            </div>';

        } elseif (stripos($text, 'Globe Telecom') !== false || stripos($text, 'GLOBE BUSINESS') !== false || stripos($text, 'CTGI') !== false) {
            $data['telco'] = 'Globe';

            // --- EXTRACT GLOBE UPPER BOX DETAILS ---
            if (preg_match('/Amount\s*to\s*Pay[^\d]*([0-9,]+\.[0-9]{2})/i', $text, $m)) {
                $data['amount_due'] = 'Php ' . trim($m[1]);
            } elseif (preg_match('/Php\s*([0-9,]+\.[0-9]{2})/i', $text, $m)) {
                $data['amount_due'] = 'Php ' . trim($m[1]);
            }

            if (preg_match('/(CTGI[0-9]+)/i', $text, $m)) {
                $data['corporate_id'] = trim($m[1]);
            }
            if (preg_match('/Invoice\s*Date\s*([0-9\/]+)/i', $text, $m)) {
                $data['invoice_date'] = trim($m[1]);
            }
            if (preg_match('/Billing\s*Period\s*([0-9\/]+\s*to\s*[0-9\/]+)/i', $text, $m)) {
                $data['billing_period'] = trim($m[1]);
            }
            if (preg_match('/Due\s*Date\s*([0-9\/]+)/i', $text, $m)) {
                $data['due_date'] = trim($m[1]);
            }
            if (preg_match('/Primary\s*Number\s*([0-9]+)/i', $text, $m)) {
                $data['mobile_number'] = trim($m[1]);
            }
            if (preg_match('/Credit\s*Limit\s*Php\s*([0-9,\.]+)/i', $text, $m)) {
                $data['credit_limit'] = trim($m[1]);
            }
            if (preg_match('/Customer\s*TIN\s*([0-9\-]+)/i', $text, $m)) {
                $data['customer_tin'] = trim($m[1]);
            }

            // Globe Statement Summary Charges
            if (preg_match('/Monthly\s*Plan\s*P\s*([0-9,\.]+)/i', $text, $m)) {
                $data['recurring_charges'] = trim($m[1]);
            }
            if (preg_match('/Excess\s*Usage\s*P\s*([0-9,\.]+)/i', $text, $m)) {
                $data['usage_charges'] = trim($m[1]);
            }
            if (preg_match('/Previous\s*Bill\s*Amount\s*P\s*([0-9,\.]+)/i', $text, $m)) {
                $data['prev_balance'] = trim($m[1]);
            }
            if (preg_match('/Payment\s*\(P\s*([0-9,\.]+)\)/i', $text, $m)) {
                $data['payment'] = '(' . trim($m[1]) . ')';
            }
            if (preg_match('/Remaining\s*Balance\s*P\s*([0-9,\.]+)/i', $text, $m)) {
                $data['rem_prev_balance'] = trim($m[1]);
            }

            // Build detailed HTML matching Globe Reference Image[cite: 21]
            $data['summary_html'] = '
            <div style="font-weight: bold; font-size: 15px; color: #0f172a; margin-top: 20px; margin-bottom: 8px;">Statement Summary</div>
            
            <div style="border: 1px solid #333; margin-bottom: 15px;">
                <div style="background-color: #f1f5f9; padding: 8px 12px; font-weight: bold; font-size: 13px; border-bottom: 1px solid #333;">Charges For This Month</div>
                <div style="padding: 8px 12px; font-size: 13px;">
                    <div style="font-weight: bold;">Monthly Recurring Fee</div>
                    <div style="display: flex; justify-content: space-between; padding-left: 10px; margin-top: 2px;"><span>Monthly Plan</span><span>P ' . htmlspecialchars($data['recurring_charges']) . '</span></div>
                    <div style="display: flex; justify-content: space-between; margin-top: 6px; font-weight: bold;"><span>Excess Usage</span><span>P ' . htmlspecialchars($data['usage_charges'] !== 'N/A' ? $data['usage_charges'] : '0.00') . '</span></div>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 12px; border-top: 1px solid #333; font-weight: bold; font-size: 13px; background-color: #f9f9f9;">
                    <span>Total</span><span>' . htmlspecialchars($data['amount_due']) . '</span>
                </div>
            </div>

            <div style="border: 1px solid #333; margin-bottom: 15px;">
                <div style="background-color: #f1f5f9; padding: 8px 12px; font-weight: bold; font-size: 13px; border-bottom: 1px solid #333;">Previous Bill Activity</div>
                <div style="display: flex; justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px;"><span>Previous Bill Amount</span><span>P ' . htmlspecialchars($data['prev_balance']) . '</span></div>
                <div style="padding: 8px 12px; font-size: 13px; border-bottom: 1px solid #eee;">
                    <div>Less :</div>
                    <div style="display: flex; justify-content: space-between; padding-left: 10px; margin-top: 2px;"><span>Payment</span><span>(P ' . htmlspecialchars(str_replace(['(', ')'], '', $data['payment'])) . ')</span></div>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 8px 12px; font-weight: bold; font-size: 13px;">
                    <span>Remaining Balance</span><span>P ' . htmlspecialchars($data['rem_prev_balance']) . '</span>
                </div>
            </div>

            <div style="border: 1px solid #333; display: flex; justify-content: space-between; padding: 10px 12px; font-weight: bold; font-size: 14px; background-color: #f1f5f9;">
                <span>Amount to Pay</span><span>' . htmlspecialchars($data['amount_due']) . '</span>
            </div>';
        }

        return $data;
    }

    /**
     * Function para buuin ang HTML Email Body kasama ang Smart o Globe details
     */
    function generate_email_body_html($company, $assignee_name, $account_number, $billing_info) {
        $telco_brand = $billing_info['telco'];
        $amount_due = $billing_info['amount_due'];
        $corporate_id = $billing_info['corporate_id'];
        $summary_html = $billing_info['summary_html'];

        $header_bg = ($telco_brand === 'Smart') ? '#16a34a' : '#0f172a';
        $brand_title = ($telco_brand === 'Smart') ? 'Smart Business Statement of Account' : 'Globe Business Statement of Account';

        return '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif; color: #333333; line-height: 1.5; background: #f4f4f4; margin: 0; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #ddd; overflow: hidden;">
                
                <!-- Header Banner -->
                <div style="background: ' . $header_bg . '; color: #ffffff; padding: 20px;">
                    <h2 style="margin: 0; font-size: 20px; font-weight: bold;">' . $brand_title . '</h2>
                    <p style="margin: 5px 0 0 0; font-size: 13px; opacity: 0.9;">Bounty Support Workspace Integration (' . htmlspecialchars($telco_brand) . ')</p>
                </div>

                <!-- Body Text -->
                <div style="padding: 20px;">
                    <p style="margin-top: 0;">Good day <strong>' . htmlspecialchars($company) . '</strong>,</p>
                    <p>Please find below your statement summary details. The official PDF copy is securely attached to this email for your review and processing.</p>
                    
                    <!-- Top Main Amount Box matching layout images -->
                    <div style="border: 1px solid #333; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #fafafa; border-bottom: 1px solid #333;">
                            <div>
                                <span style="font-size: 16px; font-weight: bold; color: #000;">' . ($telco_brand === 'Smart' ? 'Total Amount Due' : 'Amount to Pay') . '</span>
                                <div style="font-size: 11px; color: #666;">(total amount due)</div>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: #000;">' . htmlspecialchars($amount_due) . '</div>
                        </div>

                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            ' . ($telco_brand === 'Smart' ? '
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; width: 50%;"><strong>Invoice Date</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($billing_info['invoice_date']) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Billing Period</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($billing_info['billing_period']) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Account Number</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($account_number) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Mobile Number</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($billing_info['mobile_number']) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Invoice Number</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($billing_info['invoice_number']) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Credit Limit</strong></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: right;">' . htmlspecialchars($billing_info['credit_limit']) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px;"><strong>Due Date</strong></td>
                                <td style="padding: 8px 12px; text-align: right;">' . htmlspecialchars($billing_info['due_date']) . '</td>
                            </tr>
                            ' : '
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; width: 50%;"><strong>Corporate ID</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($corporate_id) . '</span></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd; width: 50%;"><strong>Account Number</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($account_number) . '</span></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Primary Number</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($billing_info['mobile_number']) . '</span></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Credit Limit</strong><br><span style="font-weight: bold; color: #000;">Php ' . htmlspecialchars($billing_info['credit_limit']) . '</span></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Customer TIN</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($billing_info['customer_tin']) . '</span></td>
                                <td style="padding: 8px 12px; border-bottom: 1px solid #ddd;"><strong>Invoice Date</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($billing_info['invoice_date']) . '</span></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 12px;"><strong>Billing Period</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($billing_info['billing_period']) . '</span></td>
                                <td style="padding: 8px 12px;"><strong>Due Date</strong><br><span style="font-weight: bold; color: #000;">' . htmlspecialchars($billing_info['due_date']) . '</span></td>
                            </tr>
                            ') . '
                        </table>
                    </div>

                    <!-- Assigned Contact Info Row -->
                    <div style="margin-top: 15px; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 13px; display: flex; justify-content: space-between;">
                        <span>Assigned Coordinator: <strong>' . htmlspecialchars($assignee_name) . '</strong></span>
                    </div>

                    <!-- Telco Specific Breakdown / Summary Template Blocks -->
                    ' . $summary_html . '

                    <p style="font-size: 13px; color: #555; margin-top: 25px;">If you have any questions or require clarifications regarding this statement, please feel free to reach out.</p>
                </div>

                <!-- Footer -->
                <div style="background: #f1f5f9; padding: 15px 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #ddd;">
                    Thank you,<br><strong>Bounty Support Team</strong>
                </div>
            </div>
        </body>
        </html>';
    }

    /**
     * Send email via custom SMTP socket
     */
    function send_smtp_mail_with_html_body($to, $subject,$filepath, $filename,$html_content) {
        $smtp_host = 'tcp://smtp.gmail.com';$smtp_port = 587;                    
        $smtp_user = 'jcalcantara@bounty.com.ph';
        $smtp_pass = str_replace(' ', '', 'kowg yhnc dryb uumq'); 

        $boundary_mixed = md5(time() . 'mixed');

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: <{$smtp_user}>\r\n";
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

        $socket = @fsockopen($smtp_host,$smtp_port, $errno,$errstr, 15);
        if (!is_resource($socket)) return "Connection failed: $errstr ($errno)";

        fgets($socket, 512);
        $run_command = function($cmd, $expect_code) use ($socket) {
            fwrite($socket,$cmd . "\r\n");
            $response = '';
            while ($str = fgets($socket, 512)) {
                $response .=$str;
                if (substr($str, 3, 1) == ' ') break;
            }
            return (substr($response, 0, 3) ==$expect_code);
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
        $result = fgets($socket, 512);$run_command("QUIT", 221);
        fclose($socket);

        return (substr($result, 0, 3) == '250') ? true : "Failed to send: " . trim($result);
    }

    $upload_dir = __DIR__ . "/uploads/debit_memos/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // AJAX Endpoint para sa Email Preview Request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'preview_email') {
        header('Content-Type: application/json');
        $filename = basename($_POST['filename'] ?? '');$filepath = $upload_dir .$filename;

        if (!file_exists($filepath)) {
            echo json_encode(['status' => 'error', 'message' => 'File not found.']);
            exit;
        }

        preg_match('/\d+/', $filename,$matches);
        $account_number =$matches[0] ?? '';

        $company = 'N/A';$assignee_name = 'N/A';
        if (isset($pdo)) {
            $stmtDM =$pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
            $stmtDM->execute([$account_number]);
            if ($dmRow =$stmtDM->fetch(PDO::FETCH_ASSOC)) {
                $company =$dmRow['company'] ?? 'N/A';
                $assignee_name =$dmRow['assignee_name'] ?? 'N/A';
            }
        }

        $billing_info = extract_pdf_billing_details($filepath);$html_preview = generate_email_body_html($company,$assignee_name, $account_number,$billing_info);

        echo json_encode(['status' => 'success', 'html' => $html_preview]);
        exit;
    }

    // Handle AJAX Form Submission to send emails
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'send_emails') {
        header('Content-Type: application/json');
        $selected_files = $_POST['files'] ?? [];$results = [];

        foreach ($selected_files as $filename) {$filename = basename($filename);$filepath = $upload_dir .$filename;
            
            if (!file_exists($filepath)) {
                $results[] = ['file' =>$filename, 'status' => 'error', 'message' => 'File not found on server.'];
                continue;
            }

            preg_match('/\d+/', $filename,$matches);
            $account_number =$matches[0] ?? '';

            $company = 'N/A';
            $assignee_name = 'N/A';$email_address = '';

            try {
                if (isset($pdo)) {
                    $stmtDM =$pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtDM->execute([$account_number]);
                    if ($dmRow =$stmtDM->fetch(PDO::FETCH_ASSOC)) {
                        $company =$dmRow['company'] ?? 'N/A';
                        $assignee_name =$dmRow['assignee_name'] ?? 'N/A';
                    }

                    $stmtEmail =$pdo->prepare("SELECT email_address FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                    $stmtEmail->execute([$account_number]);
                    if ($emailRow =$stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                        $email_address =$emailRow['email_address'] ?? '';
                    }
                }
            } catch (Exception $e) {}

            if (empty($email_address)) {
                $results[] = ['file' =>$filename, 'status' => 'error', 'message' => "No email registered for account: {$account_number}"];
                continue;
            }

            $billing_info = extract_pdf_billing_details($filepath);$html_content = generate_email_body_html($company,$assignee_name, $account_number,$billing_info);

            $to =$email_address;
            $subject = "{$billing_info['telco']} Statement of Account - " . $account_number;
            
            $mail_status = send_smtp_mail_with_html_body($to, $subject,$filepath, $filename,$html_content);

            if ($mail_status === true) {
                $results[] = ['file' =>$filename, 'status' => 'success', 'message' => "Successfully sent to {$to} ({$billing_info['telco']})"];
            } else {
                $results[] = ['file' => $filename, 'status' => 'error', 'message' =>$mail_status];
            }
        }

        echo json_encode(['status' => 'completed', 'results' => $results]);
        exit;
    }

    // Scan files
    $pdf_files = [];
    if (is_dir($upload_dir)) {
        $scan = scandir($upload_dir);
        if ($scan !== false) {
            foreach ($scan as$file) {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {$filepath = $upload_dir .$file;
                    
                    preg_match('/\d+/', $file,$matches);
                    $account_number =$matches[0] ?? 'Unknown';

                    $billing_info = extract_pdf_billing_details($filepath);

                    $company = 'Unknown';
                    $assignee_name = 'Unassigned';$email_address = 'Not Registered';

                    if ($account_number !== 'Unknown' && isset($pdo)) {
                        try {
                            $stmtDM =$pdo->prepare("SELECT company, assignee_name FROM debit_memos WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtDM->execute([$account_number]);
                            $dmRow =$stmtDM->fetch(PDO::FETCH_ASSOC);
                            if ($dmRow) {
                                $company =$dmRow['company'] ?? 'Unknown';
                                $assignee_name =$dmRow['assignee_name'] ?? 'Unassigned';
                            }
                        } catch (Exception $e) {}

                        try {
                            $stmtEmail =$pdo->prepare("SELECT email_address FROM account_emails WHERE TRIM(account_number) = TRIM(?) LIMIT 1");
                            $stmtEmail->execute([$account_number]);
                            $emailRow =$stmtEmail->fetch(PDO::FETCH_ASSOC);
                            if ($emailRow && !empty($emailRow['email_address'])) {
                                $email_address =$emailRow['email_address'];
                            }
                        } catch (Exception $e) {}
                    }

                    $pdf_files[] = [
                        'filename' => $file,
                        'size' => file_exists($filepath) ? filesize($filepath) : 0,                             
                        'account_number' =>$account_number,
                        'company' => $company,
                        'assignee_name' => $assignee_name,
                        'email_address' => $email_address,
                        'telco' => $billing_info['telco'],
                        'amount_due' => $billing_info['amount_due'],
                        'corporate_id' => $billing_info['corporate_id']
                    ];
                }
            }
        }
    }

    ob_start();
    ?>

    <div class="max-w-7xl mx-auto py-6 px-4">
        <div class="bg-white shadow-lg rounded-xl p-6 border border-gray-100">
            
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-xl font-bold text-gray-800 flex items-center space-x-2">
                        <span>📧</span>
                        <span>Email Statements of Account (Smart & Globe Summary)</span>
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Extracts billing details, summaries, and amount due from Smart and Globe PDFs automatically.</p>
                </div>
                <div>
                    <button type="button" id="sendSelectedBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow text-sm transition-colors">
                        Send Selected Emails
                    </button>
                </div>
            </div>

            <div class="border border-gray-200 rounded-lg overflow-hidden mb-6">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase font-semibold border-b border-gray-200">
                            <th class="py-3 px-4 w-10 text-center">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="py-3 px-4">Filename</th>
                            <th class="py-3 px-4">Provider</th>
                            <th class="py-3 px-4">Account No / Corp ID</th>
                            <th class="py-3 px-4">Amount Due</th>
                            <th class="py-3 px-4">Company & Assignee</th>
                            <th class="py-3 px-4">Registered Email</th>
                            <th class="py-3 px-4 text-center">Preview Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs text-gray-700">
                        <?php if (empty($pdf_files)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-8 text-gray-400">No PDF files found in the upload directory.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pdf_files as$file): ?>
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-4 text-center">
                                        <input type="checkbox" name="file_checkbox" value="<?php echo htmlspecialchars($file['filename']); ?>" class="file-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo ($file['email_address'] === 'Not Registered') ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="py-3 px-4 font-medium text-gray-900"><?php echo htmlspecialchars($file['filename']); ?></td>
                                    <td class="py-3 px-4">
                                        <?php if ($file['telco'] === 'Smart'): ?>
                                            <span class="px-2 py-0.5 rounded text-green-700 bg-green-50 font-bold">Smart</span>
                                        <?php elseif ($file['telco'] === 'Globe'): ?>
                                            <span class="px-2 py-0.5 rounded text-blue-700 bg-blue-50 font-bold">Globe</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-gray-600 bg-gray-100 font-medium">Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono">
                                        <div class="font-semibold text-blue-600"><?php echo htmlspecialchars($file['account_number']); ?></div>
                                        <?php if ($file['corporate_id'] !== 'N/A'): ?>
                                            <div class="text-[10px] text-gray-500">Corp: <?php echo htmlspecialchars($file['corporate_id']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-bold text-slate-800"><?php echo htmlspecialchars($file['amount_due']); ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($file['company']); ?></div>
                                        <div class="text-gray-500 text-[11px]"><?php echo htmlspecialchars($file['assignee_name']); ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($file['email_address'] === 'Not Registered'): ?>
                                            <span class="px-2 py-0.5 rounded text-red-600 bg-red-50 font-medium">Not Registered</span>
                                        <?php else: ?>
                                            <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($file['email_address']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <button type="button" onclick="openPreview('<?php echo htmlspecialchars($file['filename']); ?>')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 rounded text-[11px] font-medium transition-colors">
                                            👁️ Preview
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="emailLogContainer" class="hidden border border-gray-200 rounded-lg p-4 bg-slate-900 text-white text-xs font-mono max-h-48 overflow-y-auto">
                <div class="font-bold text-gray-300 mb-2 border-b border-slate-700 pb-1">Email Dispatch Log:</div>
                <div id="emailLogContent" class="space-y-1"></div>
            </div>

        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div id="previewModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center bg-gray-100 px-6 py-4 border-b border-gray-200">
                <h3 class="font-bold text-gray-800 text-sm">Email Content Preview</h3>
                <button type="button" onclick="closePreview()" class="text-gray-400 hover:text-gray-600 font-bold text-lg">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-50 flex-grow">
                <iframe id="previewIframe" class="w-full h-[500px] bg-white border border-gray-200 rounded-lg shadow-sm"></iframe>
            </div>
            <div class="bg-gray-100 px-6 py-3 border-t border-gray-200 text-right">
                <button type="button" onclick="closePreview()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-xs font-medium">Close Preview</button>
            </div>
        </div>
    </div>

    <script>
    const selectAll = document.getElementById('selectAll');
    const sendSelectedBtn = document.getElementById('sendSelectedBtn');
    const emailLogContainer = document.getElementById('emailLogContainer');
    const emailLogContent = document.getElementById('emailLogContent');

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

        const formData = new FormData();
        formData.append('action', 'preview_email');
        formData.append('filename', filename);

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

            const formData = new FormData();
            formData.append('action', 'send_emails');
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
                    data.results.forEach(res => {
                        if (res.status === 'success') {
                            emailLogContent.innerHTML += `<div class="text-green-400">✓ ${res.file}: ${res.message}</div>`;
                        } else {
                            emailLogContent.innerHTML += `<div class="text-red-400">✗ ${res.file}: ${res.message}</div>`;
                        }
                    });
                }
            } catch (err) {
                emailLogContent.innerHTML += `<div class="text-red-400">✗ ${err.message}</div>`;
            }

            sendSelectedBtn.removeAttribute('disabled');
            sendSelectedBtn.textContent = 'Send Selected Emails';
            emailLogContainer.scrollTop = emailLogContainer.scrollHeight;
        });
    }
    </script>

    <?php
    $content = ob_get_clean();
    if (function_exists('render_layout')) {
        render_layout("Email Statements Analyzer", $content);
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