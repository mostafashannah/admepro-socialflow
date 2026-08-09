<?php
// READ-ONLY diagnostic — checking whether the capacity-overflow rollover
// (tasks that don't fit in today's working hours get pushed to the next
// working day) over-shifted some tasks that should have still fit today,
// leaving an unexplained gap at the end of Sherif's Timeline.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tm = $pdo->query("SELECT id, name, email FROM team_members WHERE name LIKE '%Sherif%'")->fetch(PDO::FETCH_ASSOC);
$email = $tm['email'];

$stmt = $pdo->prepare(
    "SELECT id, title, stage, estimated_minutes, due_date, due_time
     FROM posts
     WHERE (assigned_to = :e OR content_assigned_to = :e OR design_assigned_to = :e)
       AND stage NOT IN ('published','approved','rejected')
     ORDER BY due_date ASC, due_time ASC"
);
$stmt->execute([':e' => $email]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== All active (non-terminal) posts for {$tm['name']} ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
