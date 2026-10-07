<?php
// email_template.php - Dito mo madaling mamamanage ang Subject, HTML Body, at Attachments

function getEmailTemplate($account_number, $company, $recipient_name = '', $employee_id = '', $mobile_number = '', $data_coverage = 'As of current billing', $approved_plan_display = '0.00', $current_charges_display = '0.00', $final_dm_val = '0.00', $pdf_file_path = '') {
    
    // 1. I-modify ang Subject dito kung kinakailangan
    $subject = "Statement of Account/Excess Charges - " . $account_number;
    
    // Sundin ang logic para sa identifier (Employee ID -> Mobile Number -> Account Number)
    $identifier = !empty($employee_id) ? $employee_id : (!empty($mobile_number) ? $mobile_number : $account_number);
    $account_name_display = trim($recipient_name) . " / " . $identifier;
        
    // 2. I-modify ang HTML Body dito para pareho sa Preview at Actual Send
    $body = "
<div style='font-family: Arial, sans-serif; font-size: 11pt; color: #333; max-width: 750px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
    
    <!-- Header / Branding -->
    <div style='border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px;'>
        <h2 style='color: #0f172a; font-size: 16px; margin: 0;'>IT Telco Admin Team</h2>
        <p style='font-size: 10px; color: #64748b; margin: 2px 0 0 0;'>Statement of Account & Debit Memo Notification</p>
    </div>
    
    <p>Dear Ma'am/Sir,</p>
    <p>Please find attached your Statement of Account (SOA) reflecting the applicable excess charges, with details below:</p>
    
    <!-- Summary Details Box / Card -->
    <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin: 15px 0;'>
        <h3 style='font-size: 12px; color: #1e293b; margin-top: 0; margin-bottom: 10px; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px;'>Summary Details</h3>
        <table style='width: 100%; font-size: 11pt; border-collapse: collapse;'>
            <tr>
                <td style='padding: 6px 0; color: #64748b; width: 45%;'>Period Covered:</td>
                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>{$data_coverage}</td>
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
    </div>

    <p style='font-size: 10pt; color: #475569;'>This statement outlines the specific breakdown and descriptions of the charges applied to your telco account for your information.</p>
    
    <!-- Notice Box -->
    <div style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px; margin: 15px 0; font-size: 10pt; color: #92400e; border-radius: 0 4px 4px 0;'>
        <b>Note:</b> This email provides a detailed breakdown and description of your telco account charges for your reference. If your excess charges is zero (₱0.00), no action is required and you may disregard this notification.
    </div>

    <!-- Footer / Signature Section -->
    <div style='margin-top: 30px; padding-top: 15px; border-top: 2px solid #e2e8f0; background-color: #f8fafc; padding: 12px; border-radius: 6px;'>
        <p style='font-size: 10pt; color: #334155; margin: 0 0 10px 0; text-align: center;'>
          Please review the attached SOA for full details.
        </p>
        <div style='background-color: #fef2f2; border: 1px solid #fecaca; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; text-align: center;'>
            <p style='font-size: 9.5pt; color: #991b1b; margin: 0; font-weight: bold;'>
                ⚠ This is an automated email, please do not reply.
            </p>
        </div>
        <p style='font-size: 8.5pt; color: #64748b; margin: 0;'>
            Thank you,<br>
            <span style='color: #2563eb; font-size: 9pt; font-weight: bold;'>IT Telco Admin Team</span>
        </p>
    </div>
</div>";

    // 3. I-handle ang attachments base sa ipinasang path o record data
    $default_attachments = [];
    if (!empty($pdf_file_path) && file_exists($pdf_file_path)) {
        $default_attachments[] = $pdf_file_path;
    }

    return [
        'subject'     => $subject,
        'body'        => $body,
        'attachments' => $default_attachments
    ];
}
?>