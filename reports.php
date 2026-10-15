<?php
/**
 * Reports Center Module (reports.php)
 *
 * Three reports, each with its own filters, preview, and Excel download:
 *   1. Debit Masterfile  - charges and DM per mobile number   (debit_memos / debit_memo_items)
 *   2. Import SOA        - uploaded statement files           (pdf_extracted_details)
 *   3. Email SOA         - statements emailed to accounts     (dispatch_logs)
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'main.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (isset($conn)) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$page_title = "Reports Center";

// -------------------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------------------
function rp_all($conn, $sql, $params = array()) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rp_row($conn, $sql, $params = array()) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : array();
}

function rp_col($conn, $sql, $params = array()) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function rp_get($key) {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : '';
}

// A date field: untouched form -> default, submitted blank -> no filter
function rp_date_param($key, $default) {
    if (!array_key_exists($key, $_GET)) return $default;
    $v = trim((string)$_GET[$key]);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

function rp_amount($v) {
    return (float)preg_replace('/[^0-9.\-]/', '', (string)$v);
}

function rp_money($v) {
    return '₱ ' . number_format((float)$v, 2);
}

function rp_date($v, $fmt = 'M d, Y') {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '';
    $t = strtotime($v);
    return $t ? date($fmt, $t) : $v;
}

function rp_e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rp_clean_amount_sql($col) {
    return "CAST(NULLIF(REGEXP_REPLACE(COALESCE($col, '0'), '[^0-9.-]', ''), '') AS DECIMAL(14,2))";
}

function rp_soa_fields() {
    return array(
        'account_number' => 'Account number',
        'billing_period' => 'Coverage date',
        'invoice_date'   => 'Invoice date',
        'due_date'       => 'Due date',
        'amount_due'     => 'Amount due',
        'mobile_number'  => 'Mobile number',
        'telco'          => 'Telco',
    );
}

// SQL test: is this imported field empty / placeholder?
function rp_blank_sql($col) {
    return "COALESCE(TRIM(p.$col), '') IN ('', 'N/A', 'n/a', '-', '0000-00-00')";
}

// PHP twin of the SQL test above
function rp_is_blank($v) {
    $v = trim((string)$v);
    return $v === '' || in_array(strtolower($v), array('n/a', '-', '0000-00-00'), true);
}

// A table cell that shows "Missing" when the imported value is empty
function rp_val($v, $formatter = null) {
    if (rp_is_blank($v)) return '<span class="rp-miss">Missing</span>';
    return rp_e($formatter ? call_user_func($formatter, $v) : $v);
}

function rp_compact_money($v) {
    $v = (float)$v;
    if ($v >= 1000000) return '₱' . round($v / 1000000, 1) . 'M';
    if ($v >= 1000)    return '₱' . round($v / 1000, 1) . 'k';
    return '₱' . number_format($v, 0);
}

// Horizontal bars: items = array(array('label'=>, 'value'=>, 'note'=>))
function rp_hbars(array $items, $money = false) {
    if (empty($items)) return '<p class="rp-empty-sm">Nothing to chart for these filters.</p>';
    $max = 0;
    foreach ($items as $it) { $max = max($max, (float)$it['value']); }
    $h = '<ul class="rp-bars">';
    foreach ($items as $it) {
        $val = (float)$it['value'];
        $pct = ($max > 0 && $val > 0) ? max(2, round(($val / $max) * 100, 1)) : 0;
        $txt = $money ? rp_money($val) : number_format($val);
        $note = (isset($it['note']) && $it['note'] !== '') ? ' <small>' . rp_e($it['note']) . '</small>' : '';
        $h .= '<li><div class="rp-bar-top"><span>' . rp_e($it['label']) . $note . '</span><b>' . $txt . '</b></div>'
            . '<div class="rp-bar-track"><span style="--w:' . $pct . '%"></span></div></li>';
    }
    return $h . '</ul>';
}

// Vertical columns: items = array(array('label'=>, 'title'=>, 'a'=>, 'b'=>optional second series))
function rp_columns(array $items, $money = false) {
    if (empty($items)) return '<p class="rp-empty-sm">Nothing to chart for these filters.</p>';
    $max = 0;
    foreach ($items as $it) { $max = max($max, (float)$it['a'] + (isset($it['b']) ? (float)$it['b'] : 0)); }
    $show_vals = count($items) <= 8;
    $h = '<div class="rp-cols">';
    foreach ($items as $it) {
        $a = (float)$it['a'];
        $b = isset($it['b']) ? (float)$it['b'] : 0;
        $ha = $max > 0 ? round(($a / $max) * 100, 1) : 0;
        $hb = $max > 0 ? round(($b / $max) * 100, 1) : 0;
        $top = $money ? rp_compact_money($a) : number_format($a + $b);
        $h .= '<div class="rp-col" title="' . rp_e($it['title']) . '">'
            . ($show_vals ? '<em>' . rp_e($top) . '</em>' : '')
            . '<div class="rp-col-bar">'
            . ($a > 0 ? '<span class="a" style="height:' . max(2, $ha) . '%"></span>' : '')
            . ($b > 0 ? '<span class="b" style="height:' . max(2, $hb) . '%"></span>' : '')
            . '</div><small>' . rp_e($it['label']) . '</small></div>';
    }
    return $h . '</div>';
}

function rp_opts($list) {
    $o = array();
    foreach ($list as $v) { $o[$v] = $v; }
    return $o;
}

function rp_select($name, $label, $options, $selected, $all_label) {
    $h  = '<label class="rp-field"><span>' . rp_e($label) . '</span><select name="' . rp_e($name) . '">';
    $h .= '<option value="">' . rp_e($all_label) . '</option>';
    foreach ($options as $value => $text) {
        $h .= '<option value="' . rp_e($value) . '"' . ((string)$selected === (string)$value ? ' selected' : '') . '>' . rp_e($text) . '</option>';
    }
    return $h . '</select></label>';
}

function rp_pager($page, $total_pages) {
    if ($total_pages <= 1) return '';
    $q = $_GET;
    $q['action'] = 'view';
    $url = function ($p) use ($q) {
        $q['page'] = $p;
        return 'reports.php?' . http_build_query($q);
    };
    $end   = min($total_pages, max(1, $page - 4) + 8);
    $start = max(1, $end - 8);

    $h  = '<div class="rp-pager-wrap"><span class="rp-muted">Page <b>' . (int)$page . '</b> of <b>' . (int)$total_pages . '</b></span>';
    $h .= '<nav class="rp-pager" aria-label="Pagination">';
    $h .= '<a class="rp-page" href="' . rp_e($url(1)) . '">First</a>';
    for ($i = $start; $i <= $end; $i++) {
        $cur = ($i === $page);
        $h .= '<a class="rp-page' . ($cur ? ' is-current' : '') . '"' . ($cur ? ' aria-current="page"' : '') . ' href="' . rp_e($url($i)) . '">' . $i . '</a>';
    }
    $h .= '<a class="rp-page" href="' . rp_e($url($total_pages)) . '">Last</a>';
    return $h . '</nav></div>';
}

/**
 * Generic Excel XML (SpreadsheetML) download used by the SOA and Email reports.
 * $numeric_cols = zero-based column indexes that should be written as numbers.
 */
