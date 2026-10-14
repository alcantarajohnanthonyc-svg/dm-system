<?php
session_start();
require_once 'config.php';
require_once 'main.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Enable exception handling on PDO
if (isset($conn)) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// 1. Date Filters Setup
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$date_clause_dmi = "";
$date_clause_dl  = "";
$params = [];

if (!empty($start_date) && !empty($end_date)) {
    $date_clause_dmi = " WHERE i.coverage_start >= :start_date AND i.coverage_end <= :end_date";
    $date_clause_dl  = " WHERE dispatch_date >= :start_date AND dispatch_date <= :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date']   = $end_date;
}

// Helper to execute prepared statements safely with parameters
function fetchAllSafe($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function fetchOneSafe($conn, $sql, $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// -------------------------------------------------------------------------
// 2. FINANCIAL COMPUTATION LOGIC (CLEAN NUMERIC + NON-NEGATIVE PROCESSED DM)
// -------------------------------------------------------------------------
// 1. Clean string numbers
$clean_proc_dm = "CAST(NULLIF(REGEXP_REPLACE(COALESCE(i.debit_memo_details, '0'), '[^0-9.-]', ''), '') AS DECIMAL(12,2))";
$clean_add_ons = "CAST(NULLIF(REGEXP_REPLACE(COALESCE(i.add_ons, '0'), '[^0-9.-]', ''), '') AS DECIMAL(12,2))";
$clean_curr_ch = "CAST(NULLIF(REGEXP_REPLACE(COALESCE(i.current_charges, '0'), '[^0-9.-]', ''), '') AS DECIMAL(12,2))";
$clean_approved = "CAST(NULLIF(REGEXP_REPLACE(COALESCE(i.approved_plan, '0'), '[^0-9.-]', ''), '') AS DECIMAL(12,2))";

// 2. If negative debit_memo_details, treat as 0
$valid_proc_dm = "CASE WHEN $clean_proc_dm < 0 THEN 0 ELSE COALESCE($clean_proc_dm, 0) END";

// 3. Final DM Formula: Max(0, Valid Processed DM - Add Ons)
$calc_final_dm_expr = "GREATEST(0, ($valid_proc_dm - COALESCE($clean_add_ons, 0)))";

// -------------------------------------------------------------------------
// 3. FINANCIAL & ACCOUNT SUMMARY
// -------------------------------------------------------------------------
$sql_summary = "SELECT 
                    COUNT(DISTINCT m.account_number) as total_accounts, 
                    COUNT(i.id) as total_dm_items,
                    SUM($valid_proc_dm) as total_processed_dm,
                    SUM(COALESCE($clean_curr_ch, 0)) as total_current_charges,
                    SUM($calc_final_dm_expr) as total_final_dm
                FROM debit_memos m 
                JOIN debit_memo_items i ON m.dm_id = i.dm_id $date_clause_dmi";

$summary = fetchOneSafe($conn, $sql_summary, $params);

// Approved Plan (Company Share) - same figure the email summary shows
$sql_plan = "SELECT SUM(COALESCE($clean_approved, 0)) as total_approved_plan
             FROM debit_memos m
             JOIN debit_memo_items i ON m.dm_id = i.dm_id $date_clause_dmi";
$plan_row = fetchOneSafe($conn, $sql_plan, $params);
$approved_plan_value = (float)($plan_row['total_approved_plan'] ?? 0);

$final_dm_value        = (float)($summary['total_final_dm'] ?? 0);
$current_charges_value = (float)($summary['total_current_charges'] ?? 0);

// -------------------------------------------------------------------------
// 4. SOA ATTACHMENT METRICS (BASED ON ACTIVE LIST ITEMS)
// -------------------------------------------------------------------------
$sql_soa = "SELECT 
    COUNT(DISTINCT i.id) as total_items,
    COUNT(DISTINCT CASE WHEN pdf.id IS NOT NULL THEN i.id END) as with_soa_count
    FROM debit_memo_items i
    JOIN debit_memos m ON m.dm_id = i.dm_id
    LEFT JOIN pdf_extracted_details pdf ON pdf.account_number = m.account_number 
         AND ABS(DATEDIFF(i.coverage_end, STR_TO_DATE(SUBSTRING_INDEX(pdf.billing_period, ' - ', -1), '%Y-%m-%d'))) <= 5
         AND (
             pdf.mobile_number IS NULL OR pdf.mobile_number = '' OR pdf.mobile_number = 'N/A' 
             OR RIGHT(REGEXP_REPLACE(pdf.mobile_number, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(i.mobile_number, '[^0-9]', ''), 10)
         ) $date_clause_dmi";

$soa_metrics = fetchOneSafe($conn, $sql_soa, $params);

$total_items_soa = (int)($soa_metrics['total_items'] ?? 0);
$with_soa        = (int)($soa_metrics['with_soa_count'] ?? 0);
$without_soa     = max(0, $total_items_soa - $with_soa);
$soa_percentage  = $total_items_soa > 0 ? round(($with_soa / $total_items_soa) * 100, 1) : 0;

// -------------------------------------------------------------------------
// 5. EMAIL DISPATCH STATUS METRICS
// -------------------------------------------------------------------------
$email_stats = ['success' => 0, 'failed' => 0, 'total_sent' => 0];
$sql_email = "SELECT 
    COUNT(*) as total_sent,
    SUM(CASE WHEN UPPER(status) = 'SUCCESS' THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN UPPER(status) = 'FAILED' THEN 1 ELSE 0 END) as failed_count
    FROM dispatch_logs $date_clause_dl";

$e_res = fetchOneSafe($conn, $sql_email, $params);
if (!empty($e_res)) {
    $email_stats['total_sent'] = $e_res['total_sent'] ?? 0;
    $email_stats['success']    = $e_res['success_count'] ?? 0;
    $email_stats['failed']     = $e_res['failed_count'] ?? 0;
}

// -------------------------------------------------------------------------
// 6. TELCO BREAKDOWN (BASED ON FINAL DM)
// -------------------------------------------------------------------------
$sql_carrier = "SELECT UPPER(COALESCE(i.carrier_name, 'OTHER')) as carrier_name, 
                       COUNT(*) as memo_count, 
                       SUM($calc_final_dm_expr) as total_amt 
                FROM debit_memo_items i 
                JOIN debit_memos m ON i.dm_id = m.dm_id $date_clause_dmi 
                GROUP BY UPPER(COALESCE(i.carrier_name, 'OTHER')) ORDER BY total_amt DESC";
$carrierStats = fetchAllSafe($conn, $sql_carrier, $params);

// -------------------------------------------------------------------------
// 7. COMPANY BREAKDOWN (ALL COMPANIES, RANKED BY FINAL DM)
// -------------------------------------------------------------------------
$sql_company = "SELECT UPPER(COALESCE(m.company, 'UNKNOWN')) as company, 
                       COUNT(*) as memo_count, 
                       SUM($calc_final_dm_expr) as total_amt 
                FROM debit_memos m 
                JOIN debit_memo_items i ON m.dm_id = i.dm_id $date_clause_dmi 
                GROUP BY UPPER(COALESCE(m.company, 'UNKNOWN')) ORDER BY total_amt DESC";
$companyStats = fetchAllSafe($conn, $sql_company, $params);

// Chart arrays
$carrier_labels = [];
$carrier_amounts = [];
$carrier_colors = ['#1d4ed8', '#059669', '#dc2626', '#7c3aed', '#64748b'];

foreach ($carrierStats as $s) {
    $carrier_labels[]  = $s['carrier_name'] ?? 'OTHER';
    $carrier_amounts[] = (float)($s['total_amt'] ?? 0);
}

$total_company_sum = array_sum(array_column($companyStats, 'total_amt'));

// -------------------------------------------------------------------------
// 8. PRESENTATION HELPERS (derived values only, no new queries)
// -------------------------------------------------------------------------
function fmt_date($d) {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('M j, Y') : $d;
}

$has_filter   = !empty($start_date) && !empty($end_date);
$period_label = $has_filter ? fmt_date($start_date) . ' to ' . fmt_date($end_date) : 'All dates';

// Quick date presets (links that reuse the existing GET filter)
$presets = [
    'This month'   => [date('Y-m-01'), date('Y-m-t')],
    'Last month'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Last 90 days' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
    'This year'    => [date('Y-01-01'), date('Y-12-31')],
];

$total_dm_items     = (int)($summary['total_dm_items'] ?? 0);
$total_accounts     = (int)($summary['total_accounts'] ?? 0);
$processed_dm_value = (float)($summary['total_processed_dm'] ?? 0);

$final_parts = explode('.', number_format($final_dm_value, 2));

// Email delivery
$email_total  = (int)$email_stats['total_sent'];
$email_ok     = (int)$email_stats['success'];
$email_fail   = (int)$email_stats['failed'];
$email_ok_pct = $email_total > 0 ? round(($email_ok / $email_total) * 100, 1) : 0;

// Ring geometry (r = 42 -> circumference)
$ring_c        = 2 * M_PI * 42;
$soa_arc       = $ring_c * min(100, max(0, $soa_percentage)) / 100;
$email_ok_arc  = $email_total > 0 ? $ring_c * ($email_ok / $email_total) : 0;
$email_bad_arc = $email_total > 0 ? $ring_c * ($email_fail / $email_total) : 0;

// Carrier colours for this design
$carrier_colors = ['#0f766e', '#e0a100', '#e2504a', '#4f6bed', '#7a8794'];
$chart_colors = [];
foreach ($carrier_labels as $i => $_) {
    $chart_colors[] = $carrier_colors[$i % count($carrier_colors)];
}

ob_start();
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Familjen+Grotesk:wght@500;600;700&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
.v2 {
    --ink: #12302e;
    --muted: #5d7673;
    --line: #dbe6e4;
    --wash: #e6f1ef;
    --teal: #0f766e;
    --teal-deep: #0b5a54;
    --amber: #e0a100;
    --coral: #e2504a;
    font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}
.v2 .num { font-family: 'Familjen Grotesk', 'Figtree', sans-serif; letter-spacing: -0.015em; }
.v2 :is(a, button, input):focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

/* Header */
.v2-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 1.25rem; }
.v2-head h2 { margin: 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em; }
.v2-head p { margin: .3rem 0 0; font-size: .875rem; color: var(--muted); }
.v2-filter { display: flex; flex-direction: column; gap: .6rem; align-items: flex-start; }
.v2-presets { display: flex; flex-wrap: wrap; gap: .4rem; }
.v2-chip { padding: .35rem .8rem; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-size: .8125rem; font-weight: 600; text-decoration: none; transition: background-color .15s; }
.v2-chip:hover { background: var(--wash); }
.v2-chip[aria-current="true"] { background: var(--teal); border-color: var(--teal); color: #fff; }
.v2-range { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.v2-range label { display: flex; align-items: center; gap: .4rem; font-size: .8125rem; font-weight: 600; color: var(--muted); }
.v2-range input { height: 2.1rem; padding: 0 .55rem; border: 1px solid var(--line); border-radius: 10px; background: #fff; font: inherit; font-size: .8125rem; color: var(--ink); }
.v2-btn { height: 2.1rem; padding: 0 .95rem; border-radius: 10px; border: 0; background: var(--ink); color: #fff; font-size: .8125rem; font-weight: 600; cursor: pointer; transition: background-color .15s; }
.v2-btn:hover { background: #1d4a47; }

/* Top row */
.v2-top { display: grid; grid-template-columns: 1fr; gap: 1.1rem; margin-bottom: 1.1rem; }
@media (min-width: 1024px) { .v2-top { grid-template-columns: minmax(0, 8fr) minmax(0, 4fr); } }

/* Main card: total + telco bar */
.v2-main { background: var(--wash); border-radius: 24px; padding: 1.75rem; display: flex; flex-direction: column; gap: 1.75rem; }
.v2-main-label { margin: 0; font-size: .9rem; font-weight: 600; color: var(--teal-deep); }
.v2-total { margin: .35rem 0 0; font-size: clamp(2.5rem, 6vw, 4.25rem); font-weight: 700; line-height: 1; display: flex; align-items: baseline; color: var(--ink); }
.v2-total .cur, .v2-total .dec { font-size: .48em; font-weight: 600; color: var(--teal); }
.v2-total .cur { margin-right: .1em; }

.v2-mid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
@media (min-width: 700px) { .v2-mid { grid-template-columns: minmax(0, 3fr) minmax(0, 1.15fr); align-items: stretch; } }

/* Summary sheet, worded and ordered like the email summary */
.v2-sheet { margin: 0; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: .3rem 1.25rem; }
.v2-sheet > div { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; padding: .8rem 0; }
.v2-sheet > div + div { border-top: 1px solid var(--line); }
.v2-sheet dt { font-size: .9rem; color: var(--muted); }
.v2-sheet dd { margin: 0; font-size: 1.05rem; font-weight: 700; white-space: nowrap; }
.v2-sheet .total dt { color: var(--ink); font-weight: 700; }
.v2-sheet .total dd { color: #c62f45; font-size: 1.15rem; }

.v2-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin: 0; align-content: center; }
@media (min-width: 700px) { .v2-facts { grid-template-columns: 1fr; } }
.v2-facts div { border-left: 2px solid rgba(15,118,110,.35); padding-left: .75rem; }
.v2-facts dt { font-size: .75rem; color: var(--muted); font-weight: 500; }
.v2-facts dd { margin: .15rem 0 0; font-size: 1.05rem; font-weight: 700; }

.v2-split h3 { margin: 0 0 .6rem; font-size: .9rem; font-weight: 700; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.v2-split h3 a { font-size: .8125rem; font-weight: 600; color: var(--teal-deep); text-decoration: none; }
.v2-split h3 a:hover { text-decoration: underline; }
.v2-bar { display: flex; gap: 3px; height: 2.6rem; border-radius: 12px; overflow: hidden; }
.v2-bar span { flex: var(--p) 1 0; min-width: 6px; transform-origin: left; animation: v2-reveal .9s cubic-bezier(.2,.7,.2,1) both; }
.v2-legend { list-style: none; margin: .9rem 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: .75rem 1rem; }
.v2-legend li { display: flex; flex-direction: column; gap: .1rem; font-size: .8125rem; }
.v2-legend .k { display: flex; align-items: center; gap: .45rem; font-weight: 700; }
.v2-legend .k i { width: .7rem; height: .7rem; border-radius: 3px; display: inline-block; }
.v2-legend .k small { font-weight: 500; color: var(--muted); }
.v2-legend .v { padding-left: 1.15rem; color: var(--ink); font-weight: 600; }

/* Gauge cards */
.v2-side { display: grid; grid-template-columns: 1fr; gap: 1.1rem; }
@media (min-width: 640px) and (max-width: 1023px) { .v2-side { grid-template-columns: 1fr 1fr; } }
.v2-gauge { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 1.25rem; display: flex; align-items: center; gap: 1.1rem; }
.v2-ring { position: relative; width: 6.5rem; height: 6.5rem; flex: none; }
.v2-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.v2-ring circle { fill: none; stroke-width: 11; }
.v2-ring .track { stroke: #edf3f2; }
.v2-ring .arc { stroke-linecap: butt; animation: v2-ring 1s cubic-bezier(.2,.7,.2,1) both; }
.v2-ring-center { position: absolute; inset: 0; display: grid; place-items: center; font-size: 1.3rem; font-weight: 700; }
.v2-gauge-text h3 { margin: 0; font-size: .9rem; font-weight: 700; }
.v2-gauge-text p { margin: .3rem 0 0; font-size: .8125rem; color: var(--muted); line-height: 1.5; }
.v2-gauge-text p b { color: var(--ink); font-weight: 700; }
.v2-gauge-text p .bad { color: var(--coral); font-weight: 700; }

/* Companies (all) */
.v2-panel { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 1.4rem; }
.v2-panel-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: .9rem 1.5rem; margin-bottom: 1rem; }
.v2-panel-head h3 { margin: 0; font-family: 'Familjen Grotesk', sans-serif; font-size: 1.15rem; font-weight: 700; }
.v2-panel-head p { margin: .2rem 0 0; font-size: .8125rem; color: var(--muted); }
.v2-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1rem; }
.v2-search { height: 2.2rem; width: min(16rem, 100%); padding: 0 .75rem; border: 1px solid var(--line); border-radius: 10px; background: #f7fbfa; font: inherit; font-size: .8125rem; color: var(--ink); }
.v2-search::placeholder { color: var(--muted); }
.v2-tools a { font-size: .8125rem; font-weight: 600; color: var(--teal-deep); text-decoration: none; white-space: nowrap; }
.v2-tools a:hover { text-decoration: underline; }

.v2-scroll { max-height: 27rem; overflow: auto; border: 1px solid var(--line); border-radius: 12px; }
.v2-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .875rem; }
.v2-tbl thead th { position: sticky; top: 0; z-index: 1; background: var(--wash); color: var(--teal-deep); font-size: .75rem; font-weight: 700; text-align: left; padding: .65rem .85rem; white-space: nowrap; }
.v2-tbl th.r, .v2-tbl td.r { text-align: right; }
.v2-tbl tbody td { padding: .7rem .85rem; border-top: 1px solid var(--line); vertical-align: middle; }
.v2-tbl tbody tr:first-child td { border-top: 0; }
.v2-tbl tbody tr:hover td { background: #f7fbfa; }
.v2-tbl .pos { width: 2.5rem; font-family: 'Familjen Grotesk', sans-serif; font-weight: 700; color: var(--teal); }
.v2-tbl .co { font-weight: 600; max-width: 22rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.v2-tbl .amt { font-weight: 700; white-space: nowrap; }
.v2-tbl .sh { min-width: 9rem; }
.v2-share { display: flex; align-items: center; gap: .6rem; }
.v2-share .track { flex: 1; height: 6px; border-radius: 999px; background: #edf3f2; overflow: hidden; min-width: 3rem; }
.v2-share .track span { display: block; height: 100%; width: var(--w); background: var(--teal); border-radius: 999px; }
.v2-share b { font-size: .75rem; font-weight: 700; color: var(--muted); min-width: 3.1rem; text-align: right; }
.v2-tbl tbody tr.top3 .track span { background: var(--teal); }
.v2-tbl tbody tr:not(.top3) .track span { background: #62aaa3; }
.v2-count { font-size: .8125rem; color: var(--muted); margin: .7rem 0 0; }
.v2-empty { padding: 2rem 0; text-align: center; color: var(--muted); font-size: .875rem; }
@media (max-width: 640px) { .v2-tbl .sh .track, .v2-tbl .memos { display: none; } .v2-tbl .sh { min-width: 0; } }

@keyframes v2-reveal { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes v2-ring { from { stroke-dasharray: 0 999; } }
@media (prefers-reduced-motion: reduce) {
    .v2-bar span, .v2-ring .arc { animation: none; }
    .v2-chip, .v2-btn { transition: none; }
}
</style>

<div class="v2">

    <!-- HEADER & FILTER -->
    <div class="v2-head">
        <div>
            <h2>Operations dashboard</h2>
            <p>Showing <strong><?= htmlspecialchars($period_label) ?></strong>. Dates filter memo coverage and email dispatch.</p>
        </div>

        <div class="v2-filter">
            <nav class="v2-presets" aria-label="Quick date ranges">
                <a class="v2-chip" href="dashboard.php" <?= !$has_filter ? 'aria-current="true"' : '' ?>>All dates</a>
                <?php foreach ($presets as $label => [$ps, $pe]):
                    $active = $has_filter && $start_date === $ps && $end_date === $pe; ?>
                    <a class="v2-chip" href="?start_date=<?= $ps ?>&amp;end_date=<?= $pe ?>" <?= $active ? 'aria-current="true"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="GET" class="v2-range" aria-label="Custom date range">
                <label>From <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
                <label>To <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
                <button type="submit" class="v2-btn">Apply</button>
            </form>
        </div>
    </div>

    <div class="v2-top">

        <!-- MAIN: TOTAL FINAL DM + TELCO SPLIT -->
        <section class="v2-main" aria-label="Total final DM">
            <div>
                <p class="v2-main-label">Total final DM</p>
                <p class="v2-total num"><span class="cur">₱</span><?= $final_parts[0] ?><span class="dec">.<?= $final_parts[1] ?></span></p>
            </div>

            <div class="v2-mid">
                <dl class="v2-sheet" aria-label="Charges summary">
                    <div>
                        <dt>Current Charges:</dt>
                        <dd>₱ <?= number_format($current_charges_value, 2) ?></dd>
                    </div>
                    <div>
                        <dt>Approved Plan (Company Share):</dt>
                        <dd>₱ <?= number_format($approved_plan_value, 2) ?></dd>
                    </div>
                    <div class="total">
                        <dt>Total Excess Charges:</dt>
                        <dd>₱ <?= number_format($final_dm_value, 2) ?></dd>
                    </div>
                </dl>

                <dl class="v2-facts">
                    <div><dt>Accounts</dt><dd><?= number_format($total_accounts) ?></dd></div>
                    <div><dt>Memo items</dt><dd><?= number_format($total_dm_items) ?></dd></div>
                </dl>
            </div>

            <div class="v2-split">
                <h3>Split by telco <a href="list_dm.php">View memos</a></h3>
                <?php if (empty($carrierStats)): ?>
                    <p class="v2-empty">No debit memos found for this period. Try a wider date range.</p>
                <?php else: ?>
                    <div class="v2-bar" role="img" aria-label="Final DM split across <?= count($carrierStats) ?> telcos">
                        <?php foreach ($carrierStats as $i => $s):
                            $amt = (float)($s['total_amt'] ?? 0);
                            $pct = $final_dm_value > 0 ? ($amt / $final_dm_value) * 100 : 0; ?>
                            <span style="--p: <?= round($pct, 2) ?>; background: <?= $chart_colors[$i] ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <ul class="v2-legend">
                        <?php foreach ($carrierStats as $i => $s):
                            $cName = $s['carrier_name'] ?? 'OTHER';
                            $amt   = (float)($s['total_amt'] ?? 0);
                            $pct   = $final_dm_value > 0 ? round(($amt / $final_dm_value) * 100, 1) : 0; ?>
                            <li>
                                <span class="k"><i style="background: <?= $chart_colors[$i] ?>"></i><?= htmlspecialchars($cName) ?> <small><?= $pct ?>%</small></span>
                                <span class="v">₱<?= number_format($amt, 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <!-- GAUGES -->
        <div class="v2-side">
            <section class="v2-gauge" aria-label="SOA coverage">
                <div class="v2-ring">
                    <svg viewBox="0 0 100 100" aria-hidden="true">
                        <circle class="track" cx="50" cy="50" r="42"></circle>
                        <circle class="arc" cx="50" cy="50" r="42" stroke="#0f766e" stroke-dasharray="<?= round($soa_arc, 2) ?> <?= round($ring_c, 2) ?>"></circle>
                    </svg>
                    <div class="v2-ring-center num"><?= $soa_percentage ?>%</div>
                </div>
                <div class="v2-gauge-text">
                    <h3>SOA attached</h3>
                    <p><b><?= number_format($with_soa) ?></b> of <?= number_format($total_items_soa) ?> memo items have a statement.
                       <b><?= number_format($without_soa) ?></b> still missing.</p>
                </div>
            </section>

            <section class="v2-gauge" aria-label="Email delivery">
                <div class="v2-ring">
                    <svg viewBox="0 0 100 100" aria-hidden="true">
                        <circle class="track" cx="50" cy="50" r="42"></circle>
                        <circle class="arc" cx="50" cy="50" r="42" stroke="#0f766e" stroke-dasharray="<?= round($email_ok_arc, 2) ?> <?= round($ring_c, 2) ?>"></circle>
                        <?php if ($email_fail > 0): ?>
                        <circle class="arc" cx="50" cy="50" r="42" stroke="#e2504a" stroke-dasharray="<?= round($email_bad_arc, 2) ?> <?= round($ring_c, 2) ?>" stroke-dashoffset="-<?= round($email_ok_arc, 2) ?>"></circle>
                        <?php endif; ?>
                    </svg>
                    <div class="v2-ring-center num"><?= $email_ok_pct ?>%</div>
                </div>
                <div class="v2-gauge-text">
                    <h3>Email delivery</h3>
                    <p><b><?= number_format($email_total) ?></b> sent: <b><?= number_format($email_ok) ?></b> delivered,
                       <span class="<?= $email_fail > 0 ? 'bad' : '' ?>"><?= number_format($email_fail) ?> failed</span>.</p>
                </div>
            </section>
        </div>
    </div>

    <!-- ALL COMPANIES -->
    <section class="v2-panel" aria-labelledby="v2-company-title">
        <div class="v2-panel-head">
            <div>
                <h3 id="v2-company-title">All companies by final DM</h3>
                <p>Ranked from highest to lowest. Share is out of the total final DM.</p>
            </div>
            <?php if (!empty($companyStats)): ?>
            <div class="v2-tools">
                <input type="search" id="coSearch" class="v2-search" placeholder="Search company" aria-label="Search companies" autocomplete="off">
                <a href="list_dm.php">Open memo list</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($companyStats)): ?>
            <p class="v2-empty">No companies found for this period. Try a wider date range.</p>
        <?php else: ?>
            <div class="v2-scroll" tabindex="0" role="region" aria-label="Company ranking table">
                <table class="v2-tbl">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Company</th>
                            <th scope="col" class="r memos">Memos</th>
                            <th scope="col" class="r">Final DM</th>
                            <th scope="col">Share</th>
                        </tr>
                    </thead>
                    <tbody id="coBody">
                        <?php foreach ($companyStats as $index => $s):
                            $cName    = $s['company'] ?? 'N/A';
                            $amt      = (float)($s['total_amt'] ?? 0);
                            $memos    = (int)($s['memo_count'] ?? 0);
                            $pct      = $total_company_sum > 0 ? round(($amt / $total_company_sum) * 100, 1) : 0;
                            $barWidth = $pct > 0 ? max(2, $pct) : 0; ?>
                            <tr class="<?= $index < 3 ? 'top3' : '' ?>" data-name="<?= htmlspecialchars(strtolower($cName)) ?>">
                                <td class="pos"><?= $index + 1 ?></td>
                                <td class="co" title="<?= htmlspecialchars($cName) ?>"><?= htmlspecialchars($cName) ?></td>
                                <td class="r memos"><?= number_format($memos) ?></td>
                                <td class="r amt">₱<?= number_format($amt, 2) ?></td>
                                <td class="sh">
                                    <div class="v2-share">
                                        <span class="track"><span style="--w: <?= $barWidth ?>%"></span></span>
                                        <b><?= $pct ?>%</b>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="coNone" class="v2-empty" hidden>No company matches your search.</p>
            </div>
            <p class="v2-count" id="coCount" aria-live="polite">Showing all <?= number_format(count($companyStats)) ?> companies</p>
        <?php endif; ?>
    </section>

</div>

<script>
(function () {
    const input = document.getElementById('coSearch');
    const body  = document.getElementById('coBody');
    if (!input || !body) return;
    const rows  = Array.from(body.querySelectorAll('tr'));
    const none  = document.getElementById('coNone');
    const count = document.getElementById('coCount');
    const total = rows.length;

    input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(function (r) {
            const match = q === '' || r.dataset.name.indexOf(q) !== -1;
            r.hidden = !match;
            if (match) shown++;
        });
        none.hidden = shown !== 0;
        count.textContent = q === ''
            ? 'Showing all ' + total.toLocaleString('en-US') + ' companies'
            : 'Showing ' + shown.toLocaleString('en-US') + ' of ' + total.toLocaleString('en-US') + ' companies';
    });
})();
</script>

<?php
$content = ob_get_clean();
render_layout("Dashboard Overview", $content);
?>