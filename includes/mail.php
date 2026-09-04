<?php
/**
 * Email sending helpers for the password-reset workflow.
 *
 * sendResetApprovedToEmployee() — called after HR approval; sends the reset link.
 * sendResetRequestToHR()         — called when an employee requests a reset (optional email).
 *
 * On Windows, PHP's mail() ignores sendmail_path and silently drops emails via
 * its broken localhost:25 built-in SMTP. This module calls sendmail.exe directly
 * via proc_open() to guarantee delivery through the configured Gmail SMTP relay.
 */

define('HR1_MAIL_FROM', getenv('HR1_MAIL_FROM') ?: 'no-reply@hr1.local');
define('HR1_MAIL_FROM_NAME', 'TRI-M Global');
define('HR1_APP_NAME', defined('APP_NAME') ? APP_NAME : 'Merchandising Management System');

/**
 * The branded sender identity shown to recipients, e.g.:
 *   TRI-M Global <adminhr00001@gmail.com>
 * Uses the existing HR Gmail address (HR1_MAIL_FROM) unchanged.
 */
function hr1MailFrom(): string
{
    $addr = HR1_MAIL_FROM;
    $name = HR1_MAIL_FROM_NAME;
    if ($name !== '') {
        // RFC 5322 display-name: quote if it ever contains specials.
        if (preg_match('/[^\x20-\x7E]|[()<>\[\]:;@\\\\,."]/', $name)) {
            $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }
        return $name . ' <' . $addr . '>';
    }
    return $addr;
}

/**
 * Absolute base URL for building email-attached asset links (logo, etc.).
 * Falls back to BASE_URL-relative when no HTTP host is available.
 */
function hr1AbsBaseUrl(): string
{
    if (defined('BASE_URL')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host !== '') {
            return $scheme . '://' . $host . rtrim(BASE_URL, '/');
        }
        return rtrim(BASE_URL, '/');
    }
    return '';
}

/**
 * Absolute URL to the official TRI-M Global logo for email header branding.
 * Returns '' (no logo) when the base URL cannot be resolved.
 */
function hr1LogoUrl(): string
{
    $base = hr1AbsBaseUrl();
    return $base !== '' ? $base . '/assets/images/tri-m-logo.png' : '';
}

/**
 * Centered official logo block for the top of HTML recruitment emails.
 * Returns an empty string when no logo URL is available so emails remain valid.
 */
function hr1EmailLogoBlock(): string
{
    $logo = hr1LogoUrl();
    if ($logo === '') {
        return '';
    }
    return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0 0 24px 0;\"><tr><td align=\"center\">\n"
        . "<img src=\"{$logo}\" alt=\"TRI-M Global\" style=\"display:block;max-height:56px;max-width:220px;border:0;\" />\n"
        . "</td></tr></table>\n";
}

define('HR1_SENDMAIL_PATH', 'C:/xampp/sendmail/sendmail.exe');

/**
 * Send a raw email through sendmail.exe via proc_open().
 * This bypasses PHP's broken mail() on Windows.
 *
 * @param string $toEmail   Recipient email address
 * @param string $subject   Email subject
 * @param string $body      Email body (plain text)
 * @param array  $extra     Additional headers (From, Reply-To, etc.)
 * @return bool  true if sendmail accepted the message (exit code 0)
 */