function rp_xml_excel($filename, $sheet_name, array $meta, array $headers, array $rows, array $numeric_cols = array()) {
    $esc = function ($v) {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $sheet_name = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sheet_name), 0, 31);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $border = '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>';

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Styles>';
    echo '<Style ss:ID="Meta"><Font ss:Bold="1"/></Style>';
    echo '<Style ss:ID="Head"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . $border . '<Interior ss:Color="#CFE5E1" ss:Pattern="Solid"/><Font ss:Bold="1"/></Style>';
    echo '<Style ss:ID="Cell"><Alignment ss:Vertical="Center"/>' . $border . '</Style>';
    echo '<Style ss:ID="Money"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $border . '<NumberFormat ss:Format="#,##0.00"/></Style>';
    echo '</Styles>' . "\n";
    echo '<Worksheet ss:Name="' . $esc($sheet_name) . '"><Table>' . "\n";

    foreach ($meta as $line) {
        echo '<Row><Cell ss:StyleID="Meta"><Data ss:Type="String">' . $esc($line) . '</Data></Cell></Row>' . "\n";
    }
    if (!empty($meta)) echo '<Row></Row>' . "\n";

    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="Head"><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($rows as $r) {
        echo '<Row>';
        foreach ($r as $i => $val) {
            if (in_array($i, $numeric_cols, true) && $val !== '' && $val !== null) {
                echo '<Cell ss:StyleID="Money"><Data ss:Type="Number">' . number_format((float)$val, 2, '.', '') . '</Data></Cell>';
            } else {
                echo '<Cell ss:StyleID="Cell"><Data ss:Type="String">' . $esc($val) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

// -------------------------------------------------------------------------
// FILTER BUILDERS (shared by preview, summary and export)
// -------------------------------------------------------------------------
function rp_masterfile_where(array $f) {
    $w = '';
    $p = array();
    if ($f['carrier_name'] !== '') { $w .= " AND dmi.carrier_name = ?"; $p[] = $f['carrier_name']; }
    if ($f['company'] !== '')      { $w .= " AND dm.company = ?";       $p[] = $f['company']; }
    if ($f['start_date'] !== '')   { $w .= " AND dmi.coverage_start >= ?"; $p[] = $f['start_date']; }
    if ($f['end_date'] !== '')     { $w .= " AND dmi.coverage_start <= ?"; $p[] = $f['end_date']; }
    return array($w, $p);
}

function rp_soa_where(array $f) {
    $w = '';
    $p = array();
    if ($f['telco'] !== '')          { $w .= " AND p.telco = ?";          $p[] = $f['telco']; }
    if ($f['billing_period'] !== '') { $w .= " AND p.billing_period = ?"; $p[] = $f['billing_period']; }
    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        $w .= " AND (p.account_number LIKE ? OR p.mobile_number LIKE ? OR p.filename LIKE ?)";
        array_push($p, $like, $like, $like);
    }
    if ($f['start_date'] !== '') { $w .= " AND DATE(p.updated_at) >= ?"; $p[] = $f['start_date']; }
    if ($f['end_date'] !== '')   { $w .= " AND DATE(p.updated_at) <= ?"; $p[] = $f['end_date']; }

    return array($w, $p);
}

// ---- "Need to upload": memo items (account + coverage period) with no matching SOA ----
// Same matching rule as the dashboard: same account, SOA billing period ends within 5 days
// of the memo item's coverage end, and the mobile number matches (or the SOA has none).
function rp_need_from() {
    return " FROM debit_memo_items i JOIN debit_memos m ON m.dm_id = i.dm_id WHERE m.account_number IS NOT NULL AND TRIM(m.account_number) <> ''";
}

function rp_need_select() {
    return "SELECT m.account_number AS acct, m.company AS company_name, m.assignee_name AS assignee, i.carrier_name, i.mobile_number, i.coverage_start, i.coverage_end";
}

function rp_need_order() {
    return " ORDER BY m.company ASC, m.account_number ASC, i.coverage_start ASC";
}

function rp_need_where(array $f) {
    $w = '';
    $p = array();
    // Coverage range of the memo item (same clause the dashboard uses)
    if ($f['start_date'] !== '') { $w .= " AND i.coverage_start >= ?"; $p[] = $f['start_date']; }
    if ($f['end_date'] !== '')   { $w .= " AND i.coverage_end <= ?";   $p[] = $f['end_date']; }
    // A chosen coverage date (SOA billing period) -> memo items ending within 5 days of its end date
    if ($f['billing_period'] !== '') {
        $parts = preg_split('/\s+(?:-|to)\s+/i', trim($f['billing_period']));
        if (count($parts) >= 2) {
            $t = strtotime(trim($parts[1]));
            if ($t !== false) { $w .= " AND ABS(DATEDIFF(i.coverage_end, ?)) <= 5"; $p[] = date('Y-m-d', $t); }
        }
    }
    if ($f['telco'] !== '') {
        $w .= " AND (UPPER(i.carrier_name) LIKE CONCAT('%', UPPER(?), '%') OR UPPER(?) LIKE CONCAT('%', UPPER(i.carrier_name), '%'))";
        array_push($p, $f['telco'], $f['telco']);
    }
    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        $w .= " AND (m.account_number LIKE ? OR m.company LIKE ? OR m.assignee_name LIKE ? OR i.mobile_number LIKE ?)";
        array_push($p, $like, $like, $like, $like);
    }
    return array($w, $p);
}

// ---- Fast lookups: a few simple queries, then everything else is matched in PHP ----
function rp_soa_context($conn) {
    $ctx = array('company' => array(), 'email' => array(), 'sent' => array());

    $st = $conn->query("SELECT TRIM(account_number) AS a, MAX(company) AS c FROM debit_memos WHERE account_number IS NOT NULL AND TRIM(account_number) <> '' GROUP BY TRIM(account_number)");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $ctx['company'][$r['a']] = (string)$r['c']; }

    $st = $conn->query("SELECT TRIM(account_number) AS a, MAX(email_address) AS e FROM account_emails WHERE account_number IS NOT NULL AND TRIM(COALESCE(email_address, '')) <> '' GROUP BY TRIM(account_number)");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $ctx['email'][$r['a']] = (string)$r['e']; }

    $st = $conn->query("SELECT filename, MAX(dispatch_date) AS d FROM dispatch_logs WHERE UPPER(status) = 'SUCCESS' GROUP BY filename");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $ctx['sent'][(string)$r['filename']] = (string)$r['d']; }

    return $ctx;
}

function rp_soa_enrich(array $r, array $ctx) {
    $acct = trim((string)$r['account_number']);
    $r['in_dm']            = isset($ctx['company'][$acct]);
    $r['company']          = $r['in_dm'] ? $ctx['company'][$acct] : null;
    $r['registered_email'] = isset($ctx['email'][$acct]) ? $ctx['email'][$acct] : '';
    $r['last_emailed']     = isset($ctx['sent'][(string)$r['filename']]) ? $ctx['sent'][(string)$r['filename']] : '';
    return $r;
}

// The "Show only" filter for statement files, evaluated in PHP
function rp_soa_check_ok(array $r, $check, array $ctx) {
    $acct = trim((string)$r['account_number']);
    switch ($check) {
        case 'no_masterfile': return !isset($ctx['company'][$acct]);
        case 'no_email':      return !isset($ctx['email'][$acct]);
        case 'not_emailed':   return !isset($ctx['sent'][(string)$r['filename']]);
        case 'emailed':       return isset($ctx['sent'][(string)$r['filename']]);
        case 'incomplete':
            foreach (rp_soa_fields() as $col => $label) { if (rp_is_blank($r[$col])) return true; }
            return false;
    }
    return true;
}

// Memo items (account + coverage period) that have no matching SOA. Same rule as the dashboard:
// same account, SOA billing period ends within 5 days of the item's coverage end,
// and the mobile number matches (or the SOA has none). Matched in PHP, not in SQL.
function rp_day_index($ymd) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$ymd, $m)) return null;
    return (int)floor(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) / 86400);
}

function rp_need_compute($conn, array $f) {
    $soa = array();
    $st = $conn->query("SELECT account_number, mobile_number, billing_period FROM pdf_extracted_details");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $parts = explode(' - ', (string)$r['billing_period']);
        $day = rp_day_index(trim(end($parts)));
        if ($day === null) continue;
        $mob   = trim((string)$r['mobile_number']);
        $blank = ($mob === '' || strtoupper($mob) === 'N/A');
        $soa[trim((string)$r['account_number'])][] = array($day, $blank ? '' : substr(preg_replace('/\D/', '', $mob), -10), $blank);
    }

    list($nw, $np) = rp_need_where($f);
    $st = $conn->prepare(rp_need_select() . rp_need_from() . $nw . rp_need_order());
    $st->execute($np);

    $need  = array();
    $accts = array();
    $scope = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $scope++;
        $key   = trim((string)$r['acct']);
        $found = false;
        $iday  = rp_day_index($r['coverage_end']);
        if ($iday !== null && isset($soa[$key])) {
            $imob = substr(preg_replace('/\D/', '', (string)$r['mobile_number']), -10);
            foreach ($soa[$key] as $c) {
                if (abs($iday - $c[0]) <= 5 && ($c[2] || $c[1] === $imob)) { $found = true; break; }
            }
        }
        if (!$found) { $need[] = $r; $accts[$key] = true; }
    }
    return array('rows' => $need, 'scope' => $scope, 'accounts' => count($accts));
}

function rp_email_where(array $f) {
    $w = '';
    $p = array();
    if ($f['status'] !== '')  { $w .= " AND UPPER(l.status) = ?"; $p[] = $f['status']; }
    if ($f['telco'] !== '')   { $w .= " AND l.telco = ?";         $p[] = $f['telco']; }
    if ($f['sent_by'] !== '') { $w .= " AND l.sent_by = ?";       $p[] = $f['sent_by']; }
    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        $w .= " AND (l.account_number LIKE ? OR l.recipient_email LIKE ? OR l.filename LIKE ? OR l.mobile_number LIKE ?)";
        array_push($p, $like, $like, $like, $like);
    }
    if ($f['start_date'] !== '') { $w .= " AND DATE(l.dispatch_date) >= ?"; $p[] = $f['start_date']; }
    if ($f['end_date'] !== '')   { $w .= " AND DATE(l.dispatch_date) <= ?"; $p[] = $f['end_date']; }
    return array($w, $p);
}

// -------------------------------------------------------------------------
// REQUEST
// -------------------------------------------------------------------------
$report_defs = array(
    'masterfile' => array('label' => 'Debit Masterfile', 'icon' => 'la-file-invoice-dollar', 'desc' => 'Charges, processed DM and system-generated DM per mobile number'),
    'soa'        => array('label' => 'Import SOA',       'icon' => 'la-file-upload',          'desc' => 'Statements uploaded, and whether each one matches a memo and an email'),
    'email'      => array('label' => 'Email SOA',        'icon' => 'la-mail-bulk',            'desc' => 'Statements emailed to accounts, with sent and failed status'),
);

$report   = (isset($_GET['report']) && isset($report_defs[$_GET['report']])) ? $_GET['report'] : 'masterfile';
// overview (default) = counts and charts only; view = load the record list; export = Excel download
$action   = 'overview';
if (isset($_GET['action']) && $_GET['action'] === 'export') { $action = 'export'; }
elseif (isset($_GET['action']) && $_GET['action'] === 'view') { $action = 'view'; }
$page     = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

$default_start = ($report === 'soa') ? '' : date('Y-m-01');
$default_end   = ($report === 'soa') ? '' : date('Y-m-t');

$f = array(
    'carrier_name'   => rp_get('carrier_name'),
    'company'        => rp_get('company'),
    'telco'          => rp_get('telco'),
    'billing_period' => rp_get('billing_period'),
    'check'          => rp_get('check'),
    'status'         => strtoupper(rp_get('status')),
    'sent_by'        => rp_get('sent_by'),
    'q'              => rp_get('q'),
    'start_date'     => rp_date_param('start_date', $default_start),
    'end_date'       => rp_date_param('end_date', $default_end),
);

// Columns used by the Debit Masterfile report
$mf_cols = "dm.account_number, dm.company AS dm_company, dm.assignee_name, dmi.carrier_name, dmi.mobile_number,
            dmi.coverage_start, dmi.coverage_end, dmi.approved_plan, dmi.phone_amortization, dmi.debit_adj,
            dmi.credit_adj, dmi.other_charges, dmi.local_call_text, dmi.ndd_charges, dmi.idd_charges,
            dmi.roaming_charges, dmi.sms_charges, dmi.gprs_charges, dmi.wiz_usage, dmi.loading_charges,
            dmi.vat, dmi.oct, dmi.current_charges, dmi.total_amount_due, dmi.debit_memo_details";
$mf_from = "FROM `debit_memo_items` AS dmi INNER JOIN debit_memos AS dm ON dm.dm_id = dmi.dm_id WHERE 1=1";

/**
 * EXPORT (Debit Masterfile): Excel XML workbook, one sheet per coverage year
 */
