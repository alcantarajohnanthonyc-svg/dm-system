<?php
// debit_memo_upload.php - Direct Google Drive & Local Server Hierarchical Folder Upload Module (Batch Size: 10)
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
@error_reporting(E_ALL);

// Runtime fallback limits for batch requests
@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '128M');
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '512M');

if (!defined('ALLOW_ACCESS')) {
    define('ALLOW_ACCESS', true);
}

// 1. I-load ang config at main para makuha ang PDO database connection
$config_file = __DIR__ . '/config.php';
$main_file = __DIR__ . '/main.php';

if (file_exists($config_file)) {
    require_once $config_file;
}
if (file_exists($main_file)) {
    require_once $main_file;
}

// 2. I-set ang global database connection (PDO)
$db_connection = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $db_connection = $pdo;
} elseif (isset($conn) && $conn instanceof PDO) {
    $db_connection = $conn;
} elseif (isset($db) && $db instanceof PDO) {
    $db_connection = $db;
}

// =========================================================================
// 3. DIRECT CSV REPORT EXPORT HANDLER (PDO - Fixed columns)
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Uploaded_Statements_Report_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    
    // CSV Column Headers
    fputcsv($output, ['ID', 'Filename', 'File Link', 'File ID', 'File Path', 'Account Number', 'Mobile Number', 'Telco', 'Amount Due', 'Billing Period', 'Invoice Date', 'Due Date', 'Updated At']);

    if ($db_connection) {
        try {
            $stmt = $db_connection->query("SELECT id, filename, file_link, file_id, file_path, account_number, mobile_number, telco, amount_due, billing_period, invoice_date, due_date, updated_at FROM pdf_extracted_details ORDER BY id DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['id'],
                    $row['filename'],
                    $row['file_link'],
                    $row['file_id'],
                    $row['file_path'],
                    $row['account_number'],
                    $row['mobile_number'],
                    $row['telco'],
                    $row['amount_due'],
                    $row['billing_period'],
                    $row['invoice_date'],
                    $row['due_date'],
                    $row['updated_at']
                ]);
            }
        } catch (Exception $e) {
            fputcsv($output, ['Error fetching data: ' . $e->getMessage()]);
        }
    } else {
        fputcsv($output, ['Error: Database connection not found.']);
    }

    fclose($output);
    exit;
}

// Include Composer autoloader para sa PDF parser at Google API Client
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Google Drive Shared Drive Folder Configuration
$google_drive_folder_id = '1nB2cAYdR4LVLTBcbop2nldVU6pcXTD_a';

