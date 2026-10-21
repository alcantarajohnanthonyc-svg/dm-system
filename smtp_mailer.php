<?php
/**
 * smtp_mailer.php
 *
 * SMTP sender para sa reports.php (BU Admin & President emails).
 * Kapareho ng settings ng send_bulk_email.php (Gmail SMTP, STARTTLS), kaya pareho ang
 * nagpapadala ng email sa list_dm at sa report. Mas malinaw ang error message kapag pumalya.
 *
 * NOTE: nandito ang SMTP login (kopya mula sa send_bulk_email.php). Huwag i-share ang file na ito.
 *
 * rp_smtp_send(array $to, $subject, $html, array $attachments = array(), array $cc = array())
 *   $attachments = array of array('path' => ..., 'name' => ..., 'type' => (optional))
 *   returns array(true|false, message)
 */
if (!function_exists('rp_smtp_send')) {
function rp_smtp_send(array $to, $subject, $html, array $attachments = array(), array $cc = array()) {
    $smtp_host  = 'tcp://smtp.gmail.com';
    $smtp_port  = 587;
    $auth_user  = 'itnotifications@bounty.com.ph';
    $auth_pass  = str_replace(' ', '', 'qvnk odfr gubq idac');
    $from_email = 'telcoreports@bounty.com.ph';
    $from_name  = 'Admin Telco';

    if (empty($to)) return array(false, 'No recipient');

    $boundary = 'rcp_' . md5(uniqid('', true));
    $host_name = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '' ? $_SERVER['SERVER_NAME'] : 'localhost';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_name} <{$from_email}>\r\n";
    $headers .= "To: " . implode(', ', $to) . "\r\n";
    if (!empty($cc)) $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode((string)$subject) . "?=\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode((string)$html)) . "\r\n";

    foreach ($attachments as $att) {
        if (empty($att['path']) || !is_file($att['path']) || filesize($att['path']) < 10) continue;
        $name = isset($att['name']) && $att['name'] !== '' ? str_replace(array('"', "\r", "\n"), '', $att['name']) : basename($att['path']);
        $type = !empty($att['type']) ? $att['type'] : (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : 'application/octet-stream');
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$type}; name=\"{$name}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
        $body .= chunk_split(base64_encode(file_get_contents($att['path']))) . "\r\n";
    }
    $body .= "--{$boundary}--";

    $errno = 0; $errstr = '';
    $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 20);
    if (!is_resource($socket)) return array(false, 'Could not connect to the mail server: ' . $errstr);
    stream_set_timeout($socket, 60);

    $read = function () use ($socket) {
        $res = '';
        while (($line = fgets($socket, 515)) !== false) {
            $res .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $res;
    };
    $cmd = function ($c, $ok) use ($socket, $read) {
        if ($c !== null) fwrite($socket, $c . "\r\n");
        $r = $read();
        return array(substr($r, 0, 3) === (string)$ok, trim($r));
    };

    $read(); // greeting

    list($ok, $r) = $cmd("EHLO " . $host_name, 250);
    if (!$ok) { fclose($socket); return array(false, 'EHLO failed: ' . $r); }
    list($ok, $r) = $cmd("STARTTLS", 220);
    if (!$ok) { fclose($socket); return array(false, 'STARTTLS failed: ' . $r); }
    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return array(false, 'TLS handshake failed (check the PHP openssl extension)');
    }
    list($ok, $r) = $cmd("EHLO " . $host_name, 250);
    if (!$ok) { fclose($socket); return array(false, 'EHLO (TLS) failed: ' . $r); }

    list($ok, $r) = $cmd("AUTH LOGIN", 334);
    if (!$ok) { fclose($socket); return array(false, 'AUTH failed: ' . $r); }
    list($ok, $r) = $cmd(base64_encode($auth_user), 334);
    if (!$ok) { fclose($socket); return array(false, 'SMTP username rejected: ' . $r); }
    list($ok, $r) = $cmd(base64_encode($auth_pass), 235);
    if (!$ok) { fclose($socket); return array(false, 'SMTP login rejected: ' . $r); }

    list($ok, $r) = $cmd("MAIL FROM: <{$from_email}>", 250);
    if (!$ok) { fclose($socket); return array(false, 'MAIL FROM rejected: ' . $r); }

    $accepted = 0;
    $refused = array();
    foreach (array_merge($to, $cc) as $rcpt) {
        $rcpt = trim($rcpt);
        if ($rcpt === '') continue;
        list($ok, $r) = $cmd("RCPT TO: <{$rcpt}>", 250);
        if ($ok) { $accepted++; } else { $refused[] = $rcpt; }
    }
    if ($accepted === 0) { fclose($socket); return array(false, 'All recipients were refused: ' . implode(', ', $refused)); }

    list($ok, $r) = $cmd("DATA", 354);
    if (!$ok) { fclose($socket); return array(false, 'DATA failed: ' . $r); }

    fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
    $result = trim($read());
    $cmd("QUIT", 221);
    fclose($socket);

    if (substr($result, 0, 3) !== '250') return array(false, 'Server did not accept the message: ' . $result);
    return array(true, 'Sent' . (empty($refused) ? '' : ' (refused: ' . implode(', ', $refused) . ')'));
}
}
