<?php
// debit_memo_upload.php - Fast Upload & Mirror Sync Module (v2)
// - Upload step is LOCAL ONLY (very fast). Google Drive work happens once, during Sync.
// - Sync processes small batches in parallel, caches Drive folder IDs on disk, reads only the first PDF pages.
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
@error_reporting(E_ALL);

// NOTE: upload_max_filesize / post_max_size / max_file_uploads cannot be changed at runtime.
// Set them in php.ini or .user.ini (e.g. upload_max_filesize=128M, post_max_size=256M, max_file_uploads=50).
@ini_set('max_execution_time', '1200');
@ini_set('max_input_time', '1200');
@ini_set('memory_limit', '1024M');

if (!defined('ALLOW_ACCESS')) {
    define('ALLOW_ACCESS', true);
}

// Set to true on a server with a proper CA bundle (recommended in production).
const DM_SSL_VERIFY = false;

// 1. Database Connection Setup
$config_file = __DIR__ . '/config.php';
$main_file   = __DIR__ . '/main.php';

if (file_exists($config_file)) require_once $config_file;
if (file_exists($main_file))   require_once $main_file;

$db_connection = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $db_connection = $pdo;
} elseif (isset($conn) && $conn instanceof PDO) {
    $db_connection = $conn;
} elseif (isset($db) && $db instanceof PDO) {
    $db_connection = $db;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Root Shared Drive Uploads Folder ID
$google_drive_folder_id = '1nB2cAYdR4LVLTBcbop2nldVU6pcXTD_a';

// In-memory cache (per request). A disk cache below makes it persistent across requests.
$gdrive_folder_cache = [];

// -------------------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------------------
function dm_json($data) {
    $flags = (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0) | JSON_PARTIAL_OUTPUT_ON_ERROR;
    return json_encode($data, $flags);
}

// Start of every AJAX endpoint: clean output, release the session lock (so parallel requests
// really run in parallel), and always answer with JSON - even on a fatal error.
function dm_json_start() {
    while (ob_get_level()) { @ob_end_clean(); }
    @ini_set('display_errors', '0');
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo dm_json(['status' => 'error', 'message' => 'Server error: ' . $e['message']]);
        }
    });
}

function dm_short_error($e) {
    $m = preg_replace('/\s+/', ' ', $e->getMessage());
    return strlen($m) > 160 ? substr($m, 0, 160) . '...' : $m;
}

function dm_upload_error($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'File exceeds the server upload size limit';
        case UPLOAD_ERR_PARTIAL:   return 'File was only partially uploaded';
        case UPLOAD_ERR_NO_FILE:   return 'No file received';
        case UPLOAD_ERR_NO_TMP_DIR:return 'Server temp folder missing';
        case UPLOAD_ERR_CANT_WRITE:return 'Server could not write the file';
        default:                   return "Upload error code $code";
    }
}

if (!function_exists('detect_telco')) {
    function detect_telco($filename, $text = '') {
        $upper = strtoupper($filename . ' ' . $text);
        if (strpos($upper, 'SMT') !== false || strpos($upper, 'SMART') !== false) return 'Smart';
        if (strpos($upper, 'ACC_') !== false || strpos($upper, 'GLOBE') !== false) return 'Globe';
        return 'Unknown';
    }
}

if (!function_exists('parse_flexible_date')) {
    function parse_flexible_date($date_str) {
        $date_str = trim($date_str);
        $dt = DateTime::createFromFormat('M d, Y', $date_str);
        if ($dt) return $dt->format('Y-m-d');

        $dt = DateTime::createFromFormat('m/d/y', $date_str);
        if ($dt) return $dt->format('Y-m-d');

        $dt = DateTime::createFromFormat('m/d/Y', $date_str);
        if ($dt) return $dt->format('Y-m-d');

        return 'N/A';
    }
}

// Full extraction rules (same as your latest version), run on partial text first for speed.
if (!function_exists('parse_statement_fields')) {
    function parse_statement_fields($text, $page1_text, $filename) {
        $result = [
            'account_number' => 'N/A',
            'mobile_number'  => 'N/A',
            'telco'          => detect_telco($filename, $text),
            'amount_due'     => '0.00',
            'billing_period' => 'N/A',
            'invoice_date'   => 'N/A',
            'due_date'       => 'N/A'
        ];

        $clean_text = preg_replace('/\s+/', ' ', $text);
        $clean_page1 = preg_replace('/\s+/', ' ', $page1_text);

        // 1. Extract Account Number
        if (preg_match('/Account\s*Number\s*[:|]?\s*(\d{8,12})/i', $text, $m)) {
            $result['account_number'] = trim($m[1]);
        } elseif (preg_match('/(\d{10})/i', $filename, $m)) {
            $result['account_number'] = trim($m[1]);
        }

        // 2. Extract Mobile Number
        $result['mobile_number'] = 'N/A';
        $phone_pattern = '/(?:Primary\s*Number|Mobile\s*Number|Mobile\s*No\.?)\D*?(\+?(?:63|0)?9\d{9}|9\d{9})/is';

        if (preg_match($phone_pattern, $text, $m)) {
            $result['mobile_number'] = trim(preg_replace('/[^\d\+]+/', '', $m[1]));
        } elseif (preg_match($phone_pattern, $clean_text, $m)) {
            $result['mobile_number'] = trim(preg_replace('/[^\d\+]+/', '', $m[1]));
        } else {
            $fallback_patterns = [
                '/Mobile\s*Number\D*?[\|\:]\D*?(\+?(?:63|0)?9\d{9}|9\d{9})/is',
                '/Mobile\s*Number\s*[\|\:]\s*[\s\|]*(\+?(?:63|0)?9\d{9}|9\d{9})/is',
                '/Mobile\s*Number\D{1,30}(\+?(?:63|0)?9\d{9}|9\d{9})/is',
                '/Mobile\s*Number\s*[:|]?\s*([0-9\s]{10,})/is'
            ];

            foreach ($fallback_patterns as $fb_pattern) {
                if (preg_match($fb_pattern, $text, $m) || preg_match($fb_pattern, $clean_text, $m)) {
                    $cleaned_phone = trim(preg_replace('/[^\d\+]+/', '', $m[1]));
                    if (strlen($cleaned_phone) >= 10) {
                        $result['mobile_number'] = $cleaned_phone;
                        break;
                    }
                }
            }
        }

        // 3. Extract Amount Due
        $raw_val = null;
        if (preg_match('/(?:TOTAL\s+AMOUNT\s+DUE|Amount\s+to\s+Pay)\s*(?:\(total\s+amount\s+due\))?\s*([A-Z]{3}|Php|P)?\s*([\d,\.\(\)]+)\s*(CR)?/i', $text, $m)) {
            $raw_val = trim($m[2]);
        } else {
            if (preg_match('/TOTAL\s+AMOUNT\s+DUE\s*[:|]?\s*(?:[A-Z]{3}|Php|P|₱)?\s*([\d,\.\(\)]+)\s*(CR)?/i', $text, $m)) {
                $raw_val = trim($m[1]);
            } else {
                if (preg_match('/TOTAL\s+AMOUNT\s+DUE\s*[:|]?\s*[\s\|]*([₱P]?)\s*([\d,\.\(\)]+)/is', $text, $m)) {
                    $raw_val = trim($m[2]);
                } elseif (preg_match('/TOTAL\s+AMOUNT\s+DUE\D*?([₱P]?\s*[\d,\.]+)/is', $text, $m)) {
                    $raw_val = trim($m[1]);
                }
            }
        }

        if ($raw_val !== null) {
            $raw_val = str_replace(['Php', 'P', '₱', ' '], '', $raw_val);
            if (strpos($raw_val, '(') !== false) {
                $raw_val = '-' . str_replace(['(', ')', ','], '', $raw_val);
            } else {
                $raw_val = str_replace(',', '', $raw_val);
            }
            if (is_numeric($raw_val)) {
                $result['amount_due'] = number_format((float)$raw_val, 2, '.', '');
            }
        }

        // 4. Telco-Specific Parsing
        if ($result['telco'] === 'Smart') {
            if (preg_match('/Invoice\s*Date\s*[:|]?\s*([A-Za-z]{3}\s+\d{1,2},\s+\d{4}|\d{2}\/\d{2}\/\d{2})/i', $text, $m)) {
                $result['invoice_date'] = parse_flexible_date($m[1]);
            }
            if (preg_match('/DUE\s*DATE\s*:\s*AMOUNT\s*DUE\s*:.*?([A-Za-z]{3}\s+\d{1,2},\s+\d{4})/is', $clean_text, $m)) {
                $result['due_date'] = parse_flexible_date($m[1]);
            }
            if (preg_match('/Billing\s*Period(?:\s*Covering)?\s*[:|]?\s*([A-Za-z]{3}\s+\d{1,2},\s+\d{4}|\d{2}\/\d{2}\/\d{2})\s*(?:to|\-)?\s*([A-Za-z]{3}\s+\d{1,2},\s+\d{4}|\d{2}\/\d{2}\/\d{2})/i', $text, $m)) {
                $result['billing_period'] = parse_flexible_date($m[1]) . ' - ' . parse_flexible_date($m[2]);
            }
        } elseif ($result['telco'] === 'Globe') {
            if (preg_match('/Billing\s*Period[^\d]*(\d{2}\/\d{2}\/\d{2})\s*(?:to|\-)\s*(\d{2}\/\d{2}\/\d{2})/i', $clean_page1, $m)) {
                $result['billing_period'] = parse_flexible_date($m[1]) . ' - ' . parse_flexible_date($m[2]);
            }
            if (preg_match('/Invoice\s*Date\b.*?(\d{3}\-\d{3}\-\d{3}\-\d{5})\D+(\d{2}\/\d{2}\/\d{2})/i', $clean_page1, $m)) {
                $result['invoice_date'] = parse_flexible_date($m[2]);
            }
            if (preg_match('/Due\s*Date\D{0,40}(?:(?:\d{2}\/\d{2}\/\d{2}\s*to\s*\d{2}\/\d{2}\/\d{2})\s*)?(\d{2}\/\d{2}\/\d{2})/i', $clean_page1, $m)) {
                $result['due_date'] = parse_flexible_date($m[1]);
            }
        }

        return $result;
    }
}

// A result is "complete" when all key fields (including due date) were found on the first pages.
// If not, the extractor re-reads the WHOLE PDF, so accuracy is the same as your original logic.
if (!function_exists('statement_fields_complete')) {
    function statement_fields_complete($r) {
        return $r['account_number'] !== 'N/A' && $r['telco'] !== 'Unknown'
            && $r['billing_period'] !== 'N/A' && $r['amount_due'] !== '0.00'
            && $r['invoice_date'] !== 'N/A' && $r['due_date'] !== 'N/A';
    }
}

// SPEED: read only the first 2 pages first (statement summary lives there).
// Only if a key field is missing do we fall back to parsing the whole PDF.
if (!function_exists('extract_pdf_statement_details')) {
    function extract_pdf_statement_details($filepath, $filename) {
        if (class_exists('Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf    = $parser->parseFile($filepath);
                $pages  = $pdf->getPages();

                $first_text = '';
                $page1_text = '';
                foreach (array_slice($pages, 0, 2) as $idx => $p) {
                    $t = $p->getText();
                    if ($idx === 0) $page1_text = $t;
                    $first_text .= $t . "\n";
                }

                $result = parse_statement_fields($first_text, $page1_text, $filename);
                if (!statement_fields_complete($result)) {
                    $result = parse_statement_fields($pdf->getText(), $page1_text, $filename);
                }
                unset($pdf, $parser, $pages);
                return $result;
            } catch (Throwable $e) {
                // fall through to raw read
            }
        }
        $raw = @file_get_contents($filepath);
        return parse_statement_fields((string)$raw, (string)$raw, $filename);
    }
}

// -------------------------------------------------------------------------
// GOOGLE DRIVE HELPERS (client reused per request, folder IDs cached on disk)
// -------------------------------------------------------------------------
function dm_drive_client() {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    $cached = false;
    try {
        $credentials_path = __DIR__ . '/credentials.json';
        if (class_exists('Google_Client') && file_exists($credentials_path)) {
            $client = new Google_Client();
            $client->setAuthConfig($credentials_path);
            $client->addScope(Google_Service_Drive::DRIVE);
            if (class_exists('GuzzleHttp\Client')) {
                $client->setHttpClient(new GuzzleHttp\Client([
                    'verify' => DM_SSL_VERIFY, 'connect_timeout' => 10, 'timeout' => 120
                ]));
            }
            $cached = ['client' => $client, 'drive' => new Google_Service_Drive($client)];
        }
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached ?: null;
}

function dm_cache_path() {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dm_drive_folders_' . md5(__DIR__) . '.json';
}
function dm_cache_read() {
    $f = dm_cache_path();
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function dm_cache_write($data) {
    @file_put_contents(dm_cache_path(), json_encode($data), LOCK_EX);
}
function dm_cache_reset() {
    global $gdrive_folder_cache;
    $gdrive_folder_cache = [];
    @unlink(dm_cache_path());
}

if (!function_exists('get_or_create_drive_folder_cached')) {
    function get_or_create_drive_folder_cached($drive_service, $parent_id, $folder_name) {
        global $gdrive_folder_cache;
        $key = $parent_id . '|' . $folder_name;

        if (isset($gdrive_folder_cache[$key])) return $gdrive_folder_cache[$key];
        $disk = dm_cache_read();
        if (isset($disk[$key])) return $gdrive_folder_cache[$key] = $disk[$key];

        // Lock so parallel workers never create the same folder twice.
        $lock = @fopen(dm_cache_path() . '.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        try {
            $disk = dm_cache_read();
            if (isset($disk[$key])) return $gdrive_folder_cache[$key] = $disk[$key];

            $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $folder_name);
            $query = "name = '{$escapedName}' and '{$parent_id}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

            $files = $drive_service->files->listFiles([
                'q' => $query,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
                'fields' => 'files(id, name)'
            ])->getFiles();

            if (!empty($files)) {
                $folder_id = $files[0]->getId();
            } else {
                $folderMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [$parent_id]
                ]);
                $folder = $drive_service->files->create($folderMetadata, [
                    'supportsAllDrives' => true,
                    'fields' => 'id'
                ]);
                $folder_id = $folder->getId();
            }

            $disk[$key] = $folder_id;
            dm_cache_write($disk);
            return $gdrive_folder_cache[$key] = $folder_id;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}

function dm_drive_download($drive, $file_id, $dest) {
    $resp = $drive->files->get($file_id, ['alt' => 'media', 'supportsAllDrives' => true]);
    $body = $resp->getBody();
    $part = $dest . '.part';
    $fh = fopen($part, 'wb');
    if (!$fh) throw new Exception('Cannot write to uploads/sync');
    try {
        while (!$body->eof()) fwrite($fh, $body->read(1048576));
    } finally {
        fclose($fh);
    }
    if (!@rename($part, $dest)) { copy($part, $dest); @unlink($part); }
}

// -------------------------------------------------------------------------
// FULL MIRROR HELPERS (Google Drive -> server folders + database)
// -------------------------------------------------------------------------
function dm_drive_retry(callable $fn, $max = 4) {
    $attempt = 0;
    while (true) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $attempt++;
            $code = (int)$e->getCode();
            $retryable = $e instanceof \GuzzleHttp\Exception\TransferException
                || $code === 429 || $code >= 500
                || ($code === 403 && stripos($e->getMessage(), 'rate') !== false);
            if (!$retryable || $attempt >= $max) throw $e;
            usleep((int)(pow(2, $attempt - 1) * 1000000 + mt_rand(0, 500000)));   // ~1s, 2s, 4s
        }
    }
}

// Make a Drive name safe for a Windows folder / file name
function dm_safe_segment($s) {
    $s = preg_replace('/[<>:"\\\\|?*\x00-\x1F\/]/', '_', (string)$s);
    $s = rtrim(trim((string)$s), '. ');
    if ($s === '' || $s === '.' || $s === '..') $s = '_';
    return $s;
}
function dm_safe_path($p) {
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', (string)$p)) as $seg) {
        if (trim($seg) === '') continue;
        $parts[] = dm_safe_segment($seg);
    }
    return implode('/', $parts);
}

