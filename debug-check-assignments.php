<?php
// READ-ONLY diagnostic — run once, then delete. Shows exactly what's stored
// for a given team member's name so we can see why the backfill did or
// didn't touch their posts.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$name = $argv[1] ?? 'Sherif Hossam';
$member = $pdo->prepare("SELECT id, name, email, role FROM team_members WHERE name = ?");
$member->execute([$name]);
$m = $member->fetch(PDO::FETCH_ASSOC);
if (!$m) { echo json_encode(['error' => "No team member named '$name'"]) . "\n"; exit; }
echo "Member: " . json_encode($m) . "\n\n";

$posts = $pdo->prepare(
    "SELECT id, title, stage, platform, assigned_to, content_assigned_to, design_assigned_to
     FROM posts WHERE assigned_to = ? OR content_assigned_to = ? OR design_assigned_to = ?"
);
$posts->execute([$m['email'], $m['email'], $m['email']]);
$rows = $posts->fetchAll(PDO::FETCH_ASSOC);
echo "Posts currently pointing at them (any of the 3 fields): " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r) . "\n";

echo "\n-- Stage-change comments mentioning their name --\n";
$comments = $pdo->prepare("SELECT post_id, content, created_at FROM comments WHERE type='stage_change' AND content LIKE ?");
$comments->execute(['%assigned to ' . $name]);
$crows = $comments->fetchAll(PDO::FETCH_ASSOC);
echo "Found: " . count($crows) . "\n";
foreach ($crows as $c) echo json_encode($c) . "\n";
