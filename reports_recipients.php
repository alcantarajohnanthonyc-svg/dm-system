<?php
/**
 * reports_recipients.php
 *
 * Helpers para sa "BU Admin & President" report sa loob ng reports.php.
 *
 *  - Totals by Group      -> President Email + PDF Summary Attachment (Group, Department, Employee ID, Employee Name, Account Number, Mobile Number, Approved Plan, Current Charges, Total Excess Charges)
 *  - Totals by Department -> BU Admin & Coordinator Email + PDF Summary Attachment (Department, Employee ID, Employee Name, Account Number, Mobile Number, Approved Plan, Current Charges, Total Excess Charges)
 *  - Naka-attach rin ang individual Debit Memo PDF ng bawat account.
 */

if (!function_exists('getBuAdminEmailTemplate')) {
    require_once __DIR__ . '/email_template.php';
}

// -------------------------------------------------------------------------
// ROLES & COLUMN MAPPINGS
// -------------------------------------------------------------------------
function rp_rcp_roles() {
    return array(
        'president' => array('label' => 'Group President', 'name_col' => 'president', 'email_col' => 'president_email'),
        'bu_admin'  => array('label' => 'BU Admin',        'name_col' => 'bu_admin_coordinator', 'email_col' => 'bu_admin_coordinator_email'),
    );
}

function rp_rcp_role($v) {
    $roles = rp_rcp_roles();
    return isset($roles[$v]) ? $v : 'bu_admin';
}

function rp_rcp_csrf() {
    if (empty($_SESSION['rcp_csrf'])) {
        $_SESSION['rcp_csrf'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : md5(uniqid('', true)));
    }
    return $_SESSION['rcp_csrf'];
}

function rp_rcp_detect_columns($conn) {
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = array();
            $stmt = $conn->query("DESCRIBE account_emails");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower($row['Field'])] = $row['Field'];
            }
        } catch (Exception $e) {
            $cols = array();
        }
    }
    return $cols;
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------
function rp_rcp_amount_sql($conn, $col) {
    static $types = null;
    if ($types === null) {
        $types = array();
        try {
            $st = $conn->query("DESCRIBE debit_memo_items");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $types[strtolower($r['Field'])] = strtolower($r['Type']); }
        } catch (Exception $e) {
            $types = array();
        }
    }
    $t = isset($types[$col]) ? $types[$col] : '';
    if ($t !== '' && preg_match('/^(decimal|numeric|int|bigint|smallint|mediumint|tinyint|float|double)/', $t)) {
        return "COALESCE(dmi.$col, 0)";
    }
    return rp_clean_amount_sql('dmi.' . $col);
}

function rp_rcp_fetch($conn, array $f, $role = '', $use_cache = true) {
    $keys = array();
    foreach (array('start_date', 'end_date', 'grp', 'department', 'q') as $k) { $keys[] = isset($f[$k]) ? (string)$f[$k] : ''; }
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rcp_' . md5(implode('|', $keys)) . '.cache';

    if ($use_cache && empty($_GET['refresh']) && is_file($file) && (time() - filemtime($file)) < 120) {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? @unserialize($raw, array('allowed_classes' => false)) : false;
        if (is_array($data)) return $data;
    }

    $rows = rp_rcp_fetch_uncached($conn, $f);
    if ($use_cache) { @file_put_contents($file, serialize($rows), LOCK_EX); }
    return $rows;
}

function rp_rcp_fetch_uncached($conn, array $f) {
    $db_cols = rp_rcp_detect_columns($conn);

    $pick = function ($names) use ($db_cols) {
        foreach ($names as $n) {
            if (isset($db_cols[$n])) return 'ae.' . $db_cols[$n];
        }
        return "''";
    };

    $pres_col        = $pick(array('president', 'president_name'));
    $pres_email_col  = $pick(array('president_email'));
    $head_col        = $pick(array('bu_head'));
    $head_email_col  = $pick(array('bu_head_email'));
    $coord_col       = $pick(array('bu_admin_coordinator', 'bu_admin_name', 'bu_coordinator'));
    $coord_email_col = $pick(array('bu_admin_coordinator_email', 'bu_admin_email', 'bu_coordinator_email'));

    $cc = rp_rcp_amount_sql($conn, 'current_charges');
    $ap = rp_rcp_amount_sql($conn, 'approved_plan');
    $processed_dm = rp_rcp_amount_sql($conn, 'debit_memo_details');
    $add_ons = rp_rcp_amount_sql($conn, 'add_ons');
    $dd = "(($processed_dm) - ($add_ons))";

    $item_where = '';
    $params = array();
    if (!empty($f['start_date'])) { $item_where .= " AND dmi.coverage_start >= ?"; $params[] = $f['start_date']; }
    if (!empty($f['end_date']))   { $item_where .= " AND dmi.coverage_start <= ?"; $params[] = $f['end_date']; }

    $where = '';
    if (!empty($f['grp']))        { $where .= " AND ae.group_name = ?";  $params[] = $f['grp']; }
    if (!empty($f['department'])) { $where .= " AND ae.department = ?"; $params[] = $f['department']; }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $where .= " AND (ae.account_number LIKE ? OR ae.full_name LIKE ? OR ae.employee_id LIKE ? OR ae.department LIKE ? OR ae.group_name LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }

    $sql = "SELECT dm.dm_id, ae.account_number, ae.full_name, ae.employee_id, ae.mobile_number, ae.group_name,
                   ae.department, dm.company AS company_name,
                   $pres_col AS president_name, $pres_email_col AS president_email,
                   $head_col AS bu_head_name, $head_email_col AS bu_head_email,
                   $coord_col AS coord_name, $coord_email_col AS coord_email,
                   t.cur, t.plan, t.excess
            FROM (
                SELECT dmi.dm_id,
                       SUM(COALESCE($cc, 0)) AS cur,
                       SUM(COALESCE($ap, 0)) AS plan,
                       SUM(COALESCE($dd, 0)) AS excess
                FROM debit_memo_items dmi
                WHERE 1=1 $item_where
                GROUP BY dmi.dm_id
            ) t
            INNER JOIN debit_memos dm ON dm.dm_id = t.dm_id
            INNER JOIN account_emails ae ON ae.account_number = TRIM(dm.account_number)
            WHERE 1=1 $where
            ORDER BY ae.group_name ASC, ae.department ASC, ae.account_number ASC";

    return rp_all($conn, $sql, $params);
}

function rp_rcp_split_emails($s) {
    $out = array();
    foreach (preg_split('/[;,\s]+/', trim((string)$s), -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[strtolower($e)] = $e; }
    }
    return array_values($out);
}

function rp_rcp_coverage(array $f) {
    $a = isset($f['start_date']) ? $f['start_date'] : '';
    $b = isset($f['end_date']) ? $f['end_date'] : '';
    if ($a !== '' && $b !== '') return rp_date($a) . ' to ' . rp_date($b);
    if ($a !== '') return 'From ' . rp_date($a);
    if ($b !== '') return 'Up to ' . rp_date($b);
    return 'As of current billing';
}

function rp_rcp_targets(array $accounts, $type) {
    $t = array();
    foreach ($accounts as $a) {
        if ($type === 'group') {
            $key = trim((string)$a['group_name']);
            if ($key === '') $key = 'No group';
            $mail_cols = array('president_email');
            $name_cols = array('president_name');
        } else {
            $key = trim((string)$a['department']);
            if ($key === '') $key = 'No department';
            $mail_cols = array('bu_head_email', 'coord_email');
            $name_cols = array('bu_head_name', 'coord_name');
        }

        if (!isset($t[$key])) {
            $t[$key] = array('key' => $key, 'label' => $key, 'to' => array(), 'names' => array(),
                             'accounts' => array(), 'cur' => 0.0, 'plan' => 0.0, 'excess' => 0.0);
        }
        foreach ($mail_cols as $c) {
            foreach (rp_rcp_split_emails(isset($a[$c]) ? $a[$c] : '') as $e) { $t[$key]['to'][strtolower($e)] = $e; }
        }
        foreach ($name_cols as $c) {
            $nm = trim((string)(isset($a[$c]) ? $a[$c] : ''));
            if ($nm !== '') $t[$key]['names'][$nm] = true;
        }
        $t[$key]['accounts'][] = $a;
        $t[$key]['cur']    += (float)$a['cur'];
        $t[$key]['plan']   += (float)$a['plan'];
        $t[$key]['excess'] += (float)$a['excess'];
    }
    foreach ($t as $k => $v) { $t[$k]['to'] = array_values($v['to']); }
    ksort($t, SORT_NATURAL | SORT_FLAG_CASE);
    return $t;
}

