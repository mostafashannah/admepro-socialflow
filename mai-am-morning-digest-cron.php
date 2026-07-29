<?php
/**
 * mai-am-morning-digest-cron.php — sends admins one short WhatsApp summary
 * of that day's morning account-manager check-ins. Run ~1hr after
 * mai-am-morning-report-cron.php (e.g. 13:00) to give AMs time to reply.
 * Idempotent — see maiSendAdminDigest().
 *
 *   0 13 * * * /usr/bin/php /var/www/socialflow/mai-am-morning-digest-cron.php >> /var/www/socialflow/mai-am-report-cron.log 2>&1
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

maiSendAdminDigest($pdo, 'morning');
echo json_encode(['ok' => true]);
