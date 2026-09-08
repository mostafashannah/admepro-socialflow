<?php
// READ-ONLY diagnostic — every post for ARABIA Uniform created in the last
// 3 hours, regardless of stage, to see what actually happened to the
// Ready Content post the user just created (never saved? already
// published? stuck in a different stage?).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, title, stage, platform, task_type, scheduled_date, scheduled_time, publish_attempts, publish_error, published_at, created_at
     FROM posts WHERE client_name LIKE '%Arabia%' AND created_at >= (NOW() - INTERVAL 3 HOUR)
     ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(no posts for this client in the last 3 hours)\n";
