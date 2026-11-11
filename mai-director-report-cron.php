<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * mai-director-report-cron.php — Mai's ONE daily check-in with the Account Director(s),
 * at 19:30 Cairo time. Different from the account managers' morning/end-of-day
 * check-ins: she asks for the main things worth noting in the system, saves what she
 * hears (client memory, lead updates), turns anything the admin must know into
 * notifications, and the results land in the admin's daily brief email.
 * Skips Friday/Saturday. Run every 10 minutes — it waits for 19:30 and is idempotent.
 *
 *   *\/10 * * * * /usr/bin/php /var/www/socialflow/mai-director-report-cron.php >> /var/www/socialflow/mai-am-report-cron.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-report-lib.php';

$tz = new DateTimeZone('Africa/Cairo');
$now = new DateTime('now', $tz);
$force = ($argv[1] ?? '') === 'now';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

// Anything started more than 3 hours ago and never finished is recorded as missed.
$missed = maiMarkMissedSessions($pdo, 'director', 3);

$dow = (int)$now->format('N');
if (!$force && ($dow === 5 || $dow === 6)) { echo json_encode(['skipped' => 'weekend', 'missed' => $missed]); exit; }
if (!$force && $now->format('H:i') < '19:30') { echo json_encode(['skipped' => 'before 19:30', 'missed' => $missed]); exit; }

$dirs = $pdo->query("SELECT id, name, email, whatsapp_number FROM team_members WHERE role = 'account_director' AND status = 'active' AND mai_checkins_enabled = 1 AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$started = 0;
foreach ($dirs as $d) {
    try {
        if (maiStartReportSession($pdo, $d, 'director')) $started++;
    } catch (Throwable $e) {
        error_log('[mai-director-report-cron] ' . $d['name'] . ': ' . $e->getMessage());
    }
}
echo json_encode(['checked' => count($dirs), 'started' => $started, 'missed' => $missed]);
