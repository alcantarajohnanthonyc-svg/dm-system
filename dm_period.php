<?php
/**
 * dm_period.php
 * Single source of truth for the two Debit Memo breakdown layouts:
 *
 *   'legacy' -> coverage_start BEFORE 2026-01-01  (2025 and backward) - old columns
 *   'new'    -> coverage_start ON/AFTER 2026-01-01 (2026 and forward) - new template
 *
 * Used by: get_breakdown.php, excel_generator.php, pdf_generator.php,
 *          export_breakdown_pdf.php, export_bulk_pdf.php
 *
 * To move the cutoff, change DM_NEW_TEMPLATE_START only.
 */
if (!defined('DM_NEW_TEMPLATE_START')) define('DM_NEW_TEMPLATE_START', '2026-01-01');

/** TRUE if this coverage_start belongs to the new (2026+) template. */
function dm_is_new_template($coverage_start) {
    if (empty($coverage_start)) return false;
    $ts = strtotime($coverage_start);
    return ($ts !== false && $ts >= strtotime(DM_NEW_TEMPLATE_START));
}

/** 'legacy' | 'new' for a given row. */
function dm_layout_for_row($row) {
    return dm_is_new_template(isset($row['coverage_start']) ? $row['coverage_start'] : '') ? 'new' : 'legacy';
}

/** Layout for a calendar year (used by the per-year Excel tabs / PDF pages). */
function dm_layout_for_year($year) {
    return (is_numeric($year) && (int)$year >= (int)substr(DM_NEW_TEMPLATE_START, 0, 4)) ? 'new' : 'legacy';
}

/** SQL fragment that restricts rows to one tab. $period: 'new' | 'legacy' | anything else = no filter. */
function dm_period_clause($period, $col = 'coverage_start') {
    if ($period === 'new')    return " AND $col >= '" . DM_NEW_TEMPLATE_START . "'";
    if ($period === 'legacy') return " AND $col < '"  . DM_NEW_TEMPLATE_START . "'";
    return '';
}

function dm_f($row, $key) {
    if (!isset($row[$key]) || $row[$key] === '') return 0.0;
    // tolerate text amounts such as "1,234.50" or "₱ 1,234.50"
    return (float)str_replace(array(',', '₱', ' '), '', (string)$row[$key]);
}

/** EXCESS CHARGES = CURRENT CHARGES - APPROVED PLAN (never below 0). */
function dm_excess_charges($row) {
    return round(max(0, dm_f($row, 'current_charges') - dm_f($row, 'approved_plan')), 2);
}

/** FINAL EXCESS CHARGES = EXCESS CHARGES - ADD ONS (never below 0). */
function dm_final_excess_charges($row) {
    return round(max(0, dm_excess_charges($row) - dm_f($row, 'add_ons')), 2);
}

/**
 * Value of the amount that should be reported as "final" for a row,
 * regardless of layout. Used by the email totals.
 */
function dm_final_amount($row) {
    if (dm_layout_for_row($row) === 'new') {
        return dm_final_excess_charges($row);
    }
    $dm = dm_f($row, 'debit_memo_details');
    $ao = dm_f($row, 'add_ons');
    return (isset($row['final_dm']) && $row['final_dm'] !== '') ? dm_f($row, 'final_dm') : ($dm - $ao);
}

/**
 * Column definitions for a layout.
 * type : date | mobile | field (straight DB column) | calc (computed, see dm_cell_value)
 * key  : DB column or calc name
 * fmt  : plain | red | blue | purple | orange | green  (text colour)
 */
