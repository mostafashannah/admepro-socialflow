<?php
// READ-ONLY diagnostic — same as debug-sherif-today.php but re-run now to
// see current stage/due_date/completed_at state, specifically checking
// whether anything is actually in design_review and whether due_date lines
// up with today (generateDailySchedule falls back to "only show it if
// due_date === today, or if due_date is empty assume today" — if due_date
// got set to some other day this would explain a completed task vanishing
// from the Timeline instead of showing at top).
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
echo "\nServer today: {$today}\n";

$stmt = $pdo->prepare(
    "SELECT id, title, stage, post_type, priority, estimated_minutes,
            assigned_to, content_assigned_to, design_assigned_to,
            due_date, due_time, scheduled_date, scheduled_time,
            content_completed_at, design_completed_at, updated_at
     FROM posts
     WHERE (assigned_to = :e OR content_assigned_to = :e OR design_assigned_to = :e)
     ORDER BY updated_at DESC
     LIMIT 20"
);
$stmt->execute([':e' => $email]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Most recently updated posts for {$tm['name']} (any date) ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) echo "(none)\n";
