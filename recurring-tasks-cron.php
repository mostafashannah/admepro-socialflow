<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * recurring-tasks-cron.php — creates today's task for every active repeating task (Timeline > Repeated Task).
 * Idempotent: each template is generated at most once per day (last_generated_date). Run every 10 minutes:
 *   *\/10 * * * * /usr/bin/php /var/www/socialflow/recurring-tasks-cron.php >> /var/www/socialflow/recurring-tasks-cron.log 2>&1
 * `php recurring-tasks-cron.php debug` prints each decision.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$debug = in_array('debug', $argv ?? [], true);
$dbg = function ($m) use ($debug) { if ($debug) echo "  · $m\n"; };
$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$today = $now->format('Y-m-d');
$dow = (int)$now->format('w'); // 0 = Sunday
$created = 0;
$rows = $pdo->query("SELECT * FROM recurring_tasks WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
$ins = $pdo->prepare("INSERT INTO posts (id, title, description, project_id, client_id, client_name, assigned_to, due_date, due_time, estimated_minutes, priority, stage, platform, post_type, created_by_email)
    VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, 'recurring')");
$mark = $pdo->prepare("UPDATE recurring_tasks SET last_generated_date = ? WHERE id = ?");
$insRoutine = $pdo->prepare("INSERT INTO posts (id, title, description, assigned_to, due_date, due_time, estimated_minutes, priority, stage, platform, post_type, created_by_email, is_routine, checklist, routine_status)
    VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'planning', '', 'routine', 'recurring', 1, ?, 'open')");
$deactivate = $pdo->prepare("UPDATE recurring_tasks SET active = 0 WHERE id = ?");
// Routine tasks nobody finished by the end of their day expire as missed (never carried over).
$expired = $pdo->prepare("UPDATE posts SET routine_status = 'missed' WHERE is_routine = 1 AND routine_status = 'open' AND LEFT(due_date, 10) < ?");
$expired->execute([$today]);
$missedNow = $expired->rowCount();
foreach ($rows as $r) {
    $start = substr((string)$r['start_date'], 0, 10);
    if ($today < $start) { $dbg("{$r['title']}: not started yet"); continue; }
    if (!empty($r['end_date']) && $today > substr((string)$r['end_date'], 0, 10)) { $dbg("{$r['title']}: ended"); continue; }
    if (!empty($r['last_generated_date']) && substr((string)$r['last_generated_date'], 0, 10) === $today) { $dbg("{$r['title']}: already created today"); continue; }
    $days = json_decode((string)$r['rule_days'], true); if (!is_array($days)) $days = [];
    $interval = max(1, (int)$r['rule_interval']);
    $diff = (int)round((strtotime($today) - strtotime($start)) / 86400);
    switch ($r['rule_type']) {
        case 'once':     $due = ($today === $start); break;
        case 'weekly':   $due = in_array($dow, array_map('intval', $days), true); break;
        case 'interval': $due = ($diff % $interval) === 0; break;
        case 'monthly':  $due = ((int)substr($start, 8, 2) === (int)$now->format('j')); break;
        default:         $due = true; // daily
    }
    if (!$due) { $dbg("{$r['title']}: not due today"); continue; }
    $items = json_decode((string)($r['checklist'] ?? ''), true);
    if (is_array($items) && $items) {
        $list = array_map(fn($t) => ['text' => (string)$t, 'done' => false], $items);
        $insRoutine->execute([$r['title'], $r['description'], $r['assigned_to'], $today, $r['due_time'], (int)$r['estimated_minutes'] > 0 ? (int)$r['estimated_minutes'] : 60,
            $r['priority'] ?: 'medium', json_encode($list, JSON_UNESCAPED_UNICODE)]);
        $mark->execute([$today, $r['id']]);
        if ($r['rule_type'] === 'once') $deactivate->execute([$r['id']]);
        $created++; $dbg("{$r['title']}: routine created for {$r['assigned_to']}");
        continue;
    }
    $ins->execute([$r['title'], $r['description'], $r['project_id'], $r['client_id'], $r['client_name'], $r['assigned_to'], $today, $r['due_time'],
        (int)$r['estimated_minutes'] > 0 ? (int)$r['estimated_minutes'] : 60, $r['priority'] ?: 'medium', $r['stage'] ?: 'design', $r['post_type'] ?: 'design']);
    $mark->execute([$today, $r['id']]);
    $created++; $dbg("{$r['title']}: created for {$r['assigned_to']}");
}
echo json_encode(['created' => $created, 'expired_missed' => $missedNow, 'at' => $now->format('H:i')]) . "\n";