function rp_rcp_build($type, array $target, $coverage) {
    if ($type === 'group') {
        $by = array();
        foreach ($target['accounts'] as $a) {
            $d = trim((string)$a['department']);
            if ($d === '') $d = 'No department';
            if (!isset($by[$d])) {
                $by[$d] = array('name' => $d, 'identifier' => '', 'current_charges' => 0, 'approved_plan' => 0, 'excess' => 0);
            }
            $by[$d]['current_charges'] += (float)$a['cur'];
            $by[$d]['approved_plan']   += (float)$a['plan'];
            $by[$d]['excess']          += (float)$a['excess'];
        }
        return getPresidentEmailTemplate($target['label'], array_values($by), $coverage, '', 'Department');
    }

    $rows = array();
    foreach ($target['accounts'] as $a) {
        $ident = trim((string)$a['employee_id']) !== '' ? $a['employee_id'] : (trim((string)$a['mobile_number']) !== '' ? $a['mobile_number'] : $a['account_number']);
        $nm = trim((string)$a['full_name']) !== '' ? $a['full_name'] : '(no name on file)';
        $rows[] = array(
            'name'            => $nm,
            'identifier'      => $ident,
            'current_charges' => (float)$a['cur'],
            'approved_plan'   => (float)$a['plan'],
            'excess'          => (float)$a['excess'],
        );
    }
    return getBuAdminEmailTemplate($target['label'], $rows, $coverage, '');
}

// -------------------------------------------------------------------------
// PDF SUMMARY REPORT (President: may Group  |  BU Admin / Coordinator: walang Group)
// -------------------------------------------------------------------------

// Hanapin / i-load ang FPDF (ito rin ang ginagamit ng pdf_generator.php sa list_dm)
function rp_rcp_load_fpdf() {
    if (class_exists('FPDF')) return true;
    if (is_file(__DIR__ . '/pdf_generator.php')) {
        try { require_once __DIR__ . '/pdf_generator.php'; } catch (Throwable $e) { error_log('rcp fpdf: ' . $e->getMessage()); }
        if (class_exists('FPDF')) return true;
    }
    foreach (array('/fpdf/fpdf.php', '/fpdf.php', '/lib/fpdf/fpdf.php', '/libs/fpdf/fpdf.php', '/vendor/setasign/fpdf/fpdf.php') as $rel) {
        if (is_file(__DIR__ . $rel)) {
            require_once __DIR__ . $rel;
            if (class_exists('FPDF')) return true;
        }
    }
    if (is_file(__DIR__ . '/vendor/autoload.php')) { require_once __DIR__ . '/vendor/autoload.php'; }
    return class_exists('FPDF');
}

// FPDF uses Windows-1252: i-convert ang UTF-8 (hal. ñ) at putulin kung masyadong mahaba
function rp_rcp_pdf_txt($s, $max = 0) {
    $s = (string)$s;
    $t = function_exists('iconv') ? @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) : false;
    if ($t === false) { $t = preg_replace('/[^\x20-\x7E]/', '?', $s); }
    if ($max > 0 && strlen($t) > $max) { $t = substr($t, 0, $max - 1) . '.'; }
    return $t;
}

function rp_rcp_generate_summary_pdf($type, array $target, $coverage) {
    if (!rp_rcp_load_fpdf()) return null;

    $is_group = ($type === 'group');

    // key, heading, width (mm), align, max characters
    if ($is_group) {
        $cols = array(
            array('group_name',     'Group',                30, 'L', 17),
            array('department',     'Department',           35, 'L', 20),
            array('employee_id',    'Employee ID',          22, 'L', 12),
            array('full_name',      'Employee Name',        45, 'L', 27),
            array('account_number', 'Account Number',       30, 'L', 16),
            array('mobile_number',  'Mobile Number',        28, 'L', 15),
            array('plan',           'Approved Plan',        26, 'R', 0),
            array('cur',            'Current Charges',      30, 'R', 0),
            array('excess',         'Total Excess Charges', 31, 'R', 0),
        );
        $title = 'GROUP SUMMARY REPORT - PRESIDENT';
    } else {
        $cols = array(
            array('department',     'Department',           45, 'L', 26),
            array('employee_id',    'Employee ID',          25, 'L', 14),
            array('full_name',      'Employee Name',        50, 'L', 30),
            array('account_number', 'Account Number',       32, 'L', 17),
            array('mobile_number',  'Mobile Number',        30, 'L', 16),
            array('plan',           'Approved Plan',        30, 'R', 0),
            array('cur',            'Current Charges',      32, 'R', 0),
            array('excess',         'Total Excess Charges', 33, 'R', 0),
        );
        $title = 'DEPARTMENT SUMMARY REPORT - BU ADMIN';
    }

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetTextColor(15, 118, 110);
    $pdf->Cell(0, 8, $title, 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(0, 5, rp_rcp_pdf_txt(($is_group ? 'Group: ' : 'Department: ') . $target['label'] . '   |   Coverage: ' . $coverage), 0, 1, 'C');
    $pdf->Cell(0, 5, 'Generated: ' . date('M d, Y g:i A'), 0, 1, 'C');
    $pdf->Ln(3);

    $draw_header = function () use ($pdf, $cols) {
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetFillColor(230, 241, 239);
        $pdf->SetTextColor(11, 90, 84);
        foreach ($cols as $c) { $pdf->Cell($c[2], 7, $c[1], 1, 0, $c[3], true); }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(30, 30, 30);
    };
    $draw_header();

    $tot_plan = 0.0; $tot_cur = 0.0; $tot_excess = 0.0;
    foreach ($target['accounts'] as $a) {
        if ($pdf->GetY() > 188) { $pdf->AddPage(); $draw_header(); }
        $tot_plan   += (float)$a['plan'];
        $tot_cur    += (float)$a['cur'];
        $tot_excess += (float)$a['excess'];
        foreach ($cols as $c) {
            $v = isset($a[$c[0]]) ? $a[$c[0]] : '';
            $txt = ($c[3] === 'R') ? number_format((float)$v, 2) : rp_rcp_pdf_txt($v, $c[4]);
            $pdf->Cell($c[2], 6, $txt, 1, 0, $c[3]);
        }
        $pdf->Ln();
    }

    if ($pdf->GetY() > 188) { $pdf->AddPage(); $draw_header(); }
    $label_w = 0;
    foreach ($cols as $c) { if ($c[3] === 'L') $label_w += $c[2]; }
    $num = array_values(array_filter($cols, function ($c) { return $c[3] === 'R'; }));
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell($label_w, 7, 'TOTAL', 1, 0, 'R', true);
    $pdf->Cell($num[0][2], 7, number_format($tot_plan, 2), 1, 0, 'R', true);
    $pdf->Cell($num[1][2], 7, number_format($tot_cur, 2), 1, 0, 'R', true);
    $pdf->Cell($num[2][2], 7, number_format($tot_excess, 2), 1, 0, 'R', true);
    $pdf->Ln();

    $tmp = tempnam(sys_get_temp_dir(), 'sum_pdf_');
    $pdf->Output('F', $tmp);

    return array('path' => $tmp, 'name' => rp_rcp_summary_name($type, $target));
}

function rp_rcp_summary_name($type, array $target) {
    $clean = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $target['label']);
    return ($type === 'group' ? 'President_Summary_' : 'BU_Admin_Summary_') . trim($clean, '_') . '.pdf';
}

function rp_rcp_dm_pdfs($conn, array $accounts, array $f) {
    $files = array();
    if (!is_file(__DIR__ . '/pdf_generator.php')) return $files;
    require_once __DIR__ . '/pdf_generator.php';
    if (!function_exists('createDebitMemoPDF')) return $files;

    $done = array();
    foreach ($accounts as $a) {
        $dm_id = (int)$a['dm_id'];
        if ($dm_id <= 0 || isset($done[$dm_id])) continue;
        $done[$dm_id] = true;
        try {
            $res = createDebitMemoPDF($dm_id, $conn, array(),
                isset($f['start_date']) ? $f['start_date'] : '', isset($f['end_date']) ? $f['end_date'] : '');
            if (is_array($res) && count($res) == 2) {
                $tmp = tempnam(sys_get_temp_dir(), 'dm_pdf_');
                $res[0]->Output('F', $tmp);
                $files[] = array('path' => $tmp, 'name' => 'Debit_Memo_' . $res[1] . '.pdf');
            }
        } catch (Throwable $e) {
            error_log('reports recipients DM pdf ' . $dm_id . ': ' . $e->getMessage());
        }
    }
    return $files;
}

function rp_rcp_cleanup(array $files) {
    foreach ($files as $fl) {
        if (!empty($fl['path']) && is_file($fl['path'])) { @unlink($fl['path']); }
    }
}