function dm_db_upsert_details($db, $name, $link, $id, $d, $path) {
    static $st = null;
    if ($st === null) {
        $st = $db->prepare("
            INSERT INTO pdf_extracted_details
            (filename, file_link, file_id, account_number, mobile_number, telco, amount_due, billing_period, invoice_date, due_date, file_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                file_link      = COALESCE(VALUES(file_link), file_link),
                file_id        = COALESCE(VALUES(file_id), file_id),
                account_number = VALUES(account_number),
                mobile_number  = VALUES(mobile_number),
                telco          = VALUES(telco),
                amount_due     = VALUES(amount_due),
                billing_period = VALUES(billing_period),
                invoice_date   = VALUES(invoice_date),
                due_date       = VALUES(due_date),
                file_path      = VALUES(file_path)
        ");
    }
    $st->execute([
        $name, $link, $id,
        $d['account_number'], $d['mobile_number'], $d['telco'],
        $d['amount_due'], $d['billing_period'], $d['invoice_date'],
        $d['due_date'], $path
    ]);
}

// Mirror ONE Drive file: keep the exact same folder path on the server, download only if missing / different size,
// read the PDF into the database only if needed (new file, changed file, not yet in DB, or "force").
function dm_mirror_one($it, $db, $drive, $force) {
    $id   = (string)($it['id'] ?? '');
    $name = dm_safe_segment(basename((string)($it['name'] ?? '')));
    $path = dm_safe_path($it['path'] ?? '');
    $size = (int)($it['size'] ?? 0);
    $link = isset($it['link']) ? (string)$it['link'] : null;
    $label = ($path !== '' ? $path . '/' : '') . $name;
    $out  = ['name' => $label, 'status' => 'error', 'message' => ''];

    if (!$drive) { $out['message'] = 'Google Drive is not configured'; return $out; }
    if ($id === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') { $out['message'] = 'Invalid file entry'; return $out; }

    $dir  = __DIR__ . '/uploads' . ($path !== '' ? '/' . $path : '');
    $dest = $dir . '/' . $name;
    if (strlen($dest) > 250) { $out['message'] = 'Path is too long for Windows (over 250 characters)'; return $out; }
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) { $out['message'] = 'Could not create folder ' . $path; return $out; }

    // 1. Download only when missing locally or the size differs from Drive
    $downloaded = false;
    if (!is_file($dest) || ($size > 0 && filesize($dest) !== $size)) {
        try {
            dm_drive_retry(function () use ($drive, $id, $dest) { dm_drive_download($drive, $id, $dest); });
        } catch (Throwable $e) {
            @unlink($dest . '.part');
            $out['message'] = 'Download failed: ' . dm_short_error($e);
            return $out;
        }
        clearstatcache(true, $dest);
        if ($size > 0 && filesize($dest) !== $size) {
            @unlink($dest);
            $out['message'] = 'Downloaded size does not match Drive (try again)';
            return $out;
        }
        $downloaded = true;
    }

    // 2. What does the database already know about this file?
    $row = null;
    if ($db) {
        $st = $db->prepare("SELECT file_id, file_path, account_number FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
        $st->execute([$name]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $processed = $row && $row['account_number'] !== 'PENDING_SYNC' && $row['file_path'] !== 'sync';

    $res = ['name' => $label, 'status' => 'success', 'skipped' => false, 'downloaded' => $downloaded,
            'read' => false, 'telco' => '-', 'account' => '-', 'warning' => ''];

    // Same filename already stored for a different Drive file in a different folder (DB keeps one row per filename)
    if ($processed && !empty($row['file_id']) && $row['file_id'] !== $id && $row['file_path'] !== $path) {
        $res['warning'] = 'Same filename is already in the database under "' . $row['file_path'] . '". File saved locally, database row not changed.';
        return $res;
    }

    $needRead = $force || $downloaded || !$processed || $row['file_path'] !== $path;

    if ($needRead) {
        $details = extract_pdf_statement_details($dest, $name);
        if ($db) {
            try { dm_db_upsert_details($db, $name, $link, $id, $details, $path); }
            catch (Throwable $e) { $res['warning'] = 'DB error: ' . dm_short_error($e); }
        }
        $res['read']    = true;
        $res['telco']   = $details['telco'];
        $res['account'] = $details['account_number'];
    } else {
        if ($db && $row && (empty($row['file_id']) || $row['file_id'] !== $id)) {
            try { $db->prepare("UPDATE pdf_extracted_details SET file_id = ?, file_link = ? WHERE filename = ?")->execute([$id, $link, $name]); }
            catch (Throwable $e) { /* not critical */ }
        }
        if (!$downloaded) $res['skipped'] = true;
    }
    return $res;
}

// -------------------------------------------------------------------------
// PROCESS ONE FILE: download (if only on Drive) -> read PDF -> organize locally -> place on Drive -> DB
// -------------------------------------------------------------------------
function dm_process_sync_file($name, $drive_id, $db, $sync_dir, $root_id) {
    $t0   = microtime(true);
    $name = basename((string)$name);                       // blocks path traversal
    $out  = ['name' => $name, 'status' => 'error', 'message' => ''];

    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
        $out['message'] = 'Invalid file name';
        return $out;
    }

    $src   = $sync_dir . '/' . $name;
    $dc    = dm_drive_client();
    $drive = $dc ? $dc['drive'] : null;
    $warnings = [];

    // 1. Make sure the file exists locally
    if (!is_file($src)) {
        if ($drive_id && $drive) {
            try {
                dm_drive_download($drive, $drive_id, $src);
            } catch (Throwable $e) {
                @unlink($src . '.part');
                $out['message'] = 'Download from Drive failed: ' . dm_short_error($e);
                return $out;
            }
        } else {
            if ($db) {
                $st = $db->prepare("SELECT file_path, account_number FROM pdf_extracted_details WHERE filename = ? LIMIT 1");
                $st->execute([$name]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['file_path'] !== 'sync' && $row['account_number'] !== 'PENDING_SYNC') {
                    return ['name' => $name, 'status' => 'success', 'skipped' => true, 'telco' => '-', 'account' => '-'];
                }
            }
            $out['message'] = 'File not found in uploads/sync';
            return $out;
        }
    }

    // 2. Read PDF
    $details = extract_pdf_statement_details($src, $name);

    $telco_folder   = !empty($details['telco']) ? $details['telco'] : 'Unknown_Telco';
    $year_folder    = (!empty($details['invoice_date']) && $details['invoice_date'] !== 'N/A') ? date('Y', strtotime($details['invoice_date'])) : date('Y');
    $billing_folder = !empty($details['billing_period']) ? str_replace(['/', '\\'], '-', $details['billing_period']) : 'Unknown_Period';
    $db_file_path   = "{$telco_folder}/{$year_folder}/{$billing_folder}";

    // 3. Move locally: uploads/sync -> uploads/Telco/Year/Billing
    $target_dir = __DIR__ . '/uploads/' . $db_file_path;
    if (!is_dir($target_dir) && !@mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
        $out['message'] = 'Could not create folder ' . $db_file_path;
        return $out;
    }
    $dest = $target_dir . '/' . $name;
    if (!@rename($src, $dest)) {
        if (@copy($src, $dest)) { @unlink($src); }
        else { $out['message'] = 'Could not move file to ' . $db_file_path; return $out; }
    }

    // 4. Google Drive: ONE call per file (move if it came from Drive, otherwise upload straight into final folder)
    $file_id = null; $file_link = null;
    if ($drive) {
        $attempt = 0;
        while (true) {
            try {
                $telco_fid   = get_or_create_drive_folder_cached($drive, $root_id, $telco_folder);
                $year_fid    = get_or_create_drive_folder_cached($drive, $telco_fid, $year_folder);
                $billing_fid = get_or_create_drive_folder_cached($drive, $year_fid, $billing_folder);

                if ($drive_id) {
                    $sync_fid = get_or_create_drive_folder_cached($drive, $root_id, 'sync');
                    $f = $drive->files->update($drive_id, new Google_Service_Drive_DriveFile(), [
                        'addParents'        => $billing_fid,
                        'removeParents'     => $sync_fid,
                        'supportsAllDrives' => true,
                        'fields'            => 'id, webViewLink'
                    ]);
                } else {
                    $f = $drive->files->create(
                        new Google_Service_Drive_DriveFile(['name' => $name, 'parents' => [$billing_fid]]),
                        [
                            'data'              => file_get_contents($dest),
                            'mimeType'          => 'application/pdf',
                            'uploadType'        => 'multipart',
                            'fields'            => 'id, webViewLink',
                            'supportsAllDrives' => true
                        ]
                    );
                }
                $file_id   = $f->getId();
                $file_link = $f->getWebViewLink();
                break;
            } catch (Throwable $e) {
                $attempt++;
                $code = (int)$e->getCode();
                if ($code === 404) dm_cache_reset();                    // stale cached folder id
                $retryable = $code === 0 || $code === 404 || $code === 429 || $code >= 500
                          || ($code === 403 && stripos($e->getMessage(), 'rate') !== false);
                if (!$retryable || $attempt >= 4) {
                    $warnings[] = 'Saved locally, Drive failed: ' . dm_short_error($e);
                    break;
                }
                usleep((int)(pow(2, $attempt - 1) * 1000000 + mt_rand(0, 500000)));   // backoff: ~1s, 2s, 4s
            }
        }
    }

    // 5. Database
    if ($db) {
        static $up = null;
        if ($up === null) {
            $up = $db->prepare("
                INSERT INTO pdf_extracted_details
                (filename, file_link, file_id, account_number, mobile_number, telco, amount_due, billing_period, invoice_date, due_date, file_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    file_link      = COALESCE(VALUES(file_link), file_link),
                    file_id        = COALESCE(VALUES(file_id), file_id),
                    account_number = VALUES(account_number),
                    mobile_number  = VALUES(mobile_number),
                    telco          = VALUES(telco),
                    amount_due     = VALUES(amount_due),
                    billing_period = VALUES(billing_period),
                    invoice_date   = VALUES(invoice_date),
                    due_date       = VALUES(due_date),
                    file_path      = VALUES(file_path)
            ");
        }
        try {
            $up->execute([
                $name, $file_link, $file_id,
                $details['account_number'], $details['mobile_number'], $details['telco'],
                $details['amount_due'], $details['billing_period'], $details['invoice_date'],
                $details['due_date'], $db_file_path
            ]);
        } catch (Throwable $e) {
            $warnings[] = 'DB error: ' . dm_short_error($e);
        }
    }

    return [
        'name'    => $name,
        'status'  => 'success',
        'telco'   => $details['telco'],
        'account' => $details['account_number'],
        'warning' => implode(' | ', $warnings),
        'ms'      => (int)round((microtime(true) - $t0) * 1000)
    ];
}

// -------------------------------------------------------------------------
// Guard: POST bigger than post_max_size arrives empty - answer clearly instead of returning the HTML page
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    dm_json_start();
    http_response_code(413);
    echo dm_json(['status' => 'error', 'message' => 'Upload too large for the server (post_max_size = ' . ini_get('post_max_size') . ').']);
    exit;
}

// -------------------------------------------------------------------------
// ENDPOINT 1: UPLOAD  (local disk + DB tracking only - no Google Drive here, so it is very fast)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_files']) && !isset($_POST['action'])) {
    dm_json_start();

    $success_files = [];
    $failed_files  = [];

    $sync_dir = __DIR__ . '/uploads/sync';
    if (!is_dir($sync_dir)) @mkdir($sync_dir, 0777, true);

    $stmt = null;
    if ($db_connection) {
        try {
            $stmt = $db_connection->prepare("
                INSERT INTO pdf_extracted_details
                (filename, file_link, file_id, file_path, account_number, telco, amount_due, billing_period)
                VALUES (?, NULL, NULL, 'sync', 'PENDING_SYNC', 'Unknown', '0.00', 'PENDING_SYNC')
                ON DUPLICATE KEY UPDATE filename = filename
            ");
        } catch (Throwable $e) { $stmt = null; }
    }

    foreach ($_FILES['pdf_files']['name'] as $i => $name) {
        $basename = basename((string)$name);
        $err = $_FILES['pdf_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        $tmp = $_FILES['pdf_files']['tmp_name'][$i] ?? '';

        if ($err !== UPLOAD_ERR_OK) { $failed_files[] = ['name' => $basename, 'reason' => dm_upload_error($err)]; continue; }
        if (!is_uploaded_file($tmp)) { $failed_files[] = ['name' => $basename, 'reason' => 'Invalid upload']; continue; }
        if (strtolower(pathinfo($basename, PATHINFO_EXTENSION)) !== 'pdf') { $failed_files[] = ['name' => $basename, 'reason' => 'Not a PDF file']; continue; }

        $fh = @fopen($tmp, 'rb');
        $head = $fh ? (string)fread($fh, 1024) : '';
        if ($fh) fclose($fh);
        if (strpos($head, '%PDF-') === false) { $failed_files[] = ['name' => $basename, 'reason' => 'Not a valid PDF']; continue; }

        if (!@move_uploaded_file($tmp, $sync_dir . '/' . $basename)) {
            $failed_files[] = ['name' => $basename, 'reason' => 'Could not save to uploads/sync (check folder permissions)'];
            continue;
        }

        if ($stmt) { try { $stmt->execute([$basename]); } catch (Throwable $e) { /* Sync step upserts the row anyway */ } }
        $success_files[] = $basename;
    }

    echo dm_json(['status' => 'success', 'success_files' => $success_files, 'failed_files' => $failed_files]);
    exit;
}

// -------------------------------------------------------------------------
// ENDPOINT 2: BUILD SYNC QUEUE (list Drive with paging + local folder; downloads happen later, in parallel)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_sync_queue') {
    dm_json_start();

    $sync_dir = __DIR__ . '/uploads/sync';
    if (!is_dir($sync_dir)) @mkdir($sync_dir, 0777, true);

    $on_drive = [];
    $drive_warning = null;
    $dc = dm_drive_client();

    if ($dc) {
        try {
            $drive    = $dc['drive'];
            $sync_fid = get_or_create_drive_folder_cached($drive, $google_drive_folder_id, 'sync');
            $pageToken = null;
            do {
                $params = [
                    'q' => "'{$sync_fid}' in parents and mimeType = 'application/pdf' and trashed = false",
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                    'pageSize' => 1000,
                    'fields' => 'nextPageToken, files(id, name, webViewLink)'
                ];
                if ($pageToken) $params['pageToken'] = $pageToken;
                $res = $drive->files->listFiles($params);
                foreach ($res->getFiles() as $f) {
                    $n = basename($f->getName());
                    if (!isset($on_drive[$n])) $on_drive[$n] = ['id' => $f->getId(), 'link' => $f->getWebViewLink()];
                }
                $pageToken = $res->getNextPageToken();
            } while ($pageToken);

            if ($db_connection && $on_drive) {
                $db_connection->beginTransaction();
                $ins = $db_connection->prepare("
                    INSERT INTO pdf_extracted_details
                    (filename, file_link, file_id, file_path, account_number, telco, amount_due, billing_period)
                    VALUES (?, ?, ?, 'sync', 'PENDING_SYNC', 'Unknown', '0.00', 'PENDING_SYNC')
                    ON DUPLICATE KEY UPDATE
                        file_link = COALESCE(NULLIF(file_link, ''), VALUES(file_link)),
                        file_id   = COALESCE(NULLIF(file_id, ''), VALUES(file_id))
                ");
                foreach ($on_drive as $n => $info) { $ins->execute([$n, $info['link'], $info['id']]); }
                $db_connection->commit();
            }
        } catch (Throwable $e) {
            if ($db_connection && $db_connection->inTransaction()) $db_connection->rollBack();
            $drive_warning = 'Google Drive unavailable (' . dm_short_error($e) . '). Continuing with local files only.';
        }
    } else {
        $drive_warning = 'Google Drive is not configured (credentials.json / Google client missing). Using local files only.';
    }

    $queue = [];
    $to_download = 0;
    foreach ((glob($sync_dir . '/*.[pP][dD][fF]') ?: []) as $p) {
        $n = basename($p);
        $queue[$n] = ['name' => $n, 'drive_id' => $on_drive[$n]['id'] ?? null];
    }
    foreach ($on_drive as $n => $info) {
        if (!isset($queue[$n])) { $queue[$n] = ['name' => $n, 'drive_id' => $info['id']]; $to_download++; }
    }

    echo dm_json([
        'status'        => 'success',
        'queue'         => array_values($queue),
        'total'         => count($queue),
        'to_download'   => $to_download,
        'drive_warning' => $drive_warning
    ]);
    exit;
}

// -------------------------------------------------------------------------
// ENDPOINT 3: PROCESS A SMALL BATCH OF FILES (one Drive client + token for the whole batch)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_sync_batch') {
    dm_json_start();
    @set_time_limit(600);

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) {
        echo dm_json(['status' => 'error', 'message' => 'No items supplied']);
        exit;
    }

    $sync_dir = __DIR__ . '/uploads/sync';
    $results  = [];
    foreach (array_slice($items, 0, 25) as $it) {
        $nm  = is_array($it) ? ($it['name'] ?? '') : (string)$it;
        $did = is_array($it) ? ($it['drive_id'] ?? null) : null;
        try {
            $results[] = dm_process_sync_file($nm, $did, $db_connection, $sync_dir, $google_drive_folder_id);
        } catch (Throwable $e) {
            $results[] = ['name' => basename((string)$nm), 'status' => 'error', 'message' => dm_short_error($e)];
        }
        gc_collect_cycles();
    }

    echo dm_json(['status' => 'success', 'results' => $results]);
    exit;
}

// Backward-compatible single-file endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_single_sync_file') {
    dm_json_start();
    $r = dm_process_sync_file($_POST['filename'] ?? '', null, $db_connection, __DIR__ . '/uploads/sync', $google_drive_folder_id);
    echo dm_json($r['status'] === 'success'
        ? ['status' => 'success', 'filename' => $r['name'], 'telco' => $r['telco'], 'account' => $r['account']]
        : ['status' => 'error', 'message' => $r['name'] . ': ' . $r['message']]);
    exit;
}

// -------------------------------------------------------------------------
// MIRROR ENDPOINT 1: open the Drive root folder
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mirror_init') {
    dm_json_start();
    $dc = dm_drive_client();
    if (!$dc) {
        echo dm_json(['status' => 'error', 'message' => 'Google Drive is not configured (credentials.json / Google client missing).']);
        exit;
    }
    try {
        $drive = $dc['drive'];
        $root = dm_drive_retry(function () use ($drive, $google_drive_folder_id) {
            return $drive->files->get($google_drive_folder_id, ['fields' => 'id, name', 'supportsAllDrives' => true]);
        });
        echo dm_json(['status' => 'success', 'root' => ['id' => $root->getId(), 'path' => ''], 'root_name' => $root->getName()]);
    } catch (Throwable $e) {
        echo dm_json(['status' => 'error', 'message' => 'Cannot open the Drive root folder: ' . dm_short_error($e)]);
    }
    exit;
}

// -------------------------------------------------------------------------
// MIRROR ENDPOINT 2: list a few Drive folders (sub-folders + PDFs). The browser walks the tree, so progress is live.
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mirror_scan_step') {
    dm_json_start();
    @set_time_limit(300);
    $dc = dm_drive_client();
    if (!$dc) {
        echo dm_json(['status' => 'error', 'message' => 'Google Drive is not configured.']);
        exit;
    }
    $drive   = $dc['drive'];
    $folders = json_decode($_POST['folders'] ?? '[]', true);
    if (!is_array($folders)) $folders = [];
    $results = [];

    foreach (array_slice($folders, 0, 10) as $f) {
        $fid   = (string)($f['id'] ?? '');
        $fpath = (string)($f['path'] ?? '');
        $subs = []; $files = []; $err = null;

        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $fid)) {
            $results[] = ['id' => $fid, 'path' => $fpath, 'folders' => [], 'files' => [], 'error' => 'Invalid folder id'];
            continue;
        }
        try {
            $token = null;
            do {
                $params = [
                    'q' => "'{$fid}' in parents and trashed = false and (mimeType = 'application/pdf' or mimeType = 'application/vnd.google-apps.folder')",
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                    'pageSize' => 1000,
                    'fields' => 'nextPageToken, files(id, name, mimeType, size, webViewLink)'
                ];
                if ($token) $params['pageToken'] = $token;
                $res = dm_drive_retry(function () use ($drive, $params) { return $drive->files->listFiles($params); });
                foreach ($res->getFiles() as $x) {
                    if ($x->getMimeType() === 'application/vnd.google-apps.folder') {
                        // the root "sync" folder holds PENDING files: the normal "Sync & Read" button handles those
                        if ($fpath === '' && strtolower($x->getName()) === 'sync') continue;
                        $subs[] = ['id' => $x->getId(), 'name' => $x->getName()];
                    } else {
                        $files[] = ['id' => $x->getId(), 'name' => $x->getName(), 'size' => (int)$x->getSize(), 'link' => $x->getWebViewLink()];
                    }
                }
                $token = $res->getNextPageToken();
            } while ($token);
        } catch (Throwable $e) {
            $err = dm_short_error($e);
        }
        $results[] = ['id' => $fid, 'path' => $fpath, 'folders' => $subs, 'files' => $files, 'error' => $err];
    }

    echo dm_json(['status' => 'success', 'results' => $results]);
    exit;
}