function dm_columns($layout) {
    if ($layout === 'new') {
        $Y = '#FFE599'; $B = '#6D9EEB'; $G = '#93C47D'; $L = '#F7F700';
        return array(
            array('label' => 'COVERAGE DATE',  'color' => $Y, 'type' => 'date',   'key' => '',                   'fmt' => 'plain'),
            array('label' => 'MOBILE NUMBER',  'color' => $Y, 'type' => 'mobile', 'key' => '',                   'fmt' => 'plain'),
            array('label' => 'APPROVED PLAN',  'color' => $Y, 'type' => 'field',  'key' => 'approved_plan',      'fmt' => 'plain'),
            array('label' => 'MSF (GLOBE / MRC (SMART)', 'color' => $B, 'type' => 'field', 'key' => 'phone_amortization', 'fmt' => 'plain'),
            array('label' => 'OTHER CHARGES / PHONE AMORTIZATION', 'color' => $B, 'type' => 'field', 'key' => 'other_charges', 'fmt' => 'plain'),
            array('label' => 'LOCAL (CALL/TEXT)', 'color' => $B, 'type' => 'field', 'key' => 'local_call_text', 'fmt' => 'plain'),
            array('label' => 'NDD',            'color' => $B, 'type' => 'field',  'key' => 'ndd_charges',        'fmt' => 'plain'),
            array('label' => 'IDD',            'color' => $B, 'type' => 'field',  'key' => 'idd_charges',        'fmt' => 'plain'),
            array('label' => 'ROAM',           'color' => $B, 'type' => 'field',  'key' => 'roaming_charges',    'fmt' => 'plain'),
            array('label' => 'SMS',            'color' => $B, 'type' => 'field',  'key' => 'sms_charges',        'fmt' => 'plain'),
            array('label' => 'GPRS',           'color' => $B, 'type' => 'field',  'key' => 'gprs_charges',       'fmt' => 'plain'),
            array('label' => 'WIZ USAGE',      'color' => $B, 'type' => 'field',  'key' => 'wiz_usage',          'fmt' => 'plain'),
            array('label' => 'LOADING CHARGES','color' => $B, 'type' => 'field',  'key' => 'loading_charges',    'fmt' => 'plain'),
            array('label' => 'VAT',            'color' => $B, 'type' => 'field',  'key' => 'vat',                'fmt' => 'plain'),
            array('label' => 'OCT',            'color' => $B, 'type' => 'field',  'key' => 'oct',                'fmt' => 'plain'),
            array('label' => 'CURRENT CHARGES','color' => $B, 'type' => 'field',  'key' => 'current_charges',    'fmt' => 'plain'),
            array('label' => "EXCESS CHARGES\n(CURRENT CHARGES - APPROVED PLAN)", 'color' => $G, 'type' => 'calc', 'key' => 'excess', 'fmt' => 'blue'),
            array('label' => "ADD ONS\n(TELCO SYSTEM ERROR ADD ONS)", 'color' => $B, 'type' => 'field', 'key' => 'add_ons', 'fmt' => 'orange'),
            array('label' => "FINAL EXCESS CHARGES\n(EXCESS CHARGES - ADD ONS)", 'color' => $L, 'type' => 'calc', 'key' => 'final_excess', 'fmt' => 'green'),
        );
    }

    // ---- legacy (2025 and backward): unchanged from the old layout ----
    $Y = '#FFE599'; $G = '#93C47D'; $B = '#4A86E8'; $L = '#F7F700';
    return array(
        array('label' => 'COVERAGE DATE',  'color' => $Y, 'type' => 'date',   'key' => '',                   'fmt' => 'plain'),
        array('label' => 'MOBILE NUMBER',  'color' => $G, 'type' => 'mobile', 'key' => '',                   'fmt' => 'plain'),
        array('label' => 'APPROVED PLAN',  'color' => $G, 'type' => 'field',  'key' => 'approved_plan',      'fmt' => 'plain'),
        array('label' => 'MSF (GLOBE / MRC (SMART)', 'color' => $B, 'type' => 'field', 'key' => 'phone_amortization', 'fmt' => 'plain'),
        array('label' => 'DEBIT ADJ',      'color' => $G, 'type' => 'field',  'key' => 'debit_adj',          'fmt' => 'plain'),
        array('label' => 'CREDIT ADJ',     'color' => $G, 'type' => 'field',  'key' => 'credit_adj',         'fmt' => 'plain'),
        array('label' => 'OTHER CHARGES / PHONE AMORTIZATION', 'color' => $B, 'type' => 'field', 'key' => 'other_charges', 'fmt' => 'plain'),
        array('label' => 'LOCAL (CALL/TEXT)', 'color' => $B, 'type' => 'field', 'key' => 'local_call_text', 'fmt' => 'plain'),
        array('label' => 'NDD (NATIONAL)', 'color' => $B, 'type' => 'field',  'key' => 'ndd_charges',        'fmt' => 'plain'),
        array('label' => 'IDD (INTERNATIONAL)', 'color' => $B, 'type' => 'field', 'key' => 'idd_charges',    'fmt' => 'plain'),
        array('label' => 'ROAM',           'color' => $B, 'type' => 'field',  'key' => 'roaming_charges',    'fmt' => 'plain'),
        array('label' => 'SMS',            'color' => $B, 'type' => 'field',  'key' => 'sms_charges',        'fmt' => 'plain'),
        array('label' => 'GPRS',           'color' => $B, 'type' => 'field',  'key' => 'gprs_charges',       'fmt' => 'plain'),
        array('label' => 'WIZ USAGE',      'color' => $B, 'type' => 'field',  'key' => 'wiz_usage',          'fmt' => 'plain'),
        array('label' => 'LOADING CHARGES','color' => $B, 'type' => 'field',  'key' => 'loading_charges',    'fmt' => 'plain'),
        array('label' => 'VAT',            'color' => $B, 'type' => 'field',  'key' => 'vat',                'fmt' => 'plain'),
        array('label' => 'OCT',            'color' => $B, 'type' => 'field',  'key' => 'oct',                'fmt' => 'plain'),
        array('label' => 'CURRENT CHARGES','color' => $B, 'type' => 'field',  'key' => 'current_charges',    'fmt' => 'plain'),
        array('label' => 'TOTAL AMOUNT DUE','color' => $B, 'type' => 'field', 'key' => 'total_amount_due',  'fmt' => 'plain'),
        array('label' => 'PROCESSED DM',   'color' => $G, 'type' => 'field',  'key' => 'debit_memo_details', 'fmt' => 'red'),
        array('label' => 'SYSTEM GENERATED DM', 'color' => $G, 'type' => 'calc', 'key' => 'sys_dm',         'fmt' => 'blue'),
        array('label' => "DIFFERENCE\n(PROCESSED DM - SYSTEM GENERATED DM)", 'color' => $L, 'type' => 'calc', 'key' => 'diff', 'fmt' => 'purple'),
        array('label' => 'ADD ONS',        'color' => $G, 'type' => 'field',  'key' => 'add_ons',            'fmt' => 'orange'),
        array('label' => "FINAL DM\n(PROCESSED DM - ADD ONS)", 'color' => $L, 'type' => 'calc', 'key' => 'final_dm', 'fmt' => 'green'),
    );
}

