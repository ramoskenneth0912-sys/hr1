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
define('HR1_APP_NAME', defined('APP_NAME') ? APP_NAME : 'Merchandising Management System');

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
    $headers = "To: {$toEmail}\r\n"
        . "Subject: {$subject}\r\n"
        . "From: " . ($extra['From'] ?? HR1_MAIL_FROM) . "\r\n"
        . "Reply-To: " . ($extra['Reply-To'] ?? HR1_MAIL_FROM) . "\r\n"
        . "X-Mailer: HR1-System/2.0\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    $message = $headers . "\r\n" . $body;

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