// -------------------------------------------------------------------------
// SENDING (Includes both Summary PDF & Individual Debit Memos)
// -------------------------------------------------------------------------
function rp_rcp_send(array $to, $subject, $html, array $attachments, array $cc = array()) {
    if (empty($to)) return array(false, 'No valid email address');

    // Same Gmail SMTP login as send_bulk_email.php (list_dm) - see smtp_mailer.php
    if (is_file(__DIR__ . '/smtp_mailer.php')) {
        require_once __DIR__ . '/smtp_mailer.php';
        if (function_exists('rp_smtp_send')) {
            return rp_smtp_send($to, $subject, $html, $attachments, $cc);
        }
    }

    $from      = defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : 'localhost');
    $from_name = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'IT Telco Admin Team';

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer') && file_exists($autoload)) {
        require_once $autoload;
    }

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->CharSet = 'UTF-8';
            if (defined('SMTP_HOST')) {
                $m->isSMTP();
                $m->Host = SMTP_HOST;
                $m->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
                if (defined('SMTP_USER')) {
                    $m->SMTPAuth = true;
                    $m->Username = SMTP_USER;
                    $m->Password = defined('SMTP_PASS') ? SMTP_PASS : '';
                }
                if (defined('SMTP_SECURE')) { $m->SMTPSecure = SMTP_SECURE; }
            }
            $m->setFrom($from, $from_name);
            foreach ($to as $addr) { $m->addAddress($addr); }
            foreach ($cc as $addr) { $m->addCC($addr); }
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = trim(strip_tags(str_replace(array('<br>', '</tr>', '</p>'), "\n", $html)));
            foreach ($attachments as $att) {
                if (!empty($att['path']) && is_file($att['path'])) {
                    $m->addAttachment($att['path'], $att['name']);
                }
            }
            $m->send();
            return array(true, 'Sent');
        } catch (\Exception $e) {
            return array(false, $e->getMessage());
        }
    }

    $boundary = md5(uniqid('', true));
    $headers  = 'From: ' . $from_name . ' <' . $from . ">\r\n";
    if (!empty($cc)) { $headers .= 'Cc: ' . implode(', ', $cc) . "\r\n"; }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $msg  = '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($html)) . "\r\n";
    foreach ($attachments as $att) {
        if (!empty($att['path']) && is_file($att['path'])) {
            $msg .= '--' . $boundary . "\r\n";
            $msg .= 'Content-Type: application/pdf; name="' . $att['name'] . "\"\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= 'Content-Disposition: attachment; filename="' . $att['name'] . "\"\r\n\r\n";
            $msg .= chunk_split(base64_encode(file_get_contents($att['path']))) . "\r\n";
        }
    }
    $msg .= '--' . $boundary . '--';

    $ok = @mail(implode(',', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $msg, $headers);
    return $ok ? array(true, 'Sent') : array(false, 'mail() could not send the message');
}

// -------------------------------------------------------------------------
// ADMIN DISPATCH REPORT (kapareho ng list_dm): Total Processed / Successful / Failed + CSV attachment
// -------------------------------------------------------------------------
function rp_rcp_send_admin_report($conn, array $items) {
    $success = 0; $failed = 0;
    foreach ($items as $it) { if ($it['status'] === 'SUCCESS') $success++; else $failed++; }
    $total = count($items);
    $res = array('ok' => false, 'message' => '', 'recipients' => array(), 'total' => $total, 'success' => $success, 'failed' => $failed);

    // recipients: admins (email_report) + the user who sent
    $recipients = array();
    try {
        $st = $conn->prepare("SELECT DISTINCT recipient_email FROM email_report WHERE report_type = ?");
        $st->execute(array('statement_dispatch'));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['recipient_email'])) $recipients[] = trim($r['recipient_email']);
        }
        if (!empty($_SESSION['user_email'])) {
            $recipients[] = trim($_SESSION['user_email']);
        } elseif (!empty($_SESSION['username'])) {
            $us = $conn->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
            $us->execute(array($_SESSION['username']));
            $u = $us->fetch(PDO::FETCH_ASSOC);
            if ($u && !empty($u['email'])) $recipients[] = trim($u['email']);
        }
    } catch (Throwable $e) {
        error_log('rcp admin report recipients: ' . $e->getMessage());
    }
    $valid = array();
    foreach ($recipients as $r) { if (filter_var($r, FILTER_VALIDATE_EMAIL)) $valid[strtolower($r)] = $r; }
    $recipients = array_values($valid);
    if (empty($recipients)) {
        $res['message'] = 'No admin recipients found (email_report / your user email).';
        return $res;
    }

    $now    = date('Y-m-d H:i:s');
    $sender = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'System Administrator');

    // CSV attachment
    $csv_name = 'BU_Admin_President_Dispatch_Report_' . date('Ymd_His') . '.csv';
    $csv_path = tempnam(sys_get_temp_dir(), 'rcp_csv_');
    $fh = fopen($csv_path, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array('Status', 'Date', 'Sent To (Role)', 'Group / Department', 'Recipient Email', 'CC', 'Coverage', 'Accounts', 'Current Charges', 'Total Excess Charges', 'Attachments', 'Sent By', 'Details'));

    $rows_html = '';
    foreach ($items as $it) {
        fputcsv($fh, array($it['status'], $it['date'], $it['type'], $it['label'], $it['to'], $it['cc'], $it['coverage'],
                           $it['accounts'], number_format((float)$it['current'], 2, '.', ''), number_format((float)$it['excess'], 2, '.', ''),
                           $it['attachments'], $it['sent_by'], $it['details']));

        $ok_row = ($it['status'] === 'SUCCESS');
        $bg = $ok_row ? '#d1fae5' : '#fee2e2';
        $fg = $ok_row ? '#065f46' : '#991b1b';
        $td = "padding: 8px; border: 1px solid #cbd5e1;";
        $rows_html .= "<tr>"
            . "<td style='{$td} text-align: center; font-weight: bold;'><span style='background-color: {$bg}; color: {$fg}; padding: 3px 8px; border-radius: 4px; font-size: 10px; display: inline-block;'>" . et_e($it['status']) . "</span></td>"
            . "<td style='{$td} text-align: center;'>" . et_e($it['date']) . "</td>"
            . "<td style='{$td} text-align: center;'>" . et_e($it['type']) . "</td>"
            . "<td style='{$td} font-weight: bold; color: #1e40af;'>" . et_e($it['label']) . "</td>"
            . "<td style='{$td} word-break: break-all;'>" . et_e($it['to']) . "</td>"
            . "<td style='{$td} text-align: center;'>" . number_format((int)$it['accounts']) . "</td>"
            . "<td style='{$td} text-align: right;'>" . number_format((float)$it['current'], 2) . "</td>"
            . "<td style='{$td} text-align: right; font-weight: bold;'>" . number_format((float)$it['excess'], 2) . "</td>"
            . "<td style='{$td} word-break: break-word; color: " . ($ok_row ? '#475569' : '#991b1b') . ";'>" . et_e($it['details']) . "</td>"
            . "</tr>";
    }
    fclose($fh);

    $th = "padding: 8px; border: 1px solid #cbd5e1;";
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>"
        . "<body style='font-family: Arial, sans-serif; font-size: 13px; color: #333333; line-height: 1.5; background-color: #f8fafc; padding: 20px;'>"
        . "<div style='max-width: 900px; margin: 0 auto; background: #ffffff; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0;'>"
        . "<p style='font-size: 13px; margin-bottom: 8px; color: #475569;'>Here is the summary of the BU Admin &amp; President emails dispatched on <strong>" . et_e($now) . "</strong> by <strong>" . et_e($sender) . "</strong>.</p>"
        . "<table style='width: 100%; border-collapse: separate; border-spacing: 12px 0; margin-top: 15px; margin-bottom: 20px;'><tr>"
        . "<td style='width: 33%; padding: 14px; background-color: #eff6ff; border: 1px solid #bfdbfe; text-align: center; border-radius: 8px;'><div style='font-size: 11px; color: #1e40af; font-weight: bold; text-transform: uppercase;'>TOTAL PROCESSED</div><div style='font-size: 24px; color: #1e3a8a; font-weight: bold; margin-top: 4px;'>{$total}</div></td>"
        . "<td style='width: 33%; padding: 14px; background-color: #ecfdf5; border: 1px solid #a7f3d0; text-align: center; border-radius: 8px;'><div style='font-size: 11px; color: #047857; font-weight: bold; text-transform: uppercase;'>SUCCESSFUL</div><div style='font-size: 24px; color: #065f46; font-weight: bold; margin-top: 4px;'>{$success}</div></td>"
        . "<td style='width: 33%; padding: 14px; background-color: #fef2f2; border: 1px solid #fecaca; text-align: center; border-radius: 8px;'><div style='font-size: 11px; color: #b91c1c; font-weight: bold; text-transform: uppercase;'>FAILED</div><div style='font-size: 24px; color: #991b1b; font-weight: bold; margin-top: 4px;'>{$failed}</div></td>"
        . "</tr></table>"
        . "<h3 style='font-size: 14px; color: #1e293b; margin-bottom: 10px; font-weight: bold;'>Dispatch Details Breakdown</h3>"
        . "<table border='0' cellpadding='0' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 11px; border: 1px solid #cbd5e1;'>"
        . "<thead><tr style='background-color: #f1f5f9; color: #334155; text-align: center; font-weight: bold;'>"
        . "<th style='{$th}'>Status</th><th style='{$th}'>Date</th><th style='{$th}'>Sent To</th><th style='{$th}'>Group / Department</th><th style='{$th}'>Recipient Email</th>"
        . "<th style='{$th}'>Accounts</th><th style='{$th}'>Current Charges</th><th style='{$th}'>Excess Charges</th><th style='{$th}'>Details</th>"
        . "</tr></thead><tbody>{$rows_html}</tbody></table>"
        . "<p style='margin-top: 15px; font-size: 11px; color: #64748b; font-style: italic;'>Note: the complete summary report is attached as a CSV file for your records.</p>"
        . "</div></body></html>";

    $attach = array(array('path' => $csv_path, 'name' => $csv_name, 'type' => 'text/csv'));
    $sent_to = array();
    $errors  = array();
    foreach ($recipients as $r) {
        list($ok, $msg) = rp_rcp_send(array($r), 'BU Admin & President Dispatch Report - ' . date('Y-m-d'), $html, $attach);
        if ($ok) { $sent_to[] = $r; } else { $errors[] = $r . ' (' . $msg . ')'; }
    }
    if (is_file($csv_path)) @unlink($csv_path);

    $res['recipients'] = $sent_to;
    $res['ok'] = !empty($sent_to);
    $res['message'] = $res['ok'] ? 'Admin report sent.' : ('Admin report failed: ' . implode('; ', $errors));
    return $res;
}

