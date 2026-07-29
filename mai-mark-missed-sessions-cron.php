<?php
/**
 * mai-mark-missed-sessions-cron.php — any Mai check-in session that's still
 * 'in_progress' several hours after it started (the AM never replied, or
 * started and abandoned it) gets marked 'missed' with score=0 instead of
 * sitting there forever excluded from her average. Run once for each
 * report type, well after that round's reasonable reply window.
 *
 *   0 16 * * * /usr/bin/php /var/www/socialflow/mai-mark-missed-sessions-cron.php morning >> /var/www/socialflow/mai-am-report-cron.log 2>&1
 *   0 23 * * * /usr/bin/php /var/www/socialflow/mai-mark-missed-sessions-cron.php eod     >> /var/www/socialflow/mai-am-report-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-report-lib.php';

$reportType = $argv[1] ?? '';
if (!in_array($reportType, ['morning', 'eod'], true)) {
    echo json_encode(['error' => "Usage: php mai-mark-missed-sessions-cron.php morning|eod"]);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$missed = maiMarkMissedSessions($pdo, $reportType);
echo json_encode(['report_type' => $reportType, 'marked_missed' => $missed]);
