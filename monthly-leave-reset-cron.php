<?php
/**
 * monthly-leave-reset-cron.php — WFH days (2/month) and Personal Leave
 * hours (4/month) are both non-shiftable: whatever's unused at month-end
 * is simply lost, not carried into the next month. Vacation days are
 * NOT touched here — those remain an annual pool.
 *
 * Run once, right after midnight on the 1st of each month:
 *   5 0 1 * * /usr/bin/php /var/www/socialflow/monthly-leave-reset-cron.php >> /var/www/socialflow/monthly-leave-reset-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$n = $pdo->exec("UPDATE team_members SET wfh_days_used = 0, personal_leave_hours_used = 0");
echo "Reset WFH/personal-leave usage for {$n} team member(s).\n";