function rp_rcp_clean_filters($src) {
    $pf = array('start_date' => '', 'end_date' => '', 'grp' => '', 'department' => '', 'q' => '');
    if (!is_array($src)) return $pf;
    foreach (array('start_date', 'end_date') as $k) {
        $v = isset($src[$k]) ? trim((string)$src[$k]) : '';
        $pf[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
    foreach (array('grp', 'department', 'q') as $k) {
        $pf[$k] = isset($src[$k]) ? trim((string)$src[$k]) : '';
    }
    return $pf;
}

// AJAX para sa review / send modals (parehong istilo ng list_dm.php). Laging JSON ang sagot.
function rp_rcp_handle_post($conn) {
    if (empty($_GET['rcp_ajax'])) return;
    $what = (string)$_GET['rcp_ajax'];

    ini_set('display_errors', '0');
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $out = function ($payload) {
        while (ob_get_level()) { ob_end_clean(); }
        echo json_encode($payload, (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0) | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    };
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            while (ob_get_level()) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(array('status' => 'error', 'message' => 'Server error: ' . $err['message']));
        }
    });
    $csrf_ok = function ($t) {
        return !empty($_SESSION['rcp_csrf']) && hash_equals($_SESSION['rcp_csrf'], (string)$t);
    };

    try {
        // ---------------- REVIEW: build the emails for the checked rows ----------------
        if ($what === 'review') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !$csrf_ok(isset($input['csrf']) ? $input['csrf'] : '')) {
                $out(array('status' => 'error', 'message' => 'Session expired. Reload the page and try again.'));
            }
            $type = (isset($input['type']) && $input['type'] === 'dept') ? 'dept' : 'group';
            $keys = (isset($input['keys']) && is_array($input['keys'])) ? array_map('strval', $input['keys']) : array();
            $pf   = rp_rcp_clean_filters(isset($input['filters']) ? $input['filters'] : array());

            $targets  = rp_rcp_targets(rp_rcp_fetch($conn, $pf), $type);
            $coverage = rp_rcp_coverage($pf);
            $pdf_ok   = rp_rcp_load_fpdf();

            $items = array();
            foreach ($keys as $key) {
                $item = array('key' => $key, 'label' => $key, 'type' => $type, 'has_data' => false, 'recipient_email' => '', 'cc_emails' => '',
                              'subject' => '', 'html_content' => '', 'coverage' => $coverage, 'account_count' => 0, 'excess' => 0,
                              'names' => '', 'attachments' => array());
                if (isset($targets[$key])) {
                    $t = $targets[$key];
                    $mail = rp_rcp_build($type, $t, $coverage);
                    $att = array(array('kind' => 'summary', 'name' => rp_rcp_summary_name($type, $t), 'dm_id' => 0, 'checked' => $pdf_ok));
                    $seen = array();
                    foreach ($t['accounts'] as $a) {
                        $id = (int)$a['dm_id'];
                        if ($id > 0 && !isset($seen[$id])) {
                            $seen[$id] = true;
                            $att[] = array('kind' => 'dm', 'name' => 'Debit_Memo_' . $a['account_number'] . '.pdf', 'dm_id' => $id, 'checked' => false);
                        }
                    }
                    $item['label']           = $t['label'];
                    $item['has_data']        = !empty($t['accounts']);
                    $item['recipient_email'] = implode(', ', $t['to']);
                    $item['subject']         = $mail['subject'];
                    $item['html_content']    = $mail['body'];
                    $item['account_count']   = count($t['accounts']);
                    $item['excess']          = (float)$t['excess'];
                    $item['names']           = implode(', ', array_keys($t['names']));
                    $item['attachments']     = $att;
                }
                $items[] = $item;
            }
            $out(array('status' => 'success', 'items' => $items, 'summary_pdf_available' => $pdf_ok));
        }

        // ---------------- SEND: one email per request ----------------
        if ($what === 'send') {
            if (!$csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
                $out(array('status' => 'error', 'message' => 'Session expired. Reload the page and try again.'));
            }
            $type    = (isset($_POST['type']) && $_POST['type'] === 'dept') ? 'dept' : 'group';
            $key     = isset($_POST['key']) ? (string)$_POST['key'] : '';
            $pf      = rp_rcp_clean_filters($_POST);
            $to      = rp_rcp_split_emails(isset($_POST['to']) ? $_POST['to'] : '');
            $cc      = rp_rcp_split_emails(isset($_POST['cc']) ? $_POST['cc'] : '');
            $subject = isset($_POST['subject']) ? trim((string)$_POST['subject']) : '';
            $html    = isset($_POST['html_content']) ? (string)$_POST['html_content'] : '';

            // a new dispatch starts: forget the previous batch (the admin report is built from this list)
            if (isset($_POST['first']) && $_POST['first'] === '1') { $_SESSION['rcp_batch'] = array(); }

            $item = array(
                'status'      => 'FAILED',
                'date'        => date('Y-m-d H:i:s'),
                'type'        => ($type === 'group') ? 'President' : 'BU Admin / Coordinator',
                'label'       => $key,
                'to'          => implode(', ', $to),
                'cc'          => implode(', ', $cc),
                'coverage'    => rp_rcp_coverage($pf),
                'accounts'    => 0,
                'current'     => 0.0,
                'excess'      => 0.0,
                'attachments' => '',
                'sent_by'     => isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'System Administrator'),
                'details'     => '',
            );

            // record the result of this email (for the admin report) and answer the browser
            $finish = function ($ok, $msg) use (&$item, $out) {
                $item['status']  = $ok ? 'SUCCESS' : 'FAILED';
                $item['details'] = $msg;
                if (!isset($_SESSION['rcp_batch']) || !is_array($_SESSION['rcp_batch'])) { $_SESSION['rcp_batch'] = array(); }
                $_SESSION['rcp_batch'][] = $item;
                $out(array(
                    'status'          => 'success',
                    'success_count'   => $ok ? 1 : 0,
                    'fail_count'      => $ok ? 0 : 1,
                    'success_details' => $ok ? array($item['label'] . ' -> ' . $item['to'] . ($item['attachments'] !== '' ? ' [' . $item['attachments'] . ']' : '') . ($msg !== 'Sent' ? ' - ' . $msg : '')) : array(),
                    'failed_details'  => $ok ? array() : array($item['label'] . ': ' . $msg),
                ));
            };

            if (empty($to)) $finish(false, 'No valid recipient email');
            if ($subject === '' || trim($html) === '') $finish(false, 'Subject or message is empty');

            $targets = rp_rcp_targets(rp_rcp_fetch($conn, $pf), $type);
            if (!isset($targets[$key])) $finish(false, 'Not found for these filters');
            $t = $targets[$key];
            $item['label']    = $t['label'];
            $item['accounts'] = count($t['accounts']);
            $item['current']  = (float)$t['cur'];
            $item['excess']   = (float)$t['excess'];

            $files = array();
            $notes = array();

            // 1) Summary PDF report
            if (isset($_POST['include_summary']) && $_POST['include_summary'] === '1') {
                $sp = rp_rcp_generate_summary_pdf($type, $t, rp_rcp_coverage($pf));
                if ($sp) { $files[] = $sp; } else { $notes[] = 'Summary PDF skipped (FPDF not found)'; }
            }

            // 2) Optional Debit Memo PDFs (the ones that were checked)
            $ids = array();
            foreach (explode(',', isset($_POST['dm_ids']) ? (string)$_POST['dm_ids'] : '') as $i) { $i = (int)$i; if ($i > 0) $ids[$i] = true; }
            if (!empty($ids)) {
                $sel = array();
                foreach ($t['accounts'] as $a) { if (isset($ids[(int)$a['dm_id']])) $sel[] = $a; }
                $files = array_merge($files, rp_rcp_dm_pdfs($conn, $sel, $pf));
            }

            // 3) Extra uploaded files
            if (!empty($_FILES['additional_attachments']['name']) && is_array($_FILES['additional_attachments']['name'])) {
                foreach ($_FILES['additional_attachments']['name'] as $n => $name) {
                    if ($_FILES['additional_attachments']['error'][$n] === UPLOAD_ERR_OK) {
                        $tmp = tempnam(sys_get_temp_dir(), 'rcp_up_');
                        if (move_uploaded_file($_FILES['additional_attachments']['tmp_name'][$n], $tmp)) {
                            $files[] = array('path' => $tmp, 'name' => basename($name));
                        }
                    }
                }
            }

            $names = array();
            foreach ($files as $fl) { $names[] = $fl['name']; }
            $item['attachments'] = implode('; ', $names);

            list($ok, $msg) = rp_rcp_send($to, $subject, $html, $files, $cc);
            rp_rcp_cleanup($files);

            $finish($ok, $ok ? ('Sent' . (empty($notes) ? '' : ' - ' . implode('; ', $notes))) : $msg);
        }

        // ---------------- ADMIN REPORT: after everything was sent ----------------
        if ($what === 'admin_report') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !$csrf_ok(isset($input['csrf']) ? $input['csrf'] : '')) {
                $out(array('status' => 'error', 'message' => 'Session expired. Reload the page and try again.'));
            }
            $items = (isset($_SESSION['rcp_batch']) && is_array($_SESSION['rcp_batch'])) ? $_SESSION['rcp_batch'] : array();
            unset($_SESSION['rcp_batch']);

            // emails that never reached the server (network error, etc.)
            if (isset($input['client_fails']) && is_array($input['client_fails'])) {
                foreach ($input['client_fails'] as $cf) {
                    $items[] = array(
                        'status' => 'FAILED', 'date' => date('Y-m-d H:i:s'), 'type' => '-',
                        'label' => isset($cf['label']) ? (string)$cf['label'] : '', 'to' => '', 'cc' => '', 'coverage' => '',
                        'accounts' => 0, 'current' => 0.0, 'excess' => 0.0, 'attachments' => '',
                        'sent_by' => isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'System Administrator'),
                        'details' => isset($cf['message']) ? (string)$cf['message'] : 'Request failed',
                    );
                }
            }
            if (empty($items)) $out(array('status' => 'error', 'message' => 'Nothing to report.'));

            $res = rp_rcp_send_admin_report($conn, $items);
            $out(array('status' => $res['ok'] ? 'success' : 'error', 'message' => $res['message'], 'recipients' => $res['recipients'],
                       'total' => $res['total'], 'success' => $res['success'], 'failed' => $res['failed']));
        }

        $out(array('status' => 'error', 'message' => 'Unknown action'));
    } catch (Throwable $e) {
        error_log('reports.php rcp_ajax ' . $what . ': ' . $e->getMessage());
        $out(array('status' => 'error', 'message' => 'Server error: ' . $e->getMessage()));
    }
}

