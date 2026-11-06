<?php
/**
 * email_monitor_lib.php  -  email delivery monitoring
 *
 * 1. emon_ensure_schema()    adds the monitoring columns to `dispatch_logs` (once, automatically)
 * 2. emon_check_bounces()    reads the Gmail inbox, finds "Delivery Status Notification" emails and marks the matching log rows
 * 3. emon_parse_dsn()        understands a bounce / delay notice
 *
 * What the statuses mean
 *   ACCEPTED  Google accepted the email for delivery (SMTP "250 OK")
 *   BOUNCED   a failure notice came back (wrong address, mailbox full, blocked ...)
 *   DELAYED   Google/the receiver could not deliver yet and is still retrying
 *   REJECTED  the email was never accepted (no email address, SMTP error ...)
 */
if (is_file(__DIR__ . '/mail_settings.php')) require_once __DIR__ . '/mail_settings.php';

// ---------------------------------------------------------------------------
// small state helpers (last check time, already-read notices)
// ---------------------------------------------------------------------------
function emon_state_get($name, $default = null) {
    $f = sys_get_temp_dir() . '/emon_' . $name . '.json';
    if (!is_file($f)) return $default;
    $j = json_decode((string)@file_get_contents($f), true);
    return ($j === null) ? $default : $j;
}
function emon_state_set($name, $value) {
    @file_put_contents(sys_get_temp_dir() . '/emon_' . $name . '.json', json_encode($value));
}

// ---------------------------------------------------------------------------
// 1. database columns
// ---------------------------------------------------------------------------
function emon_has_column($pdo, $col) {
    $st = $pdo->query("SHOW COLUMNS FROM dispatch_logs LIKE '" . $col . "'");
    return ($st && $st->fetch()) ? true : false;
}