if ($report === 'masterfile' && $action === 'export') {
    try {
        list($mf_where, $mf_params) = rp_masterfile_where($f);
        $carrier_name = $f['carrier_name'];
        $company      = $f['company'];

        $stmt = $conn->prepare("SELECT $mf_cols $mf_from $mf_where ORDER BY dmi.coverage_start ASC");
        $stmt->execute($mf_params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group items by year
        $grouped_by_year = array();
        foreach ($results as $row) {
            $year = 'Unknown';
            if (!empty($row['coverage_start'])) {
                $parsed_year = date('Y', strtotime($row['coverage_start']));
                if ($parsed_year) {
                    $year = $parsed_year;
                }
            }
            if (!isset($grouped_by_year[$year])) {
                $grouped_by_year[$year] = array();
            }
            $grouped_by_year[$year][] = $row;
        }

        if (empty($grouped_by_year)) {
            $grouped_by_year[date('Y')] = array();
        }

        // Set Headers for Excel XML Download
        $filename = 'Debit_Memo_Report_' . date('Y-m-d_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

        // Global Styles Definition
        echo '<Styles>' . "\n";
        echo '<Style ss:ID="DataCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
        echo '<Style ss:ID="RedCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1" ss:Color="#dc2626"/></Style>' . "\n";

        $unique_colors = ["#FFE599", "#93C47D", "#4A86E8", "#F7F700"];
        foreach ($unique_colors as $color) {
            $style_id = 'Header_' . md5($color);
            echo '<Style ss:ID="' . $style_id . '">';
            echo '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>';
            echo '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>';
            echo '<Interior ss:Color="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" ss:Pattern="Solid"/>';
            echo '<Font ss:Bold="1"/>';
            echo '</Style>' . "\n";
        }
        echo '</Styles>' . "\n";

        // Headers Configuration (22 columns total)
        $headers = [
            "COVERAGE DATE" => "#FFE599",
            "MOBILE NUMBER" => "#93C47D",
            "APPROVED PLAN" => "#93C47D",
            "MSF (GLOBE / MRC (SMART)" => "#4A86E8",
            "DEBIT ADJ" => "#93C47D",
            "CREDIT ADJ" => "#93C47D",
            "OTHER CHARGES / PHONE AMORTIZATION" => "#4A86E8",
            "LOCAL (CALL/TEXT)" => "#4A86E8",
            "NDD (NATIONAL)" => "#4A86E8",
            "IDD (INTERNATIONAL)" => "#4A86E8",
            "ROAM" => "#4A86E8",
            "SMS" => "#4A86E8",
            "GPRS" => "#4A86E8",
            "WIZ USAGE" => "#4A86E8",
            "LOADING CHARGES" => "#4A86E8",
            "VAT" => "#4A86E8",
            "OCT" => "#4A86E8",
            "CURRENT CHARGES" => "#4A86E8",
            "TOTAL AMOUNT DUE" => "#4A86E8",
            "PROCESSED DM" => "#93C47D",
            "SYSTEM GENERATED DM" => "#93C47D",
            "DIFFERENCE" => "#F7F700"
        ];

        // Loop through each year to create separate sheet tabs
        foreach ($grouped_by_year as $year => $year_items) {
            echo '<Worksheet ss:Name="Year ' . htmlspecialchars($year, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            echo '<Table>' . "\n";

            // 22 Column Widths
            $colWidths = [150, 130, 110, 130, 100, 100, 180, 130, 130, 140, 90, 80, 80, 90, 120, 80, 80, 130, 130, 100, 140, 140];
            foreach ($colWidths as $w) {
                echo '<Column ss:Width="' . $w . '"/>' . "\n";
            }

            // Filter Info Rows
            echo '<Row>' . "\n";
            echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">COMPANY FILTER: ' . (!empty($company) ? htmlspecialchars($company, ENT_QUOTES, 'UTF-8') : 'ALL COMPANIES') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            echo '<Row>' . "\n";
            echo '<Cell ss:Index="1" ss:MergeAcross="3"><Data ss:Type="String">CARRIER FILTER: ' . (!empty($carrier_name) ? htmlspecialchars($carrier_name, ENT_QUOTES, 'UTF-8') : 'ALL CARRIERS') . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";
            echo '<Row></Row>' . "\n";

            // Header Row
            echo '<Row>' . "\n";
            foreach ($headers as $colTitle => $colorCode) {
                $styleID = 'Header_' . md5($colorCode);
                echo '<Cell ss:StyleID="' . $styleID . '"><Data ss:Type="String">' . htmlspecialchars($colTitle, ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
            }
            echo '</Row>' . "\n";

            // Data Rows
            foreach ($year_items as $row) {
                $start = isset($row['coverage_start']) && $row['coverage_start'] ? date('M d, Y', strtotime($row['coverage_start'])) : '';
                $end   = isset($row['coverage_end']) && $row['coverage_end'] ? date('M d, Y', strtotime($row['coverage_end'])) : '';
                $dateText = ($start && $end) ? ($start . ' to ' . $end) : '';
                $mob = isset($row['mobile_number']) ? $row['mobile_number'] : '';

                echo '<Row>' . "\n";
                // Column 1: Coverage Date
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($dateText, ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
                // Column 2: Mobile Number
                echo '<Cell ss:StyleID="DataCell"><Data ss:Type="String">' . htmlspecialchars($mob, ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";

                // Columns 3 - 20: 18 Numeric Fields
                $numericFields = array(
                    'approved_plan', 'phone_amortization', 'debit_adj', 'credit_adj', 
                    'other_charges', 'local_call_text', 'ndd_charges', 'idd_charges', 
                    'roaming_charges', 'sms_charges', 'gprs_charges', 'wiz_usage', 
                    'loading_charges', 'vat', 'oct', 'current_charges', 'total_amount_due', 
                    'debit_memo_details'
                );

                foreach ($numericFields as $field) {
                    $val = isset($row[$field]) ? (float)$row[$field] : 0.00;
                    $cellStyle = ($field === 'debit_memo_details') ? 'RedCell' : 'DataCell';
                    echo '<Cell ss:StyleID="' . $cellStyle . '"><Data ss:Type="Number">' . number_format($val, 2, '.', '') . '</Data></Cell>' . "\n";
                }

                // Column 21: SYSTEM GENERATED DM = CURRENT CHARGES - APPROVED PLAN
                echo '<Cell ss:StyleID="DataCell" ss:Formula="=RC[-3]-RC[-18]"><Data ss:Type="Number">0</Data></Cell>' . "\n";

                // Column 22: DIFFERENCE = PROCESSED DM - SYSTEM GENERATED DM
                echo '<Cell ss:StyleID="RedCell" ss:Formula="=RC[-2]-RC[-1]"><Data ss:Type="Number">0</Data></Cell>' . "\n";

                echo '</Row>' . "\n";
            }

            echo '</Table>' . "\n";
            echo '</Worksheet>' . "\n";
        }

        echo '</Workbook>';
        exit;
    } catch (Exception $e) {
        echo "Export Error: " . $e->getMessage();
        exit;
    }
}

// -------------------------------------------------------------------------
// DROPDOWN OPTIONS + CARD COUNTS
// -------------------------------------------------------------------------
$opt = array('companies' => array(), 'carriers' => array(), 'telcos' => array(), 'periods' => array(), 'senders' => array());
$counts = array('masterfile' => null, 'soa' => null, 'email' => null);

try {
    if ($report === 'masterfile') {
        $opt['companies'] = rp_col($conn, "SELECT DISTINCT company FROM debit_memos WHERE company IS NOT NULL AND company != '' ORDER BY company ASC");
        $opt['carriers']  = rp_col($conn, "SELECT DISTINCT carrier_name FROM debit_memo_items WHERE carrier_name IS NOT NULL AND carrier_name != '' ORDER BY carrier_name ASC");
    } elseif ($report === 'soa') {
        $opt['telcos']  = rp_col($conn, "SELECT DISTINCT telco FROM pdf_extracted_details WHERE telco IS NOT NULL AND telco != '' ORDER BY telco ASC");
        $opt['periods'] = rp_col($conn, "SELECT DISTINCT billing_period FROM pdf_extracted_details WHERE billing_period IS NOT NULL AND billing_period != '' ORDER BY billing_period DESC");
    } else {
        $opt['telcos']   = rp_col($conn, "SELECT DISTINCT telco FROM dispatch_logs WHERE telco IS NOT NULL AND telco != '' AND telco != 'N/A' ORDER BY telco ASC");
        $opt['senders']  = rp_col($conn, "SELECT DISTINCT sent_by FROM dispatch_logs WHERE sent_by IS NOT NULL AND sent_by != '' ORDER BY sent_by ASC");
    }
} catch (Exception $e) {
    error_log('reports.php options: ' . $e->getMessage());
}

foreach (array('masterfile' => 'debit_memo_items', 'soa' => 'pdf_extracted_details', 'email' => 'dispatch_logs') as $key => $table) {
    try {
        $counts[$key] = (int)$conn->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Exception $e) {
        $counts[$key] = null;
    }
}
$count_units = array('masterfile' => 'memo items', 'soa' => 'statement files', 'email' => 'emails logged');

// -------------------------------------------------------------------------
// LOAD THE SELECTED REPORT
//   Overview (default): counts and charts only, no record list.
//   View report:        the record list, 25 per page.
// -------------------------------------------------------------------------
$is_view       = ($action === 'view');
$rows          = array();
$stats         = array();
$progress      = array();
$charts        = array('carriers' => array(), 'months' => array(), 'fields' => array(), 'days' => array(), 'reasons' => array());
$total_records = 0;
$total_pages   = 1;
$need_scope_total = 0;
$list_mode     = ($report === 'soa' && $f['check'] === 'need_upload') ? 'need_upload' : 'files';
$load_error    = '';

$soa_cols = "p.id, p.filename, p.file_link, p.account_number, p.mobile_number, p.telco, p.amount_due, p.billing_period, p.invoice_date, p.due_date, p.updated_at";

try {
    // ================= 1. DEBIT MASTERFILE =================
    if ($report === 'masterfile') {
        list($where, $params) = rp_masterfile_where($f);

        $total_records = (int)rp_row($conn, "SELECT COUNT(*) AS c $mf_from $where", $params)['c'];
        $total_pages   = max(1, (int)ceil($total_records / $per_page));

        try {
            $cc = rp_clean_amount_sql('dmi.current_charges');
            $dd = rp_clean_amount_sql('dmi.debit_memo_details');
            $stats = rp_row($conn, "SELECT COUNT(DISTINCT dm.account_number) AS accounts, SUM(COALESCE($cc, 0)) AS current_total, SUM(COALESCE($dd, 0)) AS processed_total $mf_from $where", $params);

            if (!$is_view) {
                $by_carrier = rp_all($conn, "SELECT UPPER(COALESCE(NULLIF(TRIM(dmi.carrier_name), ''), 'OTHER')) AS carrier, COUNT(*) AS items, SUM(COALESCE($dd, 0)) AS processed $mf_from $where GROUP BY carrier ORDER BY processed DESC LIMIT 8", $params);
                foreach ($by_carrier as $r) {
                    $charts['carriers'][] = array('label' => $r['carrier'], 'value' => (float)$r['processed'], 'note' => number_format((int)$r['items']) . ' items');
                }
                $by_month = rp_all($conn, "SELECT DATE_FORMAT(dmi.coverage_start, '%Y-%m') AS ym, COUNT(*) AS items, SUM(COALESCE($dd, 0)) AS processed $mf_from $where AND dmi.coverage_start IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 12", $params);
                foreach (array_reverse($by_month) as $r) {
                    $t = strtotime($r['ym'] . '-01');
                    $charts['months'][] = array(
                        'label' => date('M y', $t),
                        'title' => date('F Y', $t) . ': ' . rp_money($r['processed']) . ' across ' . number_format((int)$r['items']) . ' items',
                        'a'     => (float)$r['processed'],
                    );
                }
            }
        } catch (Exception $e) {
            error_log('reports.php masterfile overview: ' . $e->getMessage());
        }

        if ($is_view) {
            $rows = rp_all($conn, "SELECT $mf_cols $mf_from $where ORDER BY dmi.coverage_start DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset, $params);
        }

    // ================= 2. IMPORT SOA =================
    } elseif ($report === 'soa') {
        $from   = "FROM pdf_extracted_details p WHERE 1=1";
        $fields = rp_soa_fields();
        $ctx    = rp_soa_context($conn);

        // ---- Excel download ----
        if ($action === 'export') {
            if ($list_mode === 'need_upload') {
                $nc  = rp_need_compute($conn, $f);
                $all = $nc['rows'];
                $out = array();
                foreach ($all as $r) {
                    $out[] = array($r['acct'], $r['company_name'], $r['assignee'], $r['carrier_name'], $r['mobile_number'], rp_date($r['coverage_start']), rp_date($r['coverage_end']));
                }
                rp_xml_excel('SOA_Need_To_Upload_' . date('Y-m-d_His') . '.xls', 'Need to upload',
                    array('MEMO ITEMS THAT STILL NEED AN SOA',
                          'COVERAGE RANGE: ' . (($f['start_date'] !== '' || $f['end_date'] !== '') ? ($f['start_date'] . ' to ' . $f['end_date']) : 'ANY'),
                          'COVERAGE DATE: ' . ($f['billing_period'] !== '' ? $f['billing_period'] : 'ANY')),
                    array('Account Number', 'Company', 'Assignee', 'Carrier / Telco', 'Mobile Number', 'Coverage Start (Need SOA)', 'Coverage End (Need SOA)'), $out);
            }

            list($where, $params) = rp_soa_where($f);
            $all = array();
            foreach (rp_all($conn, "SELECT $soa_cols $from $where ORDER BY p.updated_at DESC, p.id DESC", $params) as $r) {
                if (rp_soa_check_ok($r, $f['check'], $ctx)) { $all[] = rp_soa_enrich($r, $ctx); }
            }
            $out = array();
            foreach ($all as $r) {
                $missing = array();
                foreach ($fields as $col => $label) { if (rp_is_blank($r[$col])) $missing[] = $label; }
                $out[] = array(
                    $r['account_number'], $r['company'], $r['mobile_number'], $r['telco'], $r['billing_period'],
                    rp_date($r['invoice_date']), rp_date($r['due_date']), rp_amount($r['amount_due']),
                    empty($missing) ? 'Complete' : implode(', ', $missing),
                    $r['in_dm'] ? 'Yes' : 'No',
                    $r['registered_email'], rp_date($r['last_emailed'], 'M d, Y g:i A'),
                    $r['filename'], $r['file_link'], rp_date($r['updated_at'], 'M d, Y g:i A'),
                );
            }
            $meta = array(
                'IMPORT SOA REPORT',
                'TELCO: ' . ($f['telco'] !== '' ? $f['telco'] : 'ALL'),
                'COVERAGE DATE: ' . ($f['billing_period'] !== '' ? $f['billing_period'] : 'ALL'),
                'IMPORTED: ' . (($f['start_date'] !== '' || $f['end_date'] !== '') ? ($f['start_date'] . ' to ' . $f['end_date']) : 'ANY DATE'),
            );
            rp_xml_excel('Import_SOA_Report_' . date('Y-m-d_His') . '.xls', 'Import SOA', $meta,
                array('Account Number', 'Company', 'Mobile Number', 'Telco', 'Coverage Date', 'Invoice Date', 'Due Date', 'Amount Due', 'Missing Details', 'In Masterfile', 'Email On File', 'Last Emailed', 'Filename', 'File Link', 'Imported On'),
                $out, array(7));
        }

        // ---- Overview numbers (ignore the "Show only" filter so the totals stay whole) ----
        $f_over = $f;
        $f_over['check'] = '';
        list($ow, $op) = rp_soa_where($f_over);

        $stats = array('total_files' => 0, 'total_due' => 0.0, 'no_masterfile' => 0, 'no_email' => 0, 'not_emailed' => 0, 'incomplete' => 0);
        foreach ($fields as $col => $label) { $stats['miss_' . $col] = 0; }

        foreach (rp_all($conn, "SELECT $soa_cols $from $ow", $op) as $r) {
            $stats['total_files']++;
            $stats['total_due'] += rp_amount($r['amount_due']);
            $acct = trim((string)$r['account_number']);
            if (!isset($ctx['company'][$acct]))                 $stats['no_masterfile']++;
            if (!isset($ctx['email'][$acct]))                   $stats['no_email']++;
            if (!isset($ctx['sent'][(string)$r['filename']]))   $stats['not_emailed']++;
            $incomplete = false;
            foreach ($fields as $col => $label) {
                if (rp_is_blank($r[$col])) { $stats['miss_' . $col]++; $incomplete = true; }
            }
            if ($incomplete) $stats['incomplete']++;
        }

        foreach ($fields as $col => $label) {
            $charts['fields'][] = array('label' => $label, 'value' => (int)(isset($stats['miss_' . $col]) ? $stats['miss_' . $col] : 0));
        }

        // Upload progress: memo items (account + coverage period) that have an SOA vs those that still need one.
        // Same rule as the dashboard. Only needed for the overview, not for the record list.
        if (!$is_view) {
            try {
                $all_scope = array('start_date' => '', 'end_date' => '', 'billing_period' => '', 'telco' => '', 'q' => '');
                $nc_all = rp_need_compute($conn, $all_scope);
                $need_n = count($nc_all['rows']);
                $progress = array('expected' => $nc_all['scope'], 'need' => $need_n, 'uploaded' => max(0, $nc_all['scope'] - $need_n), 'accounts' => $nc_all['accounts']);
            } catch (Exception $e) {
                error_log('reports.php soa progress: ' . $e->getMessage());
            }
        }

        // ---- The record list (only when View report is pressed) ----
        if ($is_view) {
            if ($list_mode === 'need_upload') {
                $nc = rp_need_compute($conn, $f);
                $need_scope_total = $nc['scope'];
                $total_records    = count($nc['rows']);
                $rows = array_slice($nc['rows'], $offset, $per_page);
            } else {
                list($where, $params) = rp_soa_where($f);
                if ($f['check'] === '') {
                    // No "Show only" filter: let the database page it
                    $total_records = (int)rp_row($conn, "SELECT COUNT(*) AS c $from $where", $params)['c'];
                    $rows = rp_all($conn, "SELECT $soa_cols $from $where ORDER BY p.updated_at DESC, p.id DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset, $params);
                } else {
                    $pool = array();
                    foreach (rp_all($conn, "SELECT $soa_cols $from $where ORDER BY p.updated_at DESC, p.id DESC", $params) as $r) {
                        if (rp_soa_check_ok($r, $f['check'], $ctx)) { $pool[] = $r; }
                    }
                    $total_records = count($pool);
                    $rows = array_slice($pool, $offset, $per_page);
                }
                foreach ($rows as $i => $r) { $rows[$i] = rp_soa_enrich($r, $ctx); }
            }
            $total_pages = max(1, (int)ceil($total_records / $per_page));
        }

    // ================= 3. EMAIL SOA =================
    } else {
        list($where, $params) = rp_email_where($f);
        $from = "FROM dispatch_logs l WHERE 1=1";

        if ($action === 'export') {
            $all = rp_all($conn, "SELECT l.* $from $where ORDER BY l.dispatch_date DESC", $params);
            $out = array();
            foreach ($all as $r) {
                $out[] = array(
                    rp_date($r['dispatch_date'], 'M d, Y g:i A'), $r['status'], $r['account_number'], $r['telco'],
                    $r['mobile_number'], $r['recipient_email'], $r['billing_period'], (float)$r['total_charge'],
                    $r['filename'], $r['file_link'], $r['sent_by'], $r['details'],
                );
            }
            $meta = array(
                'EMAIL SOA REPORT',
                'STATUS: ' . ($f['status'] !== '' ? $f['status'] : 'ALL'),
                'TELCO: ' . ($f['telco'] !== '' ? $f['telco'] : 'ALL'),
                'SENT: ' . (($f['start_date'] !== '' || $f['end_date'] !== '') ? ($f['start_date'] . ' to ' . $f['end_date']) : 'ANY DATE'),
            );
            rp_xml_excel('Email_SOA_Report_' . date('Y-m-d_His') . '.xls', 'Email SOA', $meta,
                array('Date Sent', 'Status', 'Account Number', 'Telco', 'Mobile Number', 'Recipient Email', 'Billing Period', 'Total Charge', 'Filename', 'File Link', 'Sent By', 'Details'),
                $out, array(7));
        }

        $stats = rp_row($conn, "SELECT COUNT(*) AS total_sent,
                SUM(CASE WHEN UPPER(l.status) = 'SUCCESS' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN UPPER(l.status) = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                COUNT(DISTINCT l.account_number) AS total_accounts,
                SUM(CASE WHEN UPPER(l.status) = 'SUCCESS' THEN COALESCE(l.total_charge, 0) ELSE 0 END) AS success_charge
            $from $where", $params);
        $total_records = (int)(isset($stats['total_sent']) ? $stats['total_sent'] : 0);
        $total_pages   = max(1, (int)ceil($total_records / $per_page));

        if (!$is_view) {
            try {
                $days = rp_all($conn, "SELECT DATE(l.dispatch_date) AS day_date,
                        SUM(CASE WHEN UPPER(l.status) = 'SUCCESS' THEN 1 ELSE 0 END) AS ok_count,
                        SUM(CASE WHEN UPPER(l.status) = 'FAILED' THEN 1 ELSE 0 END) AS bad_count
                    $from $where GROUP BY DATE(l.dispatch_date) ORDER BY day_date DESC LIMIT 31", $params);
                $days = array_reverse($days);
                foreach ($days as $r) {
                    if (empty($r['day_date'])) continue;
                    $t = strtotime($r['day_date']);
                    $charts['days'][] = array(
                        'label' => count($days) > 10 ? date('j', $t) : date('j M', $t),
                        'title' => date('M j, Y', $t) . ': ' . number_format((int)$r['ok_count']) . ' sent, ' . number_format((int)$r['bad_count']) . ' failed',
                        'a'     => (int)$r['ok_count'],
                        'b'     => (int)$r['bad_count'],
                    );
                }

                $reasons = rp_all($conn, "SELECT l.details AS reason, COUNT(*) AS n $from $where AND UPPER(l.status) = 'FAILED' GROUP BY l.details ORDER BY n DESC LIMIT 5", $params);
               foreach ($reasons as $r) {
    $txt = trim((string)$r['reason']);
    $label = ($txt !== '') ? (strlen($txt) > 60 ? substr($txt, 0, 57) . '...' : $txt) : 'No reason recorded';
    $charts['reasons'][] = array('label' => $label, 'value' => (int)$r['n']);
}
            } catch (Exception $e) {
                error_log('reports.php email overview: ' . $e->getMessage());
            }
        }

        if ($is_view) {
            $rows = rp_all($conn, "SELECT l.* $from $where ORDER BY l.dispatch_date DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset, $params);
        }
    }
} catch (Exception $e) {
    error_log('reports.php ' . $report . ': ' . $e->getMessage());
    $rows = array();
    $load_error = "This report couldn't be loaded. Check that its database tables exist, then try again.";
}

// Table definition for the Debit Masterfile preview (colours match the Excel header colours)
$mf_num_cols = array(
    array('Approved Plan',                     '#93C47D', 'approved_plan'),
    array('MSF (GLOBE / MRC (SMART)',          '#4A86E8', 'phone_amortization'),
    array('Debit Adj',                         '#93C47D', 'debit_adj'),
    array('Credit Adj',                        '#93C47D', 'credit_adj'),
    array('Other charges / phone amortization', '#4A86E8', 'other_charges'),
    array('Local (Call/Text)',                 '#4A86E8', 'local_call_text'),
    array('NDD (National)',                    '#4A86E8', 'ndd_charges'),
    array('IDD (International)',               '#4A86E8', 'idd_charges'),
    array('Roam',                              '#4A86E8', 'roaming_charges'),
    array('SMS',                               '#4A86E8', 'sms_charges'),
    array('GPRS',                              '#4A86E8', 'gprs_charges'),
    array('Wiz Usage',                         '#4A86E8', 'wiz_usage'),
    array('Loading Charges',                   '#4A86E8', 'loading_charges'),
    array('VAT',                               '#4A86E8', 'vat'),
    array('OCT',                               '#4A86E8', 'oct'),
    array('Current Charges',                   '#4A86E8', 'current_charges'),
    array('Total Amount Due',                  '#4A86E8', 'total_amount_due'),
    array('Processed DM',                      '#93C47D', 'debit_memo_details'),
);

$has_filters = false;
foreach (array('carrier_name', 'company', 'telco', 'billing_period', 'check', 'status', 'sent_by', 'q') as $k) {
    if ($f[$k] !== '') $has_filters = true;
}

// Links that keep the current filters
$link_base    = array_merge(array('report' => $report), $_GET);
$back_url     = 'reports.php?' . http_build_query(array_diff_key($link_base, array('action' => 1, 'page' => 1)));
$need_url     = 'reports.php?' . http_build_query(array_merge(array_diff_key($link_base, array('start_date' => 1, 'end_date' => 1, 'billing_period' => 1, 'telco' => 1, 'status' => 1)), array('check' => 'need_upload', 'action' => 'view', 'page' => 1)));
$incomplete_url = 'reports.php?' . http_build_query(array_merge($link_base, array('check' => 'incomplete', 'action' => 'view', 'page' => 1)));
$failed_url   = 'reports.php?' . http_build_query(array_merge($link_base, array('status' => 'FAILED', 'action' => 'view', 'page' => 1)));

// Capture layout content
ob_start();
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Familjen+Grotesk:wght@500;600;700&family=Figtree:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Familjen+Grotesk:wght@500;600;700&family=Figtree:wght@400;500;600;700&display=swap"></noscript>

<style>
.rp {
    --ink: #12302e; --muted: #5d7673; --line: #dbe6e4; --wash: #e6f1ef;
    --teal: #0f766e; --teal-deep: #0b5a54; --warn: #a86b00; --bad: #c62f45;
    font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
    padding: 1.5rem;
    display: flex; flex-direction: column; gap: 1.1rem;
}
.rp .num { font-family: 'Familjen Grotesk', 'Figtree', sans-serif; letter-spacing: -0.015em; }
.rp :is(a, button, input, select):focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
.rp-muted { color: var(--muted); font-size: .8125rem; }

/* Report picker */
.rp-tabs { display: grid; grid-template-columns: 1fr; gap: .9rem; }
@media (min-width: 900px) { .rp-tabs { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.rp-tab { display: flex; gap: .9rem; align-items: flex-start; padding: 1rem 1.1rem; background: #fff; border: 1px solid var(--line); border-radius: 16px; text-decoration: none; color: inherit; transition: border-color .15s, background-color .15s; }
.rp-tab:hover { border-color: #9ccbc5; }
.rp-tab[aria-current="page"] { border-color: var(--teal); background: var(--wash); box-shadow: inset 0 0 0 1px var(--teal); }
.rp-tab-icon { flex: none; width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: 12px; background: var(--wash); color: var(--teal-deep); font-size: 1.4rem; }
.rp-tab[aria-current="page"] .rp-tab-icon { background: var(--teal); color: #fff; }
.rp-tab h3 { margin: 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.05rem; font-weight: 700; }
.rp-tab p { margin: .2rem 0 0; font-size: .8125rem; line-height: 1.45; color: var(--muted); }
.rp-tab small { display: block; margin-top: .45rem; font-size: .75rem; font-weight: 600; color: var(--teal-deep); }

/* Panels */
.rp-panel { background: #fff; border: 1px solid var(--line); border-radius: 18px; }
.rp-filter-panel { padding: 1.25rem 1.4rem; }
.rp-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(14.5rem, 1fr)); gap: 1rem; }
.rp-field { display: flex; flex-direction: column; gap: .3rem; font-size: .8125rem; font-weight: 600; color: var(--muted); }
.rp-field select, .rp-field input { height: 2.4rem; padding: 0 .7rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; font: inherit; font-size: .875rem; font-weight: 500; color: var(--ink); width: 100%; }
.rp-range { display: flex; align-items: center; gap: .5rem; }
.rp-range span { color: var(--muted); font-size: .8125rem; }
.rp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px solid var(--line); }
.rp-actions .spacer { flex: 1; }
.rp-btn { display: inline-flex; align-items: center; gap: .4rem; height: 2.4rem; padding: 0 1.1rem; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--ink); font: inherit; font-size: .875rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: background-color .15s; }
.rp-btn:hover { background: #f4f8f7; }
.rp-btn-primary { background: var(--teal); border-color: var(--teal); color: #fff; }
.rp-btn-primary:hover { background: var(--teal-deep); }
.rp-btn-export { border-color: var(--teal); color: var(--teal-deep); }
.rp-btn-export:hover { background: var(--wash); }

/* Summary strip */
.rp-stats { display: flex; flex-wrap: wrap; margin: 0; padding: 0; }
.rp-stat { flex: 1 1 11rem; padding: 1rem 1.25rem; border-left: 1px solid var(--line); }
.rp-stat:first-child { border-left: 0; }
.rp-stat dt { font-size: .75rem; font-weight: 600; color: var(--muted); }
.rp-stat dd { margin: .2rem 0 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.5rem; font-weight: 700; letter-spacing: -0.015em; }
.rp-stat dd.is-warn { color: var(--warn); }
.rp-stat dd.is-bad  { color: var(--bad); }
@media (max-width: 640px) { .rp-stat { flex-basis: 50%; border-left: 0; border-top: 1px solid var(--line); } .rp-stat:nth-child(-n+2) { border-top: 0; } }

/* Results */
.rp-results-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: .5rem 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--line); }
.rp-results-head h3 { margin: 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.1rem; font-weight: 700; }
.rp-scroll { overflow: auto; max-height: 36rem; }
.rp-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .8125rem; white-space: nowrap; }
.rp-tbl thead th { position: sticky; top: 0; z-index: 1; background: var(--wash); color: var(--teal-deep); font-size: .75rem; font-weight: 700; text-align: left; padding: .7rem .85rem; border-bottom: 1px solid var(--line); }
.rp-tbl tbody td { padding: .65rem .85rem; border-bottom: 1px solid var(--line); vertical-align: middle; }
.rp-tbl tbody tr:hover td { background: #f7fbfa; }
.rp-tbl .r { text-align: right; }
.rp-tbl .strong { font-weight: 700; }
.rp-tbl .clip { max-width: 16rem; overflow: hidden; text-overflow: ellipsis; }
.rp-tbl .link { color: var(--teal-deep); font-weight: 600; text-decoration: none; }
.rp-tbl .link:hover { text-decoration: underline; }
.rp-mf thead th { color: #1b1b1b; text-align: center; white-space: normal; border: 1px solid #9aa5a3; }
.rp-mf tbody td { border-right: 1px solid var(--line); }

.rp-chip { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
.rp-chip-ok   { background: #d9f2e9; color: #0a6b4d; }
.rp-chip-warn { background: #fdf0d2; color: #8a5a00; }
.rp-chip-bad  { background: #fbdde3; color: #a3203a; }
.rp-chip-mute { background: #edf3f2; color: var(--muted); }

.rp-empty { padding: 3rem 1.25rem; text-align: center; color: var(--muted); font-size: .9rem; }
.rp-alert { padding: .9rem 1.25rem; border-radius: 12px; background: #fbdde3; color: #8f1d34; font-size: .875rem; font-weight: 600; }

.rp-pager-wrap { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; padding: .9rem 1.25rem; border-top: 1px solid var(--line); background: #f9fbfb; border-radius: 0 0 18px 18px; }
.rp-pager { display: flex; flex-wrap: wrap; gap: .35rem; }
.rp-page { padding: .3rem .75rem; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: var(--ink); font-size: .8125rem; font-weight: 600; text-decoration: none; }
.rp-page:hover { background: var(--wash); }
.rp-page.is-current { background: var(--teal); border-color: var(--teal); color: #fff; }

/* Overview charts */
.rp-grid2 { display: grid; grid-template-columns: 1fr; gap: 1.1rem; }
@media (min-width: 1000px) { .rp-grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.rp-card { padding: 1.3rem 1.4rem; }
.rp-card h3 { margin: 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.1rem; font-weight: 700; }
.rp-card > p.rp-muted { margin: .2rem 0 1rem; }
.rp-card h4 { margin: 1.25rem 0 .6rem; font-size: .8125rem; font-weight: 700; color: var(--muted); }
.rp-empty-sm { margin: 1rem 0 0; color: var(--muted); font-size: .875rem; }
.rp-linkbtn { display: inline-block; margin-top: 1rem; font-size: .8125rem; font-weight: 700; color: var(--teal-deep); text-decoration: none; }
.rp-linkbtn:hover { text-decoration: underline; }

.rp-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .85rem; }
.rp-bar-top { display: flex; justify-content: space-between; gap: 1rem; font-size: .8125rem; font-weight: 600; }
.rp-bar-top small { font-weight: 500; color: var(--muted); margin-left: .35rem; }
.rp-bar-top b { white-space: nowrap; }
.rp-bar-track { height: 8px; margin-top: .35rem; border-radius: 999px; background: #edf3f2; overflow: hidden; }
.rp-bar-track span { display: block; height: 100%; width: var(--w); background: var(--teal); border-radius: 999px; transform-origin: left; animation: rp-grow .8s cubic-bezier(.2,.7,.2,1) both; }

.rp-cols { display: flex; align-items: flex-end; gap: .5rem; overflow-x: auto; padding-top: .25rem; }
.rp-col { flex: 1 1 0; min-width: 1.6rem; max-width: 4.5rem; display: flex; flex-direction: column; align-items: center; gap: .3rem; }
.rp-col em { font-style: normal; font-size: .6875rem; font-weight: 700; color: var(--ink); white-space: nowrap; }
.rp-col-bar { width: 100%; height: 9rem; display: flex; flex-direction: column-reverse; border-radius: 6px 6px 0 0; overflow: hidden; background: #f3f8f7; }
.rp-col-bar span { display: block; width: 100%; transform-origin: bottom; animation: rp-rise .8s cubic-bezier(.2,.7,.2,1) both; }
.rp-col-bar .a { background: var(--teal); }
.rp-col-bar .b { background: #e2504a; }
.rp-col small { font-size: .6875rem; color: var(--muted); white-space: nowrap; }
.rp-legend { display: flex; gap: 1rem; margin-top: .9rem; font-size: .75rem; color: var(--muted); }
.rp-legend i { display: inline-block; width: .65rem; height: .65rem; border-radius: 3px; margin-right: .35rem; }

.rp-meter { display: flex; height: 12px; border-radius: 999px; overflow: hidden; background: #edf3f2; margin: .6rem 0 1rem; }
.rp-meter span { display: block; width: var(--w); transform-origin: left; animation: rp-grow .9s cubic-bezier(.2,.7,.2,1) both; }
.rp-meter .ok { background: var(--teal); }
.rp-meter .need { background: #e0a100; }
.rp-meter .bad { background: #e2504a; }
.rp-bignum { display: flex; align-items: baseline; flex-wrap: wrap; gap: .5rem; }
.rp-bignum strong { font-family: 'Familjen Grotesk', sans-serif; font-size: 2.4rem; font-weight: 700; letter-spacing: -0.02em; line-height: 1; }
.rp-bignum span { color: var(--muted); font-size: .875rem; }
.rp-bignum b { margin-left: auto; font-size: 1rem; color: var(--teal-deep); }
.rp-split { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin: 0; }
.rp-split div { border-left: 2px solid var(--line); padding-left: .75rem; }
.rp-split dt { font-size: .75rem; color: var(--muted); font-weight: 600; }
.rp-split dd { margin: .15rem 0 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.35rem; font-weight: 700; }
.rp-split dd.is-warn { color: var(--warn); }
.rp-split dd.is-bad { color: var(--bad); }

.rp-hint { margin: 0; padding: .9rem 1.2rem; border: 1px dashed #9ccbc5; border-radius: 14px; background: #f7fbfa; color: var(--teal-deep); font-size: .875rem; }
.rp-miss { display: inline-block; padding: .1rem .5rem; border-radius: 999px; background: #fdf0d2; color: #8a5a00; font-size: .75rem; font-weight: 700; }
.rp-back { font-size: .8125rem; font-weight: 700; color: var(--teal-deep); text-decoration: none; }
.rp-back:hover { text-decoration: underline; }

@keyframes rp-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes rp-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
@media (prefers-reduced-motion: reduce) {
    .rp-tab, .rp-btn { transition: none; }
    .rp-bar-track span, .rp-col-bar span, .rp-meter span { animation: none; }
}
</style>

<div class="rp">

    <!-- 1. PICK A REPORT -->
    <nav class="rp-tabs" aria-label="Choose a report">
        <?php foreach ($report_defs as $key => $def): ?>
            <a class="rp-tab" href="reports.php?report=<?= $key ?>" <?= $report === $key ? 'aria-current="page"' : '' ?>>
                <span class="rp-tab-icon"><i class="las <?= $def['icon'] ?>" aria-hidden="true"></i></span>
                <div>
                    <h3><?= rp_e($def['label']) ?></h3>
                    <p><?= rp_e($def['desc']) ?></p>
                    <?php if ($counts[$key] !== null): ?>
                        <small><?= number_format($counts[$key]) ?> <?= $count_units[$key] ?> in total</small>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- 2. FILTERS -->
    <section class="rp-panel rp-filter-panel" aria-label="<?= rp_e($report_defs[$report]['label']) ?> filters">
        <form method="GET" action="reports.php">
            <input type="hidden" name="report" value="<?= rp_e($report) ?>">
            <input type="hidden" name="page" value="1">

            <div class="rp-filters">
                <?php if ($report === 'masterfile'): ?>
                    <?= rp_select('carrier_name', 'Carrier / Telco', rp_opts($opt['carriers']), $f['carrier_name'], 'All carriers') ?>
                    <?= rp_select('company', 'Company / Branch', rp_opts($opt['companies']), $f['company'], 'All companies') ?>

                <?php elseif ($report === 'soa'): ?>
                    <?= rp_select('telco', 'Telco', rp_opts($opt['telcos']), $f['telco'], 'All telcos') ?>
                    <?= rp_select('billing_period', 'Coverage date', rp_opts($opt['periods']), $f['billing_period'], 'All coverage dates') ?>
                    <?= rp_select('check', 'Show only', array(
                            'need_upload'   => 'Memo items that still need an SOA',
                            'incomplete'    => 'Incomplete details',
                            'no_masterfile' => 'Not in Debit Masterfile',
                            'no_email'      => 'No email on file',
                            'not_emailed'   => 'Not emailed yet',
                            'emailed'       => 'Already emailed',
                        ), $f['check'], 'All statements') ?>
                    <label class="rp-field"><span>Search</span>
                        <input type="search" name="q" value="<?= rp_e($f['q']) ?>" placeholder="Account, mobile or file name">
                    </label>

                <?php else: ?>
                    <?= rp_select('status', 'Status', array('SUCCESS' => 'Sent', 'FAILED' => 'Failed'), $f['status'], 'Sent and failed') ?>
                    <?= rp_select('telco', 'Telco', rp_opts($opt['telcos']), $f['telco'], 'All telcos') ?>
                    <?= rp_select('sent_by', 'Sent by', rp_opts($opt['senders']), $f['sent_by'], 'Everyone') ?>
                    <label class="rp-field"><span>Search</span>
                        <input type="search" name="q" value="<?= rp_e($f['q']) ?>" placeholder="Account, email, mobile or file name">
                    </label>
                <?php endif; ?>

                <div class="rp-field">
                    <span id="rpDateLabel"><?= $report === 'masterfile' ? 'Coverage date' : ($report === 'soa' ? 'Imported date' : 'Date sent') ?></span>
                    <div class="rp-range">
                        <input type="date" name="start_date" value="<?= rp_e($f['start_date']) ?>" aria-label="From date">
                        <span>to</span>
                        <input type="date" name="end_date" value="<?= rp_e($f['end_date']) ?>" aria-label="To date">
                    </div>
                </div>
            </div>

            <div class="rp-actions">
                <button type="submit" name="action" value="view" class="rp-btn rp-btn-primary">
                    <i class="las la-search" aria-hidden="true"></i> View report
                </button>
                <a href="reports.php?report=<?= rp_e($report) ?>" class="rp-btn">Reset</a>
                <span class="spacer"></span>
                <button type="submit" name="action" value="export" class="rp-btn rp-btn-export">
                    <i class="las la-file-excel" aria-hidden="true"></i> Download Excel
                </button>
            </div>
        </form>
    </section>

    <?php if ($load_error !== ''): ?>
        <div class="rp-alert" role="alert"><?= rp_e($load_error) ?></div>
    <?php else: ?>

    <!-- 3. SUMMARY NUMBERS (always shown, cheap totals only) -->
    <?php if ($report === 'masterfile' && !empty($stats)): ?>
        <section class="rp-panel" aria-label="Summary">
            <dl class="rp-stats">
                <div class="rp-stat"><dt>Memo items</dt><dd><?= number_format($total_records) ?></dd></div>
                <div class="rp-stat"><dt>Accounts</dt><dd><?= number_format((int)$stats['accounts']) ?></dd></div>
                <div class="rp-stat"><dt>Current charges</dt><dd><?= rp_money($stats['current_total']) ?></dd></div>
                <div class="rp-stat"><dt>Processed DM</dt><dd><?= rp_money($stats['processed_total']) ?></dd></div>
            </dl>
        </section>
    <?php elseif ($report === 'soa' && !empty($stats)): ?>
        <section class="rp-panel" aria-label="Summary">
            <dl class="rp-stats">
                <div class="rp-stat"><dt>Statement files</dt><dd><?= number_format((int)$stats['total_files']) ?></dd></div>
                <div class="rp-stat"><dt>Total amount due</dt><dd><?= rp_money($stats['total_due']) ?></dd></div>
                <div class="rp-stat"><dt>Incomplete details</dt><dd class="<?= (int)$stats['incomplete'] > 0 ? 'is-warn' : '' ?>"><?= number_format((int)$stats['incomplete']) ?></dd></div>
                <div class="rp-stat"><dt>Not in masterfile</dt><dd class="<?= (int)$stats['no_masterfile'] > 0 ? 'is-warn' : '' ?>"><?= number_format((int)$stats['no_masterfile']) ?></dd></div>
                <div class="rp-stat"><dt>No email on file</dt><dd class="<?= (int)$stats['no_email'] > 0 ? 'is-warn' : '' ?>"><?= number_format((int)$stats['no_email']) ?></dd></div>
                <div class="rp-stat"><dt>Not emailed yet</dt><dd><?= number_format((int)$stats['not_emailed']) ?></dd></div>
            </dl>
        </section>
    <?php elseif ($report === 'email' && !empty($stats)):
        $sent_total = (int)$stats['total_sent'];
        $ok   = (int)$stats['success_count'];
        $bad  = (int)$stats['failed_count'];
        $rate = $sent_total > 0 ? round(($ok / $sent_total) * 100, 1) : 0;
    ?>
        <section class="rp-panel" aria-label="Summary">
            <dl class="rp-stats">
                <div class="rp-stat"><dt>Emails logged</dt><dd><?= number_format($sent_total) ?></dd></div>
                <div class="rp-stat"><dt>Sent</dt><dd><?= number_format($ok) ?></dd></div>
                <div class="rp-stat"><dt>Failed</dt><dd class="<?= $bad > 0 ? 'is-bad' : '' ?>"><?= number_format($bad) ?></dd></div>
                <div class="rp-stat"><dt>Delivery rate</dt><dd><?= $rate ?>%</dd></div>
                <div class="rp-stat"><dt>Accounts reached</dt><dd><?= number_format((int)$stats['total_accounts']) ?></dd></div>
                <div class="rp-stat"><dt>Charges sent</dt><dd><?= rp_money($stats['success_charge']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if (!$is_view): ?>
    <!-- 4A. OVERVIEW: charts only, the record list is not loaded -->

        <?php if ($report === 'masterfile'): ?>
            <div class="rp-grid2">
                <section class="rp-panel rp-card" aria-labelledby="mf-carrier-title">
                    <h3 id="mf-carrier-title">Processed DM by carrier</h3>
                    <p class="rp-muted">For the filters above</p>
                    <?= rp_hbars($charts['carriers'], true) ?>
                </section>
                <section class="rp-panel rp-card" aria-labelledby="mf-month-title">
                    <h3 id="mf-month-title">Processed DM by coverage month</h3>
                    <p class="rp-muted">Up to the latest 12 months in range</p>
                    <?= rp_columns($charts['months'], true) ?>
                </section>
            </div>

        <?php elseif ($report === 'soa'):
            $exp  = isset($progress['expected']) ? (int)$progress['expected'] : 0;
            $upl  = isset($progress['uploaded']) ? (int)$progress['uploaded'] : 0;
            $need = isset($progress['need']) ? (int)$progress['need'] : 0;
            $pct  = $exp > 0 ? round(($upl / $exp) * 100, 1) : 0;
        ?>
            <div class="rp-grid2">
                <section class="rp-panel rp-card" aria-labelledby="soa-progress-title">
                    <h3 id="soa-progress-title">SOA upload progress</h3>
                    <p class="rp-muted">
                        Every memo item in the Debit Masterfile (an account and its coverage period), and whether it has an SOA.
                        Same rule as the dashboard.
                    </p>
                    <?php if (empty($progress)): ?>
                        <p class="rp-empty-sm">Upload progress isn't available right now.</p>
                    <?php else: ?>
                        <div class="rp-bignum">
                            <strong class="num"><?= number_format($upl) ?></strong>
                            <span>of <?= number_format($exp) ?> memo items have an SOA</span>
                            <b><?= $pct ?>%</b>
                        </div>
                        <div class="rp-meter" role="img" aria-label="<?= $pct ?> percent of memo items have an SOA">
                            <span class="ok" style="--w: <?= $pct ?>%"></span>
                            <span class="need" style="--w: <?= max(0, 100 - $pct) ?>%"></span>
                        </div>
                        <dl class="rp-split">
                            <div><dt>With SOA</dt><dd><?= number_format($upl) ?></dd></div>
                            <div><dt>Need to upload</dt><dd class="<?= $need > 0 ? 'is-warn' : '' ?>"><?= number_format($need) ?></dd></div>
                            <div><dt>Accounts affected</dt><dd><?= number_format((int)$progress['accounts']) ?></dd></div>
                        </dl>
                        <?php if ($need > 0): ?>
                            <a class="rp-linkbtn" href="<?= rp_e($need_url) ?>">Show the dates that still need an SOA (<?= number_format($need) ?>)</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="rp-panel rp-card" aria-labelledby="soa-quality-title">
                    <h3 id="soa-quality-title">Imported details that are missing</h3>
                    <p class="rp-muted">Statement files with an empty value, by field</p>
                    <?= rp_hbars($charts['fields']) ?>
                    <?php if (!empty($stats) && (int)$stats['incomplete'] > 0): ?>
                        <a class="rp-linkbtn" href="<?= rp_e($incomplete_url) ?>">Show the <?= number_format((int)$stats['incomplete']) ?> incomplete statements</a>
                    <?php endif; ?>
                </section>
            </div>

        <?php else: ?>
            <div class="rp-grid2">
                <section class="rp-panel rp-card" aria-labelledby="em-delivery-title">
                    <h3 id="em-delivery-title">Delivery</h3>
                    <p class="rp-muted">Sent versus failed for the filters above</p>
                    <?php
                        $e_ok   = !empty($stats) ? (int)$stats['success_count'] : 0;
                        $e_bad  = !empty($stats) ? (int)$stats['failed_count'] : 0;
                        $e_tot  = $e_ok + $e_bad;
                        $e_okp  = $e_tot > 0 ? round(($e_ok / $e_tot) * 100, 1) : 0;
                    ?>
                    <?php if ($e_tot === 0): ?>
                        <p class="rp-empty-sm">No emails were logged for these filters.</p>
                    <?php else: ?>
                        <div class="rp-bignum">
                            <strong class="num"><?= $e_okp ?>%</strong>
                            <span>delivered (<?= number_format($e_ok) ?> of <?= number_format($e_tot) ?>)</span>
                        </div>
                        <div class="rp-meter" role="img" aria-label="<?= number_format($e_ok) ?> sent, <?= number_format($e_bad) ?> failed">
                            <span class="ok" style="--w: <?= $e_okp ?>%"></span>
                            <span class="bad" style="--w: <?= max(0, 100 - $e_okp) ?>%"></span>
                        </div>
                        <?php if (!empty($charts['reasons'])): ?>
                            <h4>Most common failure reasons</h4>
                            <?= rp_hbars($charts['reasons']) ?>
                        <?php endif; ?>
                        <?php if ($e_bad > 0): ?>
                            <a class="rp-linkbtn" href="<?= rp_e($failed_url) ?>">Show the <?= number_format($e_bad) ?> failed emails</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="rp-panel rp-card" aria-labelledby="em-days-title">
                    <h3 id="em-days-title">Emails per day</h3>
                    <p class="rp-muted">Up to the latest 31 days in range</p>
                    <?= rp_columns($charts['days']) ?>
                    <div class="rp-legend"><span><i style="background:var(--teal)"></i>Sent</span><span><i style="background:#e2504a"></i>Failed</span></div>
                </section>
            </div>
        <?php endif; ?>

        <p class="rp-hint">
            You're looking at the overview. Press <b>View report</b> to load the records, 25 at a time.
        </p>

    <?php else: ?>
    <!-- 4B. RECORD LIST (only after View report), paged -->
    <section class="rp-panel" aria-label="<?= rp_e($report_defs[$report]['label']) ?> records">
        <div class="rp-results-head">
            <h3><?= $list_mode === 'need_upload' ? 'Dates that still need an SOA' : rp_e($report_defs[$report]['label']) . ' records' ?></h3>
            <span class="rp-muted">
                <?php if ($list_mode === 'need_upload'): ?>
                    <?= number_format($total_records) ?> memo item<?= $total_records === 1 ? '' : 's' ?> need an SOA (of <?= number_format($need_scope_total) ?> in this range)
                <?php else: ?>
                    <?= number_format($total_records) ?> matching record<?= $total_records === 1 ? '' : 's' ?>
                <?php endif; ?>
                &middot; <a class="rp-back" href="<?= rp_e($back_url) ?>">Back to overview</a>
            </span>
        </div>

        <?php if (empty($rows)): ?>
            <p class="rp-empty">
                <?php if ($list_mode === 'need_upload'): ?>
                    Every memo item in this range already has an SOA.
                <?php elseif ($report === 'soa'): ?>
                    No statement files match these filters.
                    <?= $has_filters ? 'Clear a filter' : 'Try a wider date range' ?> or press Reset.
                <?php elseif ($report === 'email'): ?>
                    No emails were logged for these filters.
                    <?= $has_filters ? 'Clear a filter' : 'Try a wider date range' ?> or press Reset.
                <?php else: ?>
                    No memo items match these filters.
                    <?= $has_filters ? 'Clear a filter' : 'Try a wider date range' ?> or press Reset.
                <?php endif; ?>
            </p>

        <?php elseif ($report === 'masterfile'): ?>
            <div class="rp-scroll">
                <table class="rp-tbl rp-mf" style="min-width: 2200px;">
                    <thead>
                        <tr>
                            <th scope="col" style="background:#FFE599;">Coverage Date</th>
                            <th scope="col" style="background:#4A86E8;">Account Number</th>
                            <th scope="col" style="background:#4A86E8;">Company</th>
                            <th scope="col" style="background:#4A86E8;">Assignee</th>
                            <th scope="col" style="background:#4A86E8;">Carrier / Telco</th>
                            <th scope="col" style="background:#93C47D;">Mobile Number</th>
                            <?php foreach ($mf_num_cols as $c): ?>
                                <th scope="col" style="background:<?= $c[1] ?>;"><?= rp_e($c[0]) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" style="background:#93C47D;">System Generated DM</th>
                            <th scope="col" style="background:#F7F700;">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $start = rp_date(isset($row['coverage_start']) ? $row['coverage_start'] : '');
                            $end   = rp_date(isset($row['coverage_end']) ? $row['coverage_end'] : '');
                            $coverage_text = ($start && $end) ? ($start . ' to ' . $end) : '';

                            $current_raw    = (float)(isset($row['current_charges']) ? $row['current_charges'] : 0);
                            $plan_raw       = (float)(isset($row['approved_plan']) ? $row['approved_plan'] : 0);
                            $dm_details_raw = (float)(isset($row['debit_memo_details']) ? $row['debit_memo_details'] : 0);

                            // SYSTEM GENERATED DM = Current Charges - Approved Plan
                            $sys_gen_dm = $current_raw - $plan_raw;
                            // DIFFERENCE = Processed DM - System Generated DM
                            $difference = $dm_details_raw - $sys_gen_dm;
                        ?>
                            <tr>
                                <td><?= rp_e($coverage_text) ?></td>
                                <td class="strong"><?= rp_e(isset($row['account_number']) ? $row['account_number'] : 'N/A') ?></td>
                                <td class="strong clip" style="color:var(--teal-deep);" title="<?= rp_e(isset($row['dm_company']) ? $row['dm_company'] : '') ?>"><?= rp_e(isset($row['dm_company']) ? $row['dm_company'] : 'N/A') ?></td>
                                <td><?= rp_e(isset($row['assignee_name']) ? $row['assignee_name'] : 'N/A') ?></td>
                                <td><?= rp_e(isset($row['carrier_name']) ? $row['carrier_name'] : 'N/A') ?></td>
                                <td><?= rp_e(isset($row['mobile_number']) ? $row['mobile_number'] : 'N/A') ?></td>
                                <?php foreach ($mf_num_cols as $c):
                                    $v   = (float)(isset($row[$c[2]]) ? $row[$c[2]] : 0);
                                    $cls = 'r';
                                    $sty = '';
                                    if ($c[2] === 'current_charges')   { $cls .= ' strong'; }
                                    if ($c[2] === 'total_amount_due')  { $cls .= ' strong'; $sty = 'color:#0a6b4d;'; }
                                    if ($c[2] === 'debit_memo_details') { $cls .= ' strong'; $sty = 'color:var(--bad);'; }
                                ?>
                                    <td class="<?= $cls ?>"<?= $sty !== '' ? ' style="' . $sty . '"' : '' ?>><?= number_format($v, 2) ?></td>
                                <?php endforeach; ?>
                                <td class="r strong" style="color:var(--teal-deep);"><?= number_format($sys_gen_dm, 2) ?></td>
                                <td class="r strong" style="<?= $difference != 0 ? 'color:var(--bad);' : '' ?>"><?= number_format($difference, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'soa' && $list_mode === 'need_upload'): ?>
            <div class="rp-scroll">
                <table class="rp-tbl" style="min-width: 1000px;">
                    <thead>
                        <tr>
                            <th scope="col">Account number</th>
                            <th scope="col">Company</th>
                            <th scope="col">Assignee</th>
                            <th scope="col">Carrier / Telco</th>
                            <th scope="col">Mobile number</th>
                            <th scope="col">Date need SOA (coverage)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $d1 = rp_date($r['coverage_start']);
                            $d2 = rp_date($r['coverage_end']);
                        ?>
                            <tr>
                                <td class="strong"><?= rp_e($r['acct']) ?></td>
                                <td class="clip" title="<?= rp_e($r['company_name']) ?>"><?= rp_e($r['company_name']) ?></td>
                                <td><?= rp_e($r['assignee']) ?></td>
                                <td><?= rp_e($r['carrier_name']) ?></td>
                                <td><?= rp_e($r['mobile_number']) ?></td>
                                <td><span class="rp-chip rp-chip-warn"><?= rp_e($d1 !== '' ? $d1 : '?') ?> to <?= rp_e($d2 !== '' ? $d2 : '?') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'soa'): ?>
            <div class="rp-scroll">
                <table class="rp-tbl" style="min-width: 1650px;">
                    <thead>
                        <tr>
                            <th scope="col">Account number</th>
                            <th scope="col">Company</th>
                            <th scope="col">Mobile number</th>
                            <th scope="col">Telco</th>
                            <th scope="col">Coverage date</th>
                            <th scope="col">Invoice date</th>
                            <th scope="col">Due date</th>
                            <th scope="col" class="r">Amount due</th>
                            <th scope="col">Details</th>
                            <th scope="col">In masterfile</th>
                            <th scope="col">Email on file</th>
                            <th scope="col">Last emailed</th>
                            <th scope="col">Statement</th>
                            <th scope="col">Imported on</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $in_dm     = !empty($r['in_dm']);
                            $has_email = !empty($r['registered_email']);
                            $emailed   = rp_date($r['last_emailed'], 'M d, Y');
                            $missing   = array();
                            foreach (rp_soa_fields() as $col => $label) {
                                if (rp_is_blank($r[$col])) $missing[] = $label;
                            }
                        ?>
                            <tr>
                                <td class="strong"><?= rp_is_blank($r['account_number']) ? rp_val('') : rp_e($r['account_number']) ?></td>
                                <td class="clip" title="<?= rp_e($r['company']) ?>"><?= $in_dm ? rp_e($r['company']) : '<span class="rp-muted">None</span>' ?></td>
                                <td><?= rp_val($r['mobile_number']) ?></td>
                                <td><?= rp_val($r['telco']) ?></td>
                                <td><?= rp_val($r['billing_period']) ?></td>
                                <td><?= rp_is_blank($r['invoice_date']) ? rp_val('') : rp_e(rp_date($r['invoice_date'])) ?></td>
                                <td><?= rp_is_blank($r['due_date']) ? rp_val('') : rp_e(rp_date($r['due_date'])) ?></td>
                                <td class="r strong"><?= rp_is_blank($r['amount_due']) ? rp_val('') : number_format(rp_amount($r['amount_due']), 2) ?></td>
                                <td>
                                    <?php if (empty($missing)): ?>
                                        <span class="rp-chip rp-chip-ok">Complete</span>
                                    <?php else: ?>
                                        <span class="rp-chip rp-chip-warn" title="Missing: <?= rp_e(implode(', ', $missing)) ?>"><?= count($missing) ?> missing</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="rp-chip <?= $in_dm ? 'rp-chip-ok' : 'rp-chip-warn' ?>"><?= $in_dm ? 'Yes' : 'No' ?></span></td>
                                <td><span class="rp-chip <?= $has_email ? 'rp-chip-ok' : 'rp-chip-warn' ?>" title="<?= rp_e($r['registered_email']) ?>"><?= $has_email ? 'Mapped' : 'Missing' ?></span></td>
                                <td><?= $emailed !== '' ? '<span class="rp-chip rp-chip-ok">' . rp_e($emailed) . '</span>' : '<span class="rp-chip rp-chip-mute">Not yet</span>' ?></td>
                                <td>
                                    <?php if (!empty($r['file_link'])): ?>
                                        <a class="link" href="<?= rp_e($r['file_link']) ?>" target="_blank" rel="noopener noreferrer" title="<?= rp_e($r['filename']) ?>">Open PDF</a>
                                    <?php else: ?>
                                        <span class="rp-muted" title="<?= rp_e($r['filename']) ?>">No link</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= rp_e(rp_date($r['updated_at'], 'M d, Y g:i A')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="rp-scroll">
                <table class="rp-tbl" style="min-width: 1500px;">
                    <thead>
                        <tr>
                            <th scope="col">Date sent</th>
                            <th scope="col">Status</th>
                            <th scope="col">Account number</th>
                            <th scope="col">Telco</th>
                            <th scope="col">Mobile number</th>
                            <th scope="col">Recipient email</th>
                            <th scope="col">Billing period</th>
                            <th scope="col" class="r">Total charge</th>
                            <th scope="col">Statement</th>
                            <th scope="col">Sent by</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $st = strtoupper((string)$r['status']);
                            $chip = ($st === 'SUCCESS') ? array('rp-chip-ok', 'Sent') : (($st === 'FAILED') ? array('rp-chip-bad', 'Failed') : array('rp-chip-mute', ucfirst(strtolower($st))));
                        ?>
                            <tr>
                                <td><?= rp_e(rp_date($r['dispatch_date'], 'M d, Y g:i A')) ?></td>
                                <td><span class="rp-chip <?= $chip[0] ?>"><?= rp_e($chip[1]) ?></span></td>
                                <td class="strong"><?= rp_e($r['account_number']) ?></td>
                                <td><?= rp_e($r['telco']) ?></td>
                                <td><?= rp_e($r['mobile_number']) ?></td>
                                <td class="clip" title="<?= rp_e($r['recipient_email']) ?>"><?= rp_e($r['recipient_email']) ?></td>
                                <td><?= rp_e($r['billing_period']) ?></td>
                                <td class="r strong"><?= number_format((float)$r['total_charge'], 2) ?></td>
                                <td>
                                    <?php if (!empty($r['file_link'])): ?>
                                        <a class="link" href="<?= rp_e($r['file_link']) ?>" target="_blank" rel="noopener noreferrer" title="<?= rp_e($r['filename']) ?>">Open PDF</a>
                                    <?php else: ?>
                                        <span class="rp-muted" title="<?= rp_e($r['filename']) ?>">No link</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= rp_e($r['sent_by']) ?></td>
                                <td class="clip" style="<?= $st === 'FAILED' ? 'color:var(--bad);' : 'color:var(--muted);' ?>" title="<?= rp_e($r['details']) ?>"><?= rp_e($r['details']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?= rp_pager($page, $total_pages) ?>
    </section>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
// On the Import SOA report the date range means "memo coverage" when listing items that still need an SOA
(function () {
    var sel = document.querySelector('select[name="check"]');
    var lab = document.getElementById('rpDateLabel');
    if (!sel || !lab) return;
    function sync() { lab.textContent = sel.value === 'need_upload' ? 'Memo coverage range' : 'Imported date'; }
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php
$content = ob_get_clean();
render_layout($page_title, $content);