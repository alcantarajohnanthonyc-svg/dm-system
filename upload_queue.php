<?php
require_once 'config.php';
require_once 'main.php'; // Includes the core render_layout() wrapper

$target_dir = __DIR__ . "/uploads/debit_memos/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// Handle asynchronous AJAX batch upload submission with duplicate check logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_files'])) {$success_files = [];
    $failed_files = [];$overwrite = isset($_POST['overwrite']) &&$_POST['overwrite'] === '1';

    foreach ($_FILES['pdf_files']['name'] as $i =>$name) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $tmp_name =$_FILES['pdf_files']['tmp_name'][$i];$basename = basename($name);$destination = $target_dir .$basename;
            
            // Check if file already exists
            if (file_exists($destination)) {
                if ($overwrite) {
                    // Overwrite the existing file
                    if (move_uploaded_file($tmp_name,$destination)) {
                        $success_files[] =$name . " (Updated/Overwritten)";
                    } else {
                        $failed_files[] =$name . " (Failed to overwrite)";
                    }
                } else {
                    // Skip the duplicate file
                    $failed_files[] =$name . " (Skipped: Duplicate filename exists)";
                }
            } else {
                // Brand new file, upload normally
                if (move_uploaded_file($tmp_name,$destination)) {
                    $success_files[] =$name;
                } else {
                    $failed_files[] =$name . " (Upload failed)";
                }
            }
        } else {
            $failed_files[] =$name . " (Invalid file type)";
        }
    }

    echo json_encode([
        'status' => 'success',
        'success_files' => $success_files,
        'failed_files' => $failed_files
    ]);
    exit;
}

// Start output buffering to capture page content
ob_start();
?>

<div class="max-w-4xl mx-auto py-6 px-4">
    <!-- Main Card Container -->
    <div class="bg-white shadow-lg rounded-xl p-6 border border-gray-100">
        
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-xl font-bold text-gray-800 flex items-center space-x-2">
                <span>📁</span>
                <span>Debit Memo PDF Upload Queue</span>
            </h1>
            <p class="text-sm text-gray-500 mt-1">Select or drag-and-drop individual PDF files or entire folders. Supports duplicate handling controls.</p>
        </div>

        <!-- Step 1 Title & Clear Option -->
        <div class="flex justify-between items-center mb-2">
            <span class="text-xs font-bold tracking-wider text-red-600 uppercase">Step 1: Select PDF files or folders (Accumulative)</span>
            <button type="button" id="clearBtn" class="text-xs text-gray-400 hover:text-red-600 flex items-center space-x-1 transition-colors">
                <i class="las la-trash"></i>
                <span>Clear All Files</span>
            </button>
        </div>

        <!-- Dropzone Box -->
        <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-500 transition-colors bg-gray-50/50 cursor-pointer mb-4 relative">
            <input type="file" id="pdf_files" name="pdf_files[]" multiple accept=".pdf" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
            <div class="flex flex-col items-center pointer-events-none">
                <svg class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <p id="queueCountText" class="text-sm font-medium text-gray-600 mb-3">0 file(s) loaded in queue.</p>
                <button type="button" class="px-4 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">
                    Browse Files / Folders
                </button>
            </div>
        </div>

        <!-- Duplicate Option Checkbox -->
        <div class="mb-6 flex items-center bg-blue-50 border border-blue-100 rounded-lg p-3">
            <input type="checkbox" id="overwriteCheckbox" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
            <label for="overwriteCheckbox" class="ml-2 text-xs font-medium text-blue-900 cursor-pointer">
                <strong>Overwrite existing files</strong> if they already exist on the server (Leave unchecked to skip duplicates).
            </label>
        </div>

        <!-- Selected Files Queue Box -->
        <div class="mb-6">
            <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2" id="queueHeaderLabel">Selected Files Queue (0)</div>
            <div id="fileListContainer" class="border border-gray-200 rounded-lg bg-gray-50 min-h-[80px] max-h-48 overflow-y-auto p-3 divide-y divide-gray-100">
                <p class="text-xs text-gray-400 text-center py-4" id="emptyQueueMsg">No files selected yet.</p>
            </div>
        </div>

        <!-- Progress Bar Container (Hidden by default) -->
        <div id="progressContainer" class="mb-6 hidden">
            <div class="flex justify-between text-xs font-semibold text-gray-600 mb-1">
                <span id="progressText">Uploading batches...</span>
                <span id="progressPercentage">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>

        <!-- Result Log Box (Hidden by default) -->
        <div id="logContainer" class="mb-6 hidden border border-gray-200 rounded-lg p-4 bg-slate-900 text-white text-xs font-mono max-h-40 overflow-y-auto">
            <div class="font-bold text-gray-300 mb-2 border-b border-slate-700 pb-1">Upload Result Logs:</div>
            <div id="logContent" class="space-y-1"></div>
        </div>

        <!-- Submit Button -->
        <div>
            <button type="button" id="uploadBtn" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2.5 px-4 rounded-lg shadow transition-colors flex items-center justify-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                <span>🚀</span>
                <span>Process and Upload Queue</span>
            </button>
        </div>

    </div>
</div>

