<?php
session_start();
require_once 'config.php';
require_once 'main.php';

// Logic to clear session data when 'clear=1' is passed via URL
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    unset($_SESSION['import_result']);
    header("Location: import_dm.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

ob_start();
?>


<!-- Loading Overlay -->
<div id="loading_overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.95); z-index: 9999; justify-content: center; align-items: center; flex-direction: column;">
    <div id="overlay_text" style="font-size: 28px; font-weight: bold; color: #1e293b; margin-bottom: 8px;">Processing Import...</div>
    
    <!-- File name -->
    <div id="import_filename_display" style="font-size: 18px; font-weight: 600; color: #2563eb; margin-bottom: 15px;"></div>
    
    <!-- Big Progress Bar Track -->
    <div style="width: 550px; background: #e2e8f0; border-radius: 9999px; height: 28px; overflow: hidden; margin-bottom: 15px; border: 2px solid #cbd5e1; box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);">
        <!-- Progress Bar Fill -->
        <div id="progress_bar_fill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #2563eb, #3b82f6); transition: width 0.2s ease-in-out; border-radius: 9999px;"></div>
    </div>

    <!-- Line count text made larger -->
    <div id="import_line_count" style="font-size: 16px; font-weight: 600; color: #334155; margin-bottom: 12px;"></div>
    
    <div id="wait_message" style="color: #64748b; font-size: 14px; font-weight: 500;">Please wait, do not refresh or leave the page.</div>
</div>


<!-- New Revert Loading Overlay (Independent) -->
<div id="revert_loading_overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.95); z-index: 9999; justify-content: center; align-items: center; flex-direction: column;">
    <div style="font-size: 28px; font-weight: bold; color: #e11d48; margin-bottom: 8px;">Reverting Batch Data...</div>
    <div style="font-size: 16px; font-weight: 600; color: #475569; margin-bottom: 15px;">Restoring previous database states, please wait...</div>
    
    <!-- Big Progress Bar Track -->
    <div style="width: 550px; background: #e2e8f0; border-radius: 9999px; height: 28px; overflow: hidden; margin-bottom: 15px; border: 2px solid #cbd5e1; box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);">
        <!-- Progress Bar Fill -->
        <div id="revert_progress_bar_fill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #e11d48, #f43f5e); transition: width 0.2s ease-in-out; border-radius: 9999px;"></div>
    </div>

    <div style="color: #64748b; font-size: 14px; font-weight: 500;">Do not refresh or leave the page while reverting.</div>
</div>


<div style="width: 90%; margin: 40px auto 40px auto; padding: 20px 40px; background: white; border-radius: 12px; border: 1px solid #e5e7eb;">
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <a href="list_dm.php" style="font-size: 14px; font-weight: bold; color: #475569; text-decoration: none;">&larr; Back to List</a>


<div style="text-align: center; margin-bottom: 20px;">
    <a href="download_template.php" 
       style="color: #3b82f6; font-weight: 600; text-decoration: underline; font-size: 14px;">
        Download CSV Template
    </a>
</div>


</div>
    <div style="background: white; padding: 30px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form action="process_import.php" method="POST" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="import_mode" id="import_mode" value="">
            <input type="file" name="file" id="file_input" accept=".csv" required style="display: none;" onchange="handleFileSelection(this)">
            
            <div style="display: flex; justify-content: center; gap: 40px; margin-bottom: 20px;">
                <button type="button" onclick="setMode('add')" id="btn_add" style="width: 200px; background: #10b981; color: white; padding: 12px; border-radius: 8px; cursor: pointer; opacity: 0.5; border: none; font-weight: bold;">Import Add New</button>
                <button type="button" onclick="setMode('update')" id="btn_update" style="width: 200px; background: #3b82f6; color: white; padding: 12px; border-radius: 8px; cursor: pointer; opacity: 0.5; border: none; font-weight: bold;">Import Update</button>
            </div>
            
            <div style="border: 2px dashed #d1d5db; padding: 30px; text-align: center; border-radius: 8px; margin-bottom: 20px; background: #f9fafb;">
                <span id="file_name_display" style="color: #6b7280; font-weight: 600;">No file selected</span>
            </div>
            <button type="button" id="submit_btn" onclick="processForm()" style="width: 100%; max-width: 420px; display: block; margin: 0 auto; background: #1e293b; color: white; padding: 15px; border: none; border-radius: 8px; cursor: pointer;">Process Import</button>
        </form>
    </div>

<div style="margin-top: 40px; padding: 20px 40px; background: white; border-radius: 12px; border: 1px solid #e5e7eb;"> 
<h3>Recent Imports</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px; text-align: left;">Filename</th>
                    <th style="padding: 12px; text-align: left;">User</th>
                    <th style="padding: 12px; text-align: left;">Mode</th>
                    <th style="padding: 12px; text-align: center;">Total</th>
                    <th style="padding: 12px; text-align: center;">Success</th>
                    <th style="padding: 12px; text-align: center;">Failed</th>
                    <th style="padding: 12px; text-align: left;">Date</th>
                    <th style="padding: 12px; text-align: left;">Action</th>
                </tr>
            </thead>
            <tbody>
         <?php
            $today = date('Y-m-d');
            $userId = $_SESSION['user_id'];
            $isSuperAdmin = ($_SESSION['role'] === 'superadmin');

            if ($isSuperAdmin) {
                $stmt = $conn->query("SELECT h.batch_id, h.filename, h.import_mode, h.total_rows, h.success_count, h.error_count, h.created_at, h.user_id, u.username 
                                   FROM import_history h 
                                   LEFT JOIN users u ON h.user_id = u.user_id 
                                   ORDER BY h.created_at DESC");
            } else {
                $stmt = $conn->prepare("SELECT h.batch_id, h.filename, h.import_mode, h.total_rows, h.success_count, h.error_count, h.created_at, h.user_id, u.username 
                                        FROM import_history h 
                                        LEFT JOIN users u ON h.user_id = u.user_id 
                                        WHERE h.user_id = ? AND DATE(h.created_at) = ? 
                                        ORDER BY h.created_at DESC");
                $stmt->execute([$userId, $today]);
            }

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): 
                $canRevert = ($isSuperAdmin || $row['user_id'] == $userId);
            ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px;"><?php echo htmlspecialchars($row['filename']); ?></td>
                    <td style="padding: 12px; font-weight: 600; color: #475569;"><?php echo htmlspecialchars(isset($row['username']) ? $row['username'] : 'N/A'); ?></td>
                    <td style="padding: 12px; font-weight: bold; color: <?php echo ($row['import_mode'] === 'update') ? '#3b82f6' : '#10b981'; ?>;">
                        <?php echo strtoupper(htmlspecialchars($row['import_mode'])); ?>
                    </td>
                    <td style="padding: 12px; text-align: center;"><?php echo htmlspecialchars($row['total_rows']); ?></td>
                    <td style="padding: 12px; text-align: center; color: #059669; font-weight: bold;"><?php echo htmlspecialchars($row['success_count']); ?></td>
                    <td style="padding: 12px; text-align: center; color: #dc2626; font-weight: bold;"><?php echo htmlspecialchars($row['error_count']); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($row['created_at']); ?></td>
                    <td style="padding: 12px;">
                        <?php if ($canRevert): ?>
                            <!-- Revert triggered via custom JS function for progress overlay animation -->
                            <a href="#" onclick="triggerRevert('<?php echo urlencode($row['batch_id']); ?>'); return false;" 
                               style="color: #e11d48; font-weight: bold; text-decoration: none;">Revert</a>
                        <?php else: ?>
                            <span style="color: #64748b; font-size: 11px; font-weight: 700; background: #e2e8f0; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; cursor: not-allowed;">Locked</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let cachedLineCount = 0;

function setMode(mode) {
    const modeValue = (mode === 'add') ? 'add_new' : 'update';
    document.getElementById('import_mode').value = modeValue;
    
    const btnAdd = document.getElementById('btn_add');
    const btnUpdate = document.getElementById('btn_update');
    
    if (mode === 'add') {
        btnAdd.style.opacity = '1';
        btnUpdate.style.opacity = '0.5';
    } else {
        btnUpdate.style.opacity = '1';
        btnAdd.style.opacity = '0.5';
    }
    document.getElementById('file_input').click();
}

function handleFileSelection(input) {
    if (input.files.length > 0) {
        const file = input.files[0];
        document.getElementById('file_name_display').innerText = file.name;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split(/\r\n|\n/).filter(line => line.trim() !== '');
            cachedLineCount = lines.length > 1 ? lines.length - 1 : lines.length;
        };
        reader.readAsText(file);
    }
}

let progressInterval;

function processForm() {
    const btn = document.getElementById('submit_btn');
    const mode = document.getElementById('import_mode').value;
    const fileInput = document.getElementById('file_input');

    if (mode === "") { alert("Please select a mode first."); return; }
    if (fileInput.files.length === 0) { alert("Please select a CSV file first."); return; }

    const modeLabel = (mode === 'add_new') ? 'IMPORT ADD NEW' : 'IMPORT UPDATE';
    const fileName = fileInput.files[0].name;
    
    document.getElementById('import_filename_display').innerText = "File: " + fileName;
    document.getElementById('import_line_count').innerText = "Processing: 0 / ... rows finished";
    document.getElementById('progress_bar_fill').style.width = '0%';
    document.getElementById('overlay_text').innerText = 'Processing ' + modeLabel + '...';
    document.getElementById('loading_overlay').style.display = 'flex';

    btn.innerText = "PROCESSING...";
    btn.disabled = true;

    document.getElementById('importForm').submit();

    progressInterval = setInterval(function() {
        fetch('check_progress.php')
            .then(response => response.json())
            .then(data => {
                if (data && data.total > 0) {
                    let percent = data.percent;
                    let processed = data.processed;
                    let total = data.total;

                    document.getElementById('import_line_count').innerText = "Processing: " + processed + " / " + total + " rows finished";
                    document.getElementById('progress_bar_fill').style.width = percent + '%';
                }
            })
            .catch(err => console.error("Progress fetch error:", err));
    }, 300);
}

// Revert Action Progress Trigger Handler
function triggerRevert(batchId) {
    if (!confirm('Are you sure you want to revert this batch?')) {
        return;
    }

    // Show the independent Revert Overlay
    document.getElementById('revert_loading_overlay').style.display = 'flex';
    
    let currentWidth = 0;
    const bar = document.getElementById('revert_progress_bar_fill');
    
    // Simulate smooth progress ramp-up while backend loops rolls back data
    let progressAnim = setInterval(function() {
        if (currentWidth < 90) {
            currentWidth += Math.floor(Math.random() * 10) + 5;
            if (currentWidth > 90) currentWidth = 90;
            bar.style.width = currentWidth + '%';
        }
    }, 150);

    // Call your existing revert script asynchronously
  fetch('revert_process.php?action=revert&batch_id=' + batchId)
        .then(response => {
            clearInterval(progressAnim);
            bar.style.width = '100%';
            setTimeout(() => {
                // THIS LINE IS HARDCODED TO REDIRECT BACK TO import_dm.php
               window.location.href = 'revert_result.php';
            }, 400);
        })
        .catch(error => {
            clearInterval(progressAnim);
            alert('An error occurred during revert execution.');
            document.getElementById('revert_loading_overlay').style.display = 'none';
        });
}
</script>

<?php
$content = ob_get_clean();
render_layout("Import Debit Memo", $content);
?>