// -------------------------------------------------------------------------
// EXCEL & UI FUNCTIONS
// -------------------------------------------------------------------------
function rp_rcp_export(array $accounts, array $f, $role) {
    $out = array();
    foreach ($accounts as $a) {
        $out[] = array(
            $a['group_name'], $a['department'], $a['president_name'], $a['bu_head_name'], $a['coord_name'],
            $a['account_number'], $a['full_name'], $a['employee_id'], $a['mobile_number'],
            (float)$a['cur'], (float)$a['plan'], (float)$a['excess'],
        );
    }
    $meta = array(
        'BU ADMIN & PRESIDENT REPORT',
        'GROUP: ' . ($f['grp'] !== '' ? $f['grp'] : 'ALL'),
        'DEPARTMENT: ' . ($f['department'] !== '' ? $f['department'] : 'ALL'),
        'COVERAGE: ' . (($f['start_date'] !== '' || $f['end_date'] !== '') ? ($f['start_date'] . ' to ' . $f['end_date']) : 'ANY DATE'),
    );
    rp_xml_excel('BU_Admin_President_Report_' . date('Y-m-d_His') . '.xls', 'Recipients', $meta,
        array('Group', 'Department', 'President', 'BU Head', 'Telco BU Admin Coordinator', 'Account Number', 'Employee Name', 'Employee ID', 'Mobile Number', 'Current Charges', 'Approved Plan', 'Excess Charges'),
        $out, array(9, 10, 11));
}

function rp_rcp_flash_html() {
    if (empty($_SESSION['rcp_flash'])) return '';
    $fl = $_SESSION['rcp_flash'];
    unset($_SESSION['rcp_flash']);
    $style = $fl['ok'] ? 'background:#d7efe3;color:#0b5a3f;' : '';
    return '<div class="rp-alert" role="status" style="' . $style . '">' . rp_e($fl['text']) . '</div>';
}

function rp_rcp_filters(array $f, array $groups, array $departments = array()) {
    $h  = rp_select('grp', 'Group', rp_opts($groups), $f['grp'], 'All groups');
    $h .= rp_select('department', 'Department', rp_opts($departments), isset($f['department']) ? $f['department'] : '', 'All departments');
    $h .= '<label class="rp-field"><span>Search</span><input type="search" name="q" value="' . rp_e($f['q']) . '" placeholder="Account, name, or department"></label>';
    return $h;
}

function rp_rcp_tile($label, $value, $cls = '') {
    return '<div class="rp-stat"><dt>' . rp_e($label) . '</dt><dd class="' . $cls . '">' . $value . '</dd></div>';
}

function rp_rcp_stats(array $s) {
    $h  = '<section class="rp-panel" aria-label="Summary"><dl class="rp-stats">';
    $h .= rp_rcp_tile('Accounts with charges', number_format((int)$s['accounts']));
    $h .= rp_rcp_tile('Current charges', rp_e(rp_money($s['current_total'])));
    $h .= rp_rcp_tile('Excess charges', rp_e(rp_money($s['excess_total'])));
    return $h . '</dl></section>';
}

function rp_rcp_overview(array $chart, array $s, $role) { return ''; }

function rp_rcp_names($names) {
    return !empty($names) ? rp_e(implode(', ', array_keys($names))) : '—';
}

function rp_rcp_table($type, array $targets, array $f) {
    $is_group = ($type === 'group');
    $title    = $is_group ? 'Totals by Group' : 'Totals by Department';
    $send_lbl = $is_group ? 'Email Presidents (selected)' : 'Email BU Admin / Coordinator (selected)';
    $cls      = $is_group ? 'rcp-chk-group' : 'rcp-chk-dept';
    $noun     = $is_group ? 'group' : 'department';

    $h  = '<section class="rp-panel rp-card"' . ($is_group ? '' : ' style="margin-top:1.5rem;"') . '>';
    $h .= '<h3>' . $title . '</h3>';
    $h .= '<div class="rp-scroll"><table class="rp-tbl"><thead><tr>';
    $h .= '<th style="width:2.5rem;"><input type="checkbox" aria-label="Select all" onclick="rcpToggle(this, \'' . $cls . '\')"></th>';
    $h .= '<th>' . ($is_group ? 'Group / Company' : 'Department / BU') . '</th>';
    $h .= $is_group ? '<th>President</th>' : '<th>BU Head / Telco BU Admin Coordinator</th>';
    $h .= '<th>Send to</th>';
    $h .= '<th class="r">Accounts</th><th class="r">Total current charges</th><th class="r">Total excess charges</th><th>Actions</th>';
    $h .= '</tr></thead><tbody>';

    foreach ($targets as $key => $v) {
        $to_email = implode(', ', $v['to']);
        $jkey = rp_e(json_encode((string)$key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));

        $h .= '<tr>';
        $h .= '<td>' . (empty($v['to']) ? '' : '<input type="checkbox" class="' . $cls . '" value="' . rp_e($key) . '">') . '</td>';
        $h .= '<td class="strong">' . rp_e($v['label']) . '</td>';
        $h .= '<td>' . rp_rcp_names($v['names']) . '</td>';
        $h .= '<td class="clip" title="' . rp_e($to_email) . '">'
            . (empty($v['to']) ? '<span class="rp-chip rp-chip-bad">No email</span>' : rp_e($to_email)) . '</td>';
        $h .= '<td class="r">' . number_format(count($v['accounts'])) . '</td>';
        $h .= '<td class="r">₱ ' . number_format($v['cur'], 2) . '</td>';
        $h .= '<td class="r strong">₱ ' . number_format($v['excess'], 2) . '</td>';
        $h .= '<td>' . (empty($v['to']) ? '' : '<button type="button" class="rp-btn" style="height:2rem;padding:0 .8rem;" onclick="rcpPreviewRow(\'' . $type . '\', ' . $jkey . ')">Preview &amp; Send</button>') . '</td>';
        $h .= '</tr>';
    }
    if (!$targets) { $h .= '<tr><td colspan="8">No ' . $noun . ' totals found.</td></tr>'; }
    $h .= '</tbody></table></div>';

    if ($targets) {
        $h .= '<div style="margin-top:1rem;display:flex;justify-content:flex-end;">'
            . '<button type="button" class="rp-btn rp-btn-primary" onclick="rcpOpenReview(\'' . $type . '\')"><i class="las la-paper-plane" aria-hidden="true"></i> ' . $send_lbl . '</button></div>';
    }
    return $h . '</section>';
}