function hr1Sendmail(string $toEmail, string $subject, string $body, array $extra = []): bool
{
    $isHtml = !empty($extra['html']);
    // When sending HTML, `body` holds the HTML. A plain-text fallback may be
    // provided via $extra['text']; otherwise a minimal text strip is generated.
    $htmlBody  = $isHtml ? $body : $body;
    $textBody  = $isHtml ? (string) ($extra['text'] ?? '') : $body;

    if ($isHtml) {
        $boundary = 'HR1-' . bin2hex(random_bytes(8));
        $headers = "To: {$toEmail}\r\n"
            . "Subject: {$subject}\r\n"
            . "From: " . ($extra['From'] ?? hr1MailFrom()) . "\r\n"
            . "Reply-To: " . ($extra['Reply-To'] ?? HR1_MAIL_FROM) . "\r\n"
            . "X-Mailer: HR1-System/2.0\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

        $message = $headers . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody !== '' ? $textBody : html_to_plain($htmlBody))) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--{$boundary}--\r\n";
    } else {
        $headers = "To: {$toEmail}\r\n"
            . "Subject: {$subject}\r\n"
            . "From: " . ($extra['From'] ?? hr1MailFrom()) . "\r\n"
            . "Reply-To: " . ($extra['Reply-To'] ?? HR1_MAIL_FROM) . "\r\n"
            . "X-Mailer: HR1-System/2.0\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        $message = $headers . "\r\n" . $body;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = HR1_SENDMAIL_PATH . ' -t -i';
    $process = @proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        error_log('HR1 MAIL: failed to open sendmail process — ' . HR1_SENDMAIL_PATH);
        return false;
    }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        error_log("HR1 MAIL: sendmail exited with code {$exitCode}"
            . ($stderr !== '' ? " stderr: {$stderr}" : '')
            . ($stdout !== '' ? " stdout: {$stdout}" : ''));
        return false;
    }

    return true;
}

/** Minimal HTML → plain-text conversion used only for multipart text fallback. */
function html_to_plain(string $html): string
{
    $t = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $t = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $t);
    $t = preg_replace('/<li[^>]*>/i', "- ", $t);
    $t = preg_replace('/<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $t);
    $t = strip_tags($t);
    return html_entity_decode(trim(preg_replace('/[ \t]+/', ' ', $t)), ENT_QUOTES, 'UTF-8');
}

/**
 * Send the password-reset approval email to the employee after HR approval.
 * Called from the HR approval page.
 *
 * @param string $toEmail      Employee's registered email (from users.email)
 * @param string $rawToken     Raw reset token for the link
 * @param string $employeeName Employee's display name for the greeting
 */
function sendResetApprovedToEmployee(string $toEmail, string $rawToken, string $employeeName = ''): bool
{
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('HR1 PASSWORD RESET EMAIL: skipped — invalid email address');
        return false;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $resetUrl = $scheme . '://' . $host . BASE_URL . '/auth/reset_password.php?token=' . $rawToken;

    $greeting = $employeeName !== '' ? "Hello {$employeeName}," : 'Hello,';

    $subject = 'HR1 Password Reset Request Approved';

    $body = "{$greeting}\n\n"
        . "Your password reset request has been approved by HR/Admin.\n\n"
        . "You may now proceed with resetting your HR1 account password.\n\n"
        . "Click the link below to set a new password. This link expires in 1 hour.\n\n"
        . "{$resetUrl}\n\n"
        . "If you did not request a password reset, please contact HR/Admin immediately.\n\n"
        . "Thank you,\n"
        . "HR1 System\n";

    $sent = hr1Sendmail($toEmail, $subject, $body);

    if (!$sent) {
        error_log('HR1 PASSWORD RESET EMAIL FAILED: to=' . $toEmail);
    }

    return $sent;
}

/**
 * Send the "Online Examination" email to an applicant after HR moves their
 * application to "accepted" / "passed_screening".
 *
 * This is the applicant's DIRECT, no-login access point to the examination.
 * It uses the EXACT required wording and a secure tokenized HR1 access link.
 * The applicant does NOT have an HR1 applicant account, so no login is ever
 * required and no login-required page is linked.
 *
 * Reuses the existing hr1Sendmail() SMTP relay — no separate mail system.
 *
 * @param string $toEmail        Applicant's email (from the applicants table)
 * @param string $applicantName  Applicant display name for the greeting
 * @param string $position       The applied job position (used in subject & body)
 * @param string $examAccessLink The secure tokenized no-login HR1 exam access URL
 * @return bool  true if sendmail accepted the message
 */
