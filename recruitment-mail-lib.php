<?php
// ================================================================
// SocialFlow — Careers/recruitment outbound mail via SMTP
//
// Sends confirmation/completion emails through the actual recruitment
// mailbox (s.eleseely@admepro.com by default) via SMTP, instead of
// Resend/noreply@admepro.com — so sent mail lives in the same inbox that
// receives applications, rather than a disconnected transactional sender.
// Requires: composer require phpmailer/phpmailer
// ================================================================

require_once __DIR__ . '/config.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Reads the same UI-configurable settings the recruitment cron uses
// (Recruitment → Email Settings), falling back to config.php constants.
function recruitment_smtp_settings(PDO $pdo) {
    $emailSettings = [];
    $row = $pdo->query("SELECT recruitment_email_settings FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['recruitment_email_settings'])) {
        $decoded = json_decode($row['recruitment_email_settings'], true);
        if (is_array($decoded)) $emailSettings = $decoded;
    }
    return [
        'host'     => !empty($emailSettings['smtp_host']) ? $emailSettings['smtp_host'] : (defined('RECRUITMENT_SMTP_HOST') ? RECRUITMENT_SMTP_HOST : 'smtp.hostinger.com'),
        'port'     => !empty($emailSettings['smtp_port']) ? (int) $emailSettings['smtp_port'] : (defined('RECRUITMENT_SMTP_PORT') ? RECRUITMENT_SMTP_PORT : 465),
        'username' => !empty($emailSettings['imap_email']) ? $emailSettings['imap_email'] : (defined('RECRUITMENT_IMAP_EMAIL') ? RECRUITMENT_IMAP_EMAIL : ''),
        'password' => !empty($emailSettings['imap_password']) ? $emailSettings['imap_password'] : (defined('RECRUITMENT_IMAP_PASSWORD') ? RECRUITMENT_IMAP_PASSWORD : ''),
    ];
}

// Returns true/false; never throws — a mail failure should never break
// the calling flow (application capture, form submission).
function send_recruitment_email(PDO $pdo, $to, $subject, $html, $fromName = 'Admepro Careers') {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('[recruitment-mail] PHPMailer not installed — run: composer require phpmailer/phpmailer');
        return false;
    }
    $s = recruitment_smtp_settings($pdo);
    if (!$s['host'] || !$s['username'] || !$s['password']) {
        error_log('[recruitment-mail] SMTP not configured — set host/credentials in Recruitment → Email Settings');
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $s['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $s['username'];
        $mail->Password   = $s['password'];
        $mail->SMTPSecure = $s['port'] == 587 ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $s['port'];
        $mail->Timeout    = 20;
        $mail->setFrom($s['username'], $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[recruitment-mail] send failed: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[recruitment-mail] send failed: ' . $e->getMessage());
        return false;
    }
}
