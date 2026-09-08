<?php
// READ-ONLY diagnostic — the Timer display shows a permanently frozen
// 00:00:00 for an active entry, which is the exact symptom you'd get if
// `new Date(started_at)` fails to parse in the browser (elapsed becomes
// NaN, and fmtSecs's `Number(s)||0` fallback silently treats NaN as 0
// instead of erroring). Checking the raw format of `started_at` as stored/
// returned for currently-active entries.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$cols = $pdo->query("SHOW COLUMNS FROM time_entries")->fetchAll(PDO::FETCH_ASSOC);
echo "=== time_entries columns (name : type) ===\n";
foreach ($cols as $c) echo "{$c['Field']} : {$c['Type']}\n";

$rows = $pdo->query(
    "SELECT id, post_id, user_email, started_at, paused_at, status, total_seconds, date, created_at
     FROM time_entries
     WHERE status = 'active'
     ORDER BY created_at DESC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Currently active time entries ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none currently active)\n";