<script>
let accumulatedFiles = [];
const fileInput = document.getElementById('pdf_files');
const fileListContainer = document.getElementById('fileListContainer');
const emptyQueueMsg = document.getElementById('emptyQueueMsg');
const queueCountText = document.getElementById('queueCountText');
const queueHeaderLabel = document.getElementById('queueHeaderLabel');
const uploadBtn = document.getElementById('uploadBtn');
const clearBtn = document.getElementById('clearBtn');
const dropZone = document.getElementById('dropZone');
const overwriteCheckbox = document.getElementById('overwriteCheckbox');
const progressContainer = document.getElementById('progressContainer');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const progressPercentage = document.getElementById('progressPercentage');
const logContainer = document.getElementById('logContainer');
const logContent = document.getElementById('logContent');

fileInput.addEventListener('change', (e) => {
    handleNewFiles(e.target.files);
    fileInput.value = '';
});

['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropZone.classList.add('border-blue-500', 'bg-blue-50/20');
    }, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-blue-500', 'bg-blue-50/20');
    }, false);
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    handleNewFiles(e.dataTransfer.files);
});

function handleNewFiles(files) {
    for (let i = 0; i < files.length; i++) {
        let file = files[i];
        if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
            let exists = accumulatedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                accumulatedFiles.push(file);
            }
        }
    }
    updateQueueUI();
}

function updateQueueUI() {
    fileListContainer.innerHTML = '';
    if (accumulatedFiles.length > 0) {
        emptyQueueMsg.style.display = 'none';
        uploadBtn.removeAttribute('disabled');
        queueCountText.textContent = `${accumulatedFiles.length} file(s) loaded in queue.`;
        queueHeaderLabel.textContent = `SELECTED FILES QUEUE (${accumulatedFiles.length})`;

        accumulatedFiles.forEach((file, index) => {
            const item = document.createElement('div');
            item.className = 'flex justify-between items-center py-2 px-3 text-xs text-gray-700 hover:bg-white rounded transition-colors';
            item.innerHTML = `
                <div class="flex items-center space-x-2 truncate">
                    <span class="text-red-500 font-bold">📄</span>
                    <span class="truncate font-medium">${file.name}</span>
                </div>
                <div class="flex items-center space-x-3 shrink-0">
                    <span class="text-gray-400">${(file.size / 1024).toFixed(1)} KB</span>
                    <button type="button" onclick="removeFile(${index})" class="text-gray-400 hover:text-red-600 font-bold">✕</button>
                </div>
            `;
            fileListContainer.appendChild(item);
        });
    } else {
        emptyQueueMsg.style.display = 'block';
        uploadBtn.setAttribute('disabled', 'true');
        queueCountText.textContent = '0 file(s) loaded in queue.';
        queueHeaderLabel.textContent = 'SELECTED FILES QUEUE (0)';
    }
}

function removeFile(index) {
    accumulatedFiles.splice(index, 1);
    updateQueueUI();
}

clearBtn.addEventListener('click', () => {
    accumulatedFiles = [];
    updateQueueUI();
    logContainer.classList.add('hidden');
    progressContainer.classList.add('hidden');
});

// Batch uploading process with duplicate action option
uploadBtn.addEventListener('click', async function() {
    if (accumulatedFiles.length === 0) return;

    uploadBtn.setAttribute('disabled', 'true');
    clearBtn.setAttribute('disabled', 'true');
    progressContainer.classList.remove('hidden');
    logContainer.classList.remove('hidden');
    logContent.innerHTML = '';

    const batchSize = 10;
    const totalFiles = accumulatedFiles.length;
    let uploadedCount = 0;
    const shouldOverwrite = overwriteCheckbox.checked ? '1' : '0';

    for (let i = 0; i < totalFiles; i += batchSize) {
        const batch = accumulatedFiles.slice(i, i + batchSize);
        const formData = new FormData();
        
        batch.forEach(file => {
            formData.append('pdf_files[]', file);
        });
        formData.append('overwrite', shouldOverwrite);

        progressText.textContent = `Uploading batch ${Math.floor(i / batchSize) + 1} of ${Math.ceil(totalFiles / batchSize)}...`;

        try {
            const response = await fetch('upload_queue.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.status === 'success') {
                if (data.success_files) {
                    data.success_files.forEach(f => {
                        logContent.innerHTML += `<div class="text-green-400">✓ SUCCESS: ${f}</div>`;
                    });
                }
                if (data.failed_files) {
                    data.failed_files.forEach(f => {
                        logContent.innerHTML += `<div class="text-yellow-400">⚠ NOTICE: ${f}</div>`;
                    });
                }
            }
        } catch (err) {
            console.error('Batch error:', err);
            logContent.innerHTML += `<div class="text-red-400">✗ Network/Server Error on batch starting at index ${i}</div>`;
        }

        uploadedCount += batch.length;
        let percentage = Math.round((uploadedCount / totalFiles) * 100);
        progressBar.style.width = `${percentage}%`;
        progressPercentage.textContent = `${percentage}%`;
        logContainer.scrollTop = logContainer.scrollHeight;
    }

    progressText.textContent = 'Upload complete!';
    uploadBtn.innerHTML = '<span>🚀</span><span>Process and Upload Queue</span>';
    uploadBtn.removeAttribute('disabled');
    clearBtn.removeAttribute('disabled');

    accumulatedFiles = [];
    updateQueueUI();
});
</script>

<?php
$content = ob_get_clean();
render_layout("Upload Queue", $content);
?>