// Helper Functions for Filename Fallbacks
if (!function_exists('detect_telco')) {
    function detect_telco($filename, $text = '') {
        $upper = strtoupper($filename . ' ' . $text);
        if (strpos($upper, 'SMT') !== false || strpos($upper, 'SMART') !== false) {
            return 'Smart';
        }
        if (strpos($upper, 'ACC_') !== false || strpos($upper, 'GLOBE') !== false) {
            return 'Globe';
        }
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

if (!function_exists('extract_pdf_statement_details')) {
    function extract_pdf_statement_details($filepath, $filename) {
        $text = ''; 
        $page1_text = '';
        
        if (class_exists('Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filepath);
                $text = $pdf->getText();
                $pages = $pdf->getPages();
                if (!empty($pages[0])) {
                    $page1_text = $pages[0]->getText();
                }
            } catch (Exception $e) {
                $text = @file_get_contents($filepath);
                $page1_text = $text;
            }
        } else {
            $text = @file_get_contents($filepath);
            $page1_text = $text;
        }

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

if (!function_exists('get_or_create_drive_folder')) {
    function get_or_create_drive_folder($drive_service, $parent_id, $folder_name) {
        $escapedName = str_replace("'", "\\'", $folder_name);
        $query = "name = '{$escapedName}' and '{$parent_id}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        
        $files = $drive_service->files->listFiles([
            'q' => $query,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
            'fields' => 'files(id, name)'
        ])->getFiles();

        if (!empty($files)) {
            return $files[0]->getId();
        }

        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parent_id]
        ]);

        $folder = $drive_service->files->create($folderMetadata, [
            'supportsAllDrives' => true,
            'fields' => 'id'
        ]);

        return $folder->getId();
    }
}

// Handle AJAX Batch Uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_files'])) {
    @ini_set('zlib.output_compression', 'Off');
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $success_files = [];
    $failed_files = [];
    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    $drive_service = null;
    try {
        if (class_exists('Google_Client')) {
            $client = new Google_Client();
            $credentials_path = __DIR__ . '/credentials.json';

            if (file_exists($credentials_path)) {
                $client->setAuthConfig($credentials_path);
                $client->addScope(Google_Service_Drive::DRIVE);
            }

            if (class_exists('GuzzleHttp\Client')) {
                $httpClient = new GuzzleHttp\Client(['verify' => false]);
                $client->setHttpClient($httpClient);
            }

            $drive_service = new Google_Service_Drive($client);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'success', 'success_files' => [], 'failed_files' => ["Google Drive Init Error: " . $e->getMessage()]]);
        exit;
    }

    foreach ($_FILES['pdf_files']['name'] as $i => $name) {
        $tmp_name = $_FILES['pdf_files']['tmp_name'][$i];
        $basename = basename($name);

        if (!is_uploaded_file($tmp_name)) {
            $failed_files[] = "$basename (Upload validation failed)";
            continue;
        }

        $details = extract_pdf_statement_details($tmp_name, $basename);

        $gdrive_status = "Not Uploaded";
        $file_link = null;
        $file_id = null;

        $telco_folder_name = !empty($details['telco']) ? $details['telco'] : 'Unknown_Telco';
        $year_folder_name = 'Unknown_Year';
        if (!empty($details['invoice_date']) && $details['invoice_date'] !== 'N/A') {
            $year_folder_name = date('Y', strtotime($details['invoice_date']));
        } elseif (preg_match('/(20\d{2})/', $basename, $ym)) {
            $year_folder_name = $ym[1];
        } else {
            $year_folder_name = date('Y');
        }

        $billing_folder_name = !empty($details['billing_period']) ? str_replace(['/', '\\'], '-', $details['billing_period']) : 'Unknown_Period';
        $db_file_path = "{$telco_folder_name}/{$year_folder_name}/{$billing_folder_name}";

        $local_dir = __DIR__ . '/uploads/' . $db_file_path;
        if (!file_exists($local_dir)) {
            @mkdir($local_dir, 0777, true);
        }
        if (file_exists($local_dir) && !is_writable($local_dir)) {
            @chmod($local_dir, 0777);
        }

        $local_file_target = $local_dir . '/' . $basename;
        if (file_exists($local_file_target)) {
            @chmod($local_file_target, 0777);
            @unlink($local_file_target);
        }

        @copy($tmp_name, $local_file_target);
        @chmod($local_file_target, 0644);

        if ($drive_service) {
            try {
                $telco_folder_id = get_or_create_drive_folder($drive_service, $google_drive_folder_id, $telco_folder_name);
                $year_folder_id = get_or_create_drive_folder($drive_service, $telco_folder_id, $year_folder_name);
                $target_parent_id = get_or_create_drive_folder($drive_service, $year_folder_id, $billing_folder_name);

                $escapedName = str_replace("'", "\\'", $basename);
                $query = "name = '{$escapedName}' and '{$target_parent_id}' in parents and trashed = false";
                $existingFiles = $drive_service->files->listFiles([
                    'q' => $query,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                    'fields' => 'files(id, name, webViewLink)'
                ])->getFiles();

                $content = file_get_contents($tmp_name);

                if (!empty($existingFiles)) {
                    $existingFileId = $existingFiles[0]->getId();
                    if ($overwrite) {
                        $fileMetadata = new Google_Service_Drive_DriveFile(['name' => $basename]);
                        $updatedFile = $drive_service->files->update($existingFileId, $fileMetadata, [
                            'data' => $content,
                            'mimeType' => 'application/pdf',
                            'uploadType' => 'multipart',
                            'supportsAllDrives' => true,
                            'fields' => 'id, webViewLink'
                        ]);
                        $file_id = $updatedFile->getId();
                        $file_link = $updatedFile->getWebViewLink();
                        $gdrive_status = "Overwritten in Shared Drive";
                    } else {
                        $failed_files[] = "$basename (File already exists in Google Drive and Overwrite is disabled)";
                        continue;
                    }
                } else {
                    $fileMetadata = new Google_Service_Drive_DriveFile([
                        'name' => $basename,
                        'parents' => [$target_parent_id]
                    ]);

                    $createdFile = $drive_service->files->create($fileMetadata, [
                        'data' => $content,
                        'mimeType' => 'application/pdf',
                        'uploadType' => 'multipart',
                        'fields' => 'id, webViewLink',
                        'supportsAllDrives' => true
                    ]);
                    $file_id = $createdFile->getId();
                    $file_link = $createdFile->getWebViewLink();
                    $gdrive_status = "Uploaded to Shared Drive";
                }
            } catch (Exception $e) {
                $failed_files[] = "$basename (Drive Error: " . $e->getMessage() . ")";
                continue;
            }
        }

        if ($db_connection) {
            try {
                $stmt = $db_connection->prepare("
                    INSERT INTO pdf_extracted_details 
                    (filename, account_number, mobile_number, telco, amount_due, billing_period, invoice_date, due_date, file_link, file_id, file_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        account_number = VALUES(account_number),
                        mobile_number = VALUES(mobile_number),
                        telco = VALUES(telco),
                        amount_due = VALUES(amount_due),
                        billing_period = VALUES(billing_period),
                        invoice_date = VALUES(invoice_date),
                        due_date = VALUES(due_date),
                        file_link = VALUES(file_link),
                        file_id = VALUES(file_id),
                        file_path = VALUES(file_path)
                ");
                $stmt->execute([
                    $basename, 
                    $details['account_number'], 
                    $details['mobile_number'], 
                    $details['telco'], 
                    $details['amount_due'], 
                    $details['billing_period'], 
                    $details['invoice_date'], 
                    $details['due_date'], 
                    $file_link, 
                    $file_id, 
                    $db_file_path
                ]);
                $success_files[] = "$basename (Saved successfully)";
            } catch (Exception $e) {
                $failed_files[] = "$basename (DB Error: " . $e->getMessage() . ")";
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'success_files' => $success_files,
        'failed_files' => $failed_files
    ]);
    exit;
}

// Layout Rendering Block
ob_start();
?>

<!-- TAB NAVIGATION BUTTONS -->
<div class="max-w-6xl mx-auto mt-6 flex border-b border-gray-200">
    <button type="button" onclick="switchTab('upload')" id="uploadTabBtn" class="py-3 px-6 text-sm font-bold border-b-2 border-blue-600 text-blue-600 focus:outline-none flex items-center space-x-2">
        <span>📤</span>
        <span>Upload Statements</span>
    </button>
    <button type="button" onclick="switchTab('filemanager')" id="fileManagerTabBtn" class="py-3 px-6 text-sm font-bold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none flex items-center space-x-2">
        <span>📂</span>
        <span>File Manager & Reports</span>
    </button>
</div>

<!-- TAB 1: UPLOAD STATEMENTS VIEW -->
<div id="uploadTabView">
    <div class="bg-white shadow-lg rounded-2xl p-6 border border-gray-100 max-w-6xl mx-auto mt-6">
        <div class="mb-6">
            <h2 class="text-lg font-bold text-gray-800">Upload Statements (Batch Size: 10)</h2>
            <p class="text-xs text-gray-500 mt-0.5">Drag and drop folders or PDF files safely. All PDF files inside dropped folders/subfolders will be automatically queued, saved locally, and synced to Google Drive in batches of 10.</p>
        </div>

        <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-2xl p-8 text-center bg-gray-50/50 hover:bg-gray-50 transition-colors mb-6 relative">
            <div class="flex flex-col items-center">
                <div class="w-10 h-10 mb-3 text-gray-400 flex items-center justify-center bg-white rounded-full shadow-sm border border-gray-100">
                    📁
                </div>
                <p class="text-sm font-medium text-gray-700 mb-3">Drag & drop folders or PDF files here, or choose an option below:</p>
                
                <div class="flex flex-wrap justify-center gap-3">
                    <label class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold py-2.5 px-4 rounded-xl shadow transition-colors inline-flex items-center space-x-2">
                        <span>📂</span>
                        <span>Browse Folder</span>
                        <input type="file" id="folder_input" name="folder_input[]" webkitdirectory directory class="hidden">
                    </label>

                    <label class="cursor-pointer bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold py-2.5 px-4 rounded-xl shadow transition-colors inline-flex items-center space-x-2">
                        <span>📄</span>
                        <span>Select PDF Files</span>
                        <input type="file" id="file_input" name="file_input[]" multiple accept=".pdf" class="hidden">
                    </label>
                </div>
            </div>
        </div>
        
        <div class="mb-6 flex items-center bg-blue-50/60 border border-blue-100 rounded-xl p-3.5">
            <input type="checkbox" id="overwriteCheckbox" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
            <label for="overwriteCheckbox" class="ml-2.5 text-xs font-medium text-blue-900 cursor-pointer">
                <strong>Overwrite existing files</strong> in Google Drive if they already exist.
            </label>
        </div>

        <!-- QUEUE SECTION -->
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5 mb-6">
            <div class="flex justify-between items-center mb-3 pb-2 border-b border-gray-100">
                <div class="text-xs font-bold text-gray-700 uppercase tracking-wider" id="queueHeaderLabel">SELECTED FILES QUEUE (0)</div>
                <div class="flex items-center space-x-4">
                    <span id="queueCountText" class="text-xs font-medium text-blue-600">0 file(s) loaded in queue.</span>
                    <button type="button" id="clearBtn" class="text-xs text-gray-400 hover:text-rose-600 font-medium">Clear Queue</button>
                </div>
            </div>

            <div id="fileListContainer" class="min-h-[60px] max-h-48 overflow-y-auto p-1 divide-y divide-gray-50 mb-4">
                <p class="text-xs text-gray-400 text-center py-4 italic" id="emptyQueueMsg">No files selected yet.</p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="button" id="uploadBtn" onclick="startUploadProcess()" class="bg-rose-500 hover:bg-rose-600 text-white font-semibold py-2.5 px-5 rounded-xl shadow-md transition-colors text-xs flex items-center space-x-2 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                    <span>❌</span><span>Start Batch Upload (10 per batch)</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: FILE MANAGER & DOWNLOAD REPORT VIEW -->
<div id="fileManagerTabView" style="display: none;" class="bg-white shadow-lg rounded-2xl p-6 border border-gray-100 max-w-6xl mx-auto mt-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
        <div>
            <h2 class="text-lg font-bold text-gray-800">Uploaded Statements List</h2>
            <p class="text-xs text-gray-500 mt-0.5">Manage uploaded PDF statements, search records, download files, and export CSV reports.</p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" onclick="downloadSelectedFiles()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-xl shadow text-xs flex items-center space-x-2 transition-colors">
                <span>📥</span>
                <span>Download Selected</span>
            </button>
            <a href="debit_memo_upload.php?export=csv" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-xl shadow text-xs flex items-center space-x-2 transition-colors whitespace-nowrap">
                <span>📊</span>
                <span>Export Report (CSV)</span>
            </a>
        </div>
    </div>

    <!-- Search Bar Filter & Limit Selector -->
<div class="flex flex-col md:flex-row justify-between items-center gap-3 mb-4">
    <input type="text" id="tableSearchInput" onkeyup="filterTableRecords()" placeholder="🔍 Search by filename, telco account number, amount due, billing period..." class="w-full text-xs border border-gray-300 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-50/50">
    
    <div class="flex items-center space-x-2 whitespace-nowrap">
        <span class="text-xs text-gray-500">Show:</span>
        <?php 
            $limit_val = isset($_GET['limit']) ? $_GET['limit'] : '15';
        ?>
        <select onchange="window.location.href='debit_memo_upload.php?tab=filemanager&limit=' + this.value" class="text-xs border border-gray-300 rounded-xl px-3 py-2 bg-gray-50/50 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="15" <?php echo ($limit_val == '15') ? 'selected' : ''; ?>>15</option>
            <option value="50" <?php echo ($limit_val == '50') ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo ($limit_val == '100') ? 'selected' : ''; ?>>100</option>
            <option value="all" <?php echo ($limit_val == 'all') ? 'selected' : ''; ?>>Show All</option>
        </select>
    </div>
</div>

    <!-- DATA TABLE WITH PAGINATION -->
    <div class="overflow-x-auto border border-gray-200 rounded-xl">
        <table id="statementsTable" class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-gray-700 uppercase font-semibold">
                <tr>
                    <th class="py-3 px-3 text-center w-10">
                        <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer">
                    </th>
                    <th class="py-3 px-4 text-left">Filename</th>
                    <th class="py-3 px-4 text-left">Telco</th>
                    <th class="py-3 px-4 text-left">Account #</th>
                    <th class="py-3 px-4 text-left">Amount Due</th>
                    <th class="py-3 px-4 text-left">Billing Period</th>
                    <th class="py-3 px-4 text-center">Action / Download</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white" id="tableBodyContent">
                <?php
                // Pagination & Limit setup
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                if ($page < 1) $page = 1;

                $total_records = 0;
                if ($db_connection) {
                    try {
                        $count_stmt = $db_connection->query("SELECT COUNT(*) FROM pdf_extracted_details");
                        $total_records = $count_stmt->fetchColumn();

                        if ($limit_val === 'all') {
                            $stmt = $db_connection->query("SELECT * FROM pdf_extracted_details ORDER BY id DESC");
                        } else {
                            $limit = (int)$limit_val;
                            $offset = ($page - 1) * $limit;
                            $stmt = $db_connection->prepare("SELECT * FROM pdf_extracted_details ORDER BY id DESC LIMIT :limit OFFSET :offset");
                            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                            $stmt->execute();
                        }

                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $local_file = 'uploads/' . $row['file_path'] . '/' . $row['filename'];
    
    echo "<tr class='hover:bg-gray-50 search-row'>";
    
    // Checkbox column
    echo "<td class='py-3 px-3 text-center'>";
    echo "<input type='checkbox' name='row_select' value='" . htmlspecialchars($local_file) . "' class='row-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer'>";
    echo "</td>";

    // 1. Filename
    echo "<td class='py-3 px-4 font-medium text-gray-900 search-filename'>" . htmlspecialchars($row['filename']) . "</td>";
    // Telco (optional to include or keep separate)
    echo "<td class='py-3 px-4 text-gray-600 search-telco'>" . htmlspecialchars($row['telco']) . "</td>";
    // 2. Account Number
    echo "<td class='py-3 px-4 text-gray-600 search-account'>" . htmlspecialchars($row['account_number']) . "</td>";
    // 3. Amount Due
    echo "<td class='py-3 px-4 text-gray-600 search-amount'>₱" . htmlspecialchars($row['amount_due']) . "</td>";
    // 4. Billing Period
    echo "<td class='py-3 px-4 text-gray-600 search-period'>" . htmlspecialchars($row['billing_period']) . "</td>";
    
    // Action / Download buttons
    echo "<td class='py-3 px-4 text-center whitespace-nowrap'>";
    echo "<div class='inline-flex items-center justify-center gap-2'>";
    
    if (!empty($row['file_link'])) {
        echo "<a href='" . htmlspecialchars($row['file_link']) . "' target='_blank' class='bg-blue-50 text-blue-600 px-2.5 py-1.5 rounded-lg border border-blue-200 font-semibold hover:bg-blue-100 inline-flex items-center gap-1'>Drive Link ↗</a>";
    }
    
    echo "<a href='" . htmlspecialchars($local_file) . "' download class='bg-gray-100 text-gray-700 px-2.5 py-1.5 rounded-lg border border-gray-300 font-semibold hover:bg-gray-200 inline-flex items-center gap-1'>Download ⬇</a>";

    echo "</div>";
    echo "</td>";
    echo "</tr>";
}
                    } catch (Exception $e) {
                        echo "<tr><td colspan='7' class='py-4 text-center text-rose-500'>Error loading records: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='py-4 text-center text-gray-400'>Database connection unavailable.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION CONTROLS -->
    <?php if ($limit_val !== 'all' && $total_records > $limit): ?>
    <div class="flex justify-between items-center mt-4 px-2">
        <div class="text-xs text-gray-500">
            Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo ceil($total_records / $limit); ?></strong> (Total: <?php echo $total_records; ?> files)
        </div>
        <div class="flex space-x-2">
            <?php if ($page > 1): ?>
                <a href="debit_memo_upload.php?tab=filemanager&limit=<?php echo $limit_val; ?>&page=<?php echo $page - 1; ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg border border-gray-300">Previous</a>
            <?php endif; ?>
            <?php if ($page * $limit < $total_records): ?>
                <a href="debit_memo_upload.php?tab=filemanager&limit=<?php echo $limit_val; ?>&page=<?php echo $page + 1; ?>" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- CENTERED POP-UP MODAL PANEL FOR UPLOAD PROGRESS -->
<div id="progressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-4xl p-8 mx-4">
        <div class="flex justify-between items-center mb-5">
            <h3 class="text-base font-bold text-gray-800 uppercase tracking-wider flex items-center space-x-2.5">
                <svg id="modalSpinnerIcon" class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Uploading Files to Cloud & Local (Batch Mode)</span>
            </h3>
            <span id="progressPercentage" class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-bold">0%</span>
        </div>

        <div class="mb-5">
            <div class="w-full bg-gray-200 rounded-full h-3.5 overflow-hidden shadow-inner">
                <div id="progressBar" class="bg-blue-600 h-3.5 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
            <div class="flex justify-between items-center mt-2.5">
                <span id="progressText" class="text-xs font-semibold text-gray-600">Preparing upload...</span>
                <span id="progressCountDetail" class="text-xs font-bold text-blue-600">Processed: 0 / 0 files</span>
            </div>
        </div>

        <div id="logContainer" class="mb-6 border border-gray-800 rounded-2xl p-4 bg-slate-900 text-white text-xs font-mono max-h-80 overflow-y-auto shadow-inner">
            <div class="font-bold text-gray-300 mb-2 border-b border-slate-700 pb-1.5 flex justify-between items-center">
                <span>Activity Logs:</span>
            </div>
            <div id="logContent" class="space-y-1.5"></div>
        </div>

        <div class="flex justify-end space-x-3">
            <button type="button" id="stopUploadBtn" onclick="abortUploadProcess()" class="bg-rose-600 hover:bg-rose-700 text-white font-semibold py-2.5 px-5 rounded-xl shadow-md transition-colors text-xs flex items-center space-x-1.5">
                <span>🛑</span><span>Stop Upload</span>
            </button>
            <button type="button" id="closeModalBtn" onclick="closeProgressModal()" style="display: none;" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2.5 px-5 rounded-xl shadow-md transition-colors text-xs">
                Close Panel
            </button>
        </div>
    </div>
</div>

<script>
let fileQueue = [];
let isUploading = false;
let abortUpload = false;

document.addEventListener('DOMContentLoaded', function () {
    // Awtomatikong buksan ang File Manager tab kung may ?tab=filemanager sa URL (para hindi bumalik sa Tab 1)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'filemanager' || urlParams.get('page') || urlParams.get('limit')) {
        switchTab('filemanager');
    }

    const folderInput = document.getElementById('folder_input');
    const fileInput = document.getElementById('file_input');
    const dropZone = document.getElementById('dropZone');
    const clearBtn = document.getElementById('clearBtn');

    if (dropZone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('border-blue-500', 'bg-blue-50/30');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('border-blue-500', 'bg-blue-50/30');
            }, false);
        });

        dropZone.addEventListener('drop', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('border-blue-500', 'bg-blue-50/30');

            const items = e.dataTransfer.items;
            if (items && items.length > 0) {
                for (let i = 0; i < items.length; i++) {
                    const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                    if (entry) {
                        await traverseFileTree(entry);
                    }
                }
            } else if (e.dataTransfer.files) {
                handleFiles(e.dataTransfer.files);
            }
        });
    }

    if (folderInput) {
        folderInput.addEventListener('change', function (e) {
            handleFiles(Array.from(e.target.files));
            folderInput.value = ''; 
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function (e) {
            handleFiles(Array.from(e.target.files));
            fileInput.value = ''; 
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (isUploading) return;
            fileQueue = [];
            updateQueueUI();
        });
    }
});