// -------------------------------------------------------------------------
// MIRROR ENDPOINT 3: download + read a small batch of files
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mirror_batch') {
    dm_json_start();
    @set_time_limit(600);

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) {
        echo dm_json(['status' => 'error', 'message' => 'No items supplied']);
        exit;
    }
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $dc    = dm_drive_client();
    $drive = $dc ? $dc['drive'] : null;

    $results = [];
    foreach (array_slice($items, 0, 25) as $it) {
        try {
            $results[] = dm_mirror_one(is_array($it) ? $it : [], $db_connection, $drive, $force);
        } catch (Throwable $e) {
            $results[] = ['name' => (string)($it['name'] ?? '?'), 'status' => 'error', 'message' => dm_short_error($e)];
        }
        gc_collect_cycles();
    }
    echo dm_json(['status' => 'success', 'results' => $results]);
    exit;
}

// -------------------------------------------------------------------------
// MIRROR ENDPOINT 4: list PDFs that exist on the server (to report files that are NOT on Drive)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mirror_local_inventory') {
    dm_json_start();
    @set_time_limit(300);
    $base = __DIR__ . '/uploads';
    $list = [];
    if (is_dir($base)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'pdf') continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            if (strpos($rel, 'sync/') === 0) continue;
            $list[] = $rel;
        }
    }
    echo dm_json(['status' => 'success', 'files' => $list]);
    exit;
}

// =========================================================================
// RECORDS TAB: list / edit / delete (database + server file + Google Drive)
// =========================================================================
function dm_rec_na_sql($col) { return "($col IS NULL OR TRIM($col) = '' OR UPPER(TRIM($col)) = 'N/A')"; }

function dm_rec_flags() {
    return [
        'na_account'    => dm_rec_na_sql('account_number'),
        'na_mobile'     => dm_rec_na_sql('mobile_number'),
        'unknown_telco' => "(telco IS NULL OR TRIM(telco) = '' OR telco = 'Unknown')",
        'zero_amount'   => "(amount_due IS NULL OR TRIM(amount_due) = '' OR amount_due IN ('0', '0.0', '0.00'))",
        'na_billing'    => dm_rec_na_sql('billing_period'),
        'na_invoice'    => dm_rec_na_sql('invoice_date'),
        'na_due'        => dm_rec_na_sql('due_date'),
        'pending'       => "(account_number = 'PENDING_SYNC' OR file_path = 'sync')",
        'no_link'       => "(file_link IS NULL OR file_link = '')",
    ];
}
function dm_rec_any_na_sql() {
    $f = dm_rec_flags();
    return '(' . implode(' OR ', [$f['na_account'], $f['na_mobile'], $f['unknown_telco'], $f['zero_amount'], $f['na_billing'], $f['na_invoice'], $f['na_due']]) . ')';
}

function dm_rec_where(&$params) {
    $flags = dm_rec_flags();
    $w = [];
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = '(filename LIKE ? OR account_number LIKE ? OR mobile_number LIKE ? OR billing_period LIKE ? OR file_path LIKE ?)';
        for ($i = 0; $i < 5; $i++) $params[] = $like;
    }
    $telco = trim((string)($_POST['telco'] ?? ''));
    if ($telco !== '') { $w[] = 'telco = ?'; $params[] = $telco; }

    $flag = (string)($_POST['flag'] ?? '');
    if ($flag === 'any_na')        $w[] = dm_rec_any_na_sql();
    elseif ($flag === 'complete')  $w[] = 'NOT ' . dm_rec_any_na_sql() . " AND NOT {$flags['pending']}";
    elseif (isset($flags[$flag]))  $w[] = $flags[$flag];

    return $w ? 'WHERE ' . implode(' AND ', $w) : '';
}

function dm_rec_counts($db) {
    $flags = dm_rec_flags();
    $sel = ['COUNT(*) AS total'];
    foreach ($flags as $k => $sql) $sel[] = "SUM(CASE WHEN $sql THEN 1 ELSE 0 END) AS $k";
    $sel[] = 'SUM(CASE WHEN ' . dm_rec_any_na_sql() . ' THEN 1 ELSE 0 END) AS any_na';
    $sel[] = 'SUM(CASE WHEN NOT ' . dm_rec_any_na_sql() . " AND NOT {$flags['pending']} THEN 1 ELSE 0 END) AS complete";
    $row = $db->query('SELECT ' . implode(', ', $sel) . ' FROM pdf_extracted_details')->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($row as $k => $v) $out[$k] = (int)$v;
    $telcos = [];
    foreach ($db->query("SELECT COALESCE(NULLIF(TRIM(telco), ''), 'Unknown') AS t, COUNT(*) AS c FROM pdf_extracted_details GROUP BY t ORDER BY c DESC") as $r) {
        $telcos[] = ['name' => $r['t'], 'count' => (int)$r['c']];
    }
    return ['counts' => $out, 'telcos' => $telcos];
}

// Possible local locations of a record's PDF (always inside /uploads)
function dm_rec_local_candidates($row) {
    $base = __DIR__ . '/uploads';
    $name = basename((string)($row['filename'] ?? ''));
    $fp   = str_replace('\\', '/', (string)($row['file_path'] ?? ''));
    $c = [];
    if ($name === '') return $c;
    $rel = dm_safe_path($fp);
    $c[] = $base . ($rel !== '' ? '/' . $rel : '') . '/' . dm_safe_segment($name);
    $raw = trim($fp, '/');
    if ($raw !== '' && strpos($raw, '..') === false) $c[] = $base . '/' . $raw . '/' . $name;
    return array_values(array_unique($c));
}
function dm_rec_local_exists($row) {
    foreach (dm_rec_local_candidates($row) as $p) if (is_file($p)) return true;
    return false;
}
function dm_rec_drive_id($row) {
    if (!empty($row['file_id'])) return (string)$row['file_id'];
    $l = (string)($row['file_link'] ?? '');
    if (preg_match('#/d/([A-Za-z0-9_-]+)#', $l, $m)) return $m[1];
    if (preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $l, $m)) return $m[1];
    return '';
}

