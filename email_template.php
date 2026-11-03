<?php
// email_template.php - Subject, HTML Body, at Attachments ng lahat ng SOA email templates
//
// 3 templates (updated per "Email for Sending Out - Statement of Account / Excess Charges"):
//   1. getEmailTemplate()            -> Employee
//   2. getBuAdminEmailTemplate()     -> BU Admin       (Excess Charges Report)
//   3. getPresidentEmailTemplate()   -> Group President / BOD (Excess Charges Report)
//
// Lahat ay nagre-return ng: ['subject' => ..., 'body' => ..., 'attachments' => [...]]
//
// NOTE: Wala nang telco name sa subject. Naka-define pa rin ang SOA_TELCO_NAME
//       para hindi mag-error ang ibang file na gumagamit nito.

if (!defined('SOA_TELCO_NAME')) define('SOA_TELCO_NAME', '<TELCO>');

// Subject base (pareho sa 3 templates)
if (!defined('SOA_SUBJECT_BASE')) define('SOA_SUBJECT_BASE', 'Corporate Mobile Statement of Account/Excess Charges');

// ---------------------------------------------------------------------------
// Helpers (shared ng 3 templates)
// ---------------------------------------------------------------------------

function et_e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function et_num($v) {
    // "1,234.50" / "₱ 1,234.50" / 1234.5 -> 1234.5
    return (float)str_replace(array(',', '₱', ' '), '', (string)$v);
}

function et_money($v) {
    return number_format(et_num($v), 2);
}

// Walang laman -> "N/A" (WALANG fallback sa ibang field)
function et_na($v) {
    $v = trim((string)$v);
    return $v === '' ? 'N/A' : $v;
}

function et_attachments($pdf_file_path) {
    $list = array();
    if (!empty($pdf_file_path) && file_exists($pdf_file_path)) {
        $list[] = $pdf_file_path;
    }
    return $list;
}

// Outer wrapper: header + body + footer
function et_wrap($inner_html) {
    return "
<div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333; max-width: 750px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>


    {$inner_html}

    <!-- Footer -->
    <div style='margin-top: 30px; padding: 12px; border-top: 2px solid #e2e8f0; background-color: #f8fafc; border-radius: 6px;'>
        <div style='background-color: #fef2f2; border: 1px solid #fecaca; padding: 8px 12px; border-radius: 4px; text-align: center;'>
            <p style='font-size: 9.5pt; color: #991b1b; margin: 0; font-weight: bold;'>
                ⚠ This is an automated email, please do not reply.
            </p>
        </div>
    </div>
</div>";
}

// Yellow Note box
function et_note($text) {
    return "
    <div style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px; margin: 15px 0; font-size: 10pt; color: #92400e; border-radius: 0 4px 4px 0;'>
        <b>Note:</b> {$text}
    </div>";
}

// Isang row ng Summary Details (label + value)
function et_row($label, $value_html) {
    return "
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold; width: 260px;'>{$label}</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>{$value_html}</td>
            </tr>";
}

// Summary Details block (key-value layout) - gamit ng 3 templates
function et_summary_block($rows_html) {
    return "
    <div style='background-color: #ffffff; border-radius: 6px; padding: 15px 5px; margin: 15px 0;'>
        <h3 style='font-size: 14pt; font-weight: bold; color: #1e293b; margin-top: 0; margin-bottom: 15px;'>Summary Details</h3>
        <table style='width: 100%; font-size: 11pt; border-collapse: collapse; line-height: 1.8;'>{$rows_html}
        </table>
    </div>";
}

// Total ng current charges / approved allowance / excess mula sa $rows
function et_totals($rows) {
    $t = array('current' => 0, 'plan' => 0, 'excess' => 0);
    foreach ($rows as $r) {
        $t['current'] += et_num(isset($r['current_charges']) ? $r['current_charges'] : 0);
        $t['plan']    += et_num(isset($r['approved_plan'])   ? $r['approved_plan']   : 0);
        $t['excess']  += et_num(isset($r['excess'])          ? $r['excess']          : 0);
    }
    return $t;
}

