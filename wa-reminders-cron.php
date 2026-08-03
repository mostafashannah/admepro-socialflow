<?php
/**
 * wa-reminders-cron.php — sends any due WhatsApp reminders set via Pro's
 * set_reminder tool (e.g. someone replies "remind me to call her at 12pm"
 * to a lead-assignment notification).
 *
 * Run every minute:
 *   * * * * * /usr/bin/php /var/www/socialflow/wa-reminders-cron.php >> /var/www/socialflow/wa-reminders-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$due = $pdo->query("SELECT id, phone, message FROM wa_reminders WHERE sent = 0 AND remind_at <= NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($due as $r) {
    try {
        sendWhatsAppReply($r['phone'], "⏰ Reminder: {$r['message']}");
        $pdo->prepare("UPDATE wa_reminders SET sent = 1 WHERE id = :id")->execute([':id' => $r['id']]);
    } catch (Throwable $e) {
        error_log('[wa-reminders-cron] failed to send reminder ' . $r['id'] . ': ' . $e->getMessage());
    }
}
