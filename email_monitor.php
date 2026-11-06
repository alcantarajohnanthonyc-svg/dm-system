<?php
session_start();
require_once 'config.php';
require_once 'main.php';
require_once __DIR__ . '/mail_settings.php';
require_once __DIR__ . '/email_monitor_lib.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
if (!in_array($role, array('superadmin', 'admin'), true)) { http_response_code(403); exit('Access denied.'); }

$pdo = $conn;
$schema_ok = emon_ensure_schema($pdo);

// ------------------------------------------------------------------ AJAX actions
if (isset($_GET['action']) && $_GET['action'] === 'check_bounces' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(180);
    session_write_close();
    echo json_encode(emon_check_bounces($pdo));
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'test_connection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    session_write_close();
    echo json_encode(emon_test_connection());
    exit;
}

// ------------------------------------------------------------------ filters
function em_date($v, $default) { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $default; }
$from = em_date(isset($_GET['from']) ? $_GET['from'] : '', date('Y-m-d', strtotime('-6 days')));
$to   = em_date(isset($_GET['to']) ? $_GET['to'] : '', date('Y-m-d'));
$valid_status = array('SENT', 'DELIVERED', 'BOUNCED', 'DELAYED', 'REJECTED');
$st   = isset($_GET['st']) ? strtoupper(trim($_GET['st'])) : '';
if (!in_array($st, $valid_status, true)) $st = '';
$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per  = 25;

$hrs = defined('DELIVERED_AFTER_HOURS') ? max(1, (int)DELIVERED_AFTER_HOURS) : 48;
$eff = "CASE
            WHEN delivery_status = 'BOUNCED' THEN 'BOUNCED'
            WHEN delivery_status = 'DELAYED' THEN 'DELAYED'
            WHEN delivery_status = 'REJECTED' OR (delivery_status IS NULL AND status <> 'SUCCESS') THEN 'REJECTED'
            WHEN dispatch_date <= (NOW() - INTERVAL {$hrs} HOUR) THEN 'DELIVERED'
            ELSE 'SENT'
        END";

$where  = "DATE(dispatch_date) BETWEEN :from AND :to";
$params = array(':from' => $from, ':to' => $to);
if ($q !== '') {
    $where .= " AND (account_number LIKE :q1 OR recipient_email LIKE :q2 OR mobile_number LIKE :q3 OR filename LIKE :q4)";
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = '%' . $q . '%';
}
$inner = "SELECT dl.*, ({$eff}) AS eff_status FROM dispatch_logs dl WHERE {$where}";

// ------------------------------------------------------------------ CSV export
if ($schema_ok && isset($_GET['export'])) {
    $sql = "SELECT * FROM ({$inner}) t" . ($st !== '' ? " WHERE eff_status = :st" : "") . " ORDER BY dispatch_date DESC";
    $stmt = $pdo->prepare($sql);
    $p = $params; if ($st !== '') $p[':st'] = $st;
    $stmt->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Email_Monitor_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('Date', 'Status', 'Account', 'Telco', 'Mobile', 'Recipient', 'Billing Period', 'Amount', 'File', 'Message-ID', 'Google response', 'Bounce reason', 'Bounced at', 'Sent by'));
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array($r['dispatch_date'], $r['eff_status'], $r['account_number'], $r['telco'], $r['mobile_number'], $r['recipient_email'],
            $r['billing_period'], $r['total_charge'], $r['filename'], $r['message_id'], $r['smtp_response'], $r['bounce_reason'], $r['bounced_at'], $r['sent_by']));
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------ data
$counts = array('SENT' => 0, 'DELIVERED' => 0, 'BOUNCED' => 0, 'DELAYED' => 0, 'REJECTED' => 0);
$rows = array(); $total_filtered = 0;
if ($schema_ok) {
    $cs = $pdo->prepare("SELECT eff_status, COUNT(*) AS c FROM ({$inner}) t GROUP BY eff_status");
    $cs->execute($params);
    while ($r = $cs->fetch(PDO::FETCH_ASSOC)) { if (isset($counts[$r['eff_status']])) $counts[$r['eff_status']] = (int)$r['c']; }

    $total_filtered = ($st !== '') ? $counts[$st] : array_sum($counts);
    $pages = max(1, (int)ceil($total_filtered / $per));
    if ($page > $pages) $page = $pages;
    $off = ($page - 1) * $per;

    $ls = $pdo->prepare("SELECT * FROM ({$inner}) t" . ($st !== '' ? " WHERE eff_status = :st" : "") . " ORDER BY dispatch_date DESC LIMIT {$off}, {$per}");
    $p = $params; if ($st !== '') $p[':st'] = $st;
    $ls->execute($p);
    $rows = $ls->fetchAll(PDO::FETCH_ASSOC);
} else { $pages = 1; }
$grand = array_sum($counts);
$last  = emon_state_get('last_check', null);