function rp_rcp_hub_html(array $f) {
    $cfg = array(
        'csrf'    => rp_rcp_csrf(),
        'filters' => array(
            'start_date' => isset($f['start_date']) ? $f['start_date'] : '',
            'end_date'   => isset($f['end_date']) ? $f['end_date'] : '',
            'grp'        => isset($f['grp']) ? $f['grp'] : '',
            'department' => isset($f['department']) ? $f['department'] : '',
            'q'          => isset($f['q']) ? $f['q'] : '',
        ),
    );
    $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

   $html = <<<'RCPHUB'
<!-- ========================================================================= -->
<!-- BU ADMIN & PRESIDENT EMAIL MODALS (review, preview, live log) - like list_dm -->
<!-- ========================================================================= -->

<!-- 1. REVIEW -->
<div id="rcpReviewModal" style="display:none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-6xl p-6 mx-4 flex flex-col max-h-[90vh]">
        <div class="flex justify-between items-start pb-4 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                    <i class="las la-envelope-open-text text-amber-600 text-xl"></i> Bulk Email Review &amp; Validation
                </h3>
                <p id="rcpReviewCount" class="text-xs text-gray-500 font-medium mt-0.5">0 emails loaded for review</p>
            </div>
            <button type="button" onclick="rcpCloseReview()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-700 hover:bg-gray-200 transition-all font-bold">✕</button>
        </div>

        <div class="py-3 px-4 my-3 bg-slate-50 border border-slate-200/80 rounded-2xl flex items-center gap-2 flex-wrap">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-bold"><span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Ready: <span id="rcpCountReady">0</span></span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-xs font-bold"><span class="w-2 h-2 rounded-full bg-rose-500"></span> Missing Email: <span id="rcpCountMissing">0</span></span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-lg text-xs font-bold"><span class="w-2 h-2 rounded-full bg-gray-400"></span> No Data: <span id="rcpCountNoData">0</span></span>
        </div>
        <div id="rcpPdfWarn" style="display:none;" class="mb-3 px-4 py-2.5 bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl text-xs font-semibold">
            The Summary PDF cannot be created because the FPDF library was not found on the server. Emails will be sent without the summary PDF.
        </div>

        <div class="border border-gray-200 rounded-2xl overflow-y-auto flex-grow max-h-[48vh] shadow-inner bg-white">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-100/80 sticky top-0 z-10 text-slate-700 font-bold uppercase text-[10px] tracking-wider">
                    <tr>
                        <th class="p-3 text-center w-28">Status</th>
                        <th class="p-3">Group / Department</th>
                        <th class="p-3">Coverage Dates</th>
                        <th class="p-3">Recipient Email</th>
                        <th class="p-3">CC Emails</th>
                        <th class="p-3 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="rcpReviewBody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>

        <div class="flex justify-end gap-3 mt-4 pt-3 border-t border-gray-100">
            <button type="button" onclick="rcpCloseReview()" class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-200 transition-all">Cancel</button>
            <button type="button" onclick="rcpProceed()" class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-2">
                <i class="las la-paper-plane text-sm"></i> Confirm &amp; Send Emails
            </button>
        </div>
    </div>
</div>

<!-- 2. PREVIEW -->
<div id="rcpPreviewModal" style="display:none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-3xl p-6 mx-4 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center mb-4 pb-2 border-b">
            <h3 id="rcpPreviewTitle" class="text-base font-bold text-gray-800">Email Preview &amp; Customization</h3>
            <button type="button" onclick="rcpClosePreview()" class="text-gray-400 hover:text-gray-600 font-bold">✕</button>
        </div>

        <div class="flex flex-col flex-grow overflow-y-auto space-y-3">
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">To Email (comma-separated)</label>
                <input type="text" id="rcpPvTo" class="w-full p-2 border rounded-xl text-xs bg-gray-50">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">CC Emails (Comma-separated)</label>
                <input type="text" id="rcpPvCc" class="w-full p-2 border rounded-xl text-xs bg-gray-50" placeholder="e.g. accounting@company.com">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Subject</label>
                <input type="text" id="rcpPvSubject" class="w-full p-2 border rounded-xl text-xs bg-gray-50">
            </div>

            <div class="flex flex-col flex-grow">
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                    <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                        <button type="button" onclick="rcpSwitchMode('visual')" id="rcpBtnVisual" class="px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all">Visual Preview</button>
                        <button type="button" onclick="rcpSwitchMode('code')" id="rcpBtnCode" class="px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all">HTML Code</button>
                    </div>
                </div>
                <div id="rcpVisual" class="w-full p-4 border rounded-xl bg-white shadow-inner overflow-y-auto max-h-72"></div>
                <textarea id="rcpPvBody" rows="8" oninput="rcpSyncVisual()" class="w-full p-3 border rounded-xl text-xs bg-gray-50 font-mono hidden"></textarea>
            </div>

            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Attachments to Include</label>
                    <button type="button" onclick="rcpToggleDm()" class="text-[10px] font-bold text-blue-600 hover:underline">Select / clear all Debit Memo PDFs</button>
                </div>
                <div id="rcpPvAtt" class="p-2 border rounded-xl bg-gray-50 text-xs space-y-1 max-h-28 overflow-y-auto"></div>
                <div class="mt-2 pt-2 border-t border-gray-200">
                    <label class="block text-[9px] font-bold text-emerald-600 uppercase mb-1">+ Upload New File (Optional)</label>
                    <input type="file" id="rcpPvFiles" multiple class="w-full p-1 border rounded-lg text-xs bg-white text-gray-600">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t">
                <button type="button" onclick="rcpClosePreview()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-300">Cancel</button>
                <button type="button" id="rcpBtnSave" onclick="rcpSaveAndClose()" class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700">Save Changes</button>
                <button type="button" onclick="rcpSendNow()" class="px-5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 shadow-md">Send Email Now</button>
            </div>
        </div>
    </div>
</div>

<!-- 3. LIVE LOG -->
<div id="rcpProgressModal" style="display:none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-4xl p-6 md:p-8 mx-4 flex flex-col max-h-[85vh] overflow-hidden">
        <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-4 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-50 border border-amber-100 flex items-center justify-center shadow-sm">
                    <i class="las la-paper-plane text-amber-600 text-xl animate-pulse"></i>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-gray-900 tracking-tight">Dispatching Summary Emails</h3>
                    <p class="text-[11px] text-gray-500 font-medium">Sending one email at a time...</p>
                </div>
            </div>
            <span id="rcpProgCounter" class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1.5 rounded-xl">Email 0/0</span>
        </div>

        <div class="overflow-y-auto flex-grow space-y-4 pr-1">
            <div class="bg-slate-50/80 border border-slate-200/80 p-4 rounded-2xl">
                <div class="flex justify-between items-center mb-2">
                    <span id="rcpProgText" class="text-sm md:text-base font-extrabold text-slate-900">Initializing dispatch process...</span>
                    <span id="rcpProgPct" class="text-xs font-extrabold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">0%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3.5 overflow-hidden p-0.5 border border-slate-300 shadow-inner">
                    <div id="rcpProgBar" class="bg-gradient-to-r from-emerald-500 to-teal-500 h-2.5 rounded-full transition-all duration-400 shadow-sm" style="width:0%"></div>
                </div>
            </div>
            <div class="flex flex-col min-h-[260px] max-h-[320px] bg-slate-950 text-slate-200 text-xs font-mono p-4 rounded-2xl shadow-inner border border-slate-800 overflow-hidden">
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800/80 text-[11px] text-slate-400 uppercase tracking-wider font-bold shrink-0">
                    <span>Live Terminal Activity Log</span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Live</span>
                </div>
                <div id="rcpProgLog" class="flex-grow overflow-y-auto space-y-1.5 pr-2">
                    <div class="text-slate-500 italic">Waiting for dispatch to start...</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4 mt-4 border-t border-gray-100 shrink-0">
            <button type="button" id="rcpCloseBtn" onclick="rcpCloseProgress()" style="display:none;" class="px-6 py-2.5 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 shadow-lg transition-all flex items-center gap-2">
                <i class="las la-check-circle text-base"></i> Close &amp; Reload Page
            </button>
        </div>
    </div>
</div>

<!-- 4. LOADER -->
<div id="rcpLoader" style="display:none;" class="fixed inset-0 z-[999999] flex flex-col items-center justify-center bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white p-6 rounded-2xl shadow-2xl flex flex-col items-center space-y-3 border border-slate-100">
        <i class="las la-spinner animate-spin text-4xl text-amber-600"></i>
        <p class="text-xs font-bold text-slate-700 tracking-wide uppercase">Preparing emails, please wait...</p>
    </div>
</div>

<script>
const RCP_CFG = __RCP_CONFIG__;
let rcpItems = [];
let rcpCur = -1;
let rcpDirect = false;   // preview opened straight from a table row (no review window behind it)

function rcpEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}
function rcpToggle(src, cls) { document.querySelectorAll('.' + cls).forEach(function (cb) { cb.checked = src.checked; }); }
function rcpIsEmail(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); }
function rcpEmailsValid(str) {
    const p = String(str || '').split(/[;,\s]+/).filter(Boolean);
    return p.length > 0 && p.every(rcpIsEmail);
}
function rcpStatus(it) {
    if (!it.has_data) return 'nodata';
    if (!rcpEmailsValid(it.recipient_email)) return 'missing';
    return 'ready';
}
function rcpBadge(st) {
    if (st === 'ready') return '<span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-[10px] font-bold block text-center border border-emerald-200">Ready</span>';
    if (st === 'missing') return '<span class="px-2.5 py-1 bg-rose-50 text-rose-700 rounded-lg text-[10px] font-bold block text-center border border-rose-200">Missing Email</span>';
    return '<span class="px-2.5 py-1 bg-gray-100 text-gray-600 rounded-lg text-[10px] font-bold block text-center border border-gray-200">No Data</span>';
}
function rcpLoader(on) { document.getElementById('rcpLoader').style.display = on ? 'flex' : 'none'; }