function switchTab(tab) {
    const uploadTabView = document.getElementById('uploadTabView');
    const fileManagerTabView = document.getElementById('fileManagerTabView');
    const uploadTabBtn = document.getElementById('uploadTabBtn');
    const fileManagerTabBtn = document.getElementById('fileManagerTabBtn');

    if (tab === 'upload') {
        uploadTabView.style.display = 'block';
        fileManagerTabView.style.display = 'none';

        uploadTabBtn.className = 'py-3 px-6 text-sm font-bold border-b-2 border-blue-600 text-blue-600 focus:outline-none flex items-center space-x-2';
        fileManagerTabBtn.className = 'py-3 px-6 text-sm font-bold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none flex items-center space-x-2';
    } else {
        uploadTabView.style.display = 'none';
        fileManagerTabView.style.display = 'block';

        fileManagerTabBtn.className = 'py-3 px-6 text-sm font-bold border-b-2 border-blue-600 text-blue-600 focus:outline-none flex items-center space-x-2';
        uploadTabBtn.className = 'py-3 px-6 text-sm font-bold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none flex items-center space-x-2';
    }
}

function filterTableRecords() {
    const input = document.getElementById('tableSearchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.search-row');

    rows.forEach(row => {
        const textContent = row.innerText.toLowerCase();
        if (textContent.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') {
            cb.checked = source.checked;
        }
    });
}

