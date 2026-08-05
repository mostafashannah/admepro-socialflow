<?php
// READ-ONLY diagnostic — run once, then delete. Shows the raw
// assigned_to_extra value for a post matching the given title, plus the
// exact team_members rows it resolves against, to see why an assignee's
// avatar might not be rendering.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$title = $argv[1] ?? 'Broadcast Message Design';
$stmt = $pdo->prepare("SELECT id, title, assigned_to, assigned_to_extra FROM posts WHERE title = ?");
$stmt->execute([$title]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($posts as $p) {
    echo "Post {$p['id']}: assigned_to={$p['assigned_to']}\n";
    echo "  assigned_to_extra (raw): " . var_export($p['assigned_to_extra'], true) . "\n";
    $extra = json_decode($p['assigned_to_extra'] ?: '[]', true) ?: [];
    foreach (array_merge([$p['assigned_to']], $extra) as $email) {
        $m = $pdo->prepare("SELECT id, name, email, avatar_url FROM team_members WHERE email = ?");
        $m->execute([$email]);
        $row = $m->fetch(PDO::FETCH_ASSOC);
        echo "  -> $email: " . ($row ? "name={$row['name']} avatar_url=" . var_export($row['avatar_url'], true) : "NOT FOUND") . "\n";
    }
    echo "\n";
}