async function rcpFetchJson(url, options) {
    const res = await fetch(url, options);
    const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) {
        console.error('Non-JSON response from ' + url + ' (HTTP ' + res.status + '):', text);
        const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
        throw new Error('Server returned a non-JSON response (HTTP ' + res.status + ')' + (snippet ? ': ' + snippet : ''));
    }
}

async function rcpLoad(type, keys) {
    rcpLoader(true);
    try {
        const data = await rcpFetchJson('reports.php?rcp_ajax=review', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf: RCP_CFG.csrf, type: type, keys: keys, filters: RCP_CFG.filters})
        });
        rcpLoader(false);
        if (data.status !== 'success') throw new Error(data.message || 'Server error');
        rcpItems = data.items || [];
        document.getElementById('rcpPdfWarn').style.display = data.summary_pdf_available ? 'none' : 'block';
        return rcpItems.length > 0;
    } catch (err) {
        rcpLoader(false);
        alert('Error: ' + err.message);
        return false;
    }
}

async function rcpOpenReview(type) {
    const cls = type === 'group' ? '.rcp-chk-group' : '.rcp-chk-dept';
    const keys = Array.from(document.querySelectorAll(cls + ':checked')).map(function (c) { return c.value; });
    if (!keys.length) { alert('Please select at least one ' + (type === 'group' ? 'group' : 'department') + '.'); return; }
    rcpDirect = false;
    if (await rcpLoad(type, keys)) { rcpRenderReview(); document.getElementById('rcpReviewModal').style.display = 'flex'; }
}

async function rcpPreviewRow(type, key) {
    rcpDirect = true;
    if (await rcpLoad(type, [key])) { rcpPreview(0); }
}

function rcpCloseReview() { document.getElementById('rcpReviewModal').style.display = 'none'; }

function rcpCounters() {
    let r = 0, m = 0, n = 0;
    rcpItems.forEach(function (it) { const s = rcpStatus(it); if (s === 'ready') r++; else if (s === 'missing') m++; else n++; });
    document.getElementById('rcpCountReady').innerText = r;
    document.getElementById('rcpCountMissing').innerText = m;
    document.getElementById('rcpCountNoData').innerText = n;
}

function rcpRenderReview() {
    const tb = document.getElementById('rcpReviewBody');
    tb.innerHTML = '';
    rcpItems.forEach(function (it, idx) {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50/80 transition-colors';
        tr.innerHTML =
            '<td class="p-3 align-middle" id="rcpBadge_' + idx + '">' + rcpBadge(rcpStatus(it)) + '</td>' +
            '<td class="p-3 align-middle"><div class="font-bold text-gray-900">' + rcpEsc(it.label) + '</div>' +
                '<div class="text-[10px] text-gray-500">' + Number(it.account_count || 0).toLocaleString() + ' accounts' +
                (it.names ? ' &bull; ' + rcpEsc(it.names) : '') + ' &bull; Excess ₱ ' + Number(it.excess || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</div></td>' +
            '<td class="p-3 align-middle text-gray-700">' + rcpEsc(it.coverage) + '</td>' +
            '<td class="p-3 align-middle"><input type="text" value="' + rcpEsc(it.recipient_email) + '" oninput="rcpSetField(' + idx + ', \'recipient_email\', this.value)" class="w-full p-1.5 border rounded-lg text-xs bg-white"></td>' +
            '<td class="p-3 align-middle"><input type="text" value="' + rcpEsc(it.cc_emails) + '" oninput="rcpSetField(' + idx + ', \'cc_emails\', this.value)" class="w-full p-1.5 border rounded-lg text-xs bg-white" placeholder="cc@company.com"></td>' +
            '<td class="p-3 align-middle text-center"><button type="button" onclick="rcpPreview(' + idx + ')" class="px-3 py-1.5 bg-blue-50 text-blue-700 border border-blue-200 rounded-lg text-[11px] font-bold hover:bg-blue-100">Preview</button></td>';
        tb.appendChild(tr);
    });
    document.getElementById('rcpReviewCount').innerText = rcpItems.length + ' emails loaded for review';
    rcpCounters();
}

function rcpSetField(idx, field, value) {
    rcpItems[idx][field] = value;
    const b = document.getElementById('rcpBadge_' + idx);
    if (b) b.innerHTML = rcpBadge(rcpStatus(rcpItems[idx]));
    rcpCounters();
}

/* ---------- preview ---------- */
function rcpSwitchMode(mode) {
    const vis = document.getElementById('rcpVisual'), code = document.getElementById('rcpPvBody');
    const bv = document.getElementById('rcpBtnVisual'), bc = document.getElementById('rcpBtnCode');
    if (mode === 'visual') {
        vis.style.display = 'block'; code.classList.add('hidden');
        bv.className = 'px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all';
        bc.className = 'px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all';
        vis.innerHTML = code.value;
    } else {
        vis.style.display = 'none'; code.classList.remove('hidden');
        bc.className = 'px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md transition-all';
        bv.className = 'px-3 py-1 text-xs font-bold text-gray-600 rounded-md transition-all';
    }
}
function rcpSyncVisual() { document.getElementById('rcpVisual').innerHTML = document.getElementById('rcpPvBody').value; }

function rcpPreview(idx) {
    rcpCur = idx;
    const it = rcpItems[idx];
    document.getElementById('rcpPreviewTitle').innerText = 'Email Preview & Customization - ' + it.label;
    document.getElementById('rcpPvTo').value = it.recipient_email || '';
    document.getElementById('rcpPvCc').value = it.cc_emails || '';
    document.getElementById('rcpPvSubject').value = it.subject || '';
    document.getElementById('rcpPvBody').value = it.html_content || '';
    document.getElementById('rcpPvFiles').value = '';
    document.getElementById('rcpBtnSave').style.display = rcpDirect ? 'none' : '';

    let h = '';
    (it.attachments || []).forEach(function (a, i) {
        h += '<label class="flex items-center gap-2 py-0.5 px-1 hover:bg-gray-100 rounded cursor-pointer">' +
             '<input type="checkbox" class="rcp-att rounded border-gray-300 text-blue-600" data-i="' + i + '"' + (a.checked ? ' checked' : '') + '>' +
             '<span class="font-medium text-gray-700">' + rcpEsc(a.name) + '</span>' +
             (a.kind === 'summary' ? '<span class="text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-200 rounded px-1.5">SUMMARY REPORT</span>' : '') +
             '</label>';
    });
    document.getElementById('rcpPvAtt').innerHTML = h || '<span class="text-gray-400 italic">No attachments.</span>';

    rcpSwitchMode('visual');
    document.getElementById('rcpPreviewModal').style.display = 'flex';
}

function rcpToggleDm() {
    const boxes = Array.from(document.querySelectorAll('#rcpPvAtt .rcp-att')).filter(function (b) {
        return rcpItems[rcpCur].attachments[b.getAttribute('data-i')].kind === 'dm';
    });
    const anyOff = boxes.some(function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = anyOff; });
}