function emon_ensure_schema($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if (!emon_has_column($pdo, 'delivery_status')) {
            $r = $pdo->exec("ALTER TABLE dispatch_logs
                ADD COLUMN message_id VARCHAR(190) NULL,
                ADD COLUMN delivery_status VARCHAR(20) NULL,
                ADD COLUMN smtp_response VARCHAR(255) NULL,
                ADD COLUMN bounce_reason VARCHAR(500) NULL,
                ADD COLUMN bounced_at DATETIME NULL");
            if ($r === false) throw new Exception('ALTER TABLE failed');
            foreach (array(
                "ALTER TABLE dispatch_logs ADD INDEX idx_dl_message_id (message_id)",
                "ALTER TABLE dispatch_logs ADD INDEX idx_dl_delivery_status (delivery_status)"
            ) as $sql) {
                try { $pdo->exec($sql); } catch (Exception $e) { /* indexes are optional */ }
            }
        }
        $ok = emon_has_column($pdo, 'delivery_status');
    } catch (Exception $e) {
        error_log('emon_ensure_schema: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

// ---------------------------------------------------------------------------
// 2. minimal IMAP client (plain sockets, so no php_imap extension is needed)
// ---------------------------------------------------------------------------
class EmonImap {
    private $s = null;
    private $tag = 0;
    public $uidvalidity = '';

    public function connect($host, $port, $timeout = 20) {
        if (preg_match('#^(ssl|tcp|tls)://#', $host)) {
            $ctx = stream_context_create(array('ssl' => array(
                'verify_peer'      => (defined('BOUNCE_IMAP_VERIFY_TLS') ? (bool)BOUNCE_IMAP_VERIFY_TLS : true),
                'verify_peer_name' => (defined('BOUNCE_IMAP_VERIFY_TLS') ? (bool)BOUNCE_IMAP_VERIFY_TLS : true),
            )));
            $this->s = @stream_socket_client($host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $this->s = @fopen($host, 'r+');          // used by the automated tests (fake server)
            $errstr = 'cannot open ' . $host;
        }
        if (!is_resource($this->s)) throw new Exception('Cannot connect to the mail server (' . $errstr . ')');
        stream_set_timeout($this->s, 40);
        $greet = (string)fgets($this->s, 4096);
        if (stripos($greet, 'OK') === false) throw new Exception('Mail server did not accept the connection: ' . trim($greet));
    }

    private function cmd($cmd) {
        $t = 'A' . (++$this->tag);
        fwrite($this->s, $t . ' ' . $cmd . "\r\n");
        $text = ''; $lits = array(); $status = 'BAD'; $tail = '';
        while (($line = fgets($this->s, 8192)) !== false) {
            if (strncmp($line, $t . ' ', strlen($t) + 1) === 0) {
                if (preg_match('/^' . $t . ' (OK|NO|BAD)\b(.*)$/s', $line, $m)) { $status = $m[1]; $tail = trim($m[2]); }
                break;
            }
            $text .= $line;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {          // a literal follows: read exactly N bytes
                $n = (int)$m[1]; $data = '';
                while (strlen($data) < $n) {
                    $chunk = fread($this->s, $n - strlen($data));
                    if ($chunk === false || $chunk === '') break;
                    $data .= $chunk;
                }
                $lits[] = $data;
            }
        }
        return array('status' => $status, 'text' => $text, 'tail' => $tail, 'literals' => $lits);
    }

    public function login($user, $pass) {
        $q = function ($v) { return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $v) . '"'; };
        $r = $this->cmd('LOGIN ' . $q($user) . ' ' . $q(str_replace(' ', '', $pass)));
        if ($r['status'] !== 'OK') throw new Exception('Mail login failed: ' . ($r['tail'] !== '' ? $r['tail'] : 'check the user / app password and that IMAP is enabled'));
    }

    public function select($box = 'INBOX') {
        $r = $this->cmd('SELECT "' . $box . '"');
        if ($r['status'] !== 'OK') throw new Exception('Cannot open the mailbox: ' . $r['tail']);
        if (preg_match('/UIDVALIDITY (\d+)/', $r['text'], $m)) $this->uidvalidity = $m[1];
        $exists = preg_match('/\*\s+(\d+)\s+EXISTS/i', $r['text'], $m) ? (int)$m[1] : 0;
        return $exists;
    }

    public function search($criteria) {
        $r = $this->cmd('UID SEARCH ' . $criteria);
        if ($r['status'] !== 'OK') throw new Exception('Mailbox search failed: ' . $r['tail']);
        $ids = array();
        if (preg_match_all('/^\* SEARCH(.*)$/m', $r['text'], $mm)) {
            foreach ($mm[1] as $part) { if (preg_match_all('/\d+/', $part, $n)) foreach ($n[0] as $x) $ids[] = (int)$x; }
        }
        sort($ids);
        return $ids;
    }

    public function fetch($uid, $maxBytes = 120000) {
        $r = $this->cmd('UID FETCH ' . (int)$uid . ' (BODY.PEEK[]<0.' . (int)$maxBytes . '>)');
        if ($r['status'] !== 'OK' || empty($r['literals'])) return '';
        return $r['literals'][0];
    }

    public function logout() {
        if (is_resource($this->s)) { @fwrite($this->s, 'A' . (++$this->tag) . " LOGOUT\r\n"); @fclose($this->s); }
    }
}

// ---------------------------------------------------------------------------
// 3. understand a bounce / delay notice (RFC 3464 "delivery status notification")
// ---------------------------------------------------------------------------
function emon_trim_text($s, $max) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return (strlen($s) > $max) ? substr($s, 0, $max - 3) . '...' : $s;
}

function emon_parse_dsn($raw) {
    $text   = str_replace("\r\n", "\n", (string)$raw);
    $unfold = preg_replace("/\n[ \t]+/", ' ', $text);     // join folded header lines
    $out = array('kind' => 'ignore', 'recipient' => '', 'status' => '', 'diagnostic' => '', 'message_id' => '',
                 'ref' => '', 'subject' => '', 'candidates' => array());

    if (preg_match('/^Subject:\s*(.+)$/mi', $unfold, $m)) $out['subject'] = trim($m[1]);

    $action = '';
    if (preg_match('/^Action:\s*([A-Za-z]+)/mi', $unfold, $m)) $action = strtolower($m[1]);
    if (preg_match('/^Final-Recipient:\s*[A-Za-z0-9\-]+;\s*<?([^\s>]+)/mi', $unfold, $m))         $out['recipient'] = strtolower($m[1]);
    elseif (preg_match('/^Original-Recipient:\s*[A-Za-z0-9\-]+;\s*<?([^\s>]+)/mi', $unfold, $m))   $out['recipient'] = strtolower($m[1]);
    if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $unfold, $m)) $out['status'] = $m[1];
    if (preg_match('/^Diagnostic-Code:\s*(?:[A-Za-z0-9\-]+;)?\s*(.+)$/mi', $unfold, $m)) $out['diagnostic'] = trim($m[1]);

    // the ORIGINAL message (our email) is quoted after this marker - its Message-ID / X-DM-Ref tell us which email it was
    $orig = '';
    if (preg_match('#Content-Type:\s*(?:message/rfc822|text/rfc822-headers)#i', $unfold, $m, PREG_OFFSET_CAPTURE)) {
        $orig = substr($unfold, $m[0][1]);
    }
    $search_in = ($orig !== '') ? $orig : $unfold;
    if (preg_match('/^Message-ID:\s*<([^>]+)>/mi', $search_in, $m) && preg_match('/^dm\./i', $m[1])) $out['message_id'] = trim($m[1]);
    if (preg_match('/^X-DM-Ref:\s*(\S+)/mi', $search_in, $m)) $out['ref'] = trim($m[1]);
    if ($out['message_id'] === '' && preg_match('/\b(dm\.\d{14}\.[a-f0-9]{8}@[A-Za-z0-9.\-]+)/i', $unfold, $m)) $out['message_id'] = $m[1];

    // classify
    $first = ($out['status'] !== '') ? $out['status'][0] : '';
    if ($action === 'failed' || $first === '5') {
        $out['kind'] = 'bounce';
    } elseif ($action === 'delayed' || $first === '4') {
        $out['kind'] = 'delay';
    } elseif (preg_match('/delivery status notification \(failure\)|undeliver|mail delivery failed|returned mail|failure notice|delivery failure|could not be delivered|not delivered/i', $out['subject'])) {
        $out['kind'] = 'bounce';
    } elseif (preg_match('/\(delay\)|delayed|delivery incomplete/i', $out['subject'])) {
        $out['kind'] = 'delay';
    }

    // plain-text bounces without a machine-readable part: collect addresses mentioned in the body
    if ($out['kind'] !== 'ignore' && $out['recipient'] === '') {
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $mm)) {
            foreach ($mm[0] as $a) {
                $a = strtolower($a);
                if (preg_match('/^(mailer-daemon|postmaster)@/', $a)) continue;
                if (!in_array($a, $out['candidates'], true)) $out['candidates'][] = $a;
            }
        }
    }
    return $out;
}

