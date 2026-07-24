<?php
session_start();
// Redirect if no revert result exists
if (!isset($_SESSION['revert_result'])) { 
    header("Location: import_dm.php"); 
    exit; 
}

$res = $_SESSION['revert_result'];
$modeText = (isset($res['mode']) && $res['mode'] === 'update') ? 'UPDATE' : 'ADD NEW';
$totalReverted = isset($res['total_reverted']) ? $res['total_reverted'] : 0;
$filename = isset($res['filename']) ? $res['filename'] : 'Unknown File';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Revert Summary</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; padding: 40px; }
        .container { max-width: 900px; margin: 0 auto; padding: 40px; background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 20px; margin: 30px 0; }
        .stat-card { padding: 25px; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; text-align: center; border-top: 4px solid #e11d48; }
        .stat-value { font-size: 38px; font-weight: 800; margin-top: 10px; color: #e11d48; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 5px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #2563eb; color: white; }
    </style>
</head>
<body>

<div class="container">
    <a href="import_dm.php?clear=1" style="text-decoration: none; color: #64748b;">&larr; Back to Import Page</a>
 
    <h2 style="color: #1e293b; margin-top: 20px;">Batch Revert Process Complete</h2>
    <p style="color: #64748b; font-size: 15px;">Successfully rolled back the import file: <strong><?php echo htmlspecialchars($filename); ?></strong> (Mode: <?php echo htmlspecialchars($modeText); ?>)</p>

    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">

    <div class="stats-grid">
        <div class="stat-card">
            <div style="color: #475569; font-size: 16px; font-weight: 600;">Total Rows Reverted / Rolled Back</div>
            <div class="stat-value"><?php echo htmlspecialchars($totalReverted); ?></div>
        </div>
    </div>
    
    <div style="margin-top: 30px;">
        <a href="import_dm.php?clear=1" class="btn btn-primary">Return to Import Management</a>
    </div>
</div>

</body>
</html>