function rcpReadPreview() {
    const it = rcpItems[rcpCur];
    it.recipient_email = document.getElementById('rcpPvTo').value.trim();
    it.cc_emails = document.getElementById('rcpPvCc').value.trim();
    it.subject = document.getElementById('rcpPvSubject').value;
    it.html_content = document.getElementById('rcpPvBody').value;
    document.querySelectorAll('#rcpPvAtt .rcp-att').forEach(function (b) {
        it.attachments[b.getAttribute('data-i')].checked = b.checked;
    });
    it.extra_files = Array.from(document.getElementById('rcpPvFiles').files || []);
    return it;
}

function rcpClosePreview() { document.getElementById('rcpPreviewModal').style.display = 'none'; }

function rcpSaveAndClose() {
    rcpReadPreview();
    rcpClosePreview();
    if (!rcpDirect) rcpRenderReview();
}

function rcpSendNow() {
    const it = rcpReadPreview();
    if (rcpStatus(it) !== 'ready') { alert('This email cannot be sent: ' + (it.has_data ? 'please enter a valid recipient email.' : 'there is no data for these filters.')); return; }
    if (!it.subject.trim() || !it.html_content.trim()) { alert('Subject and message cannot be empty.'); return; }
    if (!confirm('Send this email to ' + it.recipient_email + '?')) return;
    rcpClosePreview();
    rcpCloseReview();
    rcpDispatch([it]);
}

/* ---------- send ---------- */
function rcpProceed() {
    const ready = rcpItems.filter(function (it) { return rcpStatus(it) === 'ready'; });
    if (!ready.length) { alert('There is nothing ready to send. Check the recipient emails.'); return; }
    const skipped = rcpItems.length - ready.length;
    const msg = 'Send ' + ready.length + ' email(s)?' + (skipped ? '\n' + skipped + ' will be skipped (missing email / no data).' : '');
    if (!confirm(msg)) return;
    rcpCloseReview();
    rcpDispatch(ready);
}

async function rcpDispatch(items) {
    const modal = document.getElementById('rcpProgressModal');
    const log = document.getElementById('rcpProgLog');
    const bar = document.getElementById('rcpProgBar');
    const pct = document.getElementById('rcpProgPct');
    const txt = document.getElementById('rcpProgText');
    const cnt = document.getElementById('rcpProgCounter');
    const closeBtn = document.getElementById('rcpCloseBtn');

    modal.style.display = 'flex';
    closeBtn.style.display = 'none';
    log.innerHTML = '<div class="text-slate-400">Starting: ' + items.length + ' email(s) to send...</div>';
    bar.style.width = '0%'; pct.innerText = '0%';
    txt.innerText = 'Sending emails...';
    cnt.innerText = 'Email 0/' + items.length;

    let ok = 0, fail = 0, done = 0;
    const clientFails = [];
    for (const it of items) {
        log.innerHTML += '<div class="text-sky-300">&gt; Preparing ' + rcpEsc(it.label) + ' (summary PDF' +
            (it.attachments.some(function (a) { return a.kind === 'dm' && a.checked; }) ? ' + debit memos' : '') + ')...</div>';
        log.scrollTop = log.scrollHeight;

        const fd = new FormData();
        fd.append('csrf', RCP_CFG.csrf);
        fd.append('first', done === 0 ? '1' : '0');
        fd.append('type', it.type);
        fd.append('key', it.key);
        fd.append('to', it.recipient_email);
        fd.append('cc', it.cc_emails || '');
        fd.append('subject', it.subject);
        fd.append('html_content', it.html_content);
        fd.append('include_summary', it.attachments.some(function (a) { return a.kind === 'summary' && a.checked; }) ? '1' : '0');
        fd.append('dm_ids', it.attachments.filter(function (a) { return a.kind === 'dm' && a.checked; }).map(function (a) { return a.dm_id; }).join(','));
        Object.keys(RCP_CFG.filters).forEach(function (k) { fd.append(k, RCP_CFG.filters[k]); });
        (it.extra_files || []).forEach(function (f) { fd.append('additional_attachments[]', f); });

        try {
            const data = await rcpFetchJson('reports.php?rcp_ajax=send', {method: 'POST', body: fd});
            if (data.status === 'success') {
                ok += data.success_count || 0;
                fail += data.fail_count || 0;
                (data.success_details || []).forEach(function (s) { log.innerHTML += '<div class="text-emerald-400">+ ' + rcpEsc(s) + '</div>'; });
                (data.failed_details || []).forEach(function (s) { log.innerHTML += '<div class="text-rose-400">- ' + rcpEsc(s) + '</div>'; });
            } else {
                fail++;
                clientFails.push({label: it.label, message: data.message || 'Server error'});
                log.innerHTML += '<div class="text-rose-400">- ' + rcpEsc(it.label) + ': ' + rcpEsc(data.message || 'Server error') + '</div>';
            }
        } catch (e) {
            fail++;
            clientFails.push({label: it.label, message: e.message});
            log.innerHTML += '<div class="text-rose-400">- ' + rcpEsc(it.label) + ': ' + rcpEsc(e.message) + '</div>';
        }

        done++;
        const p = Math.round(done / items.length * 100);
        bar.style.width = p + '%'; pct.innerText = p + '%';
        txt.innerText = 'Processed: ' + done + ' / ' + items.length + ' emails (' + p + '%)';
        cnt.innerText = 'Email ' + done + '/' + items.length;
        log.scrollTop = log.scrollHeight;
    }

    // like list_dm: the admins get a report (Total Processed / Successful / Failed + CSV summary)
    log.innerHTML += '<div class="text-sky-300">&gt; Sending the dispatch report to the admins...</div>';
    log.scrollTop = log.scrollHeight;
    try {
        const rep = await rcpFetchJson('reports.php?rcp_ajax=admin_report', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf: RCP_CFG.csrf, client_fails: clientFails})
        });
        if (rep.status === 'success') {
            log.innerHTML += '<div class="text-emerald-400">+ Admin report sent to: ' + rcpEsc((rep.recipients || []).join(', ')) +
                ' (Total processed: ' + rep.total + ', Successful: ' + rep.success + ', Failed: ' + rep.failed + ')</div>';
        } else {
            log.innerHTML += '<div class="text-rose-400">- Admin report: ' + rcpEsc(rep.message || 'could not be sent') + '</div>';
        }
    } catch (e) {
        log.innerHTML += '<div class="text-rose-400">- Admin report: ' + rcpEsc(e.message) + '</div>';
    }
    log.scrollTop = log.scrollHeight;

    txt.innerHTML = 'Completed! <span style="color:#10b981;font-weight:bold;">Success: ' + ok + '</span>, <span style="color:' + (fail > 0 ? '#ef4444' : '#64748b') + ';font-weight:bold;">Failed: ' + fail + '</span>';
    closeBtn.style.display = 'flex';
}

function rcpCloseProgress() {
    document.getElementById('rcpProgressModal').style.display = 'none';
    window.location.reload();
}
</script>
RCPHUB;
    return str_replace('__RCP_CONFIG__', $json, $html);
}

function rp_rcp_breakdown(array $accounts, $f = null) {
    if (!is_array($f)) {
        $f = array('start_date' => '', 'end_date' => '', 'grp' => '', 'department' => '', 'q' => '');
    }

    $group_targets = rp_rcp_targets($accounts, 'group');
    $dept_targets  = rp_rcp_targets($accounts, 'dept');

    $h  = rp_rcp_table('group', $group_targets, $f);
    $h .= rp_rcp_table('dept',  $dept_targets,  $f);
    $h .= rp_rcp_hub_html($f);
    return $h;
}

function rp_rcp_list(array $rows, array $f, $role, $total, $preview) {
    return rp_rcp_breakdown($rows, $f);
}
?>