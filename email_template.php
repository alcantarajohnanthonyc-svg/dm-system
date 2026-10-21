<?php
// email_template.php - Subject, HTML Body, at Attachments ng lahat ng SOA email templates
//
// 3 templates (per Sir Warren's revisions):
//   1. getEmailTemplate()            -> Employee
//   2. getBuAdminEmailTemplate()     -> BU Admin       (may summary table)
//   3. getPresidentEmailTemplate()   -> Group President (may summary table)
//
// Lahat ay nagre-return ng: ['subject' => ..., 'body' => ..., 'attachments' => [...]]

// Palitan dito kung ano ang telco (hal. 'Globe', 'Smart')
if (!defined('SOA_TELCO_NAME')) define('SOA_TELCO_NAME', '<TELCO>');

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

function et_attachments($pdf_file_path) {
    $list = array();
    if (!empty($pdf_file_path) && file_exists($pdf_file_path)) {
        $list[] = $pdf_file_path;
    }
    return $list;
}

// Outer wrapper: header + body + footer (walang "Thank you, IT Telco Admin Team")
function et_wrap($inner_html) {
    return "
<div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333; max-width: 750px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>

    <!-- Header / Branding -->
    <div style='border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px;'>
        <h2 style='color: #0f172a; font-size: 16px; margin: 0;'>IT Telco Admin Team</h2>
        <p style='font-size: 10px; color: #64748b; margin: 2px 0 0 0;'>Statement of Account & Debit Memo Notification</p>
    </div>

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

// Summary table para sa BU Admin / Group President
// $rows = array of array('name' => ..., 'identifier' => ..., 'current_charges' => ..., 'approved_plan' => ..., 'excess' => ...)
function et_summary_table($rows, $first_col_label = 'Account Name') {
    $tot_current = 0; $tot_plan = 0; $tot_excess = 0;
    $tr = '';

    foreach ($rows as $r) {
        $cur  = et_num(isset($r['current_charges']) ? $r['current_charges'] : 0);
        $plan = et_num(isset($r['approved_plan'])   ? $r['approved_plan']   : 0);
        $exc  = et_num(isset($r['excess'])          ? $r['excess']          : 0);
        $tot_current += $cur; $tot_plan += $plan; $tot_excess += $exc;

        $name = et_e(isset($r['name']) ? $r['name'] : '');
        if (!empty($r['identifier'])) $name .= ' / ' . et_e($r['identifier']);
        $exc_color = $exc > 0 ? '#e11d48' : '#0f172a';

        $tr .= "
            <tr>
                <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0;'>{$name}</td>
                <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>" . number_format($cur, 2) . "</td>
                <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>" . number_format($plan, 2) . "</td>
                <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: {$exc_color};'>" . number_format($exc, 2) . "</td>
            </tr>";
    }

    if ($tr === '') {
        $tr = "<tr><td colspan='4' style='padding: 10px 8px; text-align: center; color: #64748b;'>No records for this period.</td></tr>";
    }

    $first_col_label = et_e($first_col_label);

    return "
        <table style='width: 100%; font-size: 10pt; border-collapse: collapse; border: 1px solid #e2e8f0;'>
            <thead>
                <tr style='background-color: #0f172a; color: #ffffff;'>
                    <th style='padding: 8px; text-align: left;'>{$first_col_label}</th>
                    <th style='padding: 8px; text-align: right;'>Current Charges</th>
                    <th style='padding: 8px; text-align: right;'>Approved Plan (Company Share)</th>
                    <th style='padding: 8px; text-align: right;'>Excess Charges</th>
                </tr>
            </thead>
            <tbody>{$tr}
            </tbody>
            <tfoot>
                <tr style='background-color: #f1f5f9; font-weight: bold;'>
                    <td style='padding: 8px;'>TOTAL</td>
                    <td style='padding: 8px; text-align: right;'>" . number_format($tot_current, 2) . "</td>
                    <td style='padding: 8px; text-align: right;'>" . number_format($tot_plan, 2) . "</td>
                    <td style='padding: 8px; text-align: right; color: #e11d48;'>₱ " . number_format($tot_excess, 2) . "</td>
                </tr>
            </tfoot>
        </table>";
}

// ---------------------------------------------------------------------------
// 1. EMPLOYEE
// ---------------------------------------------------------------------------

function getEmailTemplate($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00', $pdf_file_path = '') {

    $subject = SOA_TELCO_NAME . " Corporate Mobile Statement of Account/Excess Charges - " . $account_number;

    // Identifier logic (Employee ID -> Mobile Number -> Account Number)
    $identifier = !empty($employee_id) ? $employee_id : (!empty($mobile_number) ? $mobile_number : $account_number);
    $account_name_display = et_e(trim($recipient_name) . " / " . $identifier);
    $data_coverage_e = et_e($data_coverage);

    $inner = "
    <p>Dear Ma'am/Sir,</p>
    <p>Please find attached your Statement of Account (SOA) reflecting the applicable excess charges, with details below:</p>

    <!-- Summary Details -->
    <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin: 15px 0;'>
        <h3 style='font-size: 12px; color: #1e293b; margin-top: 0; margin-bottom: 10px; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px;'>Summary Details</h3>
        <table style='width: 100%; font-size: 11pt; border-collapse: collapse;'>
            <tr>
                <td style='padding: 6px 0; color: #64748b; width: 45%;'>Period Covered:</td>
                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>{$data_coverage_e}</td>
            </tr>
            <tr>
                <td style='padding: 6px 0; color: #64748b;'>Account Name:</td>
                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>{$account_name_display}</td>
            </tr>
            <tr>
                <td style='padding: 6px 0; color: #64748b;'>Current Charges:</td>
                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>{$current_charges_display}</td>
            </tr>
            <tr>
                <td style='padding: 6px 0; color: #64748b;'>Approved Plan (Company Share):</td>
                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>{$approved_plan_display}</td>
            </tr>
            <tr style='border-top: 1px solid #e2e8f0;'>
                <td style='padding: 10px 0 4px 0; color: #0f172a; font-weight: bold;'>Total Excess Charges:</td>
                <td style='padding: 10px 0 4px 0; font-weight: bold; color: #e11d48; font-size: 12pt;'>₱ {$final_dm_val}</td>
            </tr>
        </table>
    </div>"
    . et_note("This email provides a detailed breakdown and description of your telco account charges for your reference. Please review the attached SOA for full details.<br><br>Disregard this notification if your excess charges amount to zero (₱0.00), as no action is required.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}

// ---------------------------------------------------------------------------
// 2. BU ADMIN TEMPLATE (Group total breakdown replaced with Key-Value summary)
// ---------------------------------------------------------------------------
function getBuAdminEmailTemplate($business_unit, $rows = array(), $data_coverage = 'As of current billing', $pdf_file_path = '') {

    $subject = SOA_TELCO_NAME . " Corporate Mobile Statement of Account/Excess Charges - " . $business_unit;

    $bu_e  = et_e($business_unit);
    $cov_e = et_e($data_coverage);

    // Compute aggregate totals across rows
    $tot_current = 0; 
    $tot_excess  = 0;

    foreach ($rows as $r) {
        $tot_current += et_num(isset($r['current_charges']) ? $r['current_charges'] : 0);
        $tot_excess  += et_num(isset($r['excess'])          ? $r['excess']          : 0);
    }

    $inner = "
    <p>Dear Ma'am/Sir,</p>
    <p>Please find attached Statement of Account (SOA) reflecting the applicable excess charges, within your Business Unit with details below:</p>

    <!-- Summary Details Key-Value List Layout -->
    <div style='background-color: #ffffff; border-radius: 6px; padding: 15px 5px; margin: 15px 0;'>
        <h3 style='font-size: 14pt; font-weight: bold; color: #1e293b; margin-top: 0; margin-bottom: 15px;'>Summary Details</h3>
        <table style='width: 100%; font-size: 11pt; border-collapse: collapse; line-height: 1.8;'>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold; width: 220px;'>Period Covered:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>{$cov_e}</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Business Unit Name:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>{$bu_e}</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Total Current Charges:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>₱ " . number_format($tot_current, 2) . "</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Total Excess Charges:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>₱ " . number_format($tot_excess, 2) . "</td>
            </tr>
        </table>
    </div>"
    . et_note("This email provides a detailed breakdown and description of your respective Business Unit’s telco account charges for your reference. Please review the attached SOA for full details.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}

// ---------------------------------------------------------------------------
// 3. GROUP PRESIDENT TEMPLATE (Key-Value layout showing Company Name & Totals)
// ---------------------------------------------------------------------------
function getPresidentEmailTemplate($company, $rows = array(), $data_coverage = 'As of current billing', $pdf_file_path = '', $first_col_label = 'Account Name') {

    $subject = SOA_TELCO_NAME . " Corporate Mobile Statement of Account/Excess Charges - " . $company;

    $co_e  = et_e($company);
    $cov_e = et_e($data_coverage);

    // Compute total charges across all departments/BUs under this President
    $tot_current = 0; 
    $tot_excess  = 0;

    foreach ($rows as $r) {
        $tot_current += et_num(isset($r['current_charges']) ? $r['current_charges'] : 0);
        $tot_excess  += et_num(isset($r['excess'])          ? $r['excess']          : 0);
    }

    $inner = "
    <p>Dear Ma'am/Sir,</p>
    <p>Please find attached your Statement of Account (SOA) reflecting the applicable excess charges, within your company with details below:</p>

    <!-- Summary Details Key-Value List Layout -->
    <div style='background-color: #ffffff; border-radius: 6px; padding: 15px 5px; margin: 15px 0;'>
        <h3 style='font-size: 14pt; font-weight: bold; color: #1e293b; margin-top: 0; margin-bottom: 15px;'>Summary Details</h3>
        <table style='width: 100%; font-size: 11pt; border-collapse: collapse; line-height: 1.8;'>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold; width: 220px;'>Period Covered:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>{$cov_e}</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Company Name:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>{$co_e}</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Total Current Charges:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>₱ " . number_format($tot_current, 2) . "</td>
            </tr>
            <tr>
                <td style='padding: 4px 0; color: #000000; font-weight: bold;'>Total Excess Charges:</td>
                <td style='padding: 4px 0; font-weight: bold; color: #1e293b;'>₱ " . number_format($tot_excess, 2) . "</td>
            </tr>
        </table>
    </div>"
    . et_note("This email provides a detailed breakdown and description of your respective company’s telco account charges for your reference. Please review the attached SOA for full details.");

    return array(
        'subject'     => $subject,
        'body'        => et_wrap($inner),
        'attachments' => et_attachments($pdf_file_path)
    );
}
?>