<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * mai-am-eod-report-cron.php — kicks off Mai's end-of-day WhatsApp
 * check-in with every active account manager. Run once around 19:00.
 * Skips Friday/Saturday. Idempotent — see mai-am-morning-report-cron.php.
 *
 *   0,15,30,45 * * * * (per-AM time from Mai Reports tab; default 19:00)
 *   was: 0 19 * * * /usr/bin/php /var/www/socialflow/mai-am-eod-report-cron.php >> /var/www/socialflow/mai-am-report-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-report-lib.php';

$dow = (int) date('N');
if ($dow === 5 || $dow === 6) { echo json_encode(['skipped' => 'weekend']); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$ams = $pdo->query("SELECT id, name, email, whatsapp_number, mai_checkin_config FROM team_members WHERE role IN ('account_manager','account_executive') AND status = 'active' AND mai_checkins_enabled = 1 AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$started = 0;
foreach ($ams as $am) {
    try {
        if (!maiCheckinDueNow($am, 'eod', '19:00')) continue;
        if (maiStartReportSession($pdo, $am, 'eod')) $started++;
    } catch (Throwable $e) {
        error_log('[mai-am-eod-report-cron] ' . $am['name'] . ': ' . $e->getMessage());
    }
}

echo json_encode(['checked' => count($ams), 'started' => $started]);
