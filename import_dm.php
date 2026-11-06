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

<style>
/* ===== Import page (Aqua theme) ===== */
.imp-wrap { max-width: 1180px; margin: 10px auto 40px; padding: 0 14px; }
.imp-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.imp-pill { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 9999px; font-weight: 700; font-size: 13px; text-decoration: none; background: rgba(255,255,255,.9); color: var(--aq-ink); box-shadow: 0 4px 14px rgba(14,116,110,.10); transition: transform .12s; }
.imp-pill:hover { transform: translateY(-1px); }
.imp-pill i { font-size: 18px; }
.imp-pill-green { background: linear-gradient(135deg, var(--aq-green-1), var(--aq-green-2)); color: #fff; box-shadow: 0 6px 16px rgba(20,199,150,.32); }

.imp-card { background: rgba(255,255,255,.88); border: 1px solid rgba(255,255,255,.9); border-radius: 24px; box-shadow: var(--aq-shadow); padding: 26px 28px; margin-bottom: 22px; }
.imp-card-head { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
.imp-ico { width: 48px; height: 48px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #fff; background: linear-gradient(135deg, var(--aq-green-1), var(--aq-green-2)); box-shadow: 0 6px 14px rgba(20,199,150,.3); flex: 0 0 auto; }
.imp-card-head h3 { margin: 0; font-size: 18px; font-weight: 800; color: var(--aq-ink); }
.imp-card-head p { margin: 2px 0 0; font-size: 12px; color: var(--aq-muted); }
.imp-count { margin-left: auto; padding: 4px 14px; border-radius: 9999px; font-size: 12px; font-weight: 800; background: #d4f6e0; color: #059669; }

.imp-modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 18px; }
.imp-mode { display: flex; align-items: center; gap: 14px; text-align: left; padding: 16px 18px; border-radius: 20px; border: 2px solid transparent; background: #fff; cursor: pointer; box-shadow: 0 6px 18px rgba(14,116,110,.08); transition: all .15s; font-family: inherit; }
.imp-mode:hover { transform: translateY(-2px); }
.imp-mode .imp-mode-ico { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 26px; flex: 0 0 auto; }
.imp-mode strong { display: block; font-size: 15px; color: var(--aq-ink); }
.imp-mode small { color: var(--aq-muted); font-size: 12px; }
.imp-mode-add .imp-mode-ico { background: #d4f6e0; color: #059669; }
.imp-mode-upd .imp-mode-ico { background: #dbeafe; color: #2563eb; }
.imp-mode-add.is-active { border-color: #10b981; background: #f0fdf8; box-shadow: 0 8px 22px rgba(16,185,129,.25); }
.imp-mode-upd.is-active { border-color: #3b82f6; background: #f3f8ff; box-shadow: 0 8px 22px rgba(59,130,246,.22); }

.imp-drop { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 30px 20px; border: 2px dashed #b7e3d8; border-radius: 20px; background: rgba(240,253,249,.7); text-align: center; margin-bottom: 18px; transition: all .15s; }
.imp-drop i { font-size: 44px; color: #14b88a; }
.imp-drop.is-over { border-color: #10b981; background: #e4fbf2; }
.imp-drop.has-file { border-style: solid; background: #effcf6; }
#file_name_display { font-weight: 700; color: var(--aq-muted); word-break: break-all; }
.imp-drop.has-file #file_name_display { color: var(--aq-ink); }
.imp-drop small { color: var(--aq-muted); font-size: 12px; }

.imp-go { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; max-width: 420px; margin: 0 auto; padding: 14px; border: 0; border-radius: 16px; cursor: pointer; font-family: inherit; font-size: 15px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--aq-green-1), var(--aq-green-2)); box-shadow: 0 8px 20px rgba(20,199,150,.35); transition: filter .12s, transform .12s; }
.imp-go:hover { filter: brightness(1.06); transform: translateY(-1px); }
.imp-go:disabled { opacity: .6; cursor: default; transform: none; }
.imp-go i { font-size: 22px; }

/* recent imports: the table scrolls inside its own box so it can never spill out of the card */
.imp-table-wrap { overflow-x: auto; border: 1px solid var(--aq-line); border-radius: 18px; background: #fff; }
.imp-table { width: 100%; min-width: 860px; border-collapse: collapse; font-size: 13px; }
.imp-table thead th { background: linear-gradient(180deg, #dcf8f0, #c8efe6); color: var(--aq-ink); font-weight: 800; padding: 12px 14px; text-align: left; white-space: nowrap; }
.imp-table th.c, .imp-table td.c { text-align: center; }
.imp-table tbody td { padding: 12px 14px; border-top: 1px solid #e6f1ef; color: #23506b; vertical-align: middle; white-space: nowrap; }
.imp-table tbody tr:nth-child(even) { background: rgba(240,252,249,.7); }
.imp-table tbody tr:hover { background: rgba(209,250,229,.45); }
.imp-file { max-width: 260px; overflow: hidden; text-overflow: ellipsis; font-weight: 700; }
.imp-chip { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; letter-spacing: .03em; }
.imp-chip-add { background: #d4f6e0; color: #059669; }
.imp-chip-upd { background: #dbeafe; color: #2563eb; }
.imp-ok { color: #059669; font-weight: 800; }
.imp-bad { color: #e11d48; font-weight: 800; }
.imp-bad.zero { color: #94a3b8; }
.imp-date small { display: block; color: var(--aq-muted); font-size: 11px; }
.imp-revert { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9999px; font-weight: 800; font-size: 12px; color: #e11d48; background: #fde2e7; text-decoration: none; }
.imp-revert:hover { background: #fbcdd5; }
.imp-locked { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; color: #64748b; background: #e2e8f0; text-transform: uppercase; cursor: not-allowed; }
.imp-empty { text-align: center; padding: 30px; color: var(--aq-muted); font-weight: 600; }

/* progress overlays (same look as the app loader) */
.imp-overlay { display: none; position: fixed; inset: 0; z-index: 99999; align-items: center; justify-content: center; flex-direction: column; background: rgba(8,47,60,.5); backdrop-filter: blur(6px); }
.imp-overlay-card { width: min(560px, 92vw); padding: 30px 34px; border-radius: 26px; text-align: center; background: linear-gradient(160deg, rgba(255,255,255,.98), rgba(224,250,243,.98)); box-shadow: 0 30px 70px rgba(5,60,58,.35); }
.imp-overlay-card h4 { margin: 0 0 6px; font-size: 20px; font-weight: 800; color: var(--aq-ink); }
.imp-overlay-card.rev h4 { color: #e11d48; }
.imp-track { height: 16px; margin: 16px 0 12px; border-radius: 9999px; background: #d9f3ec; overflow: hidden; }
.imp-overlay-card.rev .imp-track { background: #fde2e7; }
.imp-fill { width: 0%; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #0ea67f, #14c796); transition: width .2s ease-in-out; }
.imp-overlay-card.rev .imp-fill { background: linear-gradient(90deg, #e11d48, #f43f5e); }
.imp-overlay-card .fn { font-weight: 700; color: #0e9f78; margin-bottom: 4px; word-break: break-all; }
.imp-overlay-card .ln { font-weight: 700; color: var(--aq-ink); font-size: 14px; }
.imp-overlay-card .wait { margin-top: 10px; color: var(--aq-muted); font-size: 12px; }
</style>

<!-- Loading Overlay (import progress) -->
<div id="loading_overlay" class="imp-overlay" style="display: none;">
    <div class="imp-overlay-card">
        <h4 id="overlay_text">Processing Import...</h4>
        <div id="import_filename_display" class="fn"></div>
        <div class="imp-track"><div id="progress_bar_fill" class="imp-fill"></div></div>
        <div id="import_line_count" class="ln"></div>
        <div id="wait_message" class="wait">Please wait, do not refresh or leave the page.</div>
    </div>
</div>

<!-- Revert Loading Overlay (independent) -->
<div id="revert_loading_overlay" class="imp-overlay" style="display: none;">
    <div class="imp-overlay-card rev">
        <h4>Reverting Batch Data...</h4>
        <div class="ln">Restoring previous database states, please wait...</div>
        <div class="imp-track"><div id="revert_progress_bar_fill" class="imp-fill"></div></div>
        <div class="wait">Do not refresh or leave the page while reverting.</div>
    </div>
</div>

<div class="imp-wrap">

    <div class="imp-top">
        <a href="list_dm.php" class="imp-pill"><i class="las la-arrow-left"></i> Back to List</a>
        <a href="download_template.php" class="imp-pill imp-pill-green"><i class="las la-download"></i> Download CSV Template</a>
    </div>

    <!-- IMPORT CARD -->
    <div class="imp-card">
        <div class="imp-card-head">
            <div class="imp-ico"><i class="las la-file-import"></i></div>
            <div>
                <h3>Import Debit Memo</h3>
                <p>1. Choose a mode &nbsp;&bull;&nbsp; 2. Pick your CSV file &nbsp;&bull;&nbsp; 3. Press Process Import</p>
            </div>
        </div>

        <form action="process_import.php" method="POST" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="import_mode" id="import_mode" value="">
            <input type="file" name="file" id="file_input" accept=".csv" required style="display: none;" onchange="handleFileSelection(this)">

            <div class="imp-modes">
                <button type="button" onclick="setMode('add')" id="btn_add" class="imp-mode imp-mode-add">
                    <span class="imp-mode-ico"><i class="las la-plus-circle"></i></span>
                    <span><strong>Import Add New</strong><small>Create new accounts and records</small></span>
                </button>
                <button type="button" onclick="setMode('update')" id="btn_update" class="imp-mode imp-mode-upd">
                    <span class="imp-mode-ico"><i class="las la-sync"></i></span>
                    <span><strong>Import Update</strong><small>Update records that already exist</small></span>
                </button>
            </div>

            <div class="imp-drop" id="drop_zone">
                <i class="las la-cloud-upload-alt"></i>
                <span id="file_name_display">No file selected</span>
                <small>Click a mode above to choose a .csv file, or drag &amp; drop it here</small>
            </div>

            <button type="button" id="submit_btn" onclick="processForm()" class="imp-go"><i class="las la-play-circle"></i> Process Import</button>
        </form>
    </div>

    <!-- RECENT IMPORTS -->
    <?php
        $today = date('Y-m-d');
        $userId = $_SESSION['user_id'];
        $isSuperAdmin = ($_SESSION['role'] === 'superadmin');

        if ($isSuperAdmin) {
            // Added WHERE h.status = 1 here
            $stmt = $conn->query("SELECT h.batch_id, h.filename, h.import_mode, h.total_rows, h.success_count, h.error_count, h.created_at, h.user_id, u.username 
                                 FROM import_history h 
                                 LEFT JOIN users u ON h.user_id = u.user_id 
                                 WHERE h.status = 1 
                                 ORDER BY h.created_at DESC");
        } else {
            // Added AND h.status = 1 to the existing conditions
            $stmt = $conn->prepare("SELECT h.batch_id, h.filename, h.import_mode, h.total_rows, h.success_count, h.error_count, h.created_at, h.user_id, u.username 
                                    FROM import_history h 
                                    LEFT JOIN users u ON h.user_id = u.user_id 
                                    WHERE h.user_id = ? AND DATE(h.created_at) = ? AND h.status = 1 
                                    ORDER BY h.created_at DESC");
            $stmt->execute([$userId, $today]);
        }
        $imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="imp-card">
        <div class="imp-card-head">
            <div class="imp-ico"><i class="las la-history"></i></div>
            <div>
                <h3>Recent Imports</h3>
                <p><?php echo $isSuperAdmin ? 'All import batches' : 'Your imports for today'; ?></p>
            </div>
            <span class="imp-count"><?php echo count($imports); ?> batch<?php echo count($imports) === 1 ? '' : 'es'; ?></span>
        </div>

        <div class="imp-table-wrap">
            <table class="imp-table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>User</th>
                        <th>Mode</th>
                        <th class="c">Total</th>
                        <th class="c">Success</th>
                        <th class="c">Failed</th>
                        <th>Date</th>
                        <th class="c">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($imports)): ?>
                    <tr><td colspan="8" class="imp-empty">No imports yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($imports as $row):
                    $canRevert = ($isSuperAdmin || $row['user_id'] == $userId);
                    $isUpdate  = ($row['import_mode'] === 'update');
                    $ts        = strtotime($row['created_at']);
                ?>
                    <tr>
                        <td class="imp-file" title="<?php echo htmlspecialchars($row['filename']); ?>"><?php echo htmlspecialchars($row['filename']); ?></td>
                        <td><?php echo htmlspecialchars(isset($row['username']) ? $row['username'] : 'N/A'); ?></td>
                        <td><span class="imp-chip <?php echo $isUpdate ? 'imp-chip-upd' : 'imp-chip-add'; ?>"><?php echo strtoupper(htmlspecialchars(str_replace('_', ' ', $row['import_mode']))); ?></span></td>
                        <td class="c"><?php echo htmlspecialchars($row['total_rows']); ?></td>
                        <td class="c imp-ok"><?php echo htmlspecialchars($row['success_count']); ?></td>
                        <td class="c imp-bad <?php echo ((int)$row['error_count'] === 0) ? 'zero' : ''; ?>"><?php echo htmlspecialchars($row['error_count']); ?></td>
                        <td class="imp-date"><?php echo $ts ? date('M d, Y', $ts) . '<small>' . date('h:i A', $ts) . '</small>' : htmlspecialchars($row['created_at']); ?></td>
                        <td class="c">
                            <?php if ($canRevert): ?>
                                <!-- Revert triggered via custom JS function for progress overlay animation -->
                                <a href="#" onclick="triggerRevert('<?php echo urlencode($row['batch_id']); ?>'); return false;" class="imp-revert"><i class="las la-undo"></i> Revert</a>
                            <?php else: ?>
                                <span class="imp-locked"><i class="las la-lock"></i> Locked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let cachedLineCount = 0;

function setMode(mode) {
    const modeValue = (mode === 'add') ? 'add_new' : 'update';
    document.getElementById('import_mode').value = modeValue;

    const btnAdd = document.getElementById('btn_add');
    const btnUpdate = document.getElementById('btn_update');
    btnAdd.classList.toggle('is-active', mode === 'add');
    btnUpdate.classList.toggle('is-active', mode !== 'add');

    document.getElementById('file_input').click();
}

function handleFileSelection(input) {
    if (input.files.length > 0) {
        const file = input.files[0];
        document.getElementById('file_name_display').innerText = file.name;
        document.getElementById('drop_zone').classList.add('has-file');

        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split(/\r\n|\n/).filter(line => line.trim() !== '');
            cachedLineCount = lines.length > 1 ? lines.length - 1 : lines.length;
        };
        reader.readAsText(file);
    }
}

// Drag & drop a CSV onto the drop zone
(function () {
    const zone = document.getElementById('drop_zone');
    const input = document.getElementById('file_input');
    if (!zone || !input) return;
    ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('is-over'); }));
    zone.addEventListener('drop', e => {
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (!f) return;
        if (!/\.csv$/i.test(f.name)) { alert('Please drop a .csv file.'); return; }
        input.files = e.dataTransfer.files;
        handleFileSelection(input);
    });
})();

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