function sendApplicationAcceptedEmail(string $toEmail, string $applicantName, string $position, string $examAccessLink): bool
{
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('HR1 ACCEPTANCE EMAIL: skipped — invalid email address');
        return false;
    }

    $subject = 'Online Examination – ' . $position;

    $companyName = 'TRI-M Global Logistics & Trading Inc.';
    $safeName = $applicantName !== '' ? $applicantName : 'Applicant';
    $safeLink = htmlspecialchars($examAccessLink, ENT_QUOTES, 'UTF-8');

    

    $textBody = "Dear {$safeName},\n\n"
        . "Congratulations!\n\n"
        . "We are pleased to inform you that your application for the {$position} position at {$companyName} has been reviewed, and you have been qualified to proceed to the online examination as part of our recruitment process.\n\n"
        . "As the next step, you are invited to take the Online Examination. Please access your examination using the link below:\n\n"
        . "[Access Online Examination]\n"
        . $examAccessLink . "\n\n"
        . "Please complete the examination within the required period and follow the instructions provided on the examination page.\n\n"
        . "Your examination result will be reviewed as part of the next stage of the recruitment process.\n\n"
        . "Thank you for your time and interest in joining our organization.\n\n"
        . "Human Resources\n\n"
        . $companyName . "\n";

    $htmlBody = "<!DOCTYPE html>\n<html><body style=\"margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;\">\n"
        . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f5f6f8;padding:24px 0;\"><tr><td align=\"center\">\n"
        . "<table role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;\">\n"
        . "<tr><td style=\"padding:28px 32px;\">\n"
        . hr1EmailLogoBlock()
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Dear {$safeName},</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\"><strong>Congratulations!</strong></p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">We are pleased to inform you that your application for the <strong>{$position}</strong> position at <strong>{$companyName}</strong> has been reviewed, and you have been qualified to proceed to the online examination as part of our recruitment process.</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">As the next step, you are invited to take the Online Examination. Please access your examination using the link below:</p>\n"
        . "<p style=\"margin:0 0 20px 0;text-align:center;\"><a href=\"{$safeLink}\" style=\"display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;\">Access Online Examination</a></p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Please complete the examination within the required period and follow the instructions provided on the examination page.</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Your examination result will be reviewed as part of the next stage of the recruitment process.</p>\n"
        . "<p style=\"margin:0 0 24px 0;font-size:15px;line-height:1.6;\">Thank you for your time and interest in joining our organization.</p>\n"
        . "<p style=\"margin:0 0 4px 0;font-size:15px;line-height:1.6;\">Human Resources</p>\n"
        . "<p style=\"margin:0;font-size:15px;line-height:1.6;\">{$companyName}</p>\n"
        . "</td></tr></table>\n"
        . "</td></tr></table>\n</body></html>\n";

    $sent = hr1Sendmail($toEmail, $subject, $htmlBody, ['html' => true, 'text' => $textBody]);

    if (!$sent) {
        error_log('HR1 ACCEPTANCE EMAIL FAILED: to=' . $toEmail);
    }

    return $sent;
}

/**
 * Send the "Exam Passed -> Final Interview" email to an applicant after HR1's
 * server-side result engine determines ($passed === true) inside
 * recordExamResult(). This is the single authoritative trigger.
 *
 * It does NOT select or hire the applicant and does NOT schedule the final
 * interview; it only informs the applicant that they qualified and that HR
 * will contact them with the interview schedule. The actual interview
 * invitation (date/time/location) is sent later by the existing
 * sendInterviewInvitationEmail() when HR schedules the final interview.
 *
 * @param string $toEmail     Applicant's registered email (from the database)
 * @param string $applicantName Applicant display name
 * @param string $position    The applied job position
 * @return bool  true if sendmail accepted the message
 */
