<?php
/**
 * cron_check_bounces.php  -  checks the mailbox for bounce notices WITHOUT opening the website.
 *
 * Windows Task Scheduler (every 30 minutes):
 *   Program : C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\admin_telco_dm\cron_check_bounces.php
 * Linux cron:  *\/30 * * * * php /var/www/admin_telco_dm/cron_check_bounces.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line / task scheduler only.'); }
chdir(__DIR__);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_settings.php';
require_once __DIR__ . '/email_monitor_lib.php';

if (!isset($conn)) { fwrite(STDERR, "No database connection in config.php\n"); exit(1); }
$r = emon_check_bounces($conn);
echo date('Y-m-d H:i:s') . ' - ' . $r['message'] . "\n";
exit($r['ok'] ? 0 : 1);
