<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * daily-report-cron.php — sends the WhatsApp Daily Report at the time, on the
 * days and with the sections chosen in Settings > Daily Report.
 * Runs every 10 minutes; sends at most once a day (Cairo time) once the chosen
 * time has passed. `php daily-report-cron.php now` forces a send.
 *
 *   *\/10 * * * * /usr/bin/php /var/www/socialflow/daily-report-cron.php >> /var/www/socialflow/daily-report-cron.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()
require_once __DIR__ . '/daily-report-lib.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

$force = ($argv[1] ?? '') === 'now';
$s = drLoadSettings($pdo);
if (empty($s['id'])) { echo json_encode(['skipped' => 'no settings saved yet']); exit; }
if (!$force) {
    if (!$s['enabled']) { echo json_encode(['skipped' => 'disabled']); exit; }
    $now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
    if (!in_array((int)$now->format('N'), array_map('intval', $s['days']), true)) { echo json_encode(['skipped' => 'not a report day']); exit; }
    if ($now->format('H:i') < $s['send_time']) { echo json_encode(['skipped' => 'too early']); exit; }
    $today = $now->format('Y-m-d');
    // Claim today atomically so two overlapping runs can't both send.
    $claim = $pdo->prepare("UPDATE daily_report_settings SET last_sent_date = :d WHERE id = :id AND (last_sent_date IS NULL OR last_sent_date <> :d)");
    $claim->execute([':d' => $today, ':id' => $s['id']]);
    if ($claim->rowCount() === 0) { echo json_encode(['skipped' => 'already sent today']); exit; }
}
try {
    echo json_encode(drSend($pdo, $s));
} catch (Throwable $e) {
    error_log('[daily-report-cron] ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