function downloadSelectedFiles() {
    const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('Mangyaring pumili kahit isang file bago mag-download.');
        return;
    }

    checkedBoxes.forEach((cb, index) => {
        const fileUrl = cb.value;
        setTimeout(() => {
            const a = document.createElement('a');
            a.href = fileUrl;
            a.download = '';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }, index * 300);
    });
}

async function traverseFileTree(item) {
    if (item.isFile) {
        await new Promise((resolve) => {
            item.file((file) => {
                if (file.name.toLowerCase().endsWith('.pdf')) {
                    const exists = fileQueue.some(f => f.name === file.name && f.size === file.size);
                    if (!exists) {
                        fileQueue.push(file);
                    }
                }
                resolve();
            });
        });
    } else if (item.isDirectory) {
        const dirReader = item.createReader();
        await new Promise((resolve) => {
            dirReader.readEntries(async (entries) => {
                for (let i = 0; i < entries.length; i++) {
                    await traverseFileTree(entries[i]);
                }
                resolve();
            });
        });
    }
    updateQueueUI();
}

function handleFiles(files) {
    if (!files || files.length === 0) return;
    Array.from(files).forEach(file => {
        if (file.name.toLowerCase().endsWith('.pdf')) {
            const exists = fileQueue.some(f => f.name === file.name && f.size === file.size);
            if (!exists) fileQueue.push(file);
        }
    });
    updateQueueUI();
}

