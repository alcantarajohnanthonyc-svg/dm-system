<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// Redirect if no result exists
if (!isset($_SESSION['import_result'])) { 
    header("Location: import_dm.php"); 
    exit; 
}

$res = $_SESSION['import_result'];
$batchId = isset($res['batch_id']) ? $res['batch_id'] : '';
$modeText = (isset($res['mode']) && $res['mode'] === 'update') ? 'UPDATE' : 'ADD NEW';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Summary</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; padding: 40px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 40px; background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 40px 0; }
        .stat-card { padding: 20px; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; text-align: center; }
        .stat-value { font-size: 32px; font-weight: 800; margin-top: 10px; color: #1e293b; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 5px; border: none; cursor: pointer; }
        .btn-revert { background: #e11d48; color: white; }
        .btn-download { background: #2563eb; color: white; }
        .table-container { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #e2e8f0; white-space: nowrap; }
        th { background: #f1f5f9; position: sticky; top: 0; }
    </style>
</head>
<body>

<!-- Revert Loading Overlay -->
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

<div class="container">
    <a href="import_dm.php?clear=1" style="text-decoration: none; color: #64748b;">&larr; Back to Import Page & Clear Results</a>
 
    <!-- Updated Title -->
    <h2 style="color: #1e293b;">Import <?php echo htmlspecialchars($modeText); ?> Process Complete</h2>

    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">

    <div class="stats-grid">
        <div class="stat-card">
            <div style="color: #475569;">Total</div>
            <div class="stat-value"><?php echo htmlspecialchars($res['total']); ?></div>
        </div>
        <div class="stat-card" style="border-top: 4px solid #10b981;">
            <div style="color: #10b981;">Success</div>
            <div class="stat-value" style="color: #10b981;"><?php echo htmlspecialchars($res['success']); ?></div>
        </div>
        <div class="stat-card" style="border-top: 4px solid #e11d48;">
            <div style="color: #e11d48;">Failed</div>
            <div class="stat-value" style="color: #e11d48;"><?php echo htmlspecialchars($res['failed']); ?></div>
        </div>
    </div>
    
    <h3>Detailed Row Status</h3>
    
    <div style="margin-bottom: 20px;">
        <!-- Revert Button with Progress Trigger -->
        <?php if (!empty($batchId)): ?>
            <button onclick="triggerRevert('<?php echo urlencode($batchId); ?>')" class="btn btn-revert">Revert this Import</button>
        <?php endif; ?>
        <a href="download_full_report.php" class="btn btn-download">Download Full Report</a>
        <a href="download_errors.php" class="btn btn-revert" style="background: #64748b;">Download Errors Only</a>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Company</th>
                    <th>Account</th>
                    <th>Mobile</th>
                    <th>Total Amt</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($res['details'] as $row): ?>
                <tr style="color: <?php echo $row['status'] === 'FAILED' ? '#e11d48' : '#10b981'; ?>">
                    <td><?php echo htmlspecialchars($row['row']); ?></td>
                    <td><?php echo htmlspecialchars(isset($row['original_row'][1]) ? $row['original_row'][1] : ''); ?></td>
                    <td><?php echo htmlspecialchars(isset($row['original_row'][3]) ? $row['original_row'][3] : ''); ?></td>
                    <td><?php echo htmlspecialchars(isset($row['original_row'][4]) ? $row['original_row'][4] : ''); ?></td>
                    <td><?php echo htmlspecialchars(isset($row['original_row'][21]) ? $row['original_row'][21] : ''); ?></td>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($row['status']); ?></td>
                    <td><?php echo htmlspecialchars(isset($row['remarks']) ? $row['remarks'] : 'N/A'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function triggerRevert(batchId) {
    if (!confirm('Are you sure you want to revert this entire import batch?')) {
        return;
    }

    // Show progress overlay
    document.getElementById('revert_loading_overlay').style.display = 'flex';
    
    let currentWidth = 0;
    const bar = document.getElementById('revert_progress_bar_fill');
    
    // Smooth animation tick
    let progressAnim = setInterval(function() {
        if (currentWidth < 90) {
            currentWidth += Math.floor(Math.random() * 10) + 5;
            if (currentWidth > 90) currentWidth = 90;
            bar.style.width = currentWidth + '%';
        }
    }, 150);

    // Asynchronous call to revert process handler
    fetch('revert_process.php?action=revert&batch_id=' + batchId)
        .then(response => {
            clearInterval(progressAnim);
            bar.style.width = '100%';
            setTimeout(() => {
                // Safely redirect to your newly built revert results summary page
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

</body>
</html>