function sendExamPassedFinalInterviewEmail(string $toEmail, string $applicantName, string $position): bool
{
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('HR1 EXAM-PASSED EMAIL: skipped — invalid email address');
        return false;
    }

    $subject = 'Congratulations! You Passed the Examination – Final Interview';

    $companyName = 'TRI-M Global Logistics & Trading Inc.';

    $body = "Dear " . ($applicantName !== '' ? $applicantName : 'Applicant') . ",\n\n"
        . "Congratulations!\n\n"
        . "We are pleased to inform you that you have successfully passed the online examination for the position of " . $position . ".\n\n"
        . "You are now qualified to proceed to the Final Interview stage of our recruitment process.\n\n"
        . "Please wait for the interview scheduling instructions from our HR Department. HR will contact you once your Final Interview has been scheduled.\n\n"
        . "We look forward to speaking with you.\n\n"
        . "Best regards,\n\n"
        . "HR Department\n"
        . $companyName . "\n";

    $sent = hr1Sendmail($toEmail, $subject, $body);

    if (!$sent) {
        error_log('HR1 EXAM-PASSED EMAIL FAILED: to=' . $toEmail);
    }

    return $sent;
}

/**
 * Send the "Initial Screening → Online Examination" email to an applicant.
 * This is the applicant's DIRECT access point for the examination. The
 * applicant does NOT have an HR1 applicant account, so the email link is a
 * tokenized, self-serve link — no login, no dashboard, no HR1 credentials.
 *
 * The link contains ONLY the assignment's opaque access_token (exm_...); it
 * never exposes DB ids, applicant ids, API keys, passwords or HR3 details.
 * The HR3 provider URL is never placed in this email — the applicant always
 * enters via the secure HR1 tokenized access page.
 *
 * @param string $toEmail       Applicant's registered email (from applicants.email)
 * @param string $applicantName Applicant display name
 * @param string $position      The applied job position
 * @param string $examLink      The tokenized HR1 exam access URL
 * @return bool  true if sendmail accepted the message
 */
function sendExamAssignmentEmail(string $toEmail, string $applicantName, string $position, string $examLink): bool
{
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('HR1 EXAM ASSIGNMENT EMAIL: skipped — invalid email address');
        return false;
    }

    $subject = 'Online Examination – ' . $position;

    $companyName = 'TRI-M Global Logistics & Trading Inc.';
    $safeName = $applicantName !== '' ? $applicantName : 'Applicant';
    $safeLink = htmlspecialchars($examLink, ENT_QUOTES, 'UTF-8');

    $textBody = "Dear {$safeName},\n\n"
        . "Congratulations!\n\n"
        . "We are pleased to inform you that your application for the {$position} position at {$companyName} has been reviewed, and you have been qualified to proceed to the online examination as part of our recruitment process.\n\n"
        . "As the next step, you are invited to take the Online Examination. Please access your examination using the link below:\n\n"
        . "[Access Online Examination]\n"
        . $examLink . "\n\n"
        . "Please complete the examination within the required period and follow the instructions provided on the examination page.\n\n"
        . "Your examination result will be reviewed as part of the next stage of the recruitment process.\n\n"
        . "Thank you for your time and interest in joining our organization.\n\n"
        . "Human Resources\n\n"
        . $companyName . "\n";

    $htmlBody = "<!DOCTYPE html>\n<html><body style=\"margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;\">\n"
        . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f5f6f8;padding:24px 0;\"><tr><td align=\"center\">\n"
        . "<table role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;\">\n"
        . "<tr><td style=\"padding:28px 32px;\">\n"
        . hr1EmailLogoBlock()
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Dear {$safeName},</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\"><strong>Congratulations!</strong></p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">We are pleased to inform you that your application for the <strong>{$position}</strong> position at <strong>{$companyName}</strong> has been reviewed, and you have been qualified to proceed to the online examination as part of our recruitment process.</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">As the next step, you are invited to take the Online Examination. Please access your examination using the link below:</p>\n"
        . "<p style=\"margin:0 0 20px 0;text-align:center;\"><a href=\"{$safeLink}\" style=\"display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;\">Access Online Examination</a></p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Please complete the examination within the required period and follow the instructions provided on the examination page.</p>\n"
        . "<p style=\"margin:0 0 16px 0;font-size:15px;line-height:1.6;\">Your examination result will be reviewed as part of the next stage of the recruitment process.</p>\n"
        . "<p style=\"margin:0 0 24px 0;font-size:15px;line-height:1.6;\">Thank you for your time and interest in joining our organization.</p>\n"
        . "<p style=\"margin:0 0 4px 0;font-size:15px;line-height:1.6;\">Human Resources</p>\n"
        . "<p style=\"margin:0;font-size:15px;line-height:1.6;\">{$companyName}</p>\n"
        . "</td></tr></table>\n"
        . "</td></tr></table>\n</body></html>\n";

    $sent = hr1Sendmail($toEmail, $subject, $htmlBody, ['html' => true, 'text' => $textBody]);

    if (!$sent) {
        error_log('HR1 EXAM ASSIGNMENT EMAIL FAILED: to=' . $toEmail);
    }

    return $sent;
}

