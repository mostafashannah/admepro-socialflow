<?php
/**
 * mai-am-morning-report-cron.php — kicks off Mai's morning WhatsApp
 * check-in with every active account manager. Run once around 12:00.
 * Skips Friday/Saturday (the agency's weekend). Idempotent — safe to
 * re-run; maiStartReportSession() no-ops if today's session already exists.
 *
 *   0 12 * * * /usr/bin/php /var/www/socialflow/mai-am-morning-report-cron.php >> /var/www/socialflow/mai-am-report-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-report-lib.php';

// Friday=5, Saturday=6 in date('N') — the agency's weekend.
$dow = (int) date('N');
if ($dow === 5 || $dow === 6) { echo json_encode(['skipped' => 'weekend']); exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$ams = $pdo->query("SELECT id, name, email, whatsapp_number FROM team_members WHERE role = 'account_manager' AND status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_ASSOC);
$started = 0;
foreach ($ams as $am) {
    try {
        if (maiStartReportSession($pdo, $am, 'morning')) $started++;
    } catch (Throwable $e) {
        error_log('[mai-am-morning-report-cron] ' . $am['name'] . ': ' . $e->getMessage());
    }
}

echo json_encode(['checked' => count($ams), 'started' => $started]);