/** Numeric value of a field/calc column for one row. */
function dm_cell_value($row, $col) {
    if ($col['type'] === 'field') {
        return dm_f($row, $col['key']);
    }
    switch ($col['key']) {
        // ---- new template ----
        case 'excess':       return dm_excess_charges($row);
        case 'final_excess': return dm_final_excess_charges($row);
        // ---- legacy (same formulas as before) ----
        case 'sys_dm':
            return max(0, dm_f($row, 'current_charges') - dm_f($row, 'approved_plan'));
        case 'diff':
            $sys = max(0, dm_f($row, 'current_charges') - dm_f($row, 'approved_plan'));
            return max(0, dm_f($row, 'debit_memo_details') - $sys);
        case 'final_dm':
            $dm = dm_f($row, 'debit_memo_details');
            $ao = dm_f($row, 'add_ons');
            return isset($row['final_dm']) ? (float)$row['final_dm'] : ($dm - $ao);
    }
    return 0.0;
}

/** "Mar 01, 2026 to Mar 31, 2026" */
function dm_date_text($row, $sep = ' to ') {
    $s = !empty($row['coverage_start']) ? date('M d, Y', strtotime($row['coverage_start'])) : '';
    $e = !empty($row['coverage_end'])   ? date('M d, Y', strtotime($row['coverage_end']))   : '';
    return $s . $sep . $e;
}
?>