function updateQueueUI() {
    const queueCountText = document.getElementById('queueCountText');
    const queueHeaderLabel = document.getElementById('queueHeaderLabel');
    const emptyQueueMsg = document.getElementById('emptyQueueMsg');
    const fileListContainer = document.getElementById('fileListContainer');
    const uploadBtn = document.getElementById('uploadBtn');

    if(queueCountText) queueCountText.textContent = `${fileQueue.length} file(s) loaded in queue.`;
    if(queueHeaderLabel) queueHeaderLabel.textContent = `SELECTED FILES QUEUE (${fileQueue.length})`;
    
    if (fileQueue.length > 0) {
        if(emptyQueueMsg) emptyQueueMsg.style.display = 'none';
        if(uploadBtn) uploadBtn.removeAttribute('disabled');
        let html = '';
        fileQueue.forEach((file, index) => {
            html += `<div class="py-2 px-2 flex justify-between items-center text-xs text-gray-700">
                <span class="truncate max-w-[80%]" title="${file.name}">${index + 1}. ${file.name}</span>
                <span class="text-gray-400">(${(file.size / 1024 / 1024).toFixed(2)} MB)</span>
            </div>`;
        });
        if(fileListContainer) fileListContainer.innerHTML = html;
    } else {
        if(emptyQueueMsg) emptyQueueMsg.style.display = 'block';
        if(fileListContainer) fileListContainer.innerHTML = '<p class="text-xs text-gray-400 text-center py-4 italic" id="emptyQueueMsg">No files selected yet.</p>';
        if(uploadBtn) uploadBtn.setAttribute('disabled', 'true');
    }
}

