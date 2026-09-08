<?php
// READ-ONLY diagnostic — lists every post currently sitting in "approved"
// stage, showing whether it has a platform set (real Post, should move on
// to Scheduled) or not (Task, correctly stops here). Helps confirm whether
// "stuck on Approved" posts are actually missing their platform field.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query("SELECT id, title, platform, post_type, task_type, stage FROM posts WHERE stage = 'approved' ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $kind = $r['platform'] ? "POST (platform={$r['platform']})" : "TASK (no platform)";
    echo "{$r['title']} — {$kind} — post_type=" . ($r['post_type'] ?: '—') . " task_type=" . ($r['task_type'] ?: '—') . "\n";
}
echo json_encode(['ok' => true, 'total_approved' => count($rows)]) . "\n";