function em_url($over = array()) {
    $base = array('from' => $GLOBALS['from'], 'to' => $GLOBALS['to'], 'st' => $GLOBALS['st'], 'q' => $GLOBALS['q']);
    $m = array_merge($base, $over);
    foreach ($m as $k => $v) { if ($v === '' || $v === null) unset($m[$k]); }
    return 'email_monitor.php?' . http_build_query($m);
}
$chips = array(
    'SENT'      => array('Sent', 'la-paper-plane', 'Accepted by Google. Waiting to see if it bounces.', 'em-blue'),
    'DELIVERED' => array('Delivered', 'la-check-circle', 'Accepted by Google and no bounce came back after ' . $hrs . ' hours.', 'em-green'),
    'BOUNCED'   => array('Bounced', 'la-undo-alt', 'The email came back: wrong address, mailbox full, blocked, etc.', 'em-red'),
    'DELAYED'   => array('Delayed', 'la-hourglass-half', 'Could not be delivered yet. Google is still retrying.', 'em-amber'),
    'REJECTED'  => array('Not sent', 'la-ban', 'Never accepted by the mail server (no email address, SMTP error, ...).', 'em-gray'),
);

ob_start();
?>
<style>
.em-wrap { max-width: 1320px; margin: 6px auto 40px; padding: 0 14px; }
.em-card { background: rgba(255,255,255,.88); border: 1px solid rgba(255,255,255,.9); border-radius: 22px; box-shadow: var(--aq-shadow); padding: 18px 20px; margin-bottom: 18px; }
.em-top { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.em-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 14px; border: 0; cursor: pointer; font-weight: 800; font-size: 13px; text-decoration: none; font-family: inherit; }
.em-btn i { font-size: 18px; }
.em-btn-go { color: #fff; background: linear-gradient(135deg, var(--aq-green-1), var(--aq-green-2)); box-shadow: 0 6px 16px rgba(20,199,150,.32); }
.em-btn-soft { color: var(--aq-ink); background: #fff; border: 1px solid var(--aq-line); }
.em-note { font-size: 12px; color: var(--aq-muted); }
.em-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
.em-stat { display: block; text-decoration: none; padding: 14px 16px; border-radius: 18px; background: #fff; border: 2px solid transparent; box-shadow: 0 6px 18px rgba(14,116,110,.08); }
.em-stat.on { border-color: #10b981; background: #f0fdf8; }
.em-stat .n { font-size: 26px; font-weight: 800; color: var(--aq-ink); line-height: 1.1; }
.em-stat .l { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; margin-top: 4px; }
.em-stat small { display: block; color: var(--aq-muted); font-size: 11px; margin-top: 3px; line-height: 1.3; }
.em-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.em-filters label { font-size: 11px; font-weight: 800; color: var(--aq-muted); display: block; margin-bottom: 3px; }
.em-filters input { height: 38px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--aq-line); background: #fff; font-family: inherit; }
.em-table-wrap { overflow-x: auto; border: 1px solid var(--aq-line); border-radius: 18px; background: #fff; }
.em-table { width: 100%; min-width: 1050px; border-collapse: collapse; font-size: 12px; }
.em-table th { background: linear-gradient(180deg, #dcf8f0, #c8efe6); color: var(--aq-ink); font-weight: 800; padding: 10px 12px; text-align: left; white-space: nowrap; }
.em-table td { padding: 10px 12px; border-top: 1px solid #e6f1ef; color: #23506b; vertical-align: top; }
.em-table tbody tr:nth-child(even) { background: rgba(240,252,249,.7); }
.em-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 9999px; font-weight: 800; font-size: 11px; white-space: nowrap; }
.em-blue { background: #dbeafe; color: #2563eb; } .em-green { background: #d4f6e0; color: #059669; }
.em-red { background: #fde2e7; color: #e11d48; } .em-amber { background: #fdf0c4; color: #b45309; } .em-gray { background: #e2e8f0; color: #475569; }
.em-reason { max-width: 380px; line-height: 1.35; word-break: break-word; }
.em-mid { font-family: Consolas, monospace; font-size: 10px; color: #6b8aa0; word-break: break-all; }
.em-pager { display: flex; gap: 6px; justify-content: space-between; align-items: center; padding-top: 12px; flex-wrap: wrap; }
.em-pager a, .em-pager span.cur { display: inline-flex; min-width: 34px; height: 34px; padding: 0 10px; align-items: center; justify-content: center; border-radius: 9999px; background: #fff; border: 1px solid var(--aq-line); color: var(--aq-ink); font-weight: 800; text-decoration: none; }
.em-pager span.cur { background: linear-gradient(135deg, var(--aq-green-1), var(--aq-green-2)); color: #fff; border: 0; }
.em-banner { padding: 12px 16px; border-radius: 16px; margin-bottom: 14px; font-weight: 600; font-size: 13px; }
.em-banner.ok { background: #d4f6e0; color: #065f46; } .em-banner.bad { background: #fde2e7; color: #9f1239; } .em-banner.info { background: #dbeafe; color: #1e40af; }
#em_overlay { display: none; position: fixed; inset: 0; z-index: 99999; align-items: center; justify-content: center; background: rgba(8,47,60,.5); backdrop-filter: blur(6px); }
#em_overlay .card { padding: 26px 36px; border-radius: 24px; background: #fff; text-align: center; font-weight: 800; color: var(--aq-ink); }
#em_overlay .ring { width: 48px; height: 48px; margin: 0 auto 12px; border-radius: 9999px; border: 5px solid #d3efe8; border-top-color: #10b981; animation: aqSpin .8s linear infinite; }
</style>

<div id="em_overlay"><div class="card"><div class="ring"></div><div id="em_overlay_text">Reading the mailbox...</div></div></div>

<div class="em-wrap">

<?php if (!$schema_ok): ?>
    <div class="em-banner bad">The monitoring columns could not be added to <b>dispatch_logs</b> (the database user needs ALTER permission). Run this once in phpMyAdmin, then refresh:<br>
    <code>ALTER TABLE dispatch_logs ADD COLUMN message_id VARCHAR(190) NULL, ADD COLUMN delivery_status VARCHAR(20) NULL, ADD COLUMN smtp_response VARCHAR(255) NULL, ADD COLUMN bounce_reason VARCHAR(500) NULL, ADD COLUMN bounced_at DATETIME NULL;</code></div>
<?php endif; ?>

    <div class="em-top">
        <div class="em-note">
            <b>Sent</b> only means Google accepted the email. If an address is wrong or the mailbox is full, Google sends a failure notice back to the sending mailbox &mdash;
            press <b>Check bounces</b> to read those notices and update the list.
            <?php if ($last): ?><br>Last check: <b><?php echo htmlspecialchars($last['time']); ?></b> &mdash; <?php echo htmlspecialchars($last['message']); ?><?php else: ?><br>Bounces have not been checked yet.<?php endif; ?>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="em-btn em-btn-soft" onclick="emTest()"><i class="las la-plug"></i> Test mailbox</button>
            <a class="em-btn em-btn-soft" href="<?php echo htmlspecialchars(em_url(array('export' => 1))); ?>"><i class="las la-file-csv"></i> Export CSV</a>
            <button type="button" class="em-btn em-btn-go" onclick="emCheck()"><i class="las la-sync"></i> Check bounces</button>
        </div>
    </div>
    <div id="em_msg"></div>

    <div class="em-cards">
        <?php foreach ($chips as $key => $c): ?>
            <a class="em-stat <?php echo ($st === $key) ? 'on' : ''; ?>" href="<?php echo htmlspecialchars(em_url(array('st' => ($st === $key ? '' : $key), 'page' => 1))); ?>" title="<?php echo htmlspecialchars($c[2]); ?>">
                <div class="n"><?php echo number_format($counts[$key]); ?></div>
                <div class="l"><span class="em-chip <?php echo $c[3]; ?>"><i class="las <?php echo $c[1]; ?>"></i> <?php echo $c[0]; ?></span></div>
                <small><?php echo htmlspecialchars($c[2]); ?></small>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="em-card">
        <form method="GET" class="em-filters">
            <div><label>From</label><input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"></div>
            <div><label>To</label><input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"></div>
            <div style="flex:1; min-width:220px;"><label>Search account, email, mobile or file</label><input type="text" name="q" style="width:100%;" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search..."></div>
            <?php if ($st !== ''): ?><input type="hidden" name="st" value="<?php echo htmlspecialchars($st); ?>"><?php endif; ?>
            <button type="submit" class="em-btn em-btn-go"><i class="las la-search"></i> Apply</button>
            <a class="em-btn em-btn-soft" href="email_monitor.php"><i class="las la-redo"></i> Reset</a>
        </form>
    </div>

    <div class="em-card">
        <div class="em-table-wrap">
            <table class="em-table">
                <thead><tr>
                    <th>Date sent</th><th>Status</th><th>Account</th><th>Recipient</th><th>Telco / Mobile</th><th>Period</th><th>Amount</th><th>Result / reason</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:28px; color:var(--aq-muted); font-weight:600;">No emails found for these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $c = $chips[$r['eff_status']];
                    if ($r['eff_status'] === 'BOUNCED' || $r['eff_status'] === 'DELAYED') $why = $r['bounce_reason'];
                    elseif ($r['eff_status'] === 'REJECTED') $why = $r['details'];
                    else $why = !empty($r['smtp_response']) ? $r['smtp_response'] : '';
                ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo htmlspecialchars($r['dispatch_date']); ?></td>
                        <td><span class="em-chip <?php echo $c[3]; ?>"><i class="las <?php echo $c[1]; ?>"></i> <?php echo $c[0]; ?></span></td>
                        <td style="font-weight:800;"><?php echo htmlspecialchars($r['account_number']); ?></td>
                        <td style="word-break:break-all;"><?php echo htmlspecialchars($r['recipient_email']); ?></td>
                        <td><?php echo htmlspecialchars($r['telco']); ?><br><span class="em-note"><?php echo htmlspecialchars($r['mobile_number']); ?></span></td>
                        <td><?php echo htmlspecialchars($r['billing_period']); ?></td>
                        <td style="text-align:right; white-space:nowrap;">&#8369;<?php echo number_format((float)$r['total_charge'], 2); ?></td>
                        <td class="em-reason"><?php echo htmlspecialchars($why); ?>
                            <?php if (!empty($r['message_id'])): ?><div class="em-mid"><?php echo htmlspecialchars($r['message_id']); ?></div><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="em-pager">
            <div class="em-note">Showing <?php echo number_format(count($rows)); ?> of <?php echo number_format($total_filtered); ?> email(s) &middot; <?php echo number_format($grand); ?> in the selected dates</div>
            <div style="display:flex; gap:6px; align-items:center;">
                <?php if ($page > 1): ?><a href="<?php echo htmlspecialchars(em_url(array('page' => $page - 1))); ?>"><i class="las la-angle-left"></i></a><?php endif; ?>
                <span class="cur"><?php echo $page; ?> / <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?><a href="<?php echo htmlspecialchars(em_url(array('page' => $page + 1))); ?>"><i class="las la-angle-right"></i></a><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function emBanner(ok, text) {
    var d = document.getElementById('em_msg');
    d.innerHTML = '<div class="em-banner ' + (ok ? 'ok' : 'bad') + '"></div>';
    d.firstChild.textContent = text;
}
function emCall(action, label, onDone) {
    document.getElementById('em_overlay_text').textContent = label;
    document.getElementById('em_overlay').style.display = 'flex';
    fetch('email_monitor.php?action=' + action, { method: 'POST' })
        .then(function (r) { if (!r.ok) throw new Error('Server error ' + r.status); return r.json(); })
        .then(function (j) { document.getElementById('em_overlay').style.display = 'none'; onDone(j); })
        .catch(function (e) { document.getElementById('em_overlay').style.display = 'none'; emBanner(false, 'Request failed: ' + e.message); });
}
function emCheck() {
    emCall('check_bounces', 'Reading the mailbox for bounce notices...', function (j) {
        emBanner(j.ok, j.message);
        if (j.ok) setTimeout(function () { window.location.reload(); }, 1500);
    });
}
function emTest() { emCall('test_connection', 'Testing the mailbox login...', function (j) { emBanner(j.ok, j.message); }); }
</script>
<?php
$content = ob_get_clean();
render_layout("Email Monitor", $content);
?>
