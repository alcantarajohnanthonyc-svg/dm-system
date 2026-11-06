<?php
/**
 * mail_settings.php  -  ONE place for the Gmail login and the email-monitoring settings.
 * Used by send_bulk_email.php (sending) and email_monitor_lib.php (bounce checking).
 *
 * SECURITY: this file contains the Gmail app password. Keep it out of backups you share and never paste it in chats.
 *           If it was ever shared, create a NEW app password in the Google account and put it here (one place only).
 */

// ---- Gmail sending login (app password, spaces are ignored) ----
if (!defined('SMTP_USER')) define('SMTP_USER', 'itnotifications@bounty.com.ph');
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'qvnkodfrgubqidac');

// ---- Bounce checking: where the "Delivery Status Notification" emails arrive ----
// They arrive in the mailbox of the address that SENT the email (the login above, unless "telcoreports@..." is a separate mailbox:
// in that case put that mailbox's login in BOUNCE_IMAP_USER / BOUNCE_IMAP_PASS).
// Gmail must have IMAP enabled for that mailbox (Google Workspace admin: Apps > Google Workspace > Gmail > End User Access > Enable IMAP).
if (!defined('BOUNCE_IMAP_HOST'))       define('BOUNCE_IMAP_HOST', 'ssl://imap.gmail.com');
if (!defined('BOUNCE_IMAP_PORT'))       define('BOUNCE_IMAP_PORT', 993);
if (!defined('BOUNCE_IMAP_USER'))       define('BOUNCE_IMAP_USER', SMTP_USER);
if (!defined('BOUNCE_IMAP_PASS'))       define('BOUNCE_IMAP_PASS', SMTP_PASS);
if (!defined('BOUNCE_IMAP_VERIFY_TLS')) define('BOUNCE_IMAP_VERIFY_TLS', true);   // set to false ONLY if PHP on this PC has no CA certificates

// ---- Monitoring behaviour ----
if (!defined('MAIL_MESSAGE_ID_DOMAIN')) define('MAIL_MESSAGE_ID_DOMAIN', 'bounty.com.ph');  // used in each email's Message-ID
if (!defined('DELIVERED_AFTER_HOURS'))  define('DELIVERED_AFTER_HOURS', 48);   // accepted by Google + no bounce for this long = shown as "Delivered"
if (!defined('BOUNCE_LOOKBACK_DAYS'))   define('BOUNCE_LOOKBACK_DAYS', 7);      // how far back "Check bounces" reads the inbox