// ---------------------------------------------------------------------------
// 1. EMPLOYEE
// ---------------------------------------------------------------------------
// Account Name/ Mobile No.  ->  "<Account Name> / <Mobile No.>"
// Kapag walang account name o mobile number -> "N/A" (WALANG fallback sa Employee ID / Account Number)
// Naiwan ang $employee_id at $company sa parameters para hindi masira ang existing callers (hindi na ginagamit).

function getEmailTemplate($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00', $pdf_file_path = '') {

    $subject = SOA_SUBJECT_BASE . " - " . $account_number;

    $account_name_display = et_e(et_na($recipient_name)) . " / " . et_e(et_na($mobile_number));
    $data_coverage_e = et_e($data_coverage);

    $rows_html =
          et_row('Period Covered:',            $data_coverage_e)
        . et_row('Account Name/ Mobile No.:',  $account_name_display)
        . et_row('Current Charges:',           '₱ ' . et_money($current_charges_display))
        . et_row('Approved Telco Allowance:',  '₱ ' . et_money($approved_plan_display))
        . et_row('Excess Charges:',            '₱ ' . et_money($final_dm_val));

    $inner = "
    <p style='margin: 0 0 16px 0;'>Dear Employee,</p>
    <p style='margin: 0 0 16px 0;'>Please find attached your <b>Statement of Account (SOA)</b> reflecting applicable excess charges, with details below:</p>"
    . et_summary_block($rows_html)
    . et_note("This email provides a detailed breakdown and description of your telco account charges for your reference. Please review the attached SOA for full details.<br><br>Disregard this notification if your excess charges amount to zero (₱0.00), as no action is required.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}

// ---------------------------------------------------------------------------
// 2. BU ADMIN TEMPLATE
// ---------------------------------------------------------------------------
function getBuAdminEmailTemplate($business_unit, $rows = array(), $data_coverage = 'As of current billing', $pdf_file_path = '') {

    $subject = SOA_SUBJECT_BASE . " - " . $business_unit;

    $t = et_totals($rows);

    $rows_html =
          et_row('Period Covered:',                  et_e($data_coverage))
        . et_row('Business Unit Name:',              et_e($business_unit))
        . et_row('Total Current Charges:',           '₱ ' . number_format($t['current'], 2))
        . et_row('Total Approved Telco Allowance:',  '₱ ' . number_format($t['plan'], 2))
        . et_row('Total Excess Charges:',            '₱ ' . number_format($t['excess'], 2));

    $inner = "
    <p style='margin: 0 0 16px 0;'>Dear BU Admin,</p>
    <p style='margin: 0 0 16px 0;'>Please find the attached <b>Excess Charges Report</b> for your Business Unit, with details below:</p>"
    . et_summary_block($rows_html)
    . et_note("This email provides a detailed breakdown and description of your Business Unit’s telco account charges for your reference. Please review the report for full details.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}

// ---------------------------------------------------------------------------
// 3. GROUP PRESIDENT / BOD TEMPLATE
// ---------------------------------------------------------------------------
function getPresidentEmailTemplate($company, $rows = array(), $data_coverage = 'As of current billing', $pdf_file_path = '', $first_col_label = 'Account Name') {

    $subject = SOA_SUBJECT_BASE . " - " . $company;

    $t = et_totals($rows);

    $rows_html =
          et_row('Period Covered:',                  et_e($data_coverage))
        . et_row('Company Name:',                    et_e($company))
        . et_row('Total Current Charges:',           '₱ ' . number_format($t['current'], 2))
        . et_row('Total Approved Telco Allowance:',  '₱ ' . number_format($t['plan'], 2))
        . et_row('Total Excess Charges:',            '₱ ' . number_format($t['excess'], 2));

    $inner = "
    <p style='margin: 0 0 16px 0;'>Dear Group President/ BOD,</p>
    <p style='margin: 0 0 16px 0;'>Please find the attached <b>Excess Charges Report</b> for your Company/ Group of Companies, with details below:</p>"
    . et_summary_block($rows_html)
    . et_note("This email provides a detailed breakdown and description of your company’s telco account charges for your reference. Please review the report for full details.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}
?>