function abortUploadProcess() {
    abortUpload = true;
    const progressText = document.getElementById('progressText');
    if(progressText) progressText.textContent = 'Stopping upload...';
}

function closeProgressModal() {
    const progressModal = document.getElementById('progressModal');
    if(progressModal) progressModal.style.display = 'none';
}

async function startUploadProcess() {
    if (fileQueue.length === 0 || isUploading) return;

    isUploading = true;
    abortUpload = false;
    
    const progressModal = document.getElementById('progressModal');
    const stopUploadBtn = document.getElementById('stopUploadBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const logContent = document.getElementById('logContainer');
    const progressBar = document.getElementById('progressBar');
    const progressPercentage = document.getElementById('progressPercentage');
    const progressText = document.getElementById('progressText');
    const progressCountDetail = document.getElementById('progressCountDetail');
    const overwriteCheckbox = document.getElementById('overwriteCheckbox');
    const modalSpinnerIcon = document.getElementById('modalSpinnerIcon');

    if(progressModal) progressModal.style.display = 'flex';
    if(stopUploadBtn) stopUploadBtn.style.display = 'inline-flex';
    if(closeModalBtn) closeModalBtn.style.display = 'none';
    if(logContent) logContent.innerHTML = '';
    if(modalSpinnerIcon) modalSpinnerIcon.style.display = 'inline-block';

    const chunkSize = 20; 
    const maxConcurrent = 2; 
    const totalFiles = fileQueue.length;
    let processedFiles = 0;
    let successTotal = 0;
    let failedTotal = 0;

    if(progressText) progressText.textContent = 'Starting fast parallel upload queue...';
    if(progressCountDetail) progressCountDetail.textContent = `Processed: 0 / ${totalFiles} files`;
    if(progressBar) progressBar.style.width = '1%';
    if(progressPercentage) progressPercentage.textContent = '0%';

    let batches = [];
    for (let i = 0; i < totalFiles; i += chunkSize) {
        batches.push(fileQueue.slice(i, i + chunkSize));
    }

    let batchIndex = 0;

    async function uploadBatch(batch) {
        if (abortUpload) return;

        const formData = new FormData();
        batch.forEach(file => formData.append('pdf_files[]', file));

        if (overwriteCheckbox && overwriteCheckbox.checked) {
            formData.append('overwrite', '1');
        }

        let success = false;
        let retries = 3;

        while (retries > 0 && !success && !abortUpload) {
            try {
                const response = await fetch('debit_memo_upload.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

                const result = await response.json();

                if (result.status === 'success') {
                    success = true;
                    successTotal += result.success_files.length;
                    failedTotal += result.failed_files.length;

                    result.success_files.forEach(msg => {
                        if(logContent) logContent.innerHTML += `<div class="text-emerald-400">[SUCCESS] ${msg}</div>`;
                    });
                    result.failed_files.forEach(msg => {
                        if(logContent) logContent.innerHTML += `<div class="text-rose-400">[FAILED] ${msg}</div>`;
                    });
                }
            } catch (error) {
                retries--;
                if (retries === 0) {
                    const userRetry = confirm(`May error sa pag-upload ng isang batch (${error.message}). Gusto mo bang i-retry ito?`);
                    if (userRetry) {
                        retries = 3; 
                        if(logContent) logContent.innerHTML += `<div class="text-amber-400">[USER ACTION] Piniling i-retry ang batch...</div>`;
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        continue;
                    } else {
                        if(logContent) logContent.innerHTML += `<div class="text-rose-400">[NETWORK ERROR] Batch failed and skipped: ${error.message}</div>`;
                        failedTotal += batch.length;
                    }
                } else {
                    if(logContent) logContent.innerHTML += `<div class="text-amber-400">[WARNING] Naputol ang koneksyon. Sinusubukang muli (${retries} na natitirang subok)...</div>`;
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }
            }
        }

        processedFiles += batch.length;
        const percentage = Math.round((processedFiles / totalFiles) * 100);
        
        if(progressBar) progressBar.style.width = percentage + '%';
        if(progressPercentage) progressPercentage.textContent = percentage + '%';
        if(progressCountDetail) progressCountDetail.textContent = `Processed: ${processedFiles} / ${totalFiles} files`;
        
        const logContainerElem = document.getElementById('logContainer');
        if(logContainerElem) logContainerElem.scrollTop = logContainerElem.scrollHeight;
    }

    while (batchIndex < batches.length && !abortUpload) {
        let currentWorkers = [];
        for (let w = 0; w < maxConcurrent && batchIndex < batches.length; w++) {
            currentWorkers.push(uploadBatch(batches[batchIndex++]));
        }
        await Promise.all(currentWorkers);
    }

    isUploading = false;
    if(stopUploadBtn) stopUploadBtn.style.display = 'none';
    if(closeModalBtn) closeModalBtn.style.display = 'inline-flex';
    if(modalSpinnerIcon) modalSpinnerIcon.style.display = 'none';
    
    if (abortUpload) {
        if(progressText) progressText.textContent = 'Upload aborted by user.';
    } else {
        if(progressText) progressText.textContent = `All files processed! (Success: ${successTotal}, Failed: ${failedTotal})`;
        fileQueue = [];
        updateQueueUI();
    }
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