<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * daily-email-cron.php — sends the "Daily Performance Summary" email
 * (configured in Settings > Daily Email) to every eligible team member,
 * once per day at the configured send_time/timezone.
 *
 * Nothing previously sent this email automatically — the Settings page
 * only let you configure it and manually "Send Test" / "Send to All".
 * This script is the missing scheduled job.
 *
 * Setup: run every 5 minutes so the configured send_time is caught
 * promptly, e.g. crontab entry:
 *   (star)/5 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/daily-email-cron.php >> /var/www/socialflow/daily-email-cron.log 2>&1
 * (i.e. 5-minute wildcard cron syntax)
 */

// CLI-only — this script performs real writes (emails, DB records) and has
// no authentication of its own, so it must never be reachable over plain
// HTTP (this file sits in the public web root alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$es = $pdo->query("SELECT * FROM email_settings WHERE setting_key = 'daily_performance_email' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$es || !$es['enabled']) { echo json_encode(['sent'=>false,'reason'=>'disabled_or_missing']); exit; }

$tz = new DateTimeZone($es['timezone'] ?: 'Africa/Cairo');
$now = new DateTime('now', $tz);
$today = $now->format('Y-m-d');
$nowHM = $now->format('H:i');

// Already sent today? Skip (idempotent across the every-5-min cron ticks).
if ($es['last_sent_at']) {
    $lastSent = new DateTime($es['last_sent_at']);
    $lastSent->setTimezone($tz);
    if ($lastSent->format('Y-m-d') === $today) {
        echo json_encode(['sent'=>false,'reason'=>'already_sent_today']);
        exit;
    }
}

// Only fire once we've reached (or just passed) the configured send time.
$sendTime = $es['send_time'] ?: '18:00';
if ($nowHM < $sendTime) { echo json_encode(['sent'=>false,'reason'=>'not_time_yet','now'=>$nowHM,'send_time'=>$sendTime]); exit; }

require_once __DIR__ . '/daily-email-lib.php';
$D = de_load($pdo, $tz);
$insertLog = $pdo->prepare("INSERT INTO email_logs (id, `to`, subject, from_name, status, error_message, sent_at) VALUES (UUID(), :to, :subject, :from_name, :status, :error_message, :sent_at)");
$motiv = de_motivation();
$subject = de_subject($es, $tz);
$fromName = $es['sender_name'] ?: 'SocialFlow';
$sentCount = 0;
foreach ($D['team'] as $member) {
    if (!$member['email']) continue;
    [$ok, $raw] = de_send($member['email'], $subject, de_html_for($es, $member, $D, $motiv), $fromName);
    $insertLog->execute([':to' => $member['email'], ':subject' => $subject, ':from_name' => $fromName,
        ':status' => $ok ? 'sent' : 'failed', ':error_message' => $ok ? '' : $raw, ':sent_at' => $now->format('Y-m-d H:i:s')]);
    if ($ok) $sentCount++;
}
$pdo->prepare("UPDATE email_settings SET last_sent_at = :ts, last_sent_count = :cnt WHERE setting_key = 'daily_performance_email'")
    ->execute([':ts' => gmdate('Y-m-d\TH:i:s\Z'), ':cnt' => $sentCount]);
echo json_encode(['sent' => true, 'checked' => count($D['team']), 'sent_count' => $sentCount]);
