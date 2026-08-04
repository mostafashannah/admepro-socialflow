<?php
// READ-ONLY diagnostic — run once, then delete. Shows, for EVERY team
// member, how many posts currently point at them (assigned_to,
// content_assigned_to, or design_assigned_to) vs. how many stage-change
// comments exist mentioning their name — so we can spot exactly who still
// has a gap between their real activity history and what's stored.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$members = $pdo->query("SELECT id, name, email, role, status FROM team_members WHERE role IN ('content_creator','graphic_designer') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$postsStmt = $pdo->prepare(
    "SELECT id, title, stage, platform, assigned_to, content_assigned_to, design_assigned_to
     FROM posts WHERE assigned_to = :e OR content_assigned_to = :e OR design_assigned_to = :e"
);
$commentsStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE type='stage_change' AND content LIKE :n");

$out = [];
foreach ($members as $m) {
    $postsStmt->execute([':e' => $m['email']]);
    $posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
    $commentsStmt->execute([':n' => '%assigned to ' . $m['name']]);
    $commentCount = (int) $commentsStmt->fetchColumn();
    $out[] = [
        'name' => $m['name'], 'email' => $m['email'], 'role' => $m['role'], 'status' => $m['status'],
        'posts_pointing_at_them' => count($posts),
        'stage_change_comments_with_their_name' => $commentCount,
        'posts' => array_map(fn($p) => [
            'title' => $p['title'], 'stage' => $p['stage'], 'platform' => $p['platform'],
            'assigned_to' => $p['assigned_to'], 'content_assigned_to' => $p['content_assigned_to'], 'design_assigned_to' => $p['design_assigned_to'],
        ], $posts),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