/**
 * Send the interview-invitation email to an applicant after an interview is
 * successfully scheduled. Reuses the existing hr1Sendmail() SMTP relay.
 *
 * @param string $toEmail       Applicant's registered email (from the database)
 * @param string $applicantName Applicant display name
 * @param string $position      The applied job position (used in subject & body)
 * @param array  $details       Associative array with keys: date, time, location,
 *                              company_address, interviewer, additional_instructions
 * @return bool  true if sendmail accepted the message
 */
function sendInterviewInvitationEmail(string $toEmail, string $applicantName, string $position, array $details): bool
{
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('HR1 INTERVIEW EMAIL: skipped — invalid email address');
        return false;
    }

    $subject = 'Interview Invitation – ' . $position;

    $date = (string) ($details['date'] ?? '');
    $time = (string) ($details['time'] ?? '');
    $location = (string) ($details['location'] ?? '');
    $address = (string) ($details['company_address'] ?? '');
    $interviewer = (string) ($details['interviewer'] ?? '');
    $instructions = (string) ($details['additional_instructions'] ?? '');

    $body = "Dear " . ($applicantName !== '' ? $applicantName : 'Applicant') . ",\n\n"
        . "Thank you for your application for the " . $position . " position at TRI-M Global Logistics & Trading Inc.\n\n"
        . "We are pleased to inform you that you have been selected to proceed to the interview stage of our recruitment process.\n\n"
        . "Interview Details:\n\n"
        . "Date: " . $date . "\n"
        . "Time: " . $time . "\n"
        . "Location: " . $location . "\n"
        . "Company Address: " . $address . "\n"
        . "Interviewer: " . $interviewer . "\n\n"
        . "Additional Instructions:\n"
        . ($instructions !== '' ? $instructions : '—') . "\n\n"
        . "Please arrive 10\u201315 minutes before your scheduled interview and bring your resume/CV and a valid ID.\n\n"
        . "If you have any questions or need to request a schedule change, please contact our HR department.\n\n"
        . "We look forward to meeting you.\n\n"
        . "Best regards,\n\n"
        . "Human Resources Department\n"
        . "TRI-M Global Logistics & Trading Inc.\n";

    $sent = hr1Sendmail($toEmail, $subject, $body);

    if (!$sent) {
        error_log('HR1 INTERVIEW EMAIL FAILED: to=' . $toEmail);
    }

    return $sent;
}

/**
 * Notify an HR user by email that a password reset request is pending.
 * In-app notification is handled by notifyHRofResetRequest() in password_reset.php.
 * This function is a supplementary email channel (optional).
 */
function sendResetRequestToHR(string $hrEmail, string $maskedEmail): bool
{
    $subject = HR1_APP_NAME . ' — Password Reset Request Pending';

    $body = "Hello,\n\n"
        . "A password reset has been requested for: {$maskedEmail}\n\n"
        . "Please log in to the HR system to approve or reject this request.\n\n"
        . "This is an automated message. Please do not reply.\n";

    $sent = hr1Sendmail($hrEmail, $subject, $body);

    if (!$sent) {
        error_log('HR1 HR NOTIFICATION EMAIL FAILED: to=' . $hrEmail);
    }

    return $sent;
}