// Delete one record completely: Google Drive (moved to Drive trash) -> server file -> database row.
// If Drive or the server file cannot be removed, the DB row is KEPT so you can retry.
function dm_rec_delete_one($db, $drive, $id) {
    $st = $db->prepare('SELECT * FROM pdf_extracted_details WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['id' => $id, 'filename' => '#' . $id, 'status' => 'success', 'warning' => 'Already deleted'];

    $out = ['id' => $id, 'filename' => $row['filename'], 'status' => 'error', 'message' => '', 'warning' => ''];
    $warn = [];

    // 1. Google Drive
    $fid = dm_rec_drive_id($row);
    if ($fid !== '') {
        if (!$drive) { $out['message'] = 'Google Drive is not configured, file was not deleted'; return $out; }
        try {
            dm_drive_retry(function () use ($drive, $fid) {
                $drive->files->update($fid, new Google_Service_Drive_DriveFile(['trashed' => true]), ['supportsAllDrives' => true, 'fields' => 'id']);
            });
        } catch (Throwable $e) {
            if ((int)$e->getCode() !== 404) { $out['message'] = 'Drive delete failed: ' . dm_short_error($e); return $out; }
            $warn[] = 'Not found on Drive (already removed)';
        }
    } else {
        $warn[] = 'No Drive file id, Drive copy not touched';
    }

    // 2. Server file (only inside /uploads)
    $base = realpath(__DIR__ . '/uploads');
    foreach (dm_rec_local_candidates($row) as $p) {
        if (!is_file($p)) continue;
        $rp = realpath($p);
        if (!$base || !$rp || strpos($rp, $base . DIRECTORY_SEPARATOR) !== 0) continue;
        if (!@unlink($rp)) { $out['message'] = 'Could not delete the server file (permission?)'; return $out; }
        $dir = dirname($rp);                                   // remove empty parent folders
        while ($dir && strlen($dir) > strlen($base) && strpos($dir, $base . DIRECTORY_SEPARATOR) === 0) {
            if (count(@scandir($dir) ?: []) > 2) break;
            if (!@rmdir($dir)) break;
            $dir = dirname($dir);
        }
    }

    // 3. Database
    try {
        $db->prepare('DELETE FROM pdf_extracted_details WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        $out['message'] = 'Database delete failed: ' . dm_short_error($e);
        return $out;
    }
    $out['status']  = 'success';
    $out['warning'] = implode(' | ', $warn);
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && strpos((string)$_POST['action'], 'records_') === 0) {
    dm_json_start();
    @set_time_limit(300);
    if (!$db_connection) { echo dm_json(['status' => 'error', 'message' => 'No database connection']); exit; }
    $db = $db_connection;
    $action = (string)$_POST['action'];

    try {
        if ($action === 'records_list') {
            $per  = max(10, min(200, (int)($_POST['per_page'] ?? 25)));
            $page = max(1, (int)($_POST['page'] ?? 1));
            $cols = ['id' => 'id', 'filename' => 'filename', 'account_number' => 'account_number', 'mobile_number' => 'mobile_number',
                     'telco' => 'telco', 'amount_due' => "CAST(REPLACE(amount_due, ',', '') AS DECIMAL(14,2))",
                     'billing_period' => 'billing_period', 'invoice_date' => 'invoice_date', 'due_date' => 'due_date', 'updated_at' => 'updated_at'];
            $sort = $cols[$_POST['sort'] ?? ''] ?? 'updated_at';
            $dir  = (($_POST['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

            $params = [];
            $where  = dm_rec_where($params);
            $c = $db->prepare("SELECT COUNT(*) FROM pdf_extracted_details $where");
            $c->execute($params);
            $total = (int)$c->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $per;

            $s = $db->prepare("SELECT id, file_link, file_id, file_path, filename, account_number, mobile_number, telco, amount_due,
                                      billing_period, invoice_date, due_date, updated_at
                               FROM pdf_extracted_details $where ORDER BY $sort $dir, id DESC LIMIT $per OFFSET $offset");
            $s->execute($params);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['local'] = dm_rec_local_exists($r);
                $r['drive'] = dm_rec_drive_id($r) !== '';
                unset($r['file_id']);
            }
            unset($r);
            $cn = dm_rec_counts($db);
            echo dm_json(['status' => 'success', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
                          'per_page' => $per, 'counts' => $cn['counts'], 'telcos' => $cn['telcos']]);

        } elseif ($action === 'records_ids') {
            $params = [];
            $where  = dm_rec_where($params);
            $s = $db->prepare("SELECT id FROM pdf_extracted_details $where ORDER BY id LIMIT 100000");
            $s->execute($params);
            echo dm_json(['status' => 'success', 'ids' => array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))]);

        } elseif ($action === 'records_update') {
            $id = (int)($_POST['id'] ?? 0);
            $in = json_decode((string)($_POST['data'] ?? '{}'), true);
            if ($id <= 0 || !is_array($in)) { echo dm_json(['status' => 'error', 'message' => 'Invalid request']); exit; }
            $rules = [   // field => [max length, value used when left empty]
                'account_number' => [50, null], 'mobile_number' => [50, 'N/A'], 'telco' => [50, 'Unknown'],
                'amount_due' => [50, '0.00'], 'billing_period' => [100, 'N/A'], 'invoice_date' => [50, 'N/A'], 'due_date' => [50, 'N/A'],
            ];
            $set = []; $vals = [];
            foreach ($rules as $f => $rule) {
                if (!array_key_exists($f, $in)) continue;
                $v = trim((string)$in[$f]);
                if ($v === '') {
                    if ($rule[1] === null) { echo dm_json(['status' => 'error', 'message' => 'Account number cannot be empty']); exit; }
                    $v = $rule[1];
                }
                if (function_exists('mb_substr')) $v = mb_substr($v, 0, $rule[0]); else $v = substr($v, 0, $rule[0]);
                $set[] = "$f = ?"; $vals[] = $v;
            }
            if (!$set) { echo dm_json(['status' => 'error', 'message' => 'Nothing to update']); exit; }
            $vals[] = $id;
            $db->prepare('UPDATE pdf_extracted_details SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
            $s = $db->prepare('SELECT id, file_link, file_id, file_path, filename, account_number, mobile_number, telco, amount_due, billing_period, invoice_date, due_date, updated_at FROM pdf_extracted_details WHERE id = ?');
            $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r) { echo dm_json(['status' => 'error', 'message' => 'Record not found']); exit; }
            $r['local'] = dm_rec_local_exists($r);
            $r['drive'] = dm_rec_drive_id($r) !== '';
            unset($r['file_id']);
            $cn = dm_rec_counts($db);
            echo dm_json(['status' => 'success', 'row' => $r, 'counts' => $cn['counts'], 'telcos' => $cn['telcos']]);

        } elseif ($action === 'records_delete_batch') {
            $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
            if (!is_array($ids) || !$ids) { echo dm_json(['status' => 'error', 'message' => 'No records supplied']); exit; }
            $dc = dm_drive_client();
            $drive = $dc ? $dc['drive'] : null;
            $results = [];
            foreach (array_slice($ids, 0, 25) as $id) {
                try { $results[] = dm_rec_delete_one($db, $drive, (int)$id); }
                catch (Throwable $e) { $results[] = ['id' => (int)$id, 'filename' => '#' . (int)$id, 'status' => 'error', 'message' => dm_short_error($e)]; }
            }
            echo dm_json(['status' => 'success', 'results' => $results]);

        } else {
            echo dm_json(['status' => 'error', 'message' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo dm_json(['status' => 'error', 'message' => dm_short_error($e)]);
    }
    exit;
}

ob_start();
?>

<style>
    .dm-bar-fill { background-image: linear-gradient(90deg,#4f46e5,#6366f1,#818cf8); position: relative; overflow: hidden; transition: width .35s ease; }
    .dm-bar-fill::after { content:''; position:absolute; inset:0; background-image: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.5) 50%, transparent 70%); background-size: 200% 100%; animation: dm-shimmer 1.4s linear infinite; }
    .dm-bar-fill.dm-static::after { display:none; }
    .dm-bar-fill.dm-indeterminate { width: 35% !important; animation: dm-slide 1.1s ease-in-out infinite alternate; }
    @keyframes dm-shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }
    @keyframes dm-slide { from { margin-left: 0; } to { margin-left: 65%; } }
    @keyframes dm-pulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .35; transform: scale(.75); } }
    @keyframes dm-spin { to { transform: rotate(360deg); } }
    .dm-pulse { animation: dm-pulse 1.4s ease-in-out infinite; }
    .dm-spin  { animation: dm-spin .9s linear infinite; }
    .dm-ring  { width: 14px; height: 14px; border: 2px solid #c7d2fe; border-top-color: #4f46e5; border-radius: 9999px; display: inline-block; flex: none; }
    .dm-cta { background:#4f46e5; border:2px solid #4f46e5; color:#fff; font-weight:800; font-size:14px; padding:10px 22px; border-radius:12px;
              box-shadow:0 6px 16px rgba(79,70,229,.3); display:inline-flex; align-items:center; gap:10px; transition:all .15s; cursor:pointer; }
    .dm-cta:hover:not(:disabled) { background:#4338ca; border-color:#4338ca; transform:translateY(-1px); }
    .dm-cta:disabled { background:#e2e8f0; border-color:#cbd5e1; color:#475569; box-shadow:none; cursor:not-allowed; }
    .dm-action { background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 1px 2px rgba(15,23,42,.05); transition:all .15s; overflow:hidden; display:flex; flex-direction:column; }
    .dm-action:hover { box-shadow:0 10px 24px rgba(15,23,42,.10); transform:translateY(-1px); }
    .dm-action-sync   { border-left:5px solid #4f46e5; }
    .dm-action-mirror { border-left:5px solid #0ea5e9; }
    .dm-action-main { display:flex; align-items:center; gap:12px; padding:12px 14px; width:100%; text-align:left; background:transparent; border:0; cursor:pointer; }
    .dm-action-icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex:none; }
    .dm-action-sync .dm-action-icon   { background:#eef2ff; color:#4f46e5; }
    .dm-action-mirror .dm-action-icon { background:#e0f2fe; color:#0284c7; }
    .dm-action-arrow { margin-left:auto; font-weight:800; font-size:18px; color:#94a3b8; transition:transform .15s, color .15s; }
    .dm-action:hover .dm-action-arrow { transform:translateX(4px); color:#4f46e5; }
    .dm-action-opt { padding:7px 14px; background:#f8fafc; border-top:1px solid #f1f5f9; font-size:11px; font-weight:600; color:#64748b; display:flex; align-items:center; gap:7px; }
    .dm-dot { width:8px; height:8px; border-radius:9999px; display:inline-block; flex:none; }
    /* ---- Records tab ---- */
    .rc-tabs { display:flex; gap:6px; margin-bottom:14px; border-bottom:2px solid #e2e8f0; }
    .rc-tab { padding:9px 18px; font-weight:800; font-size:13px; color:#64748b; border:0; background:transparent; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; border-radius:10px 10px 0 0; display:flex; align-items:center; gap:8px; }
    .rc-tab:hover { color:#4f46e5; background:#f8fafc; }
    .rc-tab.active { color:#4f46e5; border-bottom-color:#4f46e5; background:#eef2ff; }
    .rc-pill { background:#e2e8f0; color:#475569; border-radius:9999px; font-size:11px; padding:1px 8px; font-weight:800; }
    .rc-tab.active .rc-pill { background:#4f46e5; color:#fff; }
    .rc-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(128px,1fr)); gap:8px; margin-bottom:12px; }
    .rc-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:8px 12px; text-align:left; cursor:pointer; transition:all .12s; border-left:4px solid var(--c,#94a3b8); }
    .rc-card:hover { box-shadow:0 6px 16px rgba(15,23,42,.10); transform:translateY(-1px); }
    .rc-card.active { background:var(--bg,#f1f5f9); box-shadow:0 0 0 2px var(--c,#94a3b8) inset; }
    .rc-card .n { font-size:20px; font-weight:900; color:#0f172a; line-height:1.1; }
    .rc-card .l { font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .rc-chart { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; margin-bottom:12px; }
    .rc-chart h4 { font-size:12px; font-weight:900; color:#334155; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
    .rc-stack { display:flex; height:22px; border-radius:9999px; overflow:hidden; background:#e2e8f0; }
    .rc-stack > div { height:100%; transition:width .3s; }
    .rc-legend { display:flex; flex-wrap:wrap; gap:14px; margin:8px 0 14px; font-size:11.5px; font-weight:700; color:#475569; }
    .rc-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:5px; }
    .rc-crow { display:grid; grid-template-columns:150px 1fr 90px; gap:10px; align-items:center; padding:3px 6px; border-radius:8px; cursor:pointer; font-size:12px; }
    .rc-crow:hover { background:#f8fafc; }
    .rc-crow.active { background:#eef2ff; }
    .rc-crow .lb { font-weight:700; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .rc-crow .tr { height:14px; background:#f1f5f9; border-radius:9999px; overflow:hidden; }
    .rc-crow .fl { height:100%; border-radius:9999px; transition:width .3s; min-width:0; }
    .rc-crow .ct { font-weight:900; color:#0f172a; text-align:right; }
    .rc-crow .ct small { color:#94a3b8; font-weight:700; margin-left:4px; }
    .rc-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px; }
    .rc-input, .rc-select { border:1px solid #cbd5e1; border-radius:10px; padding:7px 10px; font-size:13px; background:#fff; }
    .rc-input:focus, .rc-select:focus { outline:2px solid #818cf8; border-color:#818cf8; }
    .rc-btn { border:1px solid #cbd5e1; background:#fff; border-radius:10px; padding:7px 13px; font-size:12px; font-weight:800; color:#334155; cursor:pointer; }
    .rc-btn:hover { background:#f1f5f9; }
    .rc-btn:disabled { opacity:.45; cursor:not-allowed; }
    .rc-btn-danger { background:#e11d48; border-color:#e11d48; color:#fff; }
    .rc-btn-danger:hover:not(:disabled) { background:#be123c; }
    .rc-btn-primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .rc-btn-primary:hover:not(:disabled) { background:#4338ca; }
    .rc-banner { background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; border-radius:10px; padding:7px 12px; font-size:12px; font-weight:700; margin-bottom:8px; display:none; }
    .rc-banner a { color:#4f46e5; text-decoration:underline; cursor:pointer; font-weight:800; }
    .rc-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:auto; max-height:68vh; }
    .rc-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .rc-table th { position:sticky; top:0; background:#f8fafc; text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; padding:9px 10px; border-bottom:1px solid #e2e8f0; white-space:nowrap; cursor:pointer; user-select:none; z-index:1; }
    .rc-table th.nosort { cursor:default; }
    .rc-table td { padding:7px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .rc-table tr:hover td { background:#f8fafc; }
    .rc-table tr.sel td { background:#eef2ff; }
    .rc-na { background:#fef3c7; color:#92400e; border-radius:6px; padding:1px 7px; font-weight:800; font-size:11px; }
    .rc-ok { color:#059669; font-weight:900; } .rc-no { color:#e11d48; font-weight:900; }
    .rc-fn { max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:700; color:#1e293b; }
    .rc-sub { font-size:10.5px; color:#94a3b8; }
    .rc-act { border:0; background:transparent; cursor:pointer; font-size:12px; font-weight:800; padding:4px 8px; border-radius:8px; }
    .rc-act.e { color:#4f46e5; } .rc-act.e:hover { background:#eef2ff; }
    .rc-act.d { color:#e11d48; } .rc-act.d:hover { background:#fff1f2; }
    .rc-modal { position:fixed; inset:0; z-index:99998; background:rgba(15,23,42,.6); backdrop-filter:blur(3px); display:none; align-items:center; justify-content:center; padding:12px; }
    .rc-modal.open { display:flex; }
    .rc-box { background:#fff; border-radius:20px; width:100%; max-width:480px; max-height:94vh; overflow:auto; padding:20px; box-shadow:0 25px 50px rgba(0,0,0,.25); }
    .rc-box h3 { font-weight:900; font-size:16px; color:#0f172a; margin-bottom:4px; }
    .rc-field { display:block; margin-top:10px; font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .rc-field input { display:block; width:100%; margin-top:3px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px; color:#0f172a; text-transform:none; letter-spacing:0; font-weight:600; }
    .rc-field input:focus { outline:2px solid #818cf8; border-color:#818cf8; }
    .rc-prog { height:10px; background:#e2e8f0; border-radius:9999px; overflow:hidden; margin-top:12px; }
    .rc-prog > div { height:100%; width:0; background:linear-gradient(90deg,#e11d48,#fb7185); transition:width .25s; }
    .rc-log { margin-top:10px; max-height:140px; overflow:auto; font-size:11px; font-family:monospace; background:#0f172a; color:#fda4af; border-radius:10px; padding:8px; display:none; }
    .dm-drop-active { border-color: #6366f1 !important; background: rgba(99,102,241,.08) !important; transform: scale(1.005); }
</style>

<!-- UPLOAD & SYNC PAGE -->
<div id="dmWrap" class="max-w-4xl mx-auto mt-2 px-2 md:px-0">

    <!-- HERO -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white px-5 py-4 mb-4 shadow-md shadow-indigo-200/50">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-200">Debit Memo</p>
                <h2 class="text-lg md:text-xl font-black tracking-tight leading-tight">Upload &amp; Sync Statements</h2>
                <p class="text-xs text-indigo-100 mt-0.5">Drop PDFs and they are uploaded, then synced and read automatically. Each statement is filed by telco, year and billing period (local + Google Drive).</p>
                <div class="mt-2.5 flex flex-wrap items-center gap-1.5 text-[11px] font-bold">
                    <span class="bg-white/15 rounded-full px-2.5 py-1">1 · Upload PDFs</span>
                    <span class="text-indigo-200">→</span>
                    <span class="bg-white/15 rounded-full px-2.5 py-1">2 · Auto Sync &amp; Read</span>
                    <span class="text-indigo-200">→</span>
                    <span class="bg-white/15 rounded-full px-2.5 py-1">3 · Organized by Telco / Year / Period</span>
                </div>
            </div>

        </div>
    </div>

    <!-- TABS -->
    <div class="rc-tabs">
        <button type="button" id="tabBtnUpload" class="rc-tab active" onclick="showTab('upload')">⬆ Upload &amp; Sync</button>
        <button type="button" id="tabBtnRecords" class="rc-tab" onclick="showTab('records')">📋 Records <span class="rc-pill" id="tabRecCount">…</span></button>
    </div>

    <div id="tabUpload">

    <!-- ACTIONS -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
        <div class="dm-action dm-action-sync">
            <button type="button" onclick="startSyncProcess()" class="dm-action-main">
                <span class="dm-action-icon">⚡</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-slate-800">Sync &amp; Read Uploaded PDFs</span>
                    <span class="block text-xs text-slate-500">Process the PDFs waiting in the sync folder</span>
                </span>
                <span class="dm-action-arrow">→</span>
            </button>
            <div class="dm-action-opt"><span class="dm-dot" style="background:#22c55e"></span> Runs automatically after every upload. Use this only for leftovers.</div>
        </div>

        <div class="dm-action dm-action-mirror">
            <button type="button" onclick="startMirrorProcess()" class="dm-action-main" title="Compare ALL files on Google Drive with this server, download the missing ones and read them into the database">
                <span class="dm-action-icon">☁</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-slate-800">Mirror ALL from Google Drive</span>
                    <span class="block text-xs text-slate-500">Download what is missing and read it into the database</span>
                </span>
                <span class="dm-action-arrow">→</span>
            </button>
            <label class="dm-action-opt cursor-pointer">
                <input type="checkbox" id="mirrorForce"> Re-read data of every PDF (slower)
            </label>
        </div>
    </div>

    <!-- DROPZONE -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4 mb-4">
        <div id="dropZone" class="border-2 border-dashed border-slate-300 rounded-xl px-5 py-4 bg-slate-50/60 hover:bg-slate-50 transition-all relative cursor-pointer">
            <div class="flex flex-col md:flex-row items-center gap-4">
                <div class="w-12 h-12 shrink-0 text-indigo-600 flex items-center justify-center bg-indigo-50 rounded-xl pointer-events-none">
                    <i class="las la-cloud-upload-alt text-3xl"></i>
                </div>
                <div class="flex-1 text-center md:text-left pointer-events-none min-w-0">
                    <p class="text-sm font-bold text-slate-800">Drag &amp; drop PDF files or whole folders here</p>
                    <p class="text-xs text-slate-500">Files are saved to <strong>uploads/sync/</strong> first, then synced to Google Drive and read automatically.</p>
                </div>
                <div class="flex flex-wrap justify-center gap-2 relative z-10 shrink-0">
                    <label class="cursor-pointer bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-lg shadow transition-all inline-flex items-center gap-2">
                        <i class="las la-folder-open text-base"></i>
                        <span>Browse Folder</span>
                        <input type="file" id="folder_input" name="folder_input[]" webkitdirectory directory class="hidden">
                    </label>
                    <label class="cursor-pointer bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-300 text-xs font-bold py-2.5 px-4 rounded-lg transition-all inline-flex items-center gap-2">
                        <i class="las la-file-pdf text-base"></i>
                        <span>Select PDF Files</span>
                        <input type="file" id="file_input" name="file_input[]" multiple accept=".pdf" class="hidden">
                    </label>
                </div>
            </div>
            <p id="scanStatus" class="mt-2 text-xs font-semibold text-indigo-600 min-h-[16px] text-center md:text-left"></p>
        </div>
    </div>

    <!-- QUEUE -->
    <div class="bg-white border border-slate-200/80 rounded-2xl shadow-sm p-4 mb-4">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-2 pb-2 border-b border-slate-100">
            <div>
                <div class="text-xs font-black text-slate-800 uppercase tracking-wider" id="queueHeaderLabel">SELECTED FILES QUEUE (0)</div>
                <div id="queueCountText" class="text-xs font-bold text-indigo-600">0 file(s) loaded</div>
            </div>
            <button type="button" id="clearBtn" class="text-xs text-slate-600 hover:text-rose-600 font-bold border border-slate-300 hover:border-rose-300 rounded-lg px-3 py-1.5 transition">Clear Queue</button>
        </div>

        <div id="fileListContainer" class="min-h-[44px] max-h-44 overflow-y-auto divide-y divide-slate-100 mb-3">
            <p class="text-sm text-slate-500 text-center py-3 italic">No files queued yet.</p>
        </div>

        <div class="flex flex-wrap justify-between items-center gap-3 px-4 py-3 rounded-xl bg-slate-50 border border-slate-200">
            <p class="text-xs text-slate-600 font-medium">Tip: upload and sync run one after the other automatically. Keep this tab open, you will see a live time estimate.</p>
            <button type="button" id="uploadBtn" onclick="startUploadProcess()" class="dm-cta" disabled>
                <span>🚀</span><span id="uploadBtnLabel">Upload &amp; Auto-Sync</span>
            </button>
        </div>
    </div>
    </div><!-- /tabUpload -->

    <!-- RECORDS TAB -->
    <div id="tabRecords" style="display:none;">
        <div class="rc-chart" id="rcChart"></div>

        <div class="rc-bar">
            <input type="search" id="rcSearch" class="rc-input" style="flex:1;min-width:200px;" placeholder="Search filename, account, mobile, billing period…">
            <select id="rcFlag" class="rc-select" title="Filter by status / N/A"></select>
            <select id="rcTelco" class="rc-select"><option value="">All telcos</option></select>
            <select id="rcPer" class="rc-select" title="Rows per page">
                <option value="25">25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option><option value="200">200 / page</option>
            </select>
            <button type="button" class="rc-btn" onclick="recLoad()">↻ Refresh</button>
            <button type="button" id="rcDelSel" class="rc-btn rc-btn-danger" onclick="recAskDelete('selected')" disabled>🗑 Delete selected (0)</button>
        </div>

        <div class="rc-banner" id="rcBanner"></div>

        <div class="rc-wrap">
            <table class="rc-table">
                <thead><tr>
                    <th class="nosort" style="width:30px;"><input type="checkbox" id="rcCheckAll" title="Select all rows on this page"></th>
                    <th data-sort="id">ID</th>
                    <th data-sort="filename">File</th>
                    <th data-sort="account_number">Account</th>
                    <th data-sort="mobile_number">Mobile</th>
                    <th data-sort="telco">Telco</th>
                    <th data-sort="amount_due">Amount</th>
                    <th data-sort="billing_period">Billing period</th>
                    <th data-sort="invoice_date">Invoice</th>
                    <th data-sort="due_date">Due</th>
                    <th class="nosort">Server</th>
                    <th class="nosort">Drive</th>
                    <th class="nosort">Actions</th>
                </tr></thead>
                <tbody id="rcBody"><tr><td colspan="13" style="padding:30px;text-align:center;color:#94a3b8;">Loading…</td></tr></tbody>
            </table>
        </div>

        <div class="rc-bar" style="margin-top:10px;justify-content:space-between;">
            <span id="rcInfo" style="font-size:12px;font-weight:700;color:#64748b;"></span>
            <span id="rcPager" style="display:flex;gap:4px;align-items:center;"></span>
        </div>

        <!-- Edit modal -->
        <div class="rc-modal" id="rcEditModal">
            <div class="rc-box">
                <h3>✏ Edit record</h3>
                <p class="rc-sub" id="rcEditName" style="word-break:break-all;"></p>
                <label class="rc-field">Account number<input id="rcEf_account_number" maxlength="50"></label>
                <label class="rc-field">Mobile number<input id="rcEf_mobile_number" maxlength="50"></label>
                <label class="rc-field">Telco<input id="rcEf_telco" maxlength="50" list="rcTelcoList"></label>
                <datalist id="rcTelcoList"><option value="Smart"><option value="Globe"><option value="Unknown"></datalist>
                <label class="rc-field">Amount due<input id="rcEf_amount_due" maxlength="50"></label>
                <label class="rc-field">Billing period<input id="rcEf_billing_period" maxlength="100"></label>
                <label class="rc-field">Invoice date (YYYY-MM-DD)<input id="rcEf_invoice_date" maxlength="50"></label>
                <label class="rc-field">Due date (YYYY-MM-DD)<input id="rcEf_due_date" maxlength="50"></label>
                <p class="rc-sub" style="margin-top:8px;">Only the database is changed. The PDF stays where it is. Leave a field empty to reset it to N/A.</p>
                <p id="rcEditErr" style="color:#e11d48;font-size:12px;font-weight:700;margin-top:6px;"></p>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                    <button type="button" class="rc-btn" onclick="recCloseEdit()">Cancel</button>
                    <button type="button" id="rcEditSave" class="rc-btn rc-btn-primary" onclick="recSaveEdit()">Save</button>
                </div>
            </div>
        </div>

        <!-- Delete modal -->
        <div class="rc-modal" id="rcDelModal">
            <div class="rc-box">
                <h3 id="rcDelTitle">Delete records</h3>
                <p id="rcDelMsg" style="font-size:13px;color:#475569;margin-top:6px;"></p>
                <div id="rcDelConfirmWrap" style="display:none;">
                    <label class="rc-field">Type DELETE to confirm<input id="rcDelConfirm" autocomplete="off" placeholder="DELETE"></label>
                </div>
                <div class="rc-prog" id="rcDelProgWrap" style="display:none;"><div id="rcDelProg"></div></div>
                <p id="rcDelStatus" style="font-size:12px;font-weight:700;color:#475569;margin-top:6px;"></p>
                <div class="rc-log" id="rcDelLog"></div>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                    <button type="button" id="rcDelCancel" class="rc-btn" onclick="recCloseDelete()">Cancel</button>
                    <button type="button" id="rcDelGo" class="rc-btn rc-btn-danger" onclick="recRunDelete()">Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PROGRESS MODAL -->
<div id="progressModal" style="display:none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-3">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-2xl max-h-[96vh] overflow-y-auto p-5 md:p-6">

        <!-- title -->
        <div class="flex justify-between items-start gap-3 mb-4">
            <div class="flex items-center gap-3 min-w-0">
                <span id="modalSpinnerIcon" class="dm-ring dm-spin" style="width:22px;height:22px;border-width:3px;"></span>
                <div class="min-w-0">
                    <h3 id="modalTitleText" class="text-sm font-black text-slate-800 uppercase tracking-wider truncate">Working…</h3>
                    <p id="modalSubtitle" class="text-[11px] text-slate-400 font-medium truncate"></p>
                </div>
            </div>
            <span id="progressPercentage" class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full text-xs font-black">0%</span>
        </div>

        <!-- stages -->
        <div id="stageSteps" class="flex flex-wrap gap-2 mb-5"></div>

        <!-- bar -->
        <div class="mb-1">
            <div class="w-full bg-slate-100 rounded-full h-3.5 overflow-hidden">
                <div id="progressBar" class="dm-bar-fill h-3.5 rounded-full" style="width:0%"></div>
            </div>
            <div class="flex justify-between items-center mt-2 text-xs font-bold text-slate-500">
                <span id="progressText">Preparing…</span>
                <span id="progressCountDetail">0 / 0</span>
            </div>
        </div>

        <!-- stats -->
        <div class="grid grid-cols-3 gap-3 my-4">
            <div class="rounded-2xl bg-indigo-50/70 border border-indigo-100 p-3 text-center">
                <div class="text-[10px] font-bold uppercase tracking-wider text-indigo-400">Time left</div>
                <div id="statEta" class="text-sm md:text-base font-black text-indigo-700 mt-0.5">Calculating…</div>
            </div>
            <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3 text-center">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Elapsed</div>
                <div id="statElapsed" class="text-sm md:text-base font-black text-slate-700 mt-0.5">0:00</div>
            </div>
            <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3 text-center">
                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Speed</div>
                <div id="statSpeed" class="text-sm md:text-base font-black text-slate-700 mt-0.5">—</div>
            </div>
        </div>

        <!-- counters + heartbeat -->
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3 text-xs font-bold">
            <div class="flex items-center gap-2">
                <span class="bg-emerald-50 text-emerald-700 rounded-full px-2.5 py-1">✓ <span id="statOk">0</span> done</span>
                <span id="statWarnWrap" class="bg-amber-50 text-amber-700 rounded-full px-2.5 py-1" style="display:none;">⚠ <span id="statWarn">0</span> warning</span>
                <span id="statFailWrap" class="bg-rose-50 text-rose-700 rounded-full px-2.5 py-1" style="display:none;">✕ <span id="statFail">0</span> failed</span>
            </div>
            <div class="flex items-center gap-2 text-slate-500">
                <span id="heartbeatDot" class="dm-pulse inline-block w-2.5 h-2.5 rounded-full" style="background:#10b981"></span>
                <span id="heartbeatText">Live</span>
            </div>
        </div>

        <!-- now processing -->
        <div class="mb-3">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Now processing</div>
            <div id="activePanel" class="rounded-2xl border border-slate-100 bg-slate-50/70 p-3 text-xs font-semibold text-slate-600 space-y-1.5 min-h-[44px]">
                <div class="text-slate-400">Waiting…</div>
            </div>
        </div>

        <!-- log -->
        <div class="mb-5">
            <button type="button" id="logToggle" class="text-[11px] font-bold text-slate-400 hover:text-slate-600 mb-1.5">▾ Hide details</button>
            <div id="logContainer" class="border border-slate-800 rounded-2xl p-3 bg-slate-900 text-slate-200 text-xs font-mono h-40 overflow-y-auto">
                <div id="logContent" class="space-y-0.5"></div>
            </div>
        </div>

        <p id="keepOpenHint" class="text-[11px] text-slate-400 font-medium mb-4">Please keep this page open until the process finishes.</p>

        <!-- footer -->
        <div class="flex flex-wrap justify-end gap-3">
            <button type="button" id="stopBtn" onclick="requestStop()" class="bg-white border border-slate-200 hover:border-rose-300 hover:text-rose-600 text-slate-500 font-bold py-2.5 px-5 rounded-xl text-xs transition">Stop after current batch</button>
            <button type="button" id="nextActionBtn" onclick="afterUploadSync()" style="display:none;" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-5 rounded-xl text-xs transition shadow-lg shadow-indigo-100">⚡ Sync &amp; Read PDFs now</button>
            <button type="button" id="closeModalBtn" onclick="closeProgressModal()" style="display:none;" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-6 rounded-xl text-xs transition shadow-lg shadow-emerald-100 items-center gap-2">
                <span>✓</span><span>Done</span>
            </button>
        </div>
    </div>
</div>

<script>
const ENDPOINT = 'debit_memo_upload.php';

// Tuning
const UPLOAD_BATCH_FILES   = 10;               // keep <= PHP max_file_uploads
const UPLOAD_BATCH_BYTES   = 24 * 1024 * 1024; // and well below post_max_size
const UPLOAD_CONCURRENCY   = 3;
const SYNC_BATCH_FILES     = 4;                // files per request (one Drive client per batch)
const SYNC_CONCURRENCY     = 4;
const REQUEST_TIMEOUT_MS   = 10 * 60 * 1000;
const LOG_MAX_LINES        = 400;
const QUEUE_RENDER_LIMIT   = 150;

let fileQueue = [];
let queueKeys = new Set();
let busy = false;

const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtBytes = b => b === 0 ? '0 KB' : b >= 1073741824 ? (b/1073741824).toFixed(2)+' GB' : b >= 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.max(1, Math.round(b/1024))+' KB';
function fmtClock(ms) { const s = Math.floor(ms/1000), m = Math.floor(s/60); return m >= 60 ? `${Math.floor(m/60)}:${String(m%60).padStart(2,'0')}:${String(s%60).padStart(2,'0')}` : `${m}:${String(s%60).padStart(2,'0')}`; }
function fmtDur(ms) {
    const s = Math.round(ms/1000);
    if (s < 5) return 'a few seconds';
    if (s < 60) return s + ' sec';
    const m = Math.floor(s/60);
    if (m < 60) return `${m} min ${String(s%60).padStart(2,'0')} sec`;
    return `${Math.floor(m/60)} h ${m%60} min`;
}

/* =====================  QUEUE + DRAG & DROP  ===================== */
document.addEventListener('DOMContentLoaded', () => {
    const folderInput = $('folder_input'), fileInput = $('file_input'), dropZone = $('dropZone');

    ['dragenter','dragover'].forEach(evt => dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dm-drop-active'); }));
    ['dragleave','drop'].forEach(evt => dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dm-drop-active'); }));

    dropZone.addEventListener('drop', async e => {
        // IMPORTANT: collect entries synchronously - the DataTransfer list is emptied after the first await
        const dt = e.dataTransfer, entries = [], loose = [];
        if (dt.items && dt.items.length) {
            for (let i = 0; i < dt.items.length; i++) {
                const it = dt.items[i];
                if (it.kind !== 'file') continue;
                const entry = it.webkitGetAsEntry ? it.webkitGetAsEntry() : null;
                if (entry) entries.push(entry); else { const f = it.getAsFile(); if (f) loose.push(f); }
            }
        } else if (dt.files) { loose.push(...dt.files); }

        const stats = { added: 0, dup: 0, skipped: 0 };
        setScan('Reading dropped items…');
        loose.forEach(f => addFile(f, stats));
        for (const entry of entries) await walkEntry(entry, stats);
        updateQueueUI();
        finishScan(stats);
    });

    dropZone.addEventListener('click', e => { if (!e.target.closest('label,button')) fileInput.click(); });
    folderInput.addEventListener('change', e => { handleFiles(e.target.files); folderInput.value = ''; });
    fileInput.addEventListener('change',   e => { handleFiles(e.target.files); fileInput.value = ''; });
    $('clearBtn').addEventListener('click', () => { if (busy) return; fileQueue = []; queueKeys.clear(); updateQueueUI(); setScan(''); });
    $('fileListContainer').addEventListener('click', e => {
        const b = e.target.closest('[data-remove]');
        if (!b || busy) return;
        fileQueue.splice(parseInt(b.dataset.remove, 10), 1);
        queueKeys = new Set(fileQueue.map(f => f.name + '|' + f.size));
        updateQueueUI();
    });
    $('logToggle').addEventListener('click', () => {
        const box = $('logContainer'), hidden = box.style.display === 'none';
        box.style.display = hidden ? '' : 'none';
        $('logToggle').textContent = hidden ? '▾ Hide details' : '▸ Show details';
    });
});

async function walkEntry(entry, stats) {
    if (entry.isFile) {
        const file = await new Promise(res => entry.file(res, () => res(null)));
        if (file) addFile(file, stats);
    } else if (entry.isDirectory) {
        const reader = entry.createReader();
        let batch;
        do {   // readEntries returns max ~100 items per call - loop until empty
            batch = await new Promise(res => reader.readEntries(res, () => res([])));
            await Promise.all(batch.map(en => walkEntry(en, stats)));
            setScan(`Scanning folder… ${stats.added} PDF(s) found`);
        } while (batch.length > 0);
    }
}

function addFile(file, stats) {
    if (!file.name.toLowerCase().endsWith('.pdf')) { stats.skipped++; return; }
    const key = file.name + '|' + file.size;
    if (queueKeys.has(key)) { stats.dup++; return; }
    queueKeys.add(key); fileQueue.push(file); stats.added++;
}
function handleFiles(files) {
    const stats = { added: 0, dup: 0, skipped: 0 };
    Array.from(files).forEach(f => addFile(f, stats));
    updateQueueUI(); finishScan(stats);
}
function setScan(t) { $('scanStatus').textContent = t; }
function finishScan(s) {
    const parts = [`Added ${s.added} PDF(s)`];
    if (s.dup) parts.push(`${s.dup} duplicate(s) skipped`);
    if (s.skipped) parts.push(`${s.skipped} non-PDF file(s) ignored`);
    setScan(s.added || s.dup || s.skipped ? parts.join(' · ') : 'No PDF files found in that selection.');
}

function updateQueueUI() {
    const total = fileQueue.reduce((a, f) => a + f.size, 0);
    $('queueCountText').textContent = `${fileQueue.length} file(s) · ${fmtBytes(total)}`;
    $('queueHeaderLabel').textContent = `SELECTED FILES QUEUE (${fileQueue.length})`;
    const box = $('fileListContainer'), btn = $('uploadBtn');

    if (!fileQueue.length) {
        box.innerHTML = '<p class="text-sm text-slate-500 text-center py-3 italic">No files queued yet.</p>';
        btn.setAttribute('disabled', 'true');
        $('uploadBtnLabel').textContent = 'Upload & Auto-Sync';
        return;
    }
    btn.removeAttribute('disabled');
    $('uploadBtnLabel').textContent = `Upload & Auto-Sync (${fileQueue.length})`;
    let html = '';
    fileQueue.slice(0, QUEUE_RENDER_LIMIT).forEach((f, i) => {
        html += `<div class="py-1.5 flex justify-between items-center gap-3 text-[13px] font-semibold text-slate-800">
            <span class="truncate flex items-center gap-2"><span class="text-rose-500">📄</span><span class="truncate">${i + 1}. ${esc(f.name)}</span></span>
            <span class="flex items-center gap-3 shrink-0"><span class="text-slate-500">${fmtBytes(f.size)}</span>
            <button type="button" data-remove="${i}" class="text-slate-300 hover:text-rose-500 font-black" title="Remove">✕</button></span>
        </div>`;
    });
    if (fileQueue.length > QUEUE_RENDER_LIMIT) {
        html += `<div class="py-3 text-center text-xs font-bold text-slate-400">+ ${fileQueue.length - QUEUE_RENDER_LIMIT} more file(s) not shown</div>`;
    }
    box.innerHTML = html;
}

/* =====================  NETWORK HELPERS  ===================== */
function postForm(url, fd, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.timeout = REQUEST_TIMEOUT_MS;
        if (onProgress) xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded, e.total); };
        xhr.onload = () => {
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (_) {}
            if (xhr.status >= 200 && xhr.status < 300 && json && json.status !== 'error') return resolve(json);
            const err = new Error((json && json.message) || (xhr.status >= 200 && xhr.status < 300 ? 'Unexpected server response (session expired? reload the page)' : `HTTP ${xhr.status}`));
            err.retryable = xhr.status >= 500;     // 4xx / bad JSON will not get better by retrying
            reject(err);
        };
        xhr.onerror   = () => { const e = new Error('Network error'); e.retryable = true; reject(e); };
        xhr.ontimeout = () => { const e = new Error('Request timed out'); e.retryable = true; reject(e); };
        xhr.send(fd);
    });
}

async function postWithRetry(url, fd, opts = {}) {
    const retries = opts.retries || 3, delay = opts.delay || 1500;
    for (let attempt = 1; ; attempt++) {
        try { return await postForm(url, fd, opts.onProgress); }
        catch (err) {
            if (!err.retryable || attempt >= retries) throw err;
            if (opts.onRetry) opts.onRetry(attempt, err);
            await new Promise(r => setTimeout(r, delay * attempt));
        }
    }
}
const actionForm = action => { const fd = new FormData(); fd.append('action', action); return fd; };

/* =====================  PROGRESS MODAL ENGINE  ===================== */
const run = { active:false, finished:false, kind:'', start:0, workStart:0, totalUnits:0, doneUnits:0, totalFiles:0, doneFiles:0,
              ok:0, warn:0, fail:0, lastActivity:0, samples:[], cancel:false, stages:[], stage:0, indeterminate:false, countText:'',
              activeItems:new Map(), ticker:null, etaShown:null, lastActiveHtml:'' };
const logQueue = [];
const LOG_CLASS = { ok:'text-emerald-400', err:'text-rose-400', warn:'text-amber-300', info:'text-indigo-300', mute:'text-slate-400' };
const guardUnload = e => { e.preventDefault(); e.returnValue = ''; };

function log(type, text) { logQueue.push([LOG_CLASS[type] || '', text]); }
function flushLog() {
    if (!logQueue.length) return;
    const box = $('logContent'), frag = document.createDocumentFragment();
    logQueue.splice(0).forEach(([cls, text]) => { const d = document.createElement('div'); d.className = cls; d.textContent = text; frag.appendChild(d); });
    box.appendChild(frag);
    while (box.childElementCount > LOG_MAX_LINES) box.removeChild(box.firstChild);
    const c = $('logContainer'); c.scrollTop = c.scrollHeight;
}

function openRun(o) {
    Object.assign(run, { active:true, finished:false, kind:o.kind, start:Date.now(), workStart:Date.now(), totalUnits:o.totalUnits || 0, doneUnits:0,
        totalFiles:o.totalFiles || 0, doneFiles:0, ok:0, warn:0, fail:0, lastActivity:Date.now(), samples:[], cancel:false,
        stages:o.stages, stage:0, countText:'', indeterminate:!!o.indeterminate, etaShown:null, lastActiveHtml:'' });
    run.activeItems.clear(); logQueue.length = 0;
    $('logContent').innerHTML = '';
    $('modalTitleText').textContent = o.title;
    $('modalSubtitle').textContent = o.subtitle || '';
    $('progressText').textContent = 'Preparing…';
    $('progressCountDetail').textContent = `0 / ${run.totalFiles}`;
    const bar = $('progressBar'); bar.className = 'dm-bar-fill h-3.5 rounded-full'; bar.style.backgroundImage = ''; bar.style.width = '0%';
    if (run.indeterminate) bar.classList.add('dm-indeterminate');
    $('progressPercentage').textContent = '0%';
    $('modalSpinnerIcon').style.display = 'inline-block';
    $('statWarnWrap').style.display = 'none'; $('statFailWrap').style.display = 'none';
    $('stopBtn').style.display = ''; $('stopBtn').disabled = false; $('stopBtn').textContent = 'Stop after current batch';
    $('closeModalBtn').style.display = 'none'; $('nextActionBtn').style.display = 'none';
    $('keepOpenHint').style.display = '';
    $('progressModal').style.display = 'flex';
    window.addEventListener('beforeunload', guardUnload);
    clearInterval(run.ticker); run.ticker = setInterval(tick, 300);
    tick();
}

function setStage(i, text) { run.stage = i; if (text) $('progressText').textContent = text; renderStages(); }
function noteProgress(units) {
    const now = Date.now();
    run.doneUnits = units; run.lastActivity = now;
    run.samples.push([now, units]);
    while (run.samples.length > 2 && now - run.samples[0][0] > 30000) run.samples.shift();
}
function rateNow() {      // units per second
    const elapsed = (Date.now() - run.workStart) / 1000;
    if (run.doneUnits <= 0 || elapsed < 1.5) return null;
    let rate = run.doneUnits / elapsed;
    const s = run.samples;
    if (s.length >= 2) {
        const dt = (s[s.length-1][0] - s[0][0]) / 1000, du = s[s.length-1][1] - s[0][1];
        if (dt >= 3 && du > 0) rate = 0.5 * rate + 0.5 * (du / dt);   // blend overall average with recent speed
    }
    return rate;
}

function renderStages() {
    $('stageSteps').innerHTML = run.stages.map((label, i) => {
        const done = run.finished || i < run.stage, cur = !run.finished && i === run.stage;
        const cls = done ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : cur ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-slate-50 text-slate-400 border-slate-200';
        const icon = done ? '✓' : cur ? '<span class="dm-pulse inline-block w-2 h-2 rounded-full bg-indigo-500"></span>' : (i + 1);
        return `<span class="inline-flex items-center gap-1.5 border rounded-full px-3 py-1 text-[11px] font-bold ${cls}">${icon}<span>${label}</span></span>`;
    }).join('');
}

function tick() { flushLog(); render(); }
function render() {
    if (!run.active && !run.finished) return;
    const now = Date.now();
    let pct = run.totalUnits > 0 ? Math.floor(run.doneUnits / run.totalUnits * 100) : 0;
    if (run.finished) pct = run.finalPct != null ? run.finalPct : pct; else pct = Math.min(99, pct);
    if (!run.indeterminate) $('progressBar').style.width = pct + '%';
    $('progressPercentage').textContent = run.indeterminate ? '…' : pct + '%';
    $('progressCountDetail').textContent = run.countText || `${run.doneFiles} / ${run.totalFiles} file(s)`;
    $('statElapsed').textContent = fmtClock(now - run.start);
    $('statOk').textContent = run.ok;
    $('statWarn').textContent = run.warn; $('statWarnWrap').style.display = run.warn ? '' : 'none';
    $('statFail').textContent = run.fail; $('statFailWrap').style.display = run.fail ? '' : 'none';

    // ETA + speed
    const rate = rateNow();
    if (run.finished) { $('statEta').textContent = 'Done'; }
    else if (run.indeterminate || rate === null) { $('statEta').textContent = 'Calculating…'; }
    else {
        const est = Math.max(0, (run.totalUnits - run.doneUnits) / rate * 1000);
        run.etaShown = run.etaShown === null ? est : run.etaShown * 0.8 + est * 0.2;   // smooth jumps
        $('statEta').textContent = '~ ' + fmtDur(run.etaShown);
    }
    $('statSpeed').textContent = rate === null ? '—' : (run.kind === 'upload' ? (rate / 1048576).toFixed(1) + ' MB/s' : (rate * 60).toFixed(1) + ' files/min');

    // heartbeat: tells the user the process is alive, not hung
    const idle = (now - run.lastActivity) / 1000, dot = $('heartbeatDot'), txt = $('heartbeatText');
    if (run.finished) { dot.style.background = '#94a3b8'; dot.classList.remove('dm-pulse'); txt.textContent = 'Finished'; }
    else if (idle < 10)  { dot.style.background = '#10b981'; txt.textContent = 'Live · working normally'; }
    else if (idle < 30)  { dot.style.background = '#f59e0b'; txt.textContent = `Still working… last update ${Math.floor(idle)}s ago`; }
    else                 { dot.style.background = '#f97316'; txt.textContent = `Large files take time (${Math.floor(idle)}s). The server is still working. Please wait.`; }

    // active items
    const items = Array.from(run.activeItems.values());
    const html = items.length
        ? items.slice(0, 4).map(t => `<div class="flex items-center gap-2"><span class="dm-ring dm-spin"></span><span class="truncate">${esc(t)}</span></div>`).join('') + (items.length > 4 ? `<div class="text-slate-400">+ ${items.length - 4} more in progress…</div>` : '')
        : `<div class="text-slate-400">${run.finished ? 'Nothing running.' : 'Waiting for the server…'}</div>`;
    if (html !== run.lastActiveHtml) { $('activePanel').innerHTML = html; run.lastActiveHtml = html; }
}

function finishRun(kind, message, finalPct) {
    run.active = false; run.finished = true; run.indeterminate = false; run.finalPct = finalPct;
    run.activeItems.clear(); busy = false;
    clearInterval(run.ticker);
    window.removeEventListener('beforeunload', guardUnload);
    const bar = $('progressBar'); bar.classList.remove('dm-indeterminate'); bar.classList.add('dm-static');
    bar.style.backgroundImage = kind === 'success' ? 'linear-gradient(90deg,#059669,#10b981)' : kind === 'error' ? 'linear-gradient(90deg,#e11d48,#fb7185)' : 'linear-gradient(90deg,#d97706,#fbbf24)';
    if (finalPct == null) run.finalPct = run.totalUnits > 0 ? Math.floor(run.doneUnits / run.totalUnits * 100) : 100;
    if (kind === 'success' && run.totalUnits > 0 && run.doneUnits >= run.totalUnits) run.finalPct = 100;
    bar.style.width = (run.indeterminate ? 100 : run.finalPct) + '%';
    $('progressText').textContent = message;
    $('modalSpinnerIcon').style.display = 'none';
    $('stopBtn').style.display = 'none'; $('keepOpenHint').style.display = 'none';
    $('closeModalBtn').style.display = 'inline-flex';
    renderStages(); flushLog(); render();
}
function requestStop() { run.cancel = true; $('stopBtn').disabled = true; $('stopBtn').textContent = 'Stopping…'; log('warn', '[STOP] Finishing running batches, then stopping…'); }
function closeProgressModal() { $('progressModal').style.display = 'none'; }
function afterUploadSync() { closeProgressModal(); startSyncProcess(); }

/* =====================  UPLOAD  ===================== */
function makeUploadBatches(files) {
    const batches = []; let cur = [], bytes = 0;
    files.forEach(f => {
        if (cur.length && (cur.length >= UPLOAD_BATCH_FILES || bytes + f.size > UPLOAD_BATCH_BYTES)) { batches.push(cur); cur = []; bytes = 0; }
        cur.push(f); bytes += f.size;
    });
    if (cur.length) batches.push(cur);
    return batches;
}

async function startUploadProcess() {
    if (busy || !fileQueue.length) return;
    busy = true;
    const files = fileQueue.slice();
    const totalBytes = files.reduce((a, f) => a + f.size, 0);

    openRun({ kind:'upload', title:'Uploading statements', subtitle:'Saving files to the server. Google Drive is handled in the Sync step.',
              stages:['Preparing', 'Uploading', 'Finished'], totalUnits:Math.max(totalBytes, 1), totalFiles:files.length });
    setStage(0, 'Preparing batches…');

    const batches = makeUploadBatches(files);
    log('info', `[START] ${files.length} file(s) · ${fmtBytes(totalBytes)} · ${batches.length} batch(es)`);
    setStage(1, 'Uploading…');

    const saved = new Set(), loaded = new Map();
    let bytesDone = 0, next = 0;
    const refresh = () => { let live = 0; loaded.forEach(v => live += v); noteProgress(Math.min(totalBytes, bytesDone + live)); };

    async function worker() {
        while (next < batches.length && !run.cancel) {
            const idx = next++, batch = batches[idx];
            const batchBytes = batch.reduce((a, f) => a + f.size, 0);
            run.activeItems.set(idx, `Batch ${idx + 1}/${batches.length} · ${batch.length} file(s) · ${fmtBytes(batchBytes)}`);
            loaded.set(idx, 0);
            const fd = new FormData();
            batch.forEach(f => fd.append('pdf_files[]', f, f.name));
            try {
                const data = await postWithRetry(ENDPOINT, fd, {
                    onProgress: (l) => { loaded.set(idx, Math.min(batchBytes, l)); refresh(); },
                    onRetry: (n, err) => { loaded.set(idx, 0); refresh(); log('warn', `[RETRY ${n}] Batch ${idx + 1}: ${err.message}`); }
                });
                (data.success_files || []).forEach(n => { saved.add(n); run.ok++; log('ok', `[SAVED] ${n}`); });
                (data.failed_files || []).forEach(x => { run.fail++; log('err', `[FAILED] ${x.name}: ${x.reason}`); });
            } catch (e) {
                run.fail += batch.length;
                log('err', `[ERROR] Batch ${idx + 1} failed: ${e.message}`);
            }
            loaded.delete(idx); bytesDone += batchBytes; run.doneFiles += batch.length;
            run.activeItems.delete(idx); refresh();
        }
    }
    await Promise.all(Array.from({ length: Math.min(UPLOAD_CONCURRENCY, batches.length) }, worker));

    // keep anything that did not make it, so the user can simply press upload again
    fileQueue = files.filter(f => !saved.has(f.name));
    queueKeys = new Set(fileQueue.map(f => f.name + '|' + f.size));
    updateQueueUI();
    setScan(fileQueue.length ? `${fileQueue.length} file(s) not uploaded. Press the Upload button to try again.` : '✓ Upload finished. Files are now synced and read automatically.');

    const took = fmtClock(Date.now() - run.start);
    const uploaded = run.ok, failed = run.fail;
    if (run.cancel) {
        finishRun('warning', `Stopped. ${uploaded} uploaded, ${fileQueue.length} still in the queue.`);
        if (uploaded > 0) $('nextActionBtn').style.display = '';      // manual Sync still available after a stop
    } else if (uploaded > 0) {
        // AUTOMATIC: go straight to Sync & Read, the user does not need to click anything
        const notes = [['ok', `[UPLOAD] ${uploaded} file(s) uploaded in ${took}. Starting Sync & Read automatically…`]];
        if (failed > 0) notes.push(['warn', `[UPLOAD] ${failed} file(s) failed and stay in the queue so you can upload them again.`]);
        busy = false;
        return startSyncProcess(notes);
    } else {
        finishRun('warning', `Nothing was uploaded: ${failed} file(s) failed (kept in the queue).`);
    }
}

/* =====================  SYNC  ===================== */
async function startSyncProcess(notes) {
    if (busy) return;
    busy = true;

    openRun({ kind:'sync', title:'Sync & read statements', subtitle:'Reads each PDF, files it by telco / year / period and mirrors it to Google Drive.',
              stages:['Scan folders', 'Read & organize', 'Finished'], indeterminate:true });
    setStage(0, 'Scanning Google Drive and the local sync folder…');
    $('keepOpenHint').textContent = 'Scanning can take a little while on big folders. Please keep this page open.';
    if (Array.isArray(notes)) notes.forEach(n => log(n[0], n[1]));
    log('info', '[1/2] Checking Google Drive and uploads/sync …');

    let init;
    try {
        init = await postWithRetry(ENDPOINT, actionForm('get_sync_queue'));
    } catch (e) {
        log('err', `[ERROR] Could not build the sync queue: ${e.message}`);
        return finishRun('error', 'Could not scan for files: ' + e.message, 0);
    }
    if (init.drive_warning) log('warn', `[DRIVE] ${init.drive_warning}`);

    const queue = init.queue || [];
    if (!queue.length) {
        log('mute', '[INFO] No pending statement files found.');
        run.totalUnits = 1; run.doneUnits = 1;
        return finishRun('success', 'Nothing to sync. No pending PDFs found.', 100);
    }

    log('info', `[2/2] Found ${queue.length} file(s)` + (init.to_download ? ` (${init.to_download} will be downloaded from Drive)` : '') + '. Processing in parallel…');
    run.indeterminate = false; $('progressBar').classList.remove('dm-indeterminate');
    run.totalUnits = queue.length; run.totalFiles = queue.length; run.doneUnits = 0; run.doneFiles = 0;
    run.workStart = Date.now(); run.samples = []; run.etaShown = null; run.lastActivity = Date.now();
    setStage(1, 'Reading and organizing files…');

    const batches = [];
    for (let i = 0; i < queue.length; i += SYNC_BATCH_FILES) batches.push(queue.slice(i, i + SYNC_BATCH_FILES));

    const callBatch = items => {
        const fd = actionForm('process_sync_batch');
        fd.append('items', JSON.stringify(items.map(x => ({ name: x.name, drive_id: x.drive_id }))));
        return postWithRetry(ENDPOINT, fd, { retries: items.length === 1 ? 2 : 3 }).then(d => d.results || []);
    };
    const handle = r => {
        run.doneFiles++;
        if (r.status === 'success') {
            if (r.skipped) log('mute', `[SKIPPED] ${r.name} (already processed)`);
            else { run.ok++; log('ok', `[DONE] ${r.name} · ${r.telco} · ${r.account}`); }
            if (r.warning) { run.warn++; log('warn', `[WARN] ${r.name}: ${r.warning}`); }
        } else { run.fail++; log('err', `[ERROR] ${r.name}: ${r.message}`); }
        noteProgress(run.doneFiles);
    };

    let next = 0;
    async function worker() {
        while (next < batches.length && !run.cancel) {
            const batch = batches[next++];
            batch.forEach(x => run.activeItems.set(x.name, x.name));
            let results;
            try { results = await callBatch(batch); }
            catch (e) {
                if (batch.length > 1) {         // isolate a problem file instead of failing the whole batch
                    log('warn', `[RETRY] Batch failed (${e.message}). Processing its files one by one…`);
                    results = [];
                    for (const it of batch) {
                        try { results.push(...await callBatch([it])); }
                        catch (e2) { results.push({ name: it.name, status: 'error', message: e2.message }); }
                    }
                } else results = [{ name: batch[0].name, status: 'error', message: e.message }];
            }
            results.forEach(handle);
            batch.forEach(x => run.activeItems.delete(x.name));
        }
    }
    await Promise.all(Array.from({ length: Math.min(SYNC_CONCURRENCY, batches.length) }, worker));

    const took = fmtClock(Date.now() - run.start);
    if (run.cancel) {
        finishRun('warning', `Stopped. ${run.doneFiles} of ${queue.length} processed. Run Sync again to continue with the rest.`);
    } else if (run.fail > 0 || run.warn > 0) {
        log('warn', `[FINISHED] ${run.ok} processed, ${run.fail} failed, ${run.warn} warning(s). Failed files stay in uploads/sync for the next run.`);
        finishRun('warning', `Finished in ${took}: ${run.ok} processed, ${run.fail} failed, ${run.warn} warning(s).`);
    } else {
        log('ok', `[FINISHED] All ${run.ok} file(s) processed and organized.`);
        finishRun('success', `All ${run.ok} file(s) processed in ${took}.`, 100);
    }
}

/* =====================  FULL MIRROR: Google Drive -> server + database  ===================== */
const MIRROR_BATCH_FILES = 5, MIRROR_CONCURRENCY = 4, MIRROR_SCAN_FOLDERS = 5, MIRROR_SCAN_CONCURRENCY = 3;
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function startMirrorProcess() {
    if (busy) return;
    if (!confirm('Full mirror will compare ALL files on Google Drive with this server, download the missing ones and read their data into the database.\n\nNothing is deleted. Continue?')) return;
    busy = true;
    const force = !!($('mirrorForce') && $('mirrorForce').checked);

    openRun({ kind:'mirror', title:'Mirror Google Drive to server', subtitle:'Same folders as Drive. Downloads only what is missing and reads PDF data into the database.',
              stages:['Scan Drive', 'Download & read', 'Finished'], indeterminate:true });
    setStage(0, 'Connecting to Google Drive…');
    $('keepOpenHint').textContent = 'Big drives take a while. It is safe to run this again anytime: it only handles what is missing.';
    log('info', '[1/3] Connecting to Google Drive…');

    let init;
    try { init = await postWithRetry(ENDPOINT, actionForm('mirror_init')); }
    catch (e) { log('err', `[ERROR] ${e.message}`); return finishRun('error', 'Could not connect: ' + e.message, 0); }

    log('info', `[1/3] Scanning Drive folder "${init.root_name}" (all sub-folders)…`);
    setStage(0, 'Scanning Drive folders…');

    // ---- Stage 1: walk the folder tree (browser-driven, so progress is visible) ----
    const folderQueue = [{ id: init.root.id, path: '' }];
    const files = [];
    let foldersDone = 0, foldersSeen = 1, scanFail = 0, scanActive = 0;

    async function scanWorker() {
        while (!run.cancel) {
            if (!folderQueue.length) { if (scanActive === 0) return; await sleep(100); continue; }
            const take = folderQueue.splice(0, MIRROR_SCAN_FOLDERS);
            const key = 'scan-' + take[0].id;
            scanActive++;
            run.activeItems.set(key, 'Scanning: ' + (take[0].path || '(root)') + (take.length > 1 ? ` +${take.length - 1} more` : ''));
            try {
                const fd = actionForm('mirror_scan_step');
                fd.append('folders', JSON.stringify(take));
                const data = await postWithRetry(ENDPOINT, fd);
                for (const r of (data.results || [])) {
                    if (r.error) {
                        const orig = take.find(t => t.id === r.id) || { id: r.id, path: r.path };
                        if ((orig.tries || 0) < 1) folderQueue.push({ id: orig.id, path: orig.path, tries: (orig.tries || 0) + 1 });
                        else { scanFail++; log('err', `[SCAN ERROR] ${r.path || '(root)'}: ${r.error}`); }
                        continue;
                    }
                    foldersDone++;
                    (r.folders || []).forEach(sf => { folderQueue.push({ id: sf.id, path: r.path ? r.path + '/' + sf.name : sf.name }); foldersSeen++; });
                    (r.files || []).forEach(f => files.push({ id: f.id, name: f.name, size: f.size, link: f.link, path: r.path }));
                }
            } catch (e) {
                scanFail += take.length;
                log('err', `[SCAN ERROR] ${e.message}`);
            } finally {
                scanActive--;
                run.activeItems.delete(key);
                run.lastActivity = Date.now();
                run.countText = `${foldersDone}/${foldersSeen} folders · ${files.length} PDF(s) found`;
                $('progressText').textContent = `Scanning Drive… ${files.length} PDF(s) found`;
            }
        }
    }
    await Promise.all(Array.from({ length: MIRROR_SCAN_CONCURRENCY }, scanWorker));

    run.activeItems.clear();
    run.countText = '';
    if (run.cancel) return finishRun('warning', 'Stopped during the scan. Nothing was downloaded.');
    if (scanFail) log('warn', `[SCAN] ${scanFail} folder(s) could not be read. Run the mirror again to catch them.`);
    if (!files.length) {
        run.totalUnits = 1; run.doneUnits = 1;
        return finishRun(scanFail ? 'warning' : 'success', 'No PDF files found on Drive.', 100);
    }

    // ---- Stage 2: download + read ----
    log('info', `[2/3] Found ${files.length} PDF(s) in ${foldersDone} folder(s). Downloading what is missing and reading data…` + (force ? ' (force re-read ON)' : ''));
    run.indeterminate = false; $('progressBar').classList.remove('dm-indeterminate');
    run.totalUnits = files.length; run.totalFiles = files.length; run.doneUnits = 0; run.doneFiles = 0;
    run.workStart = Date.now(); run.samples = []; run.etaShown = null; run.lastActivity = Date.now();
    setStage(1, 'Downloading and reading files…');

    const batches = [];
    for (let i = 0; i < files.length; i += MIRROR_BATCH_FILES) batches.push(files.slice(i, i + MIRROR_BATCH_FILES));

    const st = { downloaded: 0, read: 0, skipped: 0 };
    const callBatch = items => {
        const fd = actionForm('mirror_batch');
        fd.append('items', JSON.stringify(items));
        fd.append('force', force ? '1' : '0');
        return postWithRetry(ENDPOINT, fd, { retries: items.length === 1 ? 2 : 3 }).then(d => d.results || []);
    };
    const handle = r => {
        run.doneFiles++;
        if (r.status === 'success') {
            if (r.skipped) st.skipped++;
            else {
                run.ok++;
                if (r.downloaded) st.downloaded++;
                if (r.read) st.read++;
                const tag = r.downloaded ? (r.read ? 'DOWNLOADED + READ' : 'DOWNLOADED') : 'UPDATED';
                log('ok', `[${tag}] ${r.name}` + (r.read ? ` · ${r.telco} · ${r.account}` : ''));
            }
            if (r.warning) { run.warn++; log('warn', `[WARN] ${r.name}: ${r.warning}`); }
        } else { run.fail++; log('err', `[ERROR] ${r.name}: ${r.message}`); }
        noteProgress(run.doneFiles);
    };

    let next = 0;
    async function worker() {
        while (next < batches.length && !run.cancel) {
            const batch = batches[next++];
            batch.forEach(x => run.activeItems.set(x.id, (x.path ? x.path + '/' : '') + x.name));
            let results;
            try { results = await callBatch(batch); }
            catch (e) {
                if (batch.length > 1) {
                    log('warn', `[RETRY] Batch failed (${e.message}). Processing its files one by one…`);
                    results = [];
                    for (const it of batch) {
                        try { results.push(...await callBatch([it])); }
                        catch (e2) { results.push({ name: it.name, status: 'error', message: e2.message }); }
                    }
                } else results = [{ name: batch[0].name, status: 'error', message: e.message }];
            }
            results.forEach(handle);
            batch.forEach(x => run.activeItems.delete(x.id));
        }
    }
    await Promise.all(Array.from({ length: Math.min(MIRROR_CONCURRENCY, batches.length) }, worker));

    // ---- Stage 3: report files that are on the server but not on Drive ----
    if (!run.cancel) {
        setStage(2, 'Comparing server folders with Drive…');
        if (scanFail) {
            log('mute', '[CHECK] Skipped the "not on Drive" comparison because some folders could not be scanned.');
        } else {
            try {
                const inv = await postWithRetry(ENDPOINT, actionForm('mirror_local_inventory'));
                const driveSet = new Set(files.map(f => ((f.path ? f.path + '/' : '') + f.name).toLowerCase()));
                const extra = (inv.files || []).filter(r => !driveSet.has(r.toLowerCase()));
                if (extra.length) {
                    log('warn', `[LOCAL ONLY] ${extra.length} PDF(s) exist on the server but not on Drive (nothing was deleted):`);
                    extra.slice(0, 20).forEach(r => log('mute', '   ' + r));
                    if (extra.length > 20) log('mute', `   …and ${extra.length - 20} more`);
                } else log('ok', '[CHECK] Server and Drive have the same set of PDF files.');
            } catch (e) { log('warn', `[CHECK] Could not list server files: ${e.message}`); }
        }
    }

    const took = fmtClock(Date.now() - run.start);
    const summary = `${st.downloaded} downloaded, ${st.read} read into database, ${st.skipped} already up to date` + (run.fail ? `, ${run.fail} failed` : '');
    log(run.fail || run.warn ? 'warn' : 'ok', `[FINISHED] ${summary}`);
    if (run.cancel) finishRun('warning', `Stopped. ${run.doneFiles} of ${files.length} checked (${summary}). Run again to continue.`);
    else if (run.fail || run.warn || scanFail) finishRun('warning', `Finished in ${took}: ${summary}` + (run.warn ? `, ${run.warn} warning(s)` : '') + '.');
    else finishRun('success', `Finished in ${took}: ${summary}.`, 100);
}

/* =====================  TABS  ===================== */
function showTab(name) {
    const isRec = name === 'records';
    $('tabUpload').style.display  = isRec ? 'none' : '';
    $('tabRecords').style.display = isRec ? '' : 'none';
    $('tabBtnUpload').classList.toggle('active', !isRec);
    $('tabBtnRecords').classList.toggle('active', isRec);
    const wrap = $('dmWrap');
    if (wrap) wrap.style.maxWidth = isRec ? '1500px' : '';
    try { history.replaceState(null, '', isRec ? '#records' : '#upload'); } catch (_) {}
    if (isRec) recLoad();
}

/* =====================  RECORDS TAB  ===================== */
const REC_CARDS = [
    ['',              'total',         'All records',      '#64748b', '#f1f5f9'],
    ['complete',      'complete',      'Complete',         '#10b981', '#ecfdf5'],
    ['any_na',        'any_na',        'Has N/A / missing','#f59e0b', '#fffbeb'],
    ['na_account',    'na_account',    'N/A account',      '#f59e0b', '#fffbeb'],
    ['na_mobile',     'na_mobile',     'N/A mobile',       '#f59e0b', '#fffbeb'],
    ['unknown_telco', 'unknown_telco', 'Unknown telco',    '#f59e0b', '#fffbeb'],
    ['zero_amount',   'zero_amount',   'Amount 0.00',      '#f59e0b', '#fffbeb'],
    ['na_billing',    'na_billing',    'N/A billing period','#f59e0b','#fffbeb'],
    ['na_invoice',    'na_invoice',    'N/A invoice date', '#f59e0b', '#fffbeb'],
    ['na_due',        'na_due',        'N/A due date',     '#f59e0b', '#fffbeb'],
    ['pending',       'pending',       'Pending sync',     '#0ea5e9', '#f0f9ff'],
    ['no_link',       'no_link',       'No Drive link',    '#e11d48', '#fff1f2'],
];
const rec = { page:1, per:25, q:'', telco:'', flag:'', sort:'updated_at', dir:'desc',
              selected:new Set(), allMatching:false, total:0, pages:1, rows:[], counts:{}, loading:false, editId:null, delIds:null, delMode:'', deleting:false, cancel:false };
const fmtN = n => Number(n || 0).toLocaleString();
const recIsNa = v => v === null || v === undefined || String(v).trim() === '' || String(v).trim().toUpperCase() === 'N/A';

function recForm(action) {
    const fd = actionForm(action);
    fd.append('q', rec.q); fd.append('telco', rec.telco); fd.append('flag', rec.flag);
    return fd;
}

let recSearchTimer = null;
document.addEventListener('DOMContentLoaded', () => {
    $('rcSearch').addEventListener('input', e => { clearTimeout(recSearchTimer); recSearchTimer = setTimeout(() => { rec.q = e.target.value.trim(); rec.page = 1; recClearSel(); recLoad(); }, 350); });
    $('rcFlag').addEventListener('change', e => { rec.flag = e.target.value; rec.page = 1; recClearSel(); recLoad(); });
    $('rcTelco').addEventListener('change', e => { rec.telco = e.target.value; rec.page = 1; recClearSel(); recLoad(); });
    $('rcPer').addEventListener('change', e => { rec.per = parseInt(e.target.value, 10) || 25; rec.page = 1; recLoad(); });
    $('rcCheckAll').addEventListener('change', e => {
        rec.rows.forEach(r => e.target.checked ? rec.selected.add(r.id) : rec.selected.delete(r.id));
        if (!e.target.checked) rec.allMatching = false;
        recRenderSel();
    });
    $('rcBody').addEventListener('change', e => {
        const cb = e.target.closest('input[data-id]'); if (!cb) return;
        const id = parseInt(cb.dataset.id, 10);
        if (cb.checked) rec.selected.add(id); else { rec.selected.delete(id); rec.allMatching = false; }
        recRenderSel();
    });
    $('rcBody').addEventListener('click', e => {
        const b = e.target.closest('button[data-act]'); if (!b) return;
        const id = parseInt(b.dataset.id, 10);
        if (b.dataset.act === 'edit') recOpenEdit(id); else if (b.dataset.act === 'del') recAskDelete('one', id);
    });
    document.querySelectorAll('.rc-table th[data-sort]').forEach(th => th.addEventListener('click', () => {
        const s = th.dataset.sort;
        if (rec.sort === s) rec.dir = rec.dir === 'asc' ? 'desc' : 'asc'; else { rec.sort = s; rec.dir = 'asc'; }
        rec.page = 1; recLoad();
    }));
    $('rcDelConfirm').addEventListener('input', recUpdateDelBtn);
    if (location.hash === '#records') showTab('records');
});

function recClearSel() { rec.selected.clear(); rec.allMatching = false; recRenderSel(); }

async function recLoad() {
    if (rec.loading) return;
    rec.loading = true;
    try {
        const fd = recForm('records_list');
        fd.append('page', rec.page); fd.append('per_page', rec.per); fd.append('sort', rec.sort); fd.append('dir', rec.dir);
        const d = await postWithRetry(ENDPOINT, fd);
        rec.rows = d.rows || []; rec.total = d.total; rec.page = d.page; rec.pages = d.pages; rec.counts = d.counts || {};
        recRenderCards(); recRenderTelcos(d.telcos || []); recRenderRows(); recRenderPager(); recRenderSel();
    } catch (e) {
        $('rcBody').innerHTML = `<tr><td colspan="13" style="padding:30px;text-align:center;color:#e11d48;font-weight:700;">${esc(e.message)}</td></tr>`;
    } finally { rec.loading = false; }
}

function recRenderCards() {
    const c = rec.counts, total = c.total || 0;
    $('tabRecCount').textContent = fmtN(total);

    // dropdown filter (with counts)
    const fsel = $('rcFlag');
    fsel.innerHTML = REC_CARDS.map(([flag, key, label]) => `<option value="${flag}">${label} (${fmtN(c[key])})</option>`).join('');
    fsel.value = rec.flag;

    // chart: stacked summary + bar per N/A type
    const pct = n => total ? (n / total * 100) : 0;
    const complete = c.complete || 0, hasNa = c.any_na || 0, other = Math.max(0, total - complete - hasNa);
    let h = `<h4>Data quality · ${fmtN(total)} records</h4>
        <div class="rc-stack">
            <div style="width:${pct(complete)}%;background:#10b981" title="Complete: ${fmtN(complete)}"></div>
            <div style="width:${pct(hasNa)}%;background:#f59e0b" title="Has N/A: ${fmtN(hasNa)}"></div>
            <div style="width:${pct(other)}%;background:#94a3b8" title="Other / pending: ${fmtN(other)}"></div>
        </div>
        <div class="rc-legend">
            <span><i style="background:#10b981"></i>Complete ${fmtN(complete)} (${pct(complete).toFixed(1)}%)</span>
            <span><i style="background:#f59e0b"></i>Has N/A ${fmtN(hasNa)} (${pct(hasNa).toFixed(1)}%)</span>
            <span><i style="background:#94a3b8"></i>Pending / other ${fmtN(other)}</span>
        </div>`;
    h += REC_CARDS.filter(r => r[0] !== '' && r[0] !== 'complete' && r[0] !== 'any_na').map(([flag, key, label, col]) =>
        `<div class="rc-crow${rec.flag === flag ? ' active' : ''}" data-flag="${flag}" title="Click to filter the list">
            <span class="lb">${label}</span>
            <span class="tr"><span class="fl" style="display:block;width:${pct(c[key])}%;background:${col}"></span></span>
            <span class="ct">${fmtN(c[key])}<small>${pct(c[key]).toFixed(1)}%</small></span></div>`).join('');
    $('rcChart').innerHTML = h;
    $('rcChart').querySelectorAll('.rc-crow').forEach(r => r.addEventListener('click', () => {
        rec.flag = (rec.flag === r.dataset.flag) ? '' : r.dataset.flag; rec.page = 1; recClearSel(); recLoad();
    }));
}

function recRenderTelcos(list) {
    const sel = $('rcTelco');
    sel.innerHTML = '<option value="">All telcos</option>' + list.map(t => `<option value="${esc(t.name)}">${esc(t.name)} (${fmtN(t.count)})</option>`).join('');
    sel.value = rec.telco;
}

function recRenderRows() {
    const body = $('rcBody');
    if (!rec.rows.length) { body.innerHTML = '<tr><td colspan="13" style="padding:30px;text-align:center;color:#94a3b8;font-weight:700;">No records found.</td></tr>'; return; }
    const cell = v => recIsNa(v) ? '<span class="rc-na">N/A</span>' : esc(v);
    body.innerHTML = rec.rows.map(r => {
        const amtNa = recIsNa(r.amount_due) || ['0', '0.0', '0.00'].includes(String(r.amount_due).trim());
        const telNa = recIsNa(r.telco) || r.telco === 'Unknown';
        const pend  = r.account_number === 'PENDING_SYNC';
        return `<tr data-row="${r.id}" class="${rec.selected.has(r.id) ? 'sel' : ''}">
            <td><input type="checkbox" data-id="${r.id}" ${rec.selected.has(r.id) ? 'checked' : ''}></td>
            <td class="rc-sub">${r.id}</td>
            <td><div class="rc-fn" title="${esc(r.filename)}">${r.file_link ? `<a href="${esc(r.file_link)}" target="_blank" rel="noopener" style="color:inherit">${esc(r.filename)}</a>` : esc(r.filename)}</div><div class="rc-sub">${esc(r.file_path || '')}</div></td>
            <td>${pend ? '<span class="rc-na" style="background:#e0f2fe;color:#0369a1">PENDING</span>' : cell(r.account_number)}</td>
            <td>${cell(r.mobile_number)}</td>
            <td>${telNa ? '<span class="rc-na">Unknown</span>' : esc(r.telco)}</td>
            <td>${amtNa ? '<span class="rc-na">0.00</span>' : esc(r.amount_due)}</td>
            <td>${cell(r.billing_period)}</td>
            <td>${cell(r.invoice_date)}</td>
            <td>${cell(r.due_date)}</td>
            <td>${r.local ? '<span class="rc-ok" title="File exists on server">✓</span>' : '<span class="rc-no" title="File missing on server">✕</span>'}</td>
            <td>${r.drive ? '<span class="rc-ok" title="Has Drive file">✓</span>' : '<span class="rc-no" title="No Drive file id">✕</span>'}</td>
            <td style="white-space:nowrap"><button type="button" class="rc-act e" data-act="edit" data-id="${r.id}">Edit</button><button type="button" class="rc-act d" data-act="del" data-id="${r.id}">Delete</button></td>
        </tr>`;
    }).join('');
}

function recRenderPager() {
    const from = rec.total ? (rec.page - 1) * rec.per + 1 : 0, to = Math.min(rec.total, rec.page * rec.per);
    $('rcInfo').textContent = `Showing ${fmtN(from)}–${fmtN(to)} of ${fmtN(rec.total)}` + (rec.flag || rec.q || rec.telco ? ' (filtered)' : '');
    const btn = (label, p, dis, act) => `<button type="button" class="rc-btn${act ? ' rc-btn-primary' : ''}" ${dis ? 'disabled' : ''} data-p="${p}" style="padding:5px 10px">${label}</button>`;
    let h = btn('«', 1, rec.page <= 1) + btn('‹', rec.page - 1, rec.page <= 1);
    const start = Math.max(1, rec.page - 2), end = Math.min(rec.pages, start + 4);
    for (let p = start; p <= end; p++) h += btn(p, p, false, p === rec.page);
    h += btn('›', rec.page + 1, rec.page >= rec.pages) + btn('»', rec.pages, rec.page >= rec.pages);
    const pg = $('rcPager'); pg.innerHTML = h;
    pg.querySelectorAll('button[data-p]').forEach(b => b.addEventListener('click', () => { rec.page = parseInt(b.dataset.p, 10); recLoad(); }));
    document.querySelectorAll('.rc-table th[data-sort]').forEach(th => {
        th.textContent = th.textContent.replace(/ [▲▼]$/, '') + (th.dataset.sort === rec.sort ? (rec.dir === 'asc' ? ' ▲' : ' ▼') : '');
    });
}

function recRenderSel() {
    const n = rec.allMatching ? rec.total : rec.selected.size;
    const del = $('rcDelSel');
    del.disabled = n === 0; del.textContent = `🗑 Delete selected (${fmtN(n)})`;
    $('rcBody').querySelectorAll('tr[data-row]').forEach(tr => tr.classList.toggle('sel', rec.selected.has(parseInt(tr.dataset.row, 10))));
    const pageIds = rec.rows.map(r => r.id);
    const allOnPage = pageIds.length > 0 && pageIds.every(id => rec.selected.has(id));
    $('rcCheckAll').checked = allOnPage;
    const b = $('rcBanner');
    if (rec.allMatching) {
        b.style.display = 'block';
        b.innerHTML = `All ${fmtN(rec.total)} records in this list are selected. <a id="rcClearSel">Clear selection</a>`;
        $('rcClearSel').onclick = recClearSel;
    } else if (allOnPage && rec.total > pageIds.length) {
        b.style.display = 'block';
        b.innerHTML = `${pageIds.length} records on this page are selected. <a id="rcSelAll">Select all ${fmtN(rec.total)} records in this list</a> (across all pages)`;
        $('rcSelAll').onclick = () => { rec.allMatching = true; recRenderSel(); };
    } else if (rec.selected.size) {
        b.style.display = 'block';
        b.innerHTML = `${fmtN(rec.selected.size)} selected. <a id="rcClearSel">Clear selection</a>`;
        $('rcClearSel').onclick = recClearSel;
    } else b.style.display = 'none';
}

/* ---- Edit ---- */
const REC_EDIT_FIELDS = ['account_number', 'mobile_number', 'telco', 'amount_due', 'billing_period', 'invoice_date', 'due_date'];
function recOpenEdit(id) {
    const r = rec.rows.find(x => x.id === id); if (!r) return;
    rec.editId = id;
    $('rcEditName').textContent = r.filename;
    REC_EDIT_FIELDS.forEach(f => { $('rcEf_' + f).value = (recIsNa(r[f]) && f !== 'telco' && f !== 'amount_due') ? '' : (r[f] ?? ''); });
    $('rcEditErr').textContent = '';
    $('rcEditModal').classList.add('open');
    $('rcEf_account_number').focus();
}
function recCloseEdit() { $('rcEditModal').classList.remove('open'); rec.editId = null; }
async function recSaveEdit() {
    const data = {}; REC_EDIT_FIELDS.forEach(f => data[f] = $('rcEf_' + f).value);
    const btn = $('rcEditSave'); btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const fd = actionForm('records_update'); fd.append('id', rec.editId); fd.append('data', JSON.stringify(data));
        const d = await postWithRetry(ENDPOINT, fd);
        recCloseEdit();
        rec.counts = d.counts || rec.counts;
        recLoad();
    } catch (e) { $('rcEditErr').textContent = e.message; }
    finally { btn.disabled = false; btn.textContent = 'Save'; }
}

/* ---- Delete (one / selected / all in list) ---- */
function recAskDelete(mode, id) {
    rec.delMode = mode;
    let count;
    if (mode === 'one') { rec.delIds = [id]; count = 1; }
    else if (mode === 'selected') {
        if (rec.allMatching) { rec.delIds = null; rec.delMode = 'all'; count = rec.total; }
        else { rec.delIds = [...rec.selected]; count = rec.delIds.length; }
    } else { rec.delIds = null; count = rec.total; }
    if (!count) return;
    const filtered = !!(rec.flag || rec.q || rec.telco);
    $('rcDelTitle').textContent = rec.delMode === 'all' ? `Delete ALL ${fmtN(count)} records${filtered ? ' in this list' : ''}?` : `Delete ${fmtN(count)} record${count > 1 ? 's' : ''}?`;
    let msg = `This permanently removes the database record, the PDF on this server, and the file on Google Drive (moved to the Drive trash).`;
    if (rec.delMode === 'all') msg += filtered ? ' Only records matching the current filter/search are affected.' : ' NO filter is active, so this means EVERY record.';
    $('rcDelMsg').textContent = msg;
    $('rcDelConfirmWrap').style.display = (count > 20 || rec.delMode === 'all') ? '' : 'none';
    $('rcDelConfirm').value = '';
    $('rcDelProgWrap').style.display = 'none'; $('rcDelProg').style.width = '0%';
    $('rcDelStatus').textContent = ''; $('rcDelLog').style.display = 'none'; $('rcDelLog').innerHTML = '';
    $('rcDelGo').style.display = ''; $('rcDelCancel').textContent = 'Cancel';
    rec.deleting = false; rec.cancel = false;
    $('rcDelModal').classList.add('open');
    recUpdateDelBtn();
}
function recUpdateDelBtn() {
    const need = $('rcDelConfirmWrap').style.display !== 'none';
    $('rcDelGo').disabled = rec.deleting || (need && $('rcDelConfirm').value.trim() !== 'DELETE');
}
function recCloseDelete() {
    if (rec.deleting) { rec.cancel = true; $('rcDelStatus').textContent = 'Stopping after the current batch…'; return; }
    $('rcDelModal').classList.remove('open');
}
async function recRunDelete() {
    if (rec.deleting) return;
    rec.deleting = true; rec.cancel = false; recUpdateDelBtn();
    $('rcDelConfirmWrap').style.display = 'none';
    $('rcDelProgWrap').style.display = ''; $('rcDelCancel').textContent = 'Stop';
    const logEl = $('rcDelLog');
    let ids = rec.delIds;
    try {
        if (!ids) {
            $('rcDelStatus').textContent = 'Collecting records…';
            ids = (await postWithRetry(ENDPOINT, recForm('records_ids'))).ids || [];
        }
        const BATCH = 8, CONC = 2;
        const batches = []; for (let i = 0; i < ids.length; i += BATCH) batches.push(ids.slice(i, i + BATCH));
        let next = 0, done = 0, ok = 0, fail = 0;
        const update = () => { $('rcDelProg').style.width = (ids.length ? done / ids.length * 100 : 100) + '%'; $('rcDelStatus').textContent = `${fmtN(done)} / ${fmtN(ids.length)} processed · ${fmtN(ok)} deleted` + (fail ? ` · ${fmtN(fail)} failed` : ''); };
        update();
        const worker = async () => {
            while (next < batches.length && !rec.cancel) {
                const b = batches[next++];
                try {
                    const fd = actionForm('records_delete_batch'); fd.append('ids', JSON.stringify(b));
                    const d = await postWithRetry(ENDPOINT, fd, { retries: 2 });
                    (d.results || []).forEach(r => {
                        done++;
                        if (r.status === 'success') { ok++; rec.selected.delete(r.id); }
                        else { fail++; logEl.style.display = 'block'; logEl.insertAdjacentHTML('beforeend', `<div>✕ ${esc(r.filename)}: ${esc(r.message)}</div>`); }
                    });
                } catch (e) {
                    done += b.length; fail += b.length;
                    logEl.style.display = 'block'; logEl.insertAdjacentHTML('beforeend', `<div>✕ batch failed: ${esc(e.message)}</div>`);
                }
                update();
            }
        };
        await Promise.all(Array.from({ length: Math.min(CONC, batches.length) }, worker));
        $('rcDelStatus').textContent += rec.cancel ? ' · stopped' : ' · finished';
    } catch (e) {
        $('rcDelStatus').textContent = 'Error: ' + e.message;
    }
    rec.deleting = false; rec.allMatching = false;
    $('rcDelGo').style.display = 'none'; $('rcDelCancel').textContent = 'Close';
    recLoad();
}
</script>

<?php
$content = ob_get_clean();
if (function_exists('render_layout')) {
    render_layout("Upload Statements", $content);
} else {
    echo $content;
}
?>