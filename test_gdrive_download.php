<?php
// test_auto_download.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

$target_filename = '20260731-6017873650-SMTBI0004022803.pdf';

echo "<h3>Testing Global Dynamic Search & Download</h3>";
echo "<p>Searching for filename: <strong>{$target_filename}</strong></p>";

try {
    $client = new \Google_Client();
    $cred_path = __DIR__ . '/credentials.json';
    
    if (!file_exists($cred_path)) {
        throw new Exception("credentials.json not found.");
    }
    
    $client->setAuthConfig($cred_path);
    $client->addScope(\Google_Service_Drive::DRIVE);
    $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
    
    $service = new \Google_Service_Drive($client);
    
    // Search globally across all accessible files
    $query = "name = '" . addslashes($target_filename) . "' and trashed = false";
    
    $results = $service->files->listFiles([
        'q' => $query,
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
        'fields' => "files(id, name, mimeType, parents)"
    ]);
    
    $files = $results->getFiles();
    
    if (empty($files)) {
        echo "<p style='color:red;'>No matching files found. Please ensure the service account email has permission to view the file in Google Drive.</p>";
        exit;
    }
    
    echo "<p>Found " . count($files) . " matching file(s) in Drive. Testing downloads...</p>";
    
    $success = false;
    foreach ($files as $file) {
        $file_id = $file->getId();
        echo "<hr><p>Trying File ID: <code>{$file_id}</code>...</p>";
        
        try {
            $response = $service->files->get($file_id, [
                'alt' => 'media',
                'supportsAllDrives' => true
            ]);
            
            $content = '';
            if (method_exists($response, 'getBody')) {
                $stream = $response->getBody();
                while (!$stream->eof()) {
                    $content .= $stream->read(1024);
                }
            } else {
                $content = (string)$response;
            }
            
            if (!empty($content) && stripos($content, '<html') === false) {
                $output_filename = 'success_downloaded.pdf';
                file_put_contents($output_filename, $content);
                echo "<p style='color:green;'><strong>SUCCESS!</strong> Downloaded successfully using ID: <code>{$file_id}</code>. Size: " . filesize($output_filename) . " bytes.</p>";
                echo "<p><a href='{$output_filename}' target='_blank'>Click here to view downloaded PDF</a></p>";
                $success = true;
                break;
            } else {
                echo "<p style='color:orange;'>Downloaded content was empty or HTML.</p>";
            }
        } catch (Exception $sub_e) {
            echo "<p style='color:red;'>Failed with ID {$file_id}: " . htmlspecialchars($sub_e->getMessage()) . "</p>";
        }
    }
    
    if (!$success) {
        echo "<p style='color:red;'><strong>All matching file IDs failed to download.</strong></p>";
    }

} catch (Exception $e) {
    echo "<p style='color:red;'><strong>API Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>