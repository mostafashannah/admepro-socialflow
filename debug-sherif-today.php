<?php
// READ-ONLY diagnostic — everything assigned to Sherif due/scheduled today,
// with real completion timestamps (content_completed_at/design_completed_at)
// so we can see what's actually finished vs still pending before writing
// any re-spread script.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tm = $pdo->query("SELECT id, name, email FROM team_members WHERE name LIKE '%Sherif%'")->fetch(PDO::FETCH_ASSOC);
echo "=== Team member ===\n" . json_encode($tm) . "\n";
if (!$tm) { exit; }
$email = $tm['email'];

$today = date('Y-m-d');
$stmt = $pdo->prepare(
    "SELECT id, title, stage, post_type, priority, estimated_minutes,
            assigned_to, content_assigned_to, design_assigned_to,
            due_date, due_time, scheduled_date, scheduled_time,
            content_completed_at, design_completed_at
     FROM posts
     WHERE (assigned_to = :e OR content_assigned_to = :e OR design_assigned_to = :e)
       AND (due_date = :today OR scheduled_date = :today)
     ORDER BY due_time ASC, scheduled_time ASC"
);
$stmt->execute([':e' => $email, ':today' => $today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Today's ({$today}) tasks for {$tm['name']} ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";