function emon_reason($p) {
    $parts = array();
    if ($p['status'] !== '') $parts[] = $p['status'];
    if ($p['diagnostic'] !== '') $parts[] = $p['diagnostic'];
    if (!$parts && $p['subject'] !== '') $parts[] = $p['subject'];
    return emon_trim_text(implode(' - ', $parts), 480);
}

// ---------------------------------------------------------------------------
// match a notice to the log row and update it. returns: bounced | delayed | already | unmatched | ignored
// ---------------------------------------------------------------------------
function emon_apply($pdo, $p) {
    if ($p['kind'] === 'ignore') return 'ignored';
    $new    = ($p['kind'] === 'bounce') ? 'BOUNCED' : 'DELAYED';
    $reason = emon_reason($p);

    $mid = $p['message_id'];
    if ($mid === '' && $p['ref'] !== '') {
        $st = $pdo->prepare("SELECT message_id FROM dispatch_logs WHERE message_id LIKE ? ORDER BY dispatch_date DESC LIMIT 1");
        $st->execute(array('%.' . $p['ref'] . '@%'));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $mid = $r['message_id'];
    }

    if ($mid !== '') {
        $st = $pdo->prepare("SELECT delivery_status FROM dispatch_logs WHERE message_id = ? LIMIT 1");
        $st->execute(array($mid));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cur = (string)$row['delivery_status'];
            if ($cur === 'BOUNCED' || ($new === 'DELAYED' && $cur === 'DELAYED')) return 'already';
            $up = $pdo->prepare("UPDATE dispatch_logs SET delivery_status = ?, bounce_reason = ?, bounced_at = NOW() WHERE message_id = ?");
            $up->execute(array($new, $reason, $mid));
            return ($new === 'BOUNCED') ? 'bounced' : 'delayed';
        }
    }

    // fallback: newest still-open email sent to that address in the last 14 days
    $addrs = array();
    if ($p['recipient'] !== '') $addrs[] = $p['recipient'];
    foreach ($p['candidates'] as $c) $addrs[] = $c;
    foreach ($addrs as $a) {
        $st = $pdo->prepare("SELECT message_id, delivery_status FROM dispatch_logs
                             WHERE recipient_email LIKE ? AND (delivery_status IN ('ACCEPTED','DELAYED') OR delivery_status IS NULL)
                               AND dispatch_date >= (NOW() - INTERVAL 14 DAY)
                             ORDER BY dispatch_date DESC LIMIT 1");
        $st->execute(array('%' . $a . '%'));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        if ($row['message_id']) {
            $up = $pdo->prepare("UPDATE dispatch_logs SET delivery_status = ?, bounce_reason = ?, bounced_at = NOW() WHERE message_id = ?");
            $up->execute(array($new, $reason, $row['message_id']));
        } else {
            $up = $pdo->prepare("UPDATE dispatch_logs SET delivery_status = ?, bounce_reason = ?, bounced_at = NOW()
                                 WHERE recipient_email LIKE ? AND message_id IS NULL AND dispatch_date >= (NOW() - INTERVAL 14 DAY)
                                 ORDER BY dispatch_date DESC LIMIT 1");
            $up->execute(array($new, $reason, '%' . $a . '%'));
        }
        return ($new === 'BOUNCED') ? 'bounced' : 'delayed';
    }
    return 'unmatched';
}

// ---------------------------------------------------------------------------
// read the inbox and update everything
// ---------------------------------------------------------------------------
function emon_connect_mailbox() {
    if (!defined('BOUNCE_IMAP_USER') || BOUNCE_IMAP_USER === '' || !defined('BOUNCE_IMAP_PASS') || BOUNCE_IMAP_PASS === '') {
        throw new Exception('Mailbox login is not set. Open mail_settings.php and fill in SMTP_USER / SMTP_PASS.');
    }
    $imap = new EmonImap();
    $imap->connect(BOUNCE_IMAP_HOST, BOUNCE_IMAP_PORT);
    $imap->login(BOUNCE_IMAP_USER, BOUNCE_IMAP_PASS);
    return $imap;
}

function emon_test_connection() {
    try {
        $imap = emon_connect_mailbox();
        $n = $imap->select('INBOX');
        $imap->logout();
        return array('ok' => true, 'message' => 'Connected to ' . BOUNCE_IMAP_USER . '. The inbox has ' . $n . ' message(s).');
    } catch (Exception $e) {
        return array('ok' => false, 'message' => $e->getMessage());
    }
}

function emon_check_bounces($pdo, $days = null, $maxMessages = 300) {
    $res = array('ok' => false, 'checked' => 0, 'skipped' => 0, 'bounced' => 0, 'delayed' => 0, 'already' => 0, 'unmatched' => 0, 'ignored' => 0, 'message' => '');
    if (!emon_ensure_schema($pdo)) {
        $res['message'] = 'Could not add the monitoring columns to dispatch_logs (the database user needs ALTER permission).';
        return $res;
    }
    $days = ($days !== null) ? (int)$days : (defined('BOUNCE_LOOKBACK_DAYS') ? (int)BOUNCE_LOOKBACK_DAYS : 7);
    if ($days < 1) $days = 1;
    try {
        $imap = emon_connect_mailbox();
        $imap->select('INBOX');
        $uids = $imap->search('SINCE ' . date('j-M-Y', strtotime('-' . $days . ' days')) . ' OR FROM "mailer-daemon" FROM "postmaster"');
        $uids = array_slice($uids, -$maxMessages);

        $seen = emon_state_get('seen', array());
        if (!is_array($seen)) $seen = array();
        $seen_map = array_flip($seen);

        foreach ($uids as $uid) {
            $key = $imap->uidvalidity . ':' . $uid;
            if (isset($seen_map[$key])) { $res['skipped']++; continue; }
            $raw = $imap->fetch($uid);
            $res['checked']++;
            $outcome = emon_apply($pdo, emon_parse_dsn($raw));
            if (isset($res[$outcome])) $res[$outcome]++;
            $seen[] = $key;
        }
        $imap->logout();
        emon_state_set('seen', array_slice(array_values(array_unique($seen)), -3000));

        $res['ok'] = true;
        $res['message'] = 'Read ' . $res['checked'] . ' new notice(s): ' . $res['bounced'] . ' bounced, ' . $res['delayed'] . ' delayed, '
                        . ($res['unmatched'] + $res['ignored']) . ' not related to your dispatch emails'
                        . ($res['skipped'] ? ' (' . $res['skipped'] . ' already checked before).' : '.');
    } catch (Exception $e) {
        $res['message'] = $e->getMessage();
    }
    emon_state_set('last_check', array('time' => date('Y-m-d H:i:s'), 'ok' => $res['ok'], 'message' => $res['message']));
    return $res;
}
?>
