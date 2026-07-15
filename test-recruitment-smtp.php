<?php
// One-off diagnostic — run via `php test-recruitment-smtp.php you@example.com`
// then delete this file. Prints the SMTP settings being used (password
// masked) and the exact PHPMailer error if the send fails.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recruitment-mail-lib.php';

$to = $argv[1] ?? null;
if (!$to) { echo "Usage: php test-recruitment-smtp.php you@example.com\n"; exit(1); }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$s = recruitment_smtp_settings($pdo);
echo "host=" . var_export($s['host'], true) . "\n";
echo "port=" . var_export($s['port'], true) . "\n";
echo "username=" . var_export($s['username'], true) . "\n";
echo "password=" . (strlen($s['password']) ? str_repeat('*', strlen($s['password'])) . ' (' . strlen($s['password']) . ' chars)' : '(EMPTY)') . "\n";

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) { echo "[SMTP] $str\n"; };
    $mail->Host = $s['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $s['username'];
    $mail->Password = $s['password'];
    $mail->SMTPSecure = $s['port'] == 587 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $s['port'];
    $mail->Timeout = 20;
    $mail->setFrom($s['username'], 'Admepro Careers Test');
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = 'SocialFlow SMTP test';
    $mail->Body = 'If you got this, recruitment SMTP is working.';
    $mail->send();
    echo "SUCCESS: email sent to $to\n";
} catch (Exception $e) {
    echo "FAILED: " . $mail->ErrorInfo . "